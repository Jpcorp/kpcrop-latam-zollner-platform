import { Worker, type Job } from 'bullmq';
import { Redis as IORedis } from 'ioredis';
import { config } from '../config.js';
import { db } from '../infrastructure/database.js';
import { BsaleHttpClient, BsaleApiError } from '../infrastructure/bsale-http-client.js';

export interface SyncJobData {
  storeId: string;
  tenantId: string;
  syncType: 'webhook' | 'polling';
  entityType?: string;
  // Solo para jobs de webhook
  resourceUrl?: string;
  resourceId?: string;
  topic?: string;
  action?: string;
}

export function startSyncWorker() {
  const redis = new IORedis(config.REDIS_URL, { maxRetriesPerRequest: null });

  const worker = new Worker<SyncJobData>('sync', processJob, {
    connection: redis,
    concurrency: 5,
  });

  worker.on('failed', async (job, error) => {
    if (!job) return;
    const isDeadLetter = job.attemptsMade >= (job.opts.attempts ?? 1);
    if (!isDeadLetter) return;

    await db.insertInto('sync_events').values({
      tenant_id:       job.data.tenantId,
      store_id:        job.data.storeId,
      sync_type:       job.data.syncType,
      entity_type:     job.data.entityType ?? job.data.topic ?? 'unknown',
      status:          'dead_letter',
      records_updated: 0,
      records_failed:  0,
      error_message:   error.message,
      idempotency_key: job.id ?? null,
    }).execute();
  });

  worker.on('error', err => console.error('[sync-worker]', err));

  return worker;
}

async function processJob(job: Job<SyncJobData>): Promise<void> {
  const store = await db
    .selectFrom('tenant_stores')
    .selectAll()
    .where('id', '=', job.data.storeId)
    .executeTakeFirstOrThrow();

  if (!store.bsale_access_token) {
    // Error permanente — no reintentar
    await job.discard();
    return;
  }

  const bsale = new BsaleHttpClient(store.bsale_access_token);
  const start  = Date.now();

  try {
    if (job.data.syncType === 'webhook' && job.data.resourceUrl) {
      await processWebhookEvent(job.data, bsale);
    } else {
      await processPollingCycle(job.data, bsale, store.id);
    }
  } catch (err) {
    if (err instanceof BsaleApiError) {
      if (err.isClientError) {
        // Error 4xx: datos incorrectos, no es temporal — no reintentar
        await job.discard();
        return;
      }
    }
    throw err; // 5xx o timeout → BullMQ reintenta con backoff
  } finally {
    await db
      .updateTable('tenant_stores')
      .set({ last_sync_at: new Date(), last_sync_status: 'success' })
      .where('id', '=', job.data.storeId)
      .execute();
  }
}

async function processWebhookEvent(data: SyncJobData, bsale: BsaleHttpClient): Promise<void> {
  if (!data.resourceUrl) return;

  // Mapear topic de Bsale → entity de Synkrop
  const topicToEntity: Record<string, string> = {
    stock:   'stock',
    price:   'prices',
    product: 'products',
    variant: 'products',
  };
  const entity = topicToEntity[data.topic ?? ''] ?? 'products';

  // Obtener URL del CMS y secret para llamar al plugin
  const store = await db
    .selectFrom('tenant_stores')
    .select(['cms_url', 'cms_webhook_secret'])
    .where('id', '=', data.storeId)
    .executeTakeFirstOrThrow();

  if (!store.cms_url) {
    throw new Error(`Store ${data.storeId} no tiene cms_url configurada`);
  }

  // Construir URL del endpoint AJAX de Synkrop en PrestaShop
  // El token PS se omite aquí porque el plugin valida por cms_webhook_secret (header)
  const ajaxUrl = `${store.cms_url.replace(/\/$/, '')}/modules/synkrop/webhook.php`;

  const payload = {
    entity,
    topic:      data.topic,
    action:     data.action,
    resourceId: data.resourceId,
  };

  const response = await fetch(ajaxUrl, {
    method:  'POST',
    headers: {
      'Content-Type':        'application/json',
      'X-Synkrop-Secret':    store.cms_webhook_secret ?? '',
    },
    body:    JSON.stringify(payload),
    signal:  AbortSignal.timeout(30_000),
  });

  if (!response.ok) {
    throw new Error(`Synkrop webhook endpoint respondió ${response.status} en ${ajaxUrl}`);
  }

  const result = await response.json() as { success: boolean; updated?: number; message?: string };

  if (!result.success) {
    throw new Error(`Synkrop sync falló: ${result.message ?? 'error desconocido'}`);
  }

  console.log(`[webhook] ${data.topic}:${data.action} entity=${entity} updated=${result.updated ?? 0} store=${data.storeId}`);

  // Registrar evento exitoso
  await db.insertInto('sync_events').values({
    tenant_id:       data.tenantId,
    store_id:        data.storeId,
    sync_type:       'webhook',
    entity_type:     entity,
    status:          'success',
    records_updated: result.updated ?? 0,
    records_failed:  0,
    idempotency_key: null,
  }).execute();
}

async function processPollingCycle(
  data: SyncJobData,
  bsale: BsaleHttpClient,
  storeId: string,
): Promise<void> {
  // Polling de fallback: descargar catalogo completo y detectar cambios por hash
  // expand=variants reduce llamadas (trae variantes en la misma respuesta)
  const response = await bsale.get<{ items: unknown[]; count: number }>(
    '/v1/products.json?expand=[variants]&limit=50&offset=0',
  );

  let changed = 0;

  for (const product of response.items as Array<Record<string, unknown>>) {
    const variants = (product['variants'] as { items: Array<Record<string, unknown>> })?.items ?? [];

    for (const variant of variants) {
      const variantId = variant['id'] as number;
      const hash = computeHash(variant);

      const snapshot = await db
        .selectFrom('bsale_variant_snapshots')
        .selectAll()
        .where('tenant_id', '=', data.tenantId)
        .where('variant_id', '=', variantId)
        .executeTakeFirst();

      if (!snapshot || snapshot.content_hash !== hash) {
        // Variante nueva o modificada — encolar sync especifico
        changed++;
        await db
          .insertInto('bsale_variant_snapshots')
          .values({ tenant_id: data.tenantId, variant_id: variantId, content_hash: hash, last_known_data: variant, last_seen_at: new Date() })
          .onConflict(oc => oc.columns(['tenant_id', 'variant_id']).doUpdateSet({ content_hash: hash, last_known_data: variant, last_seen_at: new Date() }))
          .execute();
      }
    }
  }

  console.log(`[polling] store=${storeId} changed=${changed}/${response.count}`);
}

function computeHash(variant: Record<string, unknown>): string {
  const relevant = {
    code: variant['code'],
    cost: variant['cost'],
    quantity: variant['quantity'],
    state: variant['state'],
  };
  // Hash simple sin dependencias — suficiente para comparacion
  return Buffer.from(JSON.stringify(relevant)).toString('base64');
}

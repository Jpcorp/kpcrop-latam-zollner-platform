import { Worker, type Job } from 'bullmq';
import { Redis as IORedis } from 'ioredis';
import { config } from '../config.js';
import { db } from '../infrastructure/database.js';
import { BsaleHttpClient, BsaleApiError } from '../infrastructure/bsale-http-client.js';
import { resolveWebhookResource } from '../adapters/bsale-webhook-resolver.js';
import { decryptToken } from '../infrastructure/token-crypto.js';

/**
 * #93: fallo NO reintentable (permanente). El sync no puede tener éxito por más que se
 * reintente el mismo payload — p.ej. variante no mapeada en el CMS (requiere product-sync)
 * o payload inválido. `processJob` la trata con `job.discard()` en vez de reintentar.
 */
export class PermanentSyncError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'PermanentSyncError';
  }
}

export interface SyncJobData {
  storeId: string;
  tenantId: string;
  syncType: 'webhook' | 'polling' | 'manual';
  entityType?: string;
  // Solo para jobs de webhook
  resourceUrl?: string;
  resourceId?: string;
  topic?: string;
  action?: string;
  // #115: timestamp (unix) de cuando Bsale mando el webhook — permite al CMS
  // descartar eventos de stock que se procesan fuera de orden (concurrency:5
  // + latencia variable de red puede hacer que el mas viejo llegue despues).
  send?: number;
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
    })
      // #95: si el CMS ya reporto este job (mismo idempotency_key), el dead-letter es no-op
      // en vez de lanzar 23505 dentro del handler 'failed' (rechazo no manejado)
      .onConflict((oc) => oc.column('idempotency_key').doNothing())
      .execute();
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

  // #107: bsale_access_token esta cifrado en reposo (AES-256-GCM)
  const bsale = new BsaleHttpClient(decryptToken(store.bsale_access_token));

  // #93: reflejar el estado REAL del sync en el store (antes marcaba 'success' siempre).
  let syncStatus: 'success' | 'failed' = 'success';

  try {
    if (job.data.syncType === 'webhook' && job.data.resourceUrl) {
      await processWebhookEvent(job.data, bsale, job.id ?? undefined);
    } else if (job.data.syncType === 'manual') {
      // #55: sync manual disparado por una agencia via /v1/agency/clients/:id/sync
      // — no hay recurso de Bsale que resolver, se le pide al CMS un sync bulk
      // directo (mismo payload {entity} que ya soporta webhook.php para
      // compatibilidad con sync manual).
      await processManualSync(job.data, job.id ?? undefined);
    } else {
      await processPollingCycle(job.data, bsale, store.id, job.id ?? undefined);
    }
  } catch (err) {
    syncStatus = 'failed';
    // Fallos permanentes (no reintentar): 4xx de Bsale o rechazo permanente del CMS.
    const isPermanent =
      (err instanceof BsaleApiError && err.isClientError) ||
      err instanceof PermanentSyncError;
    if (isPermanent) {
      await job.discard();
      return;
    }
    throw err; // 5xx, timeout o error transitorio del CMS → BullMQ reintenta con backoff
  } finally {
    await db
      .updateTable('tenant_stores')
      .set({ last_sync_at: new Date(), last_sync_status: syncStatus })
      .where('id', '=', job.data.storeId)
      .execute();
  }
}

export async function processWebhookEvent(
  data: SyncJobData,
  bsale: BsaleHttpClient,
  jobId?: string,
): Promise<void> {
  if (!data.resourceUrl) return;

  const store = await db
    .selectFrom('tenant_stores')
    .select(['cms_url', 'cms_webhook_secret'])
    .where('id', '=', data.storeId)
    .executeTakeFirstOrThrow();

  if (!store.cms_url) {
    throw new Error(`Store ${data.storeId} no tiene cms_url configurada`);
  }

  console.log(`[webhook:start] jobId=${jobId ?? '-'} topic=${data.topic} action=${data.action} store=${data.storeId}`);

  // #109: action=delete en una variante -> el recurso ya no existe en Bsale, GET
  // devolveria 404 y el job se descartaria sin poner el stock en 0 (el catalogo
  // seguiria vendiendo un SKU eliminado). No hay GET que hacer: se dispatchea
  // directo con el resourceId que ya trae el webhook. topic=stock queda afuera
  // a proposito — su resourceId es el id del registro de stock, no el de la
  // variante, y no hay forma de resolverlo sin el recurso que ya no existe.
  if (data.action === 'delete' && data.topic === 'variant') {
    await dispatchToCms(store.cms_url, store.cms_webhook_secret, jobId,
      { topic: 'variant', action: 'delete', resourceId: data.resourceId });
    console.log(`[webhook:accepted] jobId=${jobId ?? '-'} store=${data.storeId} — delete de variante`);
    return;
  }

  // Obtener el recurso concreto de Bsale (solo el que cambió)
  const resolved = await resolveWebhookResource(bsale, data.topic ?? '', data.resourceUrl);

  console.log(`[webhook:resolved] jobId=${jobId ?? '-'} topic=${resolved.topic} hasData=${resolved.data !== null}`);

  // Stock con data=null = colección v2 vacía → no hay nada que sincronizar
  if (resolved.data === null && resolved.topic !== 'price') {
    console.log(`[webhook:skip] jobId=${jobId ?? '-'} topic=${resolved.topic} — colección vacía, omitiendo dispatch`);
    return;
  }

  // Payload quirúrgico si tenemos datos; bulk como fallback para topic=price
  const topicToEntity: Record<string, string> = {
    stock: 'stock', price: 'prices', product: 'products', variant: 'products',
  };
  const body = resolved.data !== null
    ? { topic: resolved.topic, bsaleData: resolved.data, send: data.send }
    : { entity: topicToEntity[data.topic ?? ''] ?? 'products' };

  await dispatchToCms(store.cms_url, store.cms_webhook_secret, jobId, body);
  console.log(`[webhook:accepted] jobId=${jobId ?? '-'} store=${data.storeId} — CMS procesará en background`);
}

export async function processManualSync(data: SyncJobData, jobId?: string): Promise<void> {
  const store = await db
    .selectFrom('tenant_stores')
    .select(['cms_url', 'cms_webhook_secret'])
    .where('id', '=', data.storeId)
    .executeTakeFirstOrThrow();

  if (!store.cms_url) {
    throw new Error(`Store ${data.storeId} no tiene cms_url configurada`);
  }

  const entity = data.entityType ?? 'products';
  console.log(`[manual-sync:start] jobId=${jobId ?? '-'} store=${data.storeId} entity=${entity}`);
  await dispatchToCms(store.cms_url, store.cms_webhook_secret, jobId, { entity });
  console.log(`[manual-sync:accepted] jobId=${jobId ?? '-'} store=${data.storeId}`);
}

/**
 * POST al endpoint webhook.php del plugin CMS. Extraido para reusar tanto en
 * el dispatch normal (payload quirurgico/bulk) como en el atajo de #109
 * (action=delete de variante, que no pasa por resolveWebhookResource).
 */
async function dispatchToCms(
  cmsUrl: string,
  cmsWebhookSecret: string | null | undefined,
  jobId: string | undefined,
  body: Record<string, unknown>,
): Promise<void> {
  const ajaxUrl = `${cmsUrl.replace(/\/$/, '')}/modules/synkrop/webhook.php`;

  const headers: Record<string, string> = {
    'Content-Type':     'application/json',
    'X-Synkrop-Secret': cmsWebhookSecret ?? '',
  };
  if (jobId) headers['X-Synkrop-Job-Id'] = jobId;

  console.log(`[webhook:dispatch] jobId=${jobId ?? '-'} url=${ajaxUrl} payload=${JSON.stringify(body).slice(0, 120)}`);

  const response = await fetch(ajaxUrl, {
    method:  'POST',
    headers,
    body:   JSON.stringify(body),
    signal: AbortSignal.timeout(30_000),
  });

  if (!response.ok) {
    // #93: 4xx del CMS = rechazo permanente (payload inválido) → no reintentar.
    // 5xx = transitorio → BullMQ reintenta.
    if (response.status >= 400 && response.status < 500) {
      throw new PermanentSyncError(`Synkrop rechazó el payload (${response.status}) en ${ajaxUrl}`);
    }
    throw new Error(`Synkrop webhook endpoint respondió ${response.status} en ${ajaxUrl}`);
  }

  const result = await response.json() as {
    success: boolean; updated?: number; message?: string; retryable?: boolean; status?: string;
  };

  if (!result.success) {
    // #93: el CMS marca retryable=false para fallos permanentes (p.ej. variante no mapeada).
    if (result.retryable === false) {
      throw new PermanentSyncError(`Synkrop sync falló (permanente): ${result.message ?? 'error desconocido'}`);
    }
    throw new Error(`Synkrop sync falló: ${result.message ?? 'error desconocido'}`);
  }

  // El resultado real (records_updated, errors) llega via POST /v1/sync/report
  // que el plugin CMS llama al terminar el proceso en background.
}

export async function processPollingCycle(
  data: SyncJobData,
  bsale: BsaleHttpClient,
  storeId: string,
  jobId?: string,
): Promise<void> {
  const store = await db
    .selectFrom('tenant_stores')
    .select(['cms_url', 'cms_webhook_secret'])
    .where('id', '=', storeId)
    .executeTakeFirstOrThrow();

  if (!store.cms_url) {
    throw new Error(`Store ${storeId} no tiene cms_url configurada`);
  }

  // #79: Bsale no tiene updated_since (ADR-004) — se descarga el catalogo
  // completo, paginado (mismo tamano de pagina y misma condicion de corte
  // que BsaleApiClient::getAll() en PHP: hasta que una pagina viene incompleta).
  // expand=variants reduce llamadas (trae variantes en la misma respuesta).
  const products: Array<Record<string, unknown>> = [];
  const pageSize = 50;
  let offset = 0;
  let page: { items: unknown[]; count: number };
  do {
    page = await bsale.get<{ items: unknown[]; count: number }>(
      `/v1/products.json?expand=[variants]&limit=${pageSize}&offset=${offset}`,
    );
    products.push(...(page.items as Array<Record<string, unknown>>));
    offset += pageSize;
  } while (page.items.length === pageSize);

  let changed = 0;
  let dispatched = 0;
  let failed = 0;

  for (const product of products) {
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

      if (snapshot && snapshot.content_hash === hash) continue;

      changed++;

      try {
        // #79: mismo payload quirurgico {topic, bsaleData} que ya acepta
        // webhook.php — se agrega el producto padre para que
        // SynkropService::syncSingle() tenga nombre/estado reales (sin esto
        // cae al fallback de description/code, ver SynkropService.php).
        await dispatchToCms(store.cms_url, store.cms_webhook_secret, jobId, {
          topic:     'variant',
          bsaleData: { ...variant, product },
        });
        dispatched++;

        await db
          .insertInto('bsale_variant_snapshots')
          .values({ tenant_id: data.tenantId, variant_id: variantId, content_hash: hash, last_known_data: variant, last_seen_at: new Date() })
          .onConflict(oc => oc.columns(['tenant_id', 'variant_id']).doUpdateSet({ content_hash: hash, last_known_data: variant, last_seen_at: new Date() }))
          .execute();
      } catch (err) {
        // #79: una variante que falla no debe tirar abajo el resto del
        // catalogo — se salta el upsert del snapshot a proposito, para que
        // el proximo ciclo de polling la vuelva a detectar como cambiada.
        failed++;
        console.error(`[polling:variant-error] jobId=${jobId ?? '-'} variantId=${variantId}`, err);
      }
    }
  }

  console.log(`[polling] jobId=${jobId ?? '-'} store=${storeId} changed=${changed} dispatched=${dispatched} failed=${failed} total=${products.length}`);
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

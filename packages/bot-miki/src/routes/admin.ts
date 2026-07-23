import type { FastifyInstance } from 'fastify';
import type { Queue } from 'bullmq';
import { db } from '../infrastructure/database.js';
import type { SyncJobData } from '../workers/sync-worker.js';
import { encryptToken } from '../infrastructure/token-crypto.js';
import { adminKeyMatches } from '../infrastructure/admin-key.js';
import crypto from 'node:crypto';

function generateApiKey(): string {
  return 'kp_' + crypto.randomBytes(24).toString('hex');
}

export async function adminRoute(app: FastifyInstance, opts: { queue: Queue<SyncJobData> }) {
  const { queue } = opts;
  // Middleware: verify X-Admin-Key on all /admin routes
  app.addHook('onRequest', async (request, reply) => {
    if (!adminKeyMatches(request.headers['x-admin-key'])) {
      return reply.code(403).send({ code: 'FORBIDDEN', message: 'Admin key invalida o faltante' });
    }
  });

  // ─── GET /v1/admin/tenants/:tenantId ───────────────────────────────────────

  app.get<{ Params: { tenantId: string } }>(
    '/admin/tenants/:tenantId',
    {
      schema: {
        tags: ['admin'],
        summary: 'Obtener tenant y su API Key',
        security: [{ adminKey: [] }],
        params: { type: 'object', required: ['tenantId'], properties: { tenantId: { type: 'string' } } },
        response: {
          200: {
            type: 'object',
            properties: {
              id: { type: 'string' }, tenantId: { type: 'string' }, plan: { type: 'string' },
              apiKey: { type: 'string' }, status: { type: 'string' }, createdAt: { type: 'string' },
            },
          },
          404: { type: 'object', properties: { code: { type: 'string' }, message: { type: 'string' } } },
        },
      },
    },
    async (request, reply) => {
      const license = await db
        .selectFrom('licenses')
        .selectAll()
        .where('tenant_id', '=', request.params.tenantId)
        .executeTakeFirst();

      if (!license) {
        return reply.code(404).send({ code: 'TENANT_NOT_FOUND', message: `Tenant '${request.params.tenantId}' no encontrado` });
      }

      return reply.send({
        id:        license.id,
        tenantId:  license.tenant_id,
        plan:      license.plan,
        apiKey:    license.api_key,
        status:    license.status,
        createdAt: (license.created_at as Date).toISOString(),
      });
    },
  );

  // ─── POST /v1/admin/tenants ─────────────────────────────────────────────────
  // Creates a new license + tenant. Returns the generated api_key (only time it's shown).

  app.post<{
    Body: {
      tenantId: string;
      subscriptionId: string;
      plan: 'starter' | 'growth' | 'agency';
      maxStores?: number;
      features?: string[];
      expiresAt?: string;
    };
  }>(
    '/admin/tenants',
    {
      schema: {
        tags: ['admin'],
        summary: 'Crear tenant y licencia',
        description: 'Endpoint interno para activar un nuevo cliente. Genera la API Key. Requiere X-Admin-Key.',
        security: [{ adminKey: [] }],
        body: {
          type: 'object',
          required: ['tenantId', 'subscriptionId', 'plan'],
          properties: {
            tenantId:       { type: 'string', description: 'Identificador único del tenant (ej: tienda-abc-cl)' },
            subscriptionId: { type: 'string', description: 'ID de suscripción Stripe (sub_...)' },
            plan:           { type: 'string', enum: ['starter', 'growth', 'agency'] },
            maxStores:      { type: 'number', default: 1 },
            features:       { type: 'array', items: { type: 'string' }, default: ['sync_manual'] },
            expiresAt:      { type: 'string', format: 'date-time', description: 'Null = sin vencimiento' },
          },
        },
        response: {
          201: {
            type: 'object',
            properties: {
              id:        { type: 'string' },
              tenantId:  { type: 'string' },
              plan:      { type: 'string' },
              apiKey:    { type: 'string', description: 'Guardar inmediatamente — no se muestra de nuevo' },
              status:    { type: 'string' },
              createdAt: { type: 'string' },
            },
          },
          409: { type: 'object', properties: { code: { type: 'string' }, message: { type: 'string' } } },
        },
      },
    },
    async (request, reply) => {
      const body = request.body;

      const existing = await db
        .selectFrom('licenses')
        .select('id')
        .where('tenant_id', '=', body.tenantId)
        .executeTakeFirst();

      if (existing) {
        return reply.code(409).send({
          code: 'TENANT_EXISTS',
          message: `El tenant '${body.tenantId}' ya existe`,
        });
      }

      const apiKey = generateApiKey();
      const planFeatures: Record<string, string[]> = {
        starter: ['sync_manual'],
        growth:  ['sync_manual', 'sync_auto', 'sync_prices'],
        agency:  ['sync_manual', 'sync_auto', 'sync_prices', 'dropshipping', 'multi_store'],
      };

      const license = await db
        .insertInto('licenses')
        .values({
          tenant_id:       body.tenantId,
          subscription_id: body.subscriptionId,
          plan:            body.plan,
          status:          'active',
          features:        JSON.stringify(body.features ?? planFeatures[body.plan]),
          max_stores:      body.maxStores ?? (body.plan === 'starter' ? 1 : body.plan === 'growth' ? 3 : 50),
          api_key:         apiKey,
          expires_at:      body.expiresAt ? new Date(body.expiresAt) : null,
        })
        .returningAll()
        .executeTakeFirstOrThrow();

      return reply.code(201).send({
        id:        license.id,
        tenantId:  license.tenant_id,
        plan:      license.plan,
        apiKey,
        status:    license.status,
        createdAt: (license.created_at as Date).toISOString(),
      });
    },
  );

  // ─── GET /v1/admin/tenants/:tenantId/stores ─────────────────────────────────

  app.get<{ Params: { tenantId: string } }>(
    '/admin/tenants/:tenantId/stores',
    {
      schema: {
        tags: ['admin'],
        summary: 'Listar tiendas de un tenant',
        security: [{ adminKey: [] }],
        params: { type: 'object', required: ['tenantId'], properties: { tenantId: { type: 'string' } } },
      },
    },
    async (request, reply) => {
      const license = await db
        .selectFrom('licenses')
        .select(['id', 'max_stores'])
        .where('tenant_id', '=', request.params.tenantId)
        .executeTakeFirst();

      if (!license) {
        return reply.code(404).send({ code: 'TENANT_NOT_FOUND', message: `Tenant '${request.params.tenantId}' no encontrado` });
      }

      const stores = await db
        .selectFrom('tenant_stores')
        .selectAll()
        .where('license_id', '=', license.id as string)
        .execute();

      return reply.send({ maxStores: license.max_stores, stores });
    },
  );

  // ─── POST /v1/admin/tenants/:tenantId/stores ────────────────────────────────

  app.post<{
    Params: { tenantId: string };
    Body: {
      storeName: string;
      cmsType: string;
      cmsUrl: string;
      bsaleIntegrationId?: number;
      bsaleAccessToken?: string;
      bsalePriceListId?: number;
      bsaleOfficeId?: number;
    };
  }>(
    '/admin/tenants/:tenantId/stores',
    {
      schema: {
        tags: ['admin'],
        summary: 'Agregar tienda a un tenant',
        description: 'Registra una nueva tienda CMS bajo un tenant existente. Requiere X-Admin-Key.',
        security: [{ adminKey: [] }],
        params: {
          type: 'object',
          required: ['tenantId'],
          properties: { tenantId: { type: 'string' } },
        },
        body: {
          type: 'object',
          required: ['storeName', 'cmsType', 'cmsUrl'],
          properties: {
            storeName:           { type: 'string' },
            cmsType:             { type: 'string', enum: ['wordpress','prestashop','shopify','woocommerce','magento','jumpseller'] },
            cmsUrl:              { type: 'string', format: 'uri' },
            bsaleIntegrationId:  { type: 'number' },
            bsaleAccessToken:    { type: 'string' },
            bsalePriceListId:    { type: 'number' },
            bsaleOfficeId:       { type: 'number' },
          },
        },
        response: {
          201: {
            type: 'object',
            properties: {
              id:        { type: 'string' },
              storeName: { type: 'string' },
              cmsType:   { type: 'string' },
              cmsUrl:    { type: 'string' },
              createdAt: { type: 'string' },
            },
          },
          404: { type: 'object', properties: { code: { type: 'string' }, message: { type: 'string' } } },
        },
      },
    },
    async (request, reply) => {
      const { tenantId } = request.params;
      const body = request.body;

      const license = await db
        .selectFrom('licenses')
        .select(['id', 'max_stores'])
        .where('tenant_id', '=', tenantId)
        .executeTakeFirst();

      if (!license) {
        return reply.code(404).send({ code: 'TENANT_NOT_FOUND', message: `Tenant '${tenantId}' no encontrado` });
      }

      const storeCount = await db
        .selectFrom('tenant_stores')
        .select(db.fn.count<number>('id').as('count'))
        .where('license_id', '=', license.id as string)
        .executeTakeFirstOrThrow();

      if (Number(storeCount.count) >= license.max_stores) {
        return reply.code(403).send({
          code: 'STORE_LIMIT_REACHED',
          message: `El plan permite máximo ${license.max_stores} tiendas`,
        });
      }

      const store = await db
        .insertInto('tenant_stores')
        .values({
          license_id:           license.id as string,
          store_name:           body.storeName,
          cms_type:             body.cmsType,
          cms_url:              body.cmsUrl,
          bsale_integration_id: body.bsaleIntegrationId ?? null,
          // #107: cifrado en reposo — nunca texto plano en la BD
          bsale_access_token:   body.bsaleAccessToken ? encryptToken(body.bsaleAccessToken) : null,
          bsale_price_list_id:  body.bsalePriceListId ?? null,
          bsale_office_id:      body.bsaleOfficeId ?? null,
        })
        .returningAll()
        .executeTakeFirstOrThrow();

      return reply.code(201).send({
        id:        store.id,
        storeName: store.store_name,
        cmsType:   store.cms_type,
        cmsUrl:    store.cms_url,
        createdAt: (store.created_at as Date).toISOString(),
      });
    },
  );

  // ─── PATCH /v1/admin/tenants/:tenantId/stores/:storeId ──────────────────────

  app.patch<{
    Params: { tenantId: string; storeId: string };
    Body: {
      bsaleIntegrationId?: number;
      bsaleAccessToken?: string;
      bsalePriceListId?: number;
      bsaleOfficeId?: number;
      cmsWebhookSecret?: string;
    };
  }>(
    '/admin/tenants/:tenantId/stores/:storeId',
    {
      schema: {
        tags: ['admin'],
        summary: 'Actualizar datos de una tienda',
        security: [{ adminKey: [] }],
        params: {
          type: 'object',
          required: ['tenantId', 'storeId'],
          properties: {
            tenantId: { type: 'string' },
            storeId:  { type: 'string' },
          },
        },
        body: {
          type: 'object',
          properties: {
            bsaleIntegrationId: { type: 'number', description: 'cpnId de Bsale (necesario para webhooks)' },
            bsaleAccessToken:   { type: 'string' },
            bsalePriceListId:   { type: 'number' },
            bsaleOfficeId:      { type: 'number' },
            cmsWebhookSecret:   { type: 'string', description: 'Secret para verificar llamadas del CMS' },
          },
        },
      },
    },
    async (request, reply) => {
      const { tenantId, storeId } = request.params;
      const body = request.body;

      const license = await db
        .selectFrom('licenses')
        .select('id')
        .where('tenant_id', '=', tenantId)
        .executeTakeFirst();

      if (!license) {
        return reply.code(404).send({ code: 'TENANT_NOT_FOUND', message: `Tenant '${tenantId}' no encontrado` });
      }

      const updates: Record<string, unknown> = {};
      if (body.bsaleIntegrationId !== undefined) updates.bsale_integration_id = body.bsaleIntegrationId;
      // #107: cifrado en reposo — nunca texto plano en la BD
      if (body.bsaleAccessToken   !== undefined) updates.bsale_access_token   = encryptToken(body.bsaleAccessToken);
      if (body.bsalePriceListId   !== undefined) updates.bsale_price_list_id  = body.bsalePriceListId;
      if (body.bsaleOfficeId      !== undefined) updates.bsale_office_id      = body.bsaleOfficeId;
      if (body.cmsWebhookSecret   !== undefined) updates.cms_webhook_secret   = body.cmsWebhookSecret;

      if (Object.keys(updates).length === 0) {
        return reply.code(400).send({ code: 'NO_FIELDS', message: 'Ningún campo para actualizar' });
      }

      const store = await db
        .updateTable('tenant_stores')
        .set(updates)
        .where('id', '=', storeId)
        .where('license_id', '=', license.id as string)
        .returningAll()
        .executeTakeFirst();

      if (!store) {
        return reply.code(404).send({ code: 'STORE_NOT_FOUND', message: `Tienda '${storeId}' no encontrada` });
      }

      return reply.send({ id: store.id, storeName: store.store_name, updated: Object.keys(updates) });
    },
  );

  // ─── GET /v1/admin/observability ────────────────────────────────────────────

  app.get<{
    Querystring: {
      tenantId?: string;
      storeId?:  string;
      status?:   string;
      limit?:    number;
    };
  }>(
    '/admin/observability',
    {
      schema: {
        tags: ['admin'],
        summary: 'Estado de sync jobs, eventos y webhooks registrados',
        description: 'Panel de observabilidad: muestra el estado de la cola BullMQ, últimos eventos de sync (incluyendo dead letters) y registros de webhooks.',
        security: [{ adminKey: [] }],
        querystring: {
          type: 'object',
          properties: {
            tenantId: { type: 'string', description: 'Filtrar por tenant' },
            storeId:  { type: 'string', description: 'Filtrar por tienda' },
            status:   { type: 'string', description: 'Filtrar por status (failed, dead_letter, success, etc.)' },
            limit:    { type: 'number', default: 50, description: 'Máximo de eventos a devolver' },
          },
        },
      },
    },
    async (request, reply) => {
      const { tenantId, storeId, status, limit = 50 } = request.query;

      // 1. Estado de la cola BullMQ
      const [waiting, active, completed, failed, delayed] = await Promise.all([
        queue.getWaitingCount(),
        queue.getActiveCount(),
        queue.getCompletedCount(),
        queue.getFailedCount(),
        queue.getDelayedCount(),
      ]);

      // 2. Últimos sync_events con nombre de tienda
      let eventsQuery = db
        .selectFrom('sync_events as e')
        .leftJoin('tenant_stores as s', 's.id', 'e.store_id')
        .select([
          'e.id',
          'e.tenant_id',
          'e.store_id',
          's.store_name',
          'e.sync_type',
          'e.entity_type',
          'e.status',
          'e.records_updated',
          'e.records_failed',
          'e.duration_ms',
          'e.error_message',
          'e.idempotency_key',
          'e.created_at',
        ])
        .orderBy('e.created_at', 'desc')
        .limit(Math.min(limit, 200));

      if (tenantId) eventsQuery = eventsQuery.where('e.tenant_id', '=', tenantId);
      if (storeId)  eventsQuery = eventsQuery.where('e.store_id',  '=', storeId);
      if (status)   eventsQuery = eventsQuery.where('e.status',    '=', status);

      const events = await eventsQuery.execute();

      // 3. Webhooks registrados
      let webhooksQuery = db
        .selectFrom('webhook_registrations as w')
        .leftJoin('tenant_stores as s', 's.id', 'w.store_id')
        .select([
          'w.id',
          'w.store_id',
          's.store_name',
          'w.bsale_cpn_id',
          'w.topics',
          'w.status',
          'w.notes',
          'w.requested_at',
          'w.activated_at',
        ])
        .orderBy('w.requested_at', 'desc');

      if (storeId) webhooksQuery = webhooksQuery.where('w.store_id', '=', storeId);

      const webhooks = await webhooksQuery.execute();

      // 4. Resumen de dead letters por tenant/store
      const deadLetters = events.filter(e => e.status === 'dead_letter');

      return reply.send({
        queue: {
          waiting,
          active,
          completed,
          failed,
          delayed,
          health: failed > 10 ? 'degraded' : active > 50 ? 'busy' : 'ok',
        },
        summary: {
          total:      events.length,
          deadLetters: deadLetters.length,
          byStatus:   Object.fromEntries(
            [...new Set(events.map(e => e.status))].map(s => [
              s,
              events.filter(e => e.status === s).length,
            ])
          ),
        },
        events: events.map(e => ({
          ...e,
          created_at: (e.created_at as Date).toISOString(),
        })),
        webhooks: webhooks.map(w => ({
          ...w,
          requested_at: (w.requested_at as Date).toISOString(),
          activated_at: w.activated_at ? (w.activated_at as Date).toISOString() : null,
        })),
      });
    },
  );
}

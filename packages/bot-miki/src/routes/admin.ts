import type { FastifyInstance } from 'fastify';
import { db } from '../infrastructure/database.js';
import { config } from '../config.js';
import crypto from 'node:crypto';

function generateApiKey(): string {
  return 'kp_' + crypto.randomBytes(24).toString('hex');
}

export async function adminRoute(app: FastifyInstance) {
  // Middleware: verify X-Admin-Key on all /admin routes
  app.addHook('onRequest', async (request, reply) => {
    const key = request.headers['x-admin-key'];
    if (key !== config.ADMIN_KEY) {
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
          bsale_access_token:   body.bsaleAccessToken ?? null,
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
}

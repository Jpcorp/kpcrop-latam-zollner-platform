import type { FastifyInstance, FastifyReply } from 'fastify';
import type { Queue } from 'bullmq';
import { db } from '../infrastructure/database.js';
import type { SyncJobData } from '../workers/sync-worker.js';

interface AgencyLicense {
  id: string;
  tenant_id: string;
}

/**
 * #54/#55: resuelve la licencia del caller por su propio X-API-Key (no
 * X-Admin-Key — este es self-service para la agencia, no un endpoint interno).
 * Compartido por las 3 rutas de /v1/agency/* para no repetir el mismo chequeo
 * 401/404/402 tres veces.
 */
async function resolveAgencyLicense(
  apiKey: string | undefined,
  reply: FastifyReply,
): Promise<AgencyLicense | null> {
  if (!apiKey) {
    reply.code(401).send({ code: 'MISSING_API_KEY', message: 'Header X-API-Key requerido' });
    return null;
  }

  const license = await db
    .selectFrom('licenses')
    .select(['id', 'tenant_id', 'status'])
    .where('api_key', '=', apiKey)
    .executeTakeFirst();

  if (!license) {
    reply.code(404).send({ code: 'TENANT_NOT_FOUND', message: 'Tenant no encontrado' });
    return null;
  }

  if (license.status !== 'active') {
    reply.code(402).send({
      code: 'LICENSE_EXPIRED',
      message: `Licencia ${license.status}. Renueva en www.keepcrop.com`,
    });
    return null;
  }

  return { id: license.id as string, tenant_id: license.tenant_id };
}

const VALID_ENTITIES = ['products', 'stock', 'prices'] as const;

export async function agencyRoute(app: FastifyInstance, opts: { queue: Queue<SyncJobData> }) {
  const { queue } = opts;

  // ─── GET /v1/agency/clients ─────────────────────────────────────────────────

  app.get(
    '/agency/clients',
    {
      schema: {
        tags: ['agency'],
        summary: 'Listar las tiendas (clientes) de la agencia',
        security: [{ apiKey: [] }],
        response: {
          200: {
            type: 'object',
            properties: {
              clients: {
                type: 'array',
                items: {
                  type: 'object',
                  properties: {
                    id:              { type: 'string' },
                    storeName:       { type: 'string' },
                    cmsType:         { type: 'string' },
                    lastSyncAt:      { type: 'string', nullable: true },
                    lastSyncStatus:  { type: 'string', nullable: true },
                  },
                },
              },
            },
          },
        },
      },
    },
    async (request, reply) => {
      const license = await resolveAgencyLicense(request.headers['x-api-key'] as string | undefined, reply);
      if (!license) return;

      const stores = await db
        .selectFrom('tenant_stores')
        .select(['id', 'store_name', 'cms_type', 'last_sync_at', 'last_sync_status'])
        .where('license_id', '=', license.id)
        .execute();

      return reply.send({
        clients: stores.map(s => ({
          id:             s.id,
          storeName:      s.store_name,
          cmsType:        s.cms_type,
          lastSyncAt:     s.last_sync_at ? (s.last_sync_at as Date).toISOString() : null,
          lastSyncStatus: s.last_sync_status,
        })),
      });
    },
  );

  // ─── POST /v1/agency/clients/:storeId/sync ──────────────────────────────────

  app.post<{ Params: { storeId: string }; Body: { entity?: string } }>(
    '/agency/clients/:storeId/sync',
    {
      schema: {
        tags: ['agency'],
        summary: 'Iniciar sync manual para un cliente de la agencia',
        security: [{ apiKey: [] }],
        params: {
          type: 'object',
          required: ['storeId'],
          properties: { storeId: { type: 'string' } },
        },
        // #55: sin schema de body a propósito — un POST sin body (cliente real
        // curl/fetch sin Content-Type) es válido y debe usar el default de
        // "entity". Un schema `type: object` acá rechaza `undefined` con 400
        // antes de llegar al handler. El valor se valida a mano más abajo.
      },
    },
    async (request, reply) => {
      const license = await resolveAgencyLicense(request.headers['x-api-key'] as string | undefined, reply);
      if (!license) return;

      const entity = request.body?.entity ?? 'products';
      if (!VALID_ENTITIES.includes(entity as typeof VALID_ENTITIES[number])) {
        return reply.code(400).send({ code: 'INVALID_ENTITY', message: `entity inválido: ${entity}` });
      }

      // #54: scoping estricto — nunca disparar sync en una tienda que no
      // pertenece a la licencia del caller (protege contra IDOR por storeId).
      const store = await db
        .selectFrom('tenant_stores')
        .select('id')
        .where('id', '=', request.params.storeId)
        .where('license_id', '=', license.id)
        .executeTakeFirst();

      if (!store) {
        return reply.code(404).send({ code: 'STORE_NOT_FOUND', message: `Tienda '${request.params.storeId}' no encontrada` });
      }

      const jobId = `manual_${store.id}_${entity}_${Date.now()}`;
      await queue.add(
        'bsale-webhook',
        {
          storeId:    store.id,
          tenantId:   license.tenant_id,
          syncType:   'manual',
          entityType: entity,
        },
        {
          jobId,
          attempts: 3,
          backoff:  { type: 'exponential', delay: 30_000 },
          removeOnComplete: { age: 86_400 },
          removeOnFail:     { age: 604_800 },
        },
      );

      return reply.code(202).send({ jobId, storeId: store.id, entity });
    },
  );

  // ─── GET /v1/agency/clients/:storeId/logs ───────────────────────────────────

  app.get<{ Params: { storeId: string }; Querystring: { limit?: number } }>(
    '/agency/clients/:storeId/logs',
    {
      schema: {
        tags: ['agency'],
        summary: 'Historial de syncs de un cliente de la agencia',
        security: [{ apiKey: [] }],
        params: {
          type: 'object',
          required: ['storeId'],
          properties: { storeId: { type: 'string' } },
        },
        querystring: {
          type: 'object',
          properties: { limit: { type: 'number', default: 50 } },
        },
      },
    },
    async (request, reply) => {
      const license = await resolveAgencyLicense(request.headers['x-api-key'] as string | undefined, reply);
      if (!license) return;

      // Mismo scoping que en /sync — confirma que la tienda es de esta licencia
      // antes de exponer su historial.
      const store = await db
        .selectFrom('tenant_stores')
        .select('id')
        .where('id', '=', request.params.storeId)
        .where('license_id', '=', license.id)
        .executeTakeFirst();

      if (!store) {
        return reply.code(404).send({ code: 'STORE_NOT_FOUND', message: `Tienda '${request.params.storeId}' no encontrada` });
      }

      const limit = request.query.limit ?? 50;
      const events = await db
        .selectFrom('sync_events')
        .select(['id', 'sync_type', 'entity_type', 'status', 'records_updated', 'records_failed', 'error_message', 'created_at'])
        .where('store_id', '=', store.id)
        .orderBy('created_at', 'desc')
        .limit(limit)
        .execute();

      return reply.send({
        storeId: store.id,
        logs: events.map(e => ({
          id:             e.id,
          syncType:       e.sync_type,
          entityType:     e.entity_type,
          status:         e.status,
          recordsUpdated: e.records_updated,
          recordsFailed:  e.records_failed,
          errorMessage:   e.error_message,
          createdAt:      (e.created_at as Date).toISOString(),
        })),
      });
    },
  );

  // ─── GET /v1/agency/profile (#56: white-label básico) ───────────────────────

  app.get(
    '/agency/profile',
    {
      schema: {
        tags: ['agency'],
        summary: 'Obtener el branding (white-label) configurado por la agencia',
        security: [{ apiKey: [] }],
        response: {
          200: {
            type: 'object',
            properties: {
              agencyName:       { type: 'string', nullable: true },
              agencyLogoUrl:    { type: 'string', nullable: true },
              agencyBrandColor: { type: 'string', nullable: true },
            },
          },
        },
      },
    },
    async (request, reply) => {
      const license = await resolveAgencyLicense(request.headers['x-api-key'] as string | undefined, reply);
      if (!license) return;

      const row = await db
        .selectFrom('licenses')
        .select(['agency_name', 'agency_logo_url', 'agency_brand_color'])
        .where('id', '=', license.id)
        .executeTakeFirstOrThrow();

      return reply.send({
        agencyName:       row.agency_name,
        agencyLogoUrl:    row.agency_logo_url,
        agencyBrandColor: row.agency_brand_color,
      });
    },
  );

  // ─── PUT /v1/agency/profile (#56: white-label básico) ───────────────────────

  app.put<{ Body: { agencyName?: string; agencyLogoUrl?: string; agencyBrandColor?: string } }>(
    '/agency/profile',
    {
      schema: {
        tags: ['agency'],
        summary: 'Configurar el branding (white-label) de la agencia',
        security: [{ apiKey: [] }],
        body: {
          type: 'object',
          properties: {
            agencyName:       { type: 'string', minLength: 1, maxLength: 100 },
            agencyLogoUrl:    { type: 'string', minLength: 1, maxLength: 500 },
            // #C2 (hex de 6 dígitos, con #) — mismo formato que un <input type="color">
            agencyBrandColor: { type: 'string', pattern: '^#[0-9a-fA-F]{6}$' },
          },
        },
      },
    },
    async (request, reply) => {
      const license = await resolveAgencyLicense(request.headers['x-api-key'] as string | undefined, reply);
      if (!license) return;

      const body = request.body ?? {};

      // Solo https:// — este valor termina en un <img src="..."> del lado del
      // plugin; restringir el esquema es higiene barata, sin necesidad de un
      // validador de URLs completo para un caso "básico".
      if (body.agencyLogoUrl !== undefined && !body.agencyLogoUrl.startsWith('https://')) {
        return reply.code(400).send({ code: 'INVALID_LOGO_URL', message: 'agencyLogoUrl debe empezar con https://' });
      }

      const updates: Record<string, string> = {};
      if (body.agencyName !== undefined) updates.agency_name = body.agencyName;
      if (body.agencyLogoUrl !== undefined) updates.agency_logo_url = body.agencyLogoUrl;
      if (body.agencyBrandColor !== undefined) updates.agency_brand_color = body.agencyBrandColor;

      if (Object.keys(updates).length === 0) {
        return reply.code(400).send({ code: 'NO_FIELDS', message: 'Ningún campo para actualizar' });
      }

      const row = await db
        .updateTable('licenses')
        .set(updates)
        .where('id', '=', license.id)
        .returningAll()
        .executeTakeFirstOrThrow();

      return reply.send({
        agencyName:       row.agency_name,
        agencyLogoUrl:    row.agency_logo_url,
        agencyBrandColor: row.agency_brand_color,
      });
    },
  );
}

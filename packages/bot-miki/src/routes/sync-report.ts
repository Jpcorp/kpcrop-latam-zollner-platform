import type { FastifyInstance } from 'fastify';
import { db } from '../infrastructure/database.js';

interface SyncReportBody {
  // #91/#99: el tenant se deriva del lado servidor a partir de la licencia (X-API-Key).
  // Se acepta por compatibilidad con el plugin, pero NO se usa para escribir.
  tenantId?: string;
  syncType: 'manual' | 'auto' | 'webhook' | 'dropshipping';
  entityType: 'products' | 'prices' | 'stock' | 'clients' | 'orders';
  status: 'success' | 'partial' | 'failed';
  recordsUpdated?: number;
  recordsFailed?: number;
  durationMs?: number;
  errorMessage?: string;
  idempotencyKey?: string;
}

export async function syncReportRoute(app: FastifyInstance) {
  app.post<{ Body: SyncReportBody }>(
    '/sync/report',
    {
      schema: {
        tags: ['sync'],
        summary: 'Reportar resultado de sincronizacion',
        description: 'El plugin CMS llama a este endpoint al terminar una sync para registrar el resultado en el event log de bot-miki.',
        security: [{ apiKey: [] }],
        body: {
          type: 'object',
          required: ['syncType', 'entityType', 'status'],
          properties: {
            tenantId:       { type: 'string', description: 'Ignorado: el tenant se deriva de la X-API-Key.' },
            syncType:       { type: 'string', enum: ['manual','auto','webhook','dropshipping'] },
            entityType:     { type: 'string', enum: ['products','prices','stock','clients','orders'] },
            status:         { type: 'string', enum: ['success','partial','failed'] },
            recordsUpdated: { type: 'number' },
            recordsFailed:  { type: 'number' },
            durationMs:     { type: 'number' },
            errorMessage:   { type: 'string' },
            idempotencyKey: { type: 'string' },
          },
        },
      },
    },
    async (request, reply) => {
      const body = request.body;

      // #91: autenticar con X-API-Key contra licenses.api_key y derivar el tenant del lado
      // servidor (no confiar en body.tenantId). Mismo patron que routes/license.ts.
      const apiKey = request.headers['x-api-key'] as string | undefined;
      if (!apiKey) {
        return reply.code(401).send({ code: 'MISSING_API_KEY', message: 'Header X-API-Key requerido' });
      }

      const license = await db
        .selectFrom('licenses')
        .select(['tenant_id', 'status'])
        .where('api_key', '=', apiKey)
        .executeTakeFirst();

      if (!license) {
        return reply.code(401).send({ code: 'INVALID_API_KEY', message: 'API Key invalida' });
      }
      if (license.status !== 'active') {
        return reply.code(402).send({
          code: 'LICENSE_EXPIRED',
          message: `Licencia ${license.status}. Renueva en www.keepcrop.com`,
        });
      }

      await db.insertInto('sync_events').values({
        tenant_id:       license.tenant_id,
        sync_type:       body.syncType,
        entity_type:     body.entityType,
        status:          body.status,
        records_updated: body.recordsUpdated ?? 0,
        records_failed:  body.recordsFailed ?? 0,
        duration_ms:     body.durationMs ?? null,
        error_message:   body.errorMessage ?? null,
        idempotency_key: body.idempotencyKey ?? null,
      })
        // #95: un reporte duplicado (mismo idempotency_key) es no-op, no revienta el endpoint
        .onConflict((oc) => oc.column('idempotency_key').doNothing())
        .execute();

      return reply.code(204).send();
    },
  );
}

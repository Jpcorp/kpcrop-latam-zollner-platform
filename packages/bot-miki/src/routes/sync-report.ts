import type { FastifyInstance } from 'fastify';
import { db } from '../infrastructure/database.js';

interface SyncReportBody {
  tenantId: string;
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
          required: ['tenantId', 'syncType', 'entityType', 'status'],
          properties: {
            tenantId:       { type: 'string' },
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

      await db.insertInto('sync_events').values({
        tenant_id:       body.tenantId,
        sync_type:       body.syncType,
        entity_type:     body.entityType,
        status:          body.status,
        records_updated: body.recordsUpdated ?? 0,
        records_failed:  body.recordsFailed ?? 0,
        duration_ms:     body.durationMs ?? null,
        error_message:   body.errorMessage ?? null,
        idempotency_key: body.idempotencyKey ?? null,
      }).execute();

      return reply.code(204).send();
    },
  );
}

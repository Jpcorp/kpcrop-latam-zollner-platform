import type { FastifyInstance } from 'fastify';
import { Queue } from 'bullmq';
import { db } from '../infrastructure/database.js';
import type { SyncJobData } from '../workers/sync-worker.js';

interface BsaleWebhookPayload {
  cpnId: number;
  resource: string;       // ej: "/v2/products/952.json"
  resourceId: string;
  topic: string;          // product | variant | stock | price | document
  action: string;         // post | put | delete
  send: number;           // unix timestamp
}

export async function webhooksRoute(app: FastifyInstance, opts: { queue: Queue<SyncJobData> }) {
  const { queue } = opts;
  app.post<{ Body: BsaleWebhookPayload }>(
    '/webhooks/bsale',
    {
      schema: {
        tags: ['webhooks'],
        summary: 'Recibir webhook de Bsale',
        description: 'Recibe notificaciones de cambio de Bsale y las encola en BullMQ para procesamiento asincrono. Siempre responde 200 para que Bsale no reintente.',
        body: {
          type: 'object',
          required: ['cpnId', 'resource', 'resourceId', 'topic', 'action'],
          properties: {
            cpnId:      { type: 'number' },
            resource:   { type: 'string' },
            resourceId: { type: 'string' },
            topic:      { type: 'string' },
            action:     { type: 'string' },
            send:       { type: 'number' },
          },
        },
      },
    },
    async (request, reply) => {
      const payload = request.body;

      // Validacion anti-spoofing: el resource debe ser una ruta relativa de Bsale
      if (!payload.resource.startsWith('/v')) {
        return reply.code(400).send({ error: 'invalid_resource' });
      }

      // Solo procesar topics relevantes para sync CMS
      const relevantTopics = ['product', 'variant', 'stock', 'price'];
      if (!relevantTopics.includes(payload.topic)) {
        return reply.code(200).send(); // Aceptar pero ignorar
      }

      // Buscar tenant por cpnId de Bsale
      const store = await db
        .selectFrom('tenant_stores')
        .selectAll()
        .where('bsale_integration_id', '=', payload.cpnId)
        .executeTakeFirst();

      if (!store) {
        // 200 para que Bsale no reintente con tenants no registrados
        return reply.code(200).send();
      }

      // Encolar job — el worker hace la segunda llamada a Bsale para obtener datos completos
      const idempotencyKey = `webhook:${store.id}:${payload.topic}:${payload.resourceId}:${payload.send}`;
      await queue.add(
        'bsale-webhook',
        {
          storeId:     store.id,
          tenantId:    store.license_id,
          syncType:    'webhook',
          resourceUrl: payload.resource,
          resourceId:  payload.resourceId,
          topic:       payload.topic,
          action:      payload.action,
        },
        {
          jobId:    idempotencyKey,
          attempts: 5,
          backoff:  { type: 'exponential', delay: 30_000 },
        },
      );

      // Responder 200 inmediatamente — nunca bloquear aqui
      return reply.code(200).send();
    },
  );
}

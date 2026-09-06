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

// #97: whitelist de rutas por topic — resourceUrl viene del payload del webhook
// (no autenticado, ver mas abajo) y se usa directo en bsale.get(resourceUrl) mas
// adelante en el worker. Sin esto, un cpnId adivinado/enumerado permite forzar a
// bot-miki a leer CUALQUIER endpoint de Bsale (ej. /v1/clients.json,
// /v1/documents.json) con el token del tenant victima, no solo stock/variant/product.
// price no usa resourceUrl (cae a bulk) — no necesita whitelist.
const RESOURCE_WHITELIST: Record<string, RegExp> = {
  product: /^\/v[12]\/products\/\d+\.json(\?.*)?$/,
  variant: /^\/v[12]\/variants\/\d+\.json(\?.*)?$/,
  // stock permite ademas la forma coleccion de v2 (sin id en el path, ej.
  // "/v2/stocks.json?variantid=123") — el resolver ya maneja ambas formas.
  stock:   /^\/v[12]\/stocks(\/\d+)?\.json(\?.*)?$/,
};

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

      // Solo procesar topics relevantes para sync CMS.
      // #130 Fase 2: 'document' se dispara cuando alguien emite una boleta/factura
      // en Bsale sobre una nota de venta ya creada — el CMS lo usa para cerrar el
      // pedido solo (checkEmissions()), sin esperar a que un admin clickee
      // "Verificar emisiones" a mano.
      const relevantTopics = ['product', 'variant', 'stock', 'price', 'document'];
      if (!relevantTopics.includes(payload.topic)) {
        // Log para dimensionar topics no procesados
        request.log.info(
          { topic: payload.topic, action: payload.action, cpnId: payload.cpnId, resourceId: payload.resourceId },
          'webhook con topic ignorado'
        );
        return reply.code(200).send(); // Aceptar pero ignorar
      }

      // #97: el resource debe coincidir con la forma esperada para su topic —
      // rechaza cualquier intento de apuntar bsale.get() a un endpoint distinto.
      const whitelist = RESOURCE_WHITELIST[payload.topic];
      if (whitelist && !whitelist.test(payload.resource)) {
        return reply.code(400).send({ error: 'invalid_resource' });
      }

      // Buscar tenant por cpnId de Bsale — join con licenses para obtener el tenant_id string.
      // #127: filtra por status='active' — con licencia vencida/suspendida el
      // auto-sync (webhook) queda apagado a proposito (el modo degradado solo
      // permite el sync MANUAL desde el panel, con limite de frecuencia). Antes
      // encolaba igual y el CMS recien rechazaba en syncSingle() — desperdiciaba
      // rate-limit de Bsale y un ciclo de worker en trabajo que se iba a descartar.
      // Varias tiendas pueden compartir una misma integracion Bsale (un cpnId):
      // 3 e-commerce distintos tirando del mismo ERP, sea bajo una licencia
      // multi-tienda (plan growth/agency, max_stores) o bajo licencias
      // separadas. Antes esto era executeTakeFirst() SIN order by: Bsale
      // mandaba un webhook, se encolaba para UNA sola tienda elegida
      // arbitrariamente por Postgres, y las demas nunca se enteraban del
      // cambio en tiempo real (solo lo veian en el siguiente ciclo de polling).
      const stores = await db
        .selectFrom('tenant_stores as s')
        .innerJoin('licenses as l', 'l.id', 's.license_id')
        .select(['s.id', 's.license_id', 'l.tenant_id'])
        .where('s.bsale_integration_id', '=', payload.cpnId)
        .where('l.status', '=', 'active')
        .execute();

      if (stores.length === 0) {
        // 200 para que Bsale no reintente con tenants no registrados
        return reply.code(200).send();
      }

      if (stores.length > 1) {
        // Sin esto, "una de las tiendas no se actualizo" es indepurable:
        // no queda rastro de a cuantas se abanico el evento.
        request.log.info(
          { cpnId: payload.cpnId, topic: payload.topic, resourceId: payload.resourceId, stores: stores.length },
          'webhook abanicado a varias tiendas'
        );
      }

      // Encolar un job POR TIENDA — el worker hace la segunda llamada a Bsale
      // para obtener datos completos. El jobId ya incluye store.id, asi que la
      // idempotencia por reintento de Bsale se mantiene por tienda: el mismo
      // webhook reintentado deduplica, pero no colisiona entre tiendas.
      for (const store of stores) {
        const idempotencyKey = `webhook_${store.id}_${payload.topic}_${payload.resourceId}_${payload.send}`;
        await queue.add(
          'bsale-webhook',
          {
            storeId:     store.id,
            tenantId:    store.tenant_id,
            syncType:    'webhook',
            // #130: sync_events.entity_type tiene un CHECK que no incluye 'document'
            // (sí incluye 'orders') — sin esto, el dead-letter de handleJobFailed
            // (que cae a job.data.topic si no hay entityType) violaría el
            // constraint y perdería el registro en silencio.
            entityType:  payload.topic === 'document' ? 'orders' : undefined,
            resourceUrl: payload.resource,
            resourceId:  payload.resourceId,
            topic:       payload.topic,
            action:      payload.action,
            send:        payload.send, // #115: para que el CMS descarte eventos de stock fuera de orden
          },
          {
            jobId:    idempotencyKey,
            attempts: 5,
            backoff:  { type: 'exponential', delay: 30_000 },
            // #115: sin esto, Redis acumula un job completado por cada webhook
            // de Bsale para siempre — con feeds de alto volumen (stock/precio
            // cambiando seguido) crece sin techo. Mismos valores que ya usa
            // el scheduler para los jobs de polling.
            removeOnComplete: { age: 86_400 },
            removeOnFail:     { age: 604_800 },
          },
        );
      }

      // Responder 200 inmediatamente — nunca bloquear aqui
      return reply.code(200).send();
    },
  );
}

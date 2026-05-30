# ADR-003 — Cola de Tareas e Idempotencia

**Estado:** Aceptado — implementado en `src/scheduler/index.ts` y `src/routes/webhooks.ts`  
**Fecha:** 2026-05-22  
**Autores:** Equipo kpcrop-latam  

---

## Contexto

La cola de tareas de `bot-miki` tiene reintentos automaticos ante fallos de Bsale API (timeouts, errores 5xx). El riesgo central es que un reintento ejecute la misma operacion dos veces y cree datos duplicados en el CMS o en Bsale (productos duplicados, ordenes duplicadas, actualizaciones de precio dos veces).

Bsale API no garantiza idempotencia en todos sus endpoints — esto es comun en ERPs LATAM. El sistema debe garantizar idempotencia en su propia capa, independientemente del comportamiento de la API externa.

---

## Decision

Cada job en la cola tiene una **clave de idempotencia** unica. Si un job con la misma clave ya fue completado exitosamente en las ultimas 24 horas, el job se descarta sin ejecutarse. Si el job fallo, puede reintentarse.

### Estructura de la Clave de Idempotencia

**Jobs de polling (scheduler):**
```
polling:{storeId}:{entityType}:{windowId}
```

**Jobs de webhook:**
```
webhook:{storeId}:{topic}:{resourceId}:{send}
```

| Parte | Descripcion | Ejemplo |
|---|---|---|
| `storeId` | UUID de la tienda (no tenant — una licencia puede tener varias tiendas) | `a1b2c3...` |
| `entityType` | Entidad sincronizada | `products` / `prices` / `stock` |
| `topic` | Topic del webhook de Bsale | `product` / `variant` / `stock` / `price` |
| `resourceId` | ID del recurso en Bsale | `952` |
| `send` | Unix timestamp del evento (del payload del webhook) | `1716393600` |
| `windowId` | Hora ISO (ventana de deduplicacion para polling) | `2026-05-22T14` |

**Ejemplo polling:**
```
polling:a1b2c3d4-...-uuid:products:2026-05-22T14
```

**Ejemplo webhook:**
```
webhook:a1b2c3d4-...-uuid:product:952:1716393600
```

> **Por que `storeId` y no `tenantId`:** Un tenant puede tener multiples tiendas. La unidad de sync es la tienda, no el tenant. Usar `tenantId` mezclaría jobs de tiendas distintas bajo la misma clave.

---

## Implementacion con BullMQ

```typescript
// Polling — packages/bot-miki/src/scheduler/index.ts (implementacion real)
const windowId       = now.toISOString().slice(0, 13); // "2026-05-22T14"
const idempotencyKey = `polling:${job.store_id}:${job.entity_type}:${windowId}`;

await queue.add('polling', { storeId, tenantId, syncType: 'polling', entityType }, {
  jobId:    idempotencyKey,           // BullMQ deduplica por jobId
  attempts: 3,
  backoff:  { type: 'exponential', delay: 60_000 },
  removeOnComplete: { age: 86_400 },  // mantener 24h para deduplicacion
  removeOnFail:     { age: 604_800 }, // mantener 7 dias para debugging
});

// Webhook — packages/bot-miki/src/routes/webhooks.ts (implementacion real)
const idempotencyKey = `webhook:${store.id}:${payload.topic}:${payload.resourceId}:${payload.send}`;

await queue.add('bsale-webhook', { storeId, tenantId, syncType: 'webhook', ... }, {
  jobId:    idempotencyKey,
  attempts: 5,
  backoff:  { type: 'exponential', delay: 30_000 },  // 30s → 2m → 8m → 32m → 2h
});
```

BullMQ deduplica jobs por `jobId`. Si se intenta encolar un job con el mismo `jobId` que uno ya en la cola o completado (dentro del TTL `removeOnComplete`), el segundo encolar es silenciosamente ignorado.

---

## Estrategia de Reintentos por Tipo de Error

```typescript
// packages/bot-miki/src/workers/sync-worker.ts (implementacion real)
// Concurrencia: 5 (no 10 — conservador hasta validar rate limits de Bsale)

const worker = new Worker<SyncJobData>('sync', processJob, {
  connection: redis,
  concurrency: 5,
});

async function processJob(job: Job<SyncJobData>): Promise<void> {
  // ...
  try {
    // procesa webhook o polling
  } catch (err) {
    if (err instanceof BsaleApiError && err.isClientError) {
      await job.discard();  // 4xx: no reintentar
      return;
    }
    throw err; // 5xx/timeout: BullMQ reintenta con backoff
  }
}

// Dead letter: se captura en el evento 'failed' cuando attemptsMade >= attempts
worker.on('failed', async (job, error) => {
  if (!job) return;
  const isDeadLetter = job.attemptsMade >= (job.opts.attempts ?? 1);
  if (!isDeadLetter) return;

  await db.insertInto('sync_events').values({
    status: 'dead_letter',
    error_message: error.message,
    // ...
  }).execute();
});
```

| Tipo de error | Codigo HTTP | Accion |
|---|---|---|
| Error del cliente | 400, 401, 403, 404, 422 | Descartar — no reintentar |
| Rate limit Bsale | 429 | Reintentar con delay segun `Retry-After` header |
| Error del servidor Bsale | 500, 502, 503, 504 | Reintentar con backoff exponencial |
| Timeout de red | — | Reintentar con backoff exponencial |
| Licencia expirada | — | Descartar — no reintentar |

---

## Dead Letter Queue

Despues de 5 intentos fallidos, el job va a la Dead Letter Queue (DLQ). La DLQ dispara:

1. Alerta en Slack al canal `#sync-alerts` con `{tenantId, syncType, entityType, lastError}`
2. Registro en `sync_events` con `status: 'dead_letter'`
3. El tenant ve en su dashboard que el ultimo sync automatico fallo

La DLQ no se reintenta automaticamente. Un operador debe revisar y decidir si reencolar manualmente.

---

## Consecuencias

**Positivas:**
- Reintentos sin miedo — el sistema es seguro ante duplicados incluso si Bsale no lo garantiza
- Visibilidad total del estado de cada job en Bull Board y en el dashboard del tenant
- El comportamiento ante cada tipo de error es predecible y documentado

**Negativas:**
- El `windowId` por hora implica que si un sync falla en el minuto 59 de una hora y se reintenta en el minuto 01 de la siguiente, es un job diferente (nueva clave). Esto es aceptable — el reintento en la nueva ventana es correcto semanticamente.
- Los jobs manuales necesitan una clave diferente (incluir timestamp de segundo en lugar de hora) para permitir multiples syncs manuales en la misma hora.

```typescript
// Para sync manual, usar timestamp de minuto como windowId
const windowId = new Date().toISOString().slice(0, 16); // "2026-05-22T14:35"
```

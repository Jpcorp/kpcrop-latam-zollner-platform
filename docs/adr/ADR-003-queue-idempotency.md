# ADR-003 — Cola de Tareas e Idempotencia

**Estado:** Propuesto  
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

```
{tenantId}:{syncType}:{entityType}:{windowId}
```

| Parte | Descripcion | Ejemplo |
|---|---|---|
| `tenantId` | Identificador del tenant | `acme-store` |
| `syncType` | Tipo de sincronizacion | `auto` / `manual` |
| `entityType` | Entidad sincronizada | `products` / `prices` / `stock` |
| `windowId` | Ventana temporal (previene duplicados en el mismo periodo, permite reintentos entre periodos) | `2026-05-22T14:00` (hora) |

**Ejemplo:**
```
acme-store:auto:products:2026-05-22T14:00
```

Esta clave garantiza que el sync automatico de productos de las 14:00 del tenant `acme-store` se ejecuta como maximo una vez exitosamente en esa hora. Si falla, se puede reintentar dentro de la misma ventana. A las 15:00 se genera una nueva clave.

---

## Implementacion con BullMQ

```typescript
// packages/bot-miki/src/jobs/sync-job.ts

import { Queue, Worker } from 'bullmq';

const syncQueue = new Queue('sync', { connection: redis });

async function enqueueSyncJob(params: SyncJobParams) {
  const windowId = new Date().toISOString().slice(0, 13); // "2026-05-22T14"
  const idempotencyKey = `${params.tenantId}:${params.syncType}:${params.entityType}:${windowId}`;

  await syncQueue.add(
    'sync',
    { ...params, idempotencyKey },
    {
      jobId: idempotencyKey,  // BullMQ deduplica por jobId
      attempts: 5,
      backoff: {
        type: 'exponential',
        delay: 30_000,        // 30s inicial → 30s, 2m, 8m, 32m
      },
      removeOnComplete: { age: 86_400 },  // mantener 24h para deduplicacion
      removeOnFail: { age: 604_800 },     // mantener 7 dias para debugging
    }
  );
}
```

BullMQ deduplica jobs por `jobId`. Si se intenta encolar un job con el mismo `jobId` que uno ya en la cola o completado (dentro del TTL `removeOnComplete`), el segundo encolar es silenciosamente ignorado.

---

## Estrategia de Reintentos por Tipo de Error

```typescript
// packages/bot-miki/src/workers/sync-worker.ts

const worker = new Worker('sync', async (job) => {
  try {
    await executeSyncJob(job.data);
  } catch (error) {
    if (isClientError(error)) {
      // Error 4xx de Bsale: datos incorrectos, no temporal.
      // No reintentar — marcar como fallido definitivamente.
      await job.discard();
      await logSyncEvent({ ...job.data, status: 'failed', reason: error.message });
      return;
    }

    if (isLicenseError(error)) {
      // Licencia expirada: no reintentar hasta que se renueve.
      await job.discard();
      await logSyncEvent({ ...job.data, status: 'skipped', reason: 'license_expired' });
      return;
    }

    // Error 5xx o timeout: relanzar para que BullMQ reintente con backoff.
    throw error;
  }
}, { connection: redis, concurrency: 10 });
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

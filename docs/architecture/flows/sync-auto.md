# Flujo: Sync Automatico

bot-miki ejecuta syncs programadas por tenant segun la configuracion de cada uno (ej: cada hora, cada noche, en tiempo real via webhook de Bsale). El comercio no necesita estar presente.

```mermaid
sequenceDiagram
    autonumber
    participant SCH as Scheduler (bot-miki)
    participant Q as Cola BullMQ
    participant W as Worker (bot-miki)
    participant LIC as Motor Licencias
    participant BS as Bsale API
    participant P as Plugin CMS (Webhook)
    participant LOG as Event Log

    SCH->>Q: Encolar job {storeId, tenantId, syncType, entityType}
    Note over Q: jobId = polling:{storeId}:{entityType}:{hora ISO}

    Q->>W: Despachar job (concurrencia: 5)
    Note over W: La licencia se verifica consultando licenses JOIN tenant_stores en DB

    alt Licencia no activa
        W->>LOG: Registrar {status: "skipped"}
        W-->>Q: Job descartado (no reintento)
    end

    W->>BS: GET /v1/products.json?expand=[variants]&limit=50&offset=0 (paginado hasta el final)
    Note over BS: Bsale NO tiene updated_since — se descarga el catalogo completo
    BS-->>W: {items: [...], count: N}

    alt Bsale API no disponible (5xx / timeout)
        W-->>Q: Job fallido → reintento con backoff exponencial
        Note over Q: Backoff: 60s → 4m → 16m (max 3 reintentos para polling)
        Q->>W: Reintento N
    end

    W->>W: Por cada variante: computar hash({code, cost, quantity, state})
    W->>W: Comparar con bsale_variant_snapshots en DB
    Note over W: Solo variantes con hash diferente generan trabajo

    alt Variante con cambio detectado
        W->>P: POST webhook.php {topic:"variant", bsaleData} (#79, mismo payload quirurgico que un webhook real)
        alt Dispatch OK
            W->>W: Upsert snapshot en bsale_variant_snapshots
        else Dispatch falla
            Note over W: No se actualiza el snapshot — el proximo ciclo la vuelve a detectar como cambiada
        end
    end

    W->>LOG: Actualizar last_sync_at y last_sync_status en tenant_stores
```

---

## Estrategia de Reintentos

**Jobs de polling (scheduler):**

| Intento | Delay | Condicion |
|---|---|---|
| 1 (original) | 0s | Siempre |
| 2 | 60s | Error 5xx o timeout de Bsale |
| 3 | 4 min | Error 5xx o timeout de Bsale |
| Dead Letter | — | Despues de 3 intentos fallidos → registro en sync_events |

**Jobs de webhook:**

| Intento | Delay | Condicion |
|---|---|---|
| 1 (original) | 0s | Siempre |
| 2-5 | 30s → 2m → 8m → 32m | Error 5xx o timeout de Bsale |
| Dead Letter | — | Despues de 5 intentos fallidos → registro en sync_events |

**No se reintenta si:**
- Bsale devuelve 4xx (error del cliente — datos incorrectos, no temporal) → `job.discard()`
- El store no tiene `bsale_access_token` configurado → `job.discard()`
- El job tiene el mismo `jobId` que uno ya completado dentro del `removeOnComplete.age` (24h)

---

## Configuracion por Tenant

Cada tenant puede configurar en el dashboard:

```json
{
  "tenantId": "acme-store",
  "schedule": {
    "products": "0 * * * *",
    "prices":   "*/30 * * * *",
    "stock":    "*/15 * * * *",
    "clients":  "0 2 * * *",
    "orders":   "*/5 * * * *"
  },
  "syncEntities": ["products", "prices", "stock"],
  "bsaleIntegrationId": 42
}
```

Los schedules son expresiones cron ejecutadas por el Scheduler de bot-miki. Cada entidad puede tener frecuencia independiente.

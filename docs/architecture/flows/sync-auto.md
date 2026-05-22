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

    SCH->>Q: Encolar job {tenantId, cmsType, syncType, idempotencyKey}
    Note over Q: idempotencyKey = tenantId:syncType:fecha

    Q->>W: Despachar job (concurrencia controlada)
    W->>LIC: Verificar licencia activa para tenantId
    LIC-->>W: OK / Licencia expirada

    alt Licencia expirada
        W->>LOG: Registrar {tenantId, status: "skipped", reason: "license_expired"}
        W-->>Q: Job completado (no reintento)
    end

    W->>BS: GET /v1/products?updated_since={lastSyncAt}
    BS-->>W: Productos modificados desde ultimo sync

    alt Bsale API no disponible (5xx / timeout)
        W-->>Q: Job fallido → reintento con backoff exponencial
        Note over Q: Backoff: 30s → 2m → 8m → 32m (max 4 reintentos)
        Q->>W: Reintento N
    end

    W->>W: Mapear a Canonical Product Model
    W->>P: POST /sync/receive {products: [...], idempotencyKey}
    P-->>W: 200 OK {updated: N, errors: []}

    alt Plugin CMS no responde
        W->>LOG: Registrar {status: "partial", reason: "cms_unreachable"}
        W-->>Q: Job completado con advertencia (no reintento de Bsale)
    end

    W->>LOG: Registrar {tenantId, status: "success", productsUpdated: N, duration: Xms}
    LOG-->>W: OK
```

---

## Estrategia de Reintentos

| Intento | Delay | Condicion |
|---|---|---|
| 1 (original) | 0s | Siempre |
| 2 | 30s | Error 5xx o timeout de Bsale |
| 3 | 2 min | Error 5xx o timeout de Bsale |
| 4 | 8 min | Error 5xx o timeout de Bsale |
| 5 | 32 min | Error 5xx o timeout de Bsale |
| Dead Letter | — | Despues de 4 reintentos fallidos → alerta Slack |

**No se reintenta si:**
- Bsale devuelve 4xx (error del cliente — datos incorrectos, no temporal)
- La licencia del tenant esta expirada
- El job tiene la misma `idempotencyKey` que un job ya completado exitosamente en las ultimas 24h

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

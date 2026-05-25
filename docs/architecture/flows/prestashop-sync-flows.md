# Flujos de Sincronización — Plugin PrestaShop

Diagrama end-to-end de los tres modos de sincronización entre Bsale ERP y PrestaShop a través del hub bot-miki.

---

## Componentes involucrados

```mermaid
graph LR
    subgraph PS["PrestaShop (PHP)"]
        UI["sync-panel.tpl\nBackoffice UI"]
        CTRL["AdminBsaleSyncController\najaxProcessSyncNow()"]
        SVC["BsaleSyncService\nsync(entityType)"]
        LC["LicenseClient\ngetToken()"]
        BC["BsaleApiClient\ngetAll()"]
        DB_PS[("MySQL\nbsalesync_config\nbsalesync_log\nbsalesync_product_map")]
    end

    subgraph BM["bot-miki (Node.js + Fastify)"]
        LIC["GET /v1/license/token"]
        WH["POST /v1/webhooks/bsale"]
        RPT["POST /v1/sync/report"]
        SCH["Scheduler\ncron evaluator"]
        WRK["BullMQ Worker\nsync-worker.ts"]
        Q[("Redis\nBullMQ Queue")]
        DB_BM[("PostgreSQL\nlicenses\ntenant_stores\nsync_events\nscheduled_jobs")]
    end

    BSALE["Bsale ERP API\napi.bsale.io"]
    CF["Cloudflare Edge\nCache JWT 4 min"]

    UI -->|click botón| CTRL
    CTRL --> SVC
    SVC --> LC
    SVC --> BC
    LC -->|GET /v1/license/token| CF
    CF -->|cache miss| LIC
    LIC --- DB_BM
    BC -->|GET /v1/products.json| BSALE
    SVC --> DB_PS
    CTRL -->|POST /v1/sync/report| RPT
    RPT --- DB_BM

    BSALE -->|webhook event| WH
    WH --> Q
    Q --> WRK
    SCH --> Q
    SCH --- DB_BM
```

---

## Modo 1 — Sincronización Manual

El dueño de tienda inicia el proceso desde el backoffice de PrestaShop.

```mermaid
sequenceDiagram
    actor Admin as Dueño de tienda
    participant UI as sync-panel.tpl
    participant CTRL as AdminBsaleSyncController
    participant LC as LicenseClient
    participant BM as bot-miki<br/>/v1/license/token
    participant CF as Cloudflare Edge
    participant BC as BsaleApiClient
    participant BSALE as Bsale API
    participant SVC as BsaleSyncService
    participant PS as PrestaShop DB<br/>(MySQL)
    participant RPT as bot-miki<br/>/v1/sync/report

    Admin->>UI: Click "Sincronizar Productos"
    UI->>CTRL: AJAX POST ajaxProcessSyncNow(entity=products)

    Note over CTRL: buildSyncService()<br/>Descifra token Bsale (AES-256-CBC)

    CTRL->>LC: getToken()
    LC->>PS: SELECT license_jwt FROM bsalesync_config<br/>(¿hay JWT en caché no expirado?)

    alt JWT válido en caché
        PS-->>LC: JWT (expira en >60s)
        LC-->>CTRL: JWT cacheado ✓
    else Sin caché o expirado
        LC->>CF: GET /v1/license/token<br/>X-API-Key: kp_...
        CF->>BM: Cache miss → forward
        BM->>BM: SELECT licenses WHERE tenant_id + api_key
        alt Licencia activa
            BM-->>CF: 200 + JWT (TTL 5min)<br/>Cache-Control: max-age=240
            CF-->>LC: JWT fresco
            LC->>PS: UPDATE bsalesync_config SET license_jwt, expires
        else Licencia suspendida/expirada
            BM-->>CF: 402 Payment Required
            CF-->>LC: 402
            LC-->>CTRL: LicenseException ❌
            CTRL-->>UI: { success: false, code: LICENSE_ERROR }
        end
    end

    CTRL->>SVC: sync('products')
    SVC->>BC: getAll('/v1/products.json', expand=[variants,images])

    loop Paginación automática (50 items/página)
        BC->>BC: throttle() → espera 100ms entre requests
        BC->>BSALE: GET /v1/products.json?limit=50&offset=N
        BSALE-->>BC: { items: [...], count: 50 }
        alt Rate limit alcanzado
            BSALE-->>BC: 429 Too Many Requests
            BC->>BC: sleep(60s) → reintenta
        end
    end

    BC-->>SVC: Array completo de productos + variantes

    loop Por cada producto → por cada variante
        SVC->>PS: SELECT id_product WHERE reference = SKU
        alt Producto existe
            SVC->>PS: UPDATE product (nombre, precio, activo)
        else Producto nuevo
            SVC->>PS: INSERT INTO product
        end
        SVC->>PS: StockAvailable::setQuantity(id, 0, cantidad)
        SVC->>PS: UPSERT bsalesync_product_map<br/>(bsale_variant_id ↔ id_product)
    end

    SVC-->>CTRL: SyncResult { updated: N, failed: M, durationMs: X }

    CTRL->>PS: INSERT bsalesync_log (tipo=manual, resultado, duración)
    CTRL->>RPT: POST /v1/sync/report<br/>{ tenantId, syncType: manual, status, records... }
    RPT->>RPT: INSERT sync_events (historial centralizado)

    CTRL-->>UI: { success: true, updated: N, failed: M, duration: Xms }
    UI-->>Admin: "42 productos actualizados, 0 errores"
```

---

## Modo 2 — Sincronización Automática (Scheduler)

bot-miki evalúa los jobs programados y lanza syncs sin intervención del usuario.

```mermaid
sequenceDiagram
    participant SCH as Scheduler<br/>bot-miki (tick 60s)
    participant DB_BM as PostgreSQL<br/>bot-miki
    participant Q as BullMQ Queue<br/>(Redis)
    participant WRK as sync-worker.ts
    participant BSALE as Bsale API
    participant PS as PrestaShop<br/>BsaleSyncService
    participant RPT as bot-miki<br/>/v1/sync/report

    loop Cada 60 segundos
        SCH->>DB_BM: SELECT scheduled_jobs<br/>WHERE active=true AND next_run_at <= NOW()<br/>JOIN tenant_stores JOIN licenses WHERE status=active
        DB_BM-->>SCH: Lista de jobs vencidos

        loop Por cada job vencido
            SCH->>Q: queue.add('scheduled-sync', {<br/>  storeId, tenantId, syncType:'auto',<br/>  entityType<br/>}, { jobId: idempotencyKey })
            Note over SCH,Q: jobId previene duplicados<br/>si el tick se solapa
        end
    end

    Q->>WRK: Dequeue job (concurrency=5)
    WRK->>BSALE: GET recurso según entityType
    BSALE-->>WRK: Datos actualizados

    Note over WRK: Aplica cambios en PrestaShop<br/>(mismo flujo que sync manual)
    WRK->>PS: BsaleSyncService::sync(entityType)
    PS-->>WRK: SyncResult

    WRK->>RPT: POST /v1/sync/report<br/>{ syncType: auto, status, records... }
    RPT->>DB_BM: INSERT sync_events

    WRK->>DB_BM: UPDATE scheduled_jobs<br/>SET last_run_at=NOW(), next_run_at=calcNextRun()
```

---

## Modo 3 — Webhook en Tiempo Real

Bsale notifica a bot-miki cuando cambia un producto, precio o stock.

```mermaid
sequenceDiagram
    participant BSALE as Bsale ERP
    participant WH as bot-miki<br/>POST /v1/webhooks/bsale
    participant DB_BM as PostgreSQL<br/>bot-miki
    participant Q as BullMQ Queue<br/>(Redis)
    participant WRK as sync-worker.ts
    participant BSALE2 as Bsale API<br/>(datos completos)
    participant PS as PrestaShop<br/>BsaleSyncService
    participant RPT as bot-miki<br/>/v1/sync/report

    BSALE->>WH: POST { cpnId, topic, resourceId,<br/>action, resource, send }

    Note over WH: Validación anti-spoofing:<br/>resource debe empezar con /v

    alt Topic no relevante (ej: document)
        WH-->>BSALE: 200 OK (aceptar, ignorar)
    else Topic relevante (product/variant/stock/price)
        WH->>DB_BM: SELECT tenant_stores<br/>WHERE bsale_integration_id = cpnId
        alt Tenant no registrado
            WH-->>BSALE: 200 OK (evita reintentos)
        else Tenant encontrado
            Note over WH: jobId = webhook:{storeId}:{topic}:{resourceId}:{send}<br/>Idempotency key: Bsale puede reintentar el mismo evento
            WH->>Q: queue.add('bsale-webhook', {<br/>  storeId, tenantId, syncType:'webhook',<br/>  resourceUrl, resourceId, topic, action<br/>}, { jobId, attempts:5, backoff:exponential })
            WH-->>BSALE: 200 OK inmediato<br/>(< 5s, nunca bloquear aquí)
        end
    end

    Q->>WRK: Dequeue job

    Note over WRK: Solo descarga el recurso que cambió,<br/>NO todo el catálogo

    WRK->>BSALE2: GET {resourceUrl} (producto/stock/precio específico)
    BSALE2-->>WRK: Datos del recurso

    WRK->>PS: BsaleSyncService::sync() solo para ese recurso
    PS-->>WRK: SyncResult

    alt Job falla (Bsale caído, PS caído)
        WRK->>Q: retry (hasta 5 veces, backoff exponencial 30s base)
        Note over Q: Si falla 5 veces → DLQ<br/>INSERT sync_events status=failed
    end

    WRK->>RPT: POST /v1/sync/report<br/>{ syncType: webhook, status, records... }
    RPT->>DB_BM: INSERT sync_events
```

---

## Comparación de los tres modos

```mermaid
graph TD
    subgraph Triggers["Disparadores"]
        A["👤 Manual\nClick en backoffice"]
        B["⏰ Automático\nCron de bot-miki"]
        C["⚡ Webhook\nBsale en tiempo real"]
    end

    subgraph Scope["Alcance de datos"]
        D["Todo el catálogo\n(productos + stock + precios)"]
        E["Todo el catálogo\n(según job programado)"]
        F["Solo el recurso\nque cambió"]
    end

    subgraph Speed["Velocidad"]
        G["Lento\n(minutos para catálogos grandes)"]
        H["Lento\n(programado, no urgente)"]
        I["Rápido\n(segundos)"]
    end

    subgraph UseCase["Caso de uso ideal"]
        J["Primera instalación\nó corrección masiva"]
        K["Respaldo nocturno\ngarantía de consistencia"]
        L["Operación diaria\ncambios en tiempo real"]
    end

    A --> D --> G --> J
    B --> E --> H --> K
    C --> F --> I --> L
```

---

## Flujo de validación de licencia (detalle)

```mermaid
flowchart TD
    A["Plugin necesita operar\n(sync manual, auto o webhook)"] --> B

    B{"¿JWT en caché\nbsalesync_config?\n(expira en >60s)"}
    B -->|Sí| C["Usa JWT cacheado\n✓ Sin red"]
    B -->|No| D["GET /v1/license/token\nX-API-Key: kp_..."]

    D --> E{"Cloudflare Edge\n¿Cache hit?"}
    E -->|Hit (4 min TTL)| F["Responde desde edge\n✓ Sin tocar bot-miki"]
    E -->|Miss| G["bot-miki consulta\nPostgreSQL licenses"]

    G --> H{"¿Existe tenant\n+ api_key?"}
    H -->|No| I["404 → LicenseException\n❌ Plugin bloqueado"]
    H -->|Sí| J{"¿status = active?"}

    J -->|suspended/cancelled| K["402 → LicenseException\n❌ Renueva en kpcrop.com/billing"]
    J -->|active| L["Genera JWT HS256\nTTL 5 min\nCache-Control: max-age=240"]

    L --> M["Plugin guarda JWT\nen bsalesync_config"]
    M --> C
    F --> M
    C --> N["Sync continúa ✓"]
```

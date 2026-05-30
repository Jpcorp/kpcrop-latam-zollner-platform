# Esquema de Base de Datos

Dos bases de datos independientes: **PostgreSQL** (bot-miki) y **MySQL** (plugin PrestaShop en el servidor del cliente).

---

## PostgreSQL — bot-miki

```mermaid
erDiagram
    LICENSES {
        uuid id PK
        varchar tenant_id UK "ID unico del tenant"
        varchar subscription_id "Stripe subscription ID"
        varchar plan "starter | growth | agency"
        varchar status "active | suspended | cancelled"
        jsonb features "['sync_auto','dropshipping',...]"
        int max_stores "Tiendas permitidas por plan"
        varchar api_key UK "kp_... clave de autenticacion del plugin"
        timestamptz created_at
        timestamptz expires_at "null = sin vencimiento fijo"
        timestamptz updated_at
    }

    TENANT_STORES {
        uuid id PK
        uuid license_id FK
        varchar store_name "Tienda Principal"
        varchar cms_type "wordpress|prestashop|shopify|..."
        varchar cms_url "https://mitienda.cl"
        varchar cms_webhook_secret "para validar callbacks del plugin"
        int bsale_integration_id "ID de integracion en Bsale"
        varchar bsale_access_token "token cifrado de Bsale del cliente"
        int bsale_price_list_id "Lista de precios a sincronizar"
        int bsale_office_id "Sucursal para stock (null = todas)"
        timestamptz last_sync_at
        varchar last_sync_status "success|partial|failed"
        timestamptz created_at
    }

    SYNC_EVENTS {
        uuid id PK
        varchar tenant_id FK
        uuid store_id FK
        varchar sync_type "manual|auto|webhook|dropshipping"
        varchar entity_type "products|prices|stock|clients|orders"
        varchar status "success|partial|failed|skipped|dead_letter"
        int records_updated
        int records_failed
        int duration_ms
        text error_message
        varchar idempotency_key UK "tenantId:type:entity:windowId"
        timestamptz created_at
    }

    BSALE_VARIANT_SNAPSHOTS {
        varchar tenant_id PK
        int variant_id PK
        text content_hash "base64(JSON({code, cost, quantity, state}))"
        jsonb last_known_data "ultimo estado conocido completo"
        timestamptz last_seen_at
    }

    WEBHOOK_REGISTRATIONS {
        uuid id PK
        uuid store_id FK
        varchar bsale_cpn_id "cpnId de la empresa en Bsale"
        varchar[] topics "topics activos: product,stock,price"
        varchar status "pending|active|failed"
        text notes "referencia al email enviado a Bsale"
        timestamptz requested_at
        timestamptz activated_at
    }

    SCHEDULED_JOBS {
        uuid id PK
        uuid store_id FK
        varchar entity_type "products|prices|stock|clients"
        varchar cron_expression "0 * * * *"
        boolean active
        timestamptz last_run_at
        varchar last_run_status
        timestamptz next_run_at
    }

    LICENSES ||--o{ TENANT_STORES : "tiene"
    TENANT_STORES ||--o{ SYNC_EVENTS : "genera"
    TENANT_STORES ||--o{ WEBHOOK_REGISTRATIONS : "tiene"
    TENANT_STORES ||--o{ SCHEDULED_JOBS : "tiene"
    TENANT_STORES ||--o{ BSALE_VARIANT_SNAPSHOTS : "mantiene snapshot"
```

---

## MySQL — Plugin PrestaShop (en el servidor del cliente)

```mermaid
erDiagram
    BSALESYNC_CONFIG {
        int id PK
        int id_shop "ID de la tienda PrestaShop (multishop)"
        text bsale_api_token "token de Bsale (cifrado con AES)"
        int bsale_integration_id
        int bsale_price_list_id "Lista de precios seleccionada"
        int bsale_office_id "Sucursal de stock (0 = todas)"
        varchar daemon_api_url "https://api.kpcrop.com/v1"
        varchar daemon_api_key "kp_... API key de la licencia"
        text license_jwt "JWT cacheado"
        datetime license_jwt_expires
        boolean sync_products
        boolean sync_prices
        boolean sync_stock
        boolean sync_clients
        boolean sync_images
        varchar on_inactive_product "deactivate|delete|ignore"
        datetime last_sync_at
        varchar last_sync_status
        int last_sync_count
    }

    BSALESYNC_PRODUCT_MAP {
        int id PK
        int id_shop
        int id_product "ID de producto en PrestaShop"
        int id_product_attribute "ID de combinacion (variante) en PS"
        int bsale_variant_id "ID de variante en Bsale"
        varchar bsale_code "SKU — clave de idempotencia"
        varchar bsale_barcode
        datetime last_synced_at
    }

    BSALESYNC_IMAGES {
        int id PK
        int id_product "ID de producto en PrestaShop"
        int id_image "ID de imagen en PrestaShop"
        varchar source_url "URL original en CDN de Bsale"
        datetime imported_at
    }

    BSALESYNC_LOG {
        int id PK
        int id_shop
        varchar sync_type "manual|auto|webhook"
        varchar entity_type "products|prices|stock|clients"
        varchar status "success|partial|failed"
        int records_ok
        int records_failed
        int duration_ms
        text error_details "JSON con errores por SKU"
        datetime created_at
    }

    BSALESYNC_CONFIG ||--o{ BSALESYNC_PRODUCT_MAP : "mapea"
    BSALESYNC_CONFIG ||--o{ BSALESYNC_LOG : "registra"
    BSALESYNC_PRODUCT_MAP ||--o{ BSALESYNC_IMAGES : "tiene"
```

---

## SQL de Creacion — PostgreSQL (bot-miki)

```sql
-- migrations/001_initial_schema.sql

CREATE EXTENSION IF NOT EXISTS "pgcrypto";

CREATE TABLE licenses (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id       VARCHAR(100) UNIQUE NOT NULL,
    subscription_id VARCHAR(100) NOT NULL,
    plan            VARCHAR(20) NOT NULL CHECK (plan IN ('starter','growth','agency')),
    status          VARCHAR(20) NOT NULL DEFAULT 'active'
                        CHECK (status IN ('active','suspended','cancelled')),
    features        JSONB NOT NULL DEFAULT '[]',
    max_stores      INTEGER NOT NULL DEFAULT 1,
    api_key         VARCHAR(64) UNIQUE NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at      TIMESTAMPTZ,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE tenant_stores (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    license_id              UUID NOT NULL REFERENCES licenses(id) ON DELETE CASCADE,
    store_name              VARCHAR(200) NOT NULL,
    cms_type                VARCHAR(30) NOT NULL
                                CHECK (cms_type IN ('wordpress','prestashop','shopify',
                                                    'woocommerce','magento','jumpseller')),
    cms_url                 VARCHAR(500) NOT NULL,
    cms_webhook_secret      VARCHAR(64),
    bsale_integration_id    INTEGER,
    bsale_access_token      TEXT,
    bsale_price_list_id     INTEGER,
    bsale_office_id         INTEGER,
    last_sync_at            TIMESTAMPTZ,
    last_sync_status        VARCHAR(20),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE sync_events (
    id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id         VARCHAR(100) NOT NULL,
    store_id          UUID REFERENCES tenant_stores(id),
    sync_type         VARCHAR(20) NOT NULL
                          CHECK (sync_type IN ('manual','auto','webhook','dropshipping')),
    entity_type       VARCHAR(20) NOT NULL
                          CHECK (entity_type IN ('products','prices','stock',
                                                 'clients','orders','guides')),
    status            VARCHAR(20) NOT NULL
                          CHECK (status IN ('success','partial','failed',
                                           'skipped','dead_letter')),
    records_updated   INTEGER DEFAULT 0,
    records_failed    INTEGER DEFAULT 0,
    duration_ms       INTEGER,
    error_message     TEXT,
    idempotency_key   VARCHAR(200) UNIQUE,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE bsale_variant_snapshots (
    tenant_id       VARCHAR(100) NOT NULL,
    variant_id      INTEGER NOT NULL,
    content_hash    TEXT NOT NULL,     -- base64(JSON({code, cost, quantity, state}))
    last_known_data JSONB,             -- estado completo de la variante (para debug)
    last_seen_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (tenant_id, variant_id)
);

CREATE TABLE webhook_registrations (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    store_id        UUID NOT NULL REFERENCES tenant_stores(id) ON DELETE CASCADE,
    bsale_cpn_id    VARCHAR(50) NOT NULL,
    topics          VARCHAR(30)[] NOT NULL DEFAULT '{}',
    status          VARCHAR(20) NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','active','failed')),
    notes           TEXT,
    requested_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    activated_at    TIMESTAMPTZ
);

CREATE TABLE scheduled_jobs (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    store_id        UUID NOT NULL REFERENCES tenant_stores(id) ON DELETE CASCADE,
    entity_type     VARCHAR(20) NOT NULL,
    cron_expression VARCHAR(50) NOT NULL DEFAULT '0 * * * *',
    active          BOOLEAN NOT NULL DEFAULT true,
    last_run_at     TIMESTAMPTZ,
    last_run_status VARCHAR(20),
    next_run_at     TIMESTAMPTZ
);

-- Indices para queries frecuentes
CREATE INDEX idx_sync_events_tenant_date ON sync_events (tenant_id, created_at DESC);
CREATE INDEX idx_sync_events_store ON sync_events (store_id, created_at DESC);
CREATE INDEX idx_tenant_stores_license ON tenant_stores (license_id);
CREATE INDEX idx_snapshots_tenant ON bsale_variant_snapshots (tenant_id);
```

---

## Flujo de Datos entre Bases de Datos

```mermaid
flowchart LR
    subgraph PS["Servidor del Cliente (MySQL)"]
        PC["bsalesync_config\n(token Bsale, API key licencia)"]
        PM["bsalesync_product_map\n(PS id ↔ Bsale variant_id)"]
        PL["bsalesync_log\n(historial local)"]
    end

    subgraph BM["Demonio bot-miki (PostgreSQL)"]
        L["licenses\n(plan, features, estado)"]
        TS["tenant_stores\n(config por tienda)"]
        SE["sync_events\n(event log global)"]
        VS["bsale_variant_snapshots\n(para polling diff)"]
    end

    PC -->|"GET /v1/license/token\n(X-API-Key: kp_...)"| L
    L -->|"JWT firmado (TTL 5min)"| PC
    PL -->|"POST /v1/sync/report"| SE
    TS -->|"Webhook POST"| PC
    VS -->|"Polling fallback"| PM
```

---

## Notas de Seguridad

**bot-miki (PostgreSQL):**
- `bsale_access_token` debe cifrarse en reposo con AES-256 usando una clave separada del JWT_SECRET
- `api_key` se almacena como hash (bcrypt) — nunca en texto plano
- Las columnas de tokens no deben aparecer en logs de queries

**Plugin PrestaShop (MySQL):**
- `bsale_api_token` se cifra con `openssl_encrypt()` usando la `_COOKIE_KEY_` de PrestaShop como clave
- El `daemon_api_key` se almacena tal cual (es la clave del tenant, no un secreto critico)
- La tabla `bsalesync_config` no debe ser accesible desde el front-end de la tienda

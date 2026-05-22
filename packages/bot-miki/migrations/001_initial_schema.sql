-- Migracion 001: Schema inicial de bot-miki
-- Ejecutar: psql $DATABASE_URL -f migrations/001_initial_schema.sql

CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ─── Licencias ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS licenses (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id       VARCHAR(100) UNIQUE NOT NULL,
    subscription_id VARCHAR(100) NOT NULL,
    plan            VARCHAR(20) NOT NULL CHECK (plan IN ('starter','growth','agency')),
    status          VARCHAR(20) NOT NULL DEFAULT 'active'
                        CHECK (status IN ('active','suspended','cancelled')),
    features        JSONB NOT NULL DEFAULT '["sync_manual"]',
    max_stores      INTEGER NOT NULL DEFAULT 1,
    api_key         VARCHAR(64) UNIQUE NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at      TIMESTAMPTZ,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ─── Tiendas por tenant ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tenant_stores (
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

-- ─── Event log de sincronizaciones ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sync_events (
    id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id         VARCHAR(100) NOT NULL,
    store_id          UUID REFERENCES tenant_stores(id) ON DELETE SET NULL,
    sync_type         VARCHAR(20) NOT NULL
                          CHECK (sync_type IN ('manual','auto','webhook','dropshipping')),
    entity_type       VARCHAR(20) NOT NULL
                          CHECK (entity_type IN ('products','prices','stock',
                                                 'clients','orders','guides')),
    status            VARCHAR(20) NOT NULL
                          CHECK (status IN ('success','partial','failed',
                                           'skipped','dead_letter')),
    records_updated   INTEGER NOT NULL DEFAULT 0,
    records_failed    INTEGER NOT NULL DEFAULT 0,
    duration_ms       INTEGER,
    error_message     TEXT,
    idempotency_key   VARCHAR(200) UNIQUE,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ─── Snapshot de variantes Bsale (para deteccion de cambios en polling) ──────
CREATE TABLE IF NOT EXISTS bsale_variant_snapshots (
    tenant_id       VARCHAR(100) NOT NULL,
    variant_id      INTEGER NOT NULL,
    content_hash    VARCHAR(64) NOT NULL,
    last_known_data JSONB,
    last_seen_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (tenant_id, variant_id)
);

-- ─── Registro de webhooks (uno por tienda) ────────────────────────────────────
CREATE TABLE IF NOT EXISTS webhook_registrations (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    store_id        UUID NOT NULL REFERENCES tenant_stores(id) ON DELETE CASCADE,
    bsale_cpn_id    VARCHAR(50) NOT NULL,
    topics          TEXT[] NOT NULL DEFAULT '{}',
    status          VARCHAR(20) NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','active','failed')),
    notes           TEXT,
    requested_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    activated_at    TIMESTAMPTZ
);

-- ─── Jobs programados por tienda ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS scheduled_jobs (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    store_id        UUID NOT NULL REFERENCES tenant_stores(id) ON DELETE CASCADE,
    entity_type     VARCHAR(20) NOT NULL,
    cron_expression VARCHAR(50) NOT NULL DEFAULT '0 * * * *',
    active          BOOLEAN NOT NULL DEFAULT true,
    last_run_at     TIMESTAMPTZ,
    last_run_status VARCHAR(20),
    next_run_at     TIMESTAMPTZ
);

-- ─── Indices ─────────────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_sync_events_tenant_date  ON sync_events (tenant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sync_events_store        ON sync_events (store_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_tenant_stores_license    ON tenant_stores (license_id);
CREATE INDEX IF NOT EXISTS idx_snapshots_tenant         ON bsale_variant_snapshots (tenant_id);
CREATE INDEX IF NOT EXISTS idx_scheduled_jobs_next_run  ON scheduled_jobs (next_run_at) WHERE active = true;

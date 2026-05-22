-- Migracion 002: Datos de prueba para desarrollo local
-- NO ejecutar en staging ni produccion
-- Ejecutar: psql $DATABASE_URL -f migrations/002_seed_dev.sql

-- Licencia de desarrollo (plan agency con todos los features)
INSERT INTO licenses (
    tenant_id, subscription_id, plan, status, features, max_stores, api_key
) VALUES (
    'dev-tenant-001',
    'sub_dev_test_001',
    'agency',
    'active',
    '["sync_manual","sync_auto","dropshipping","multi_store"]',
    50,
    'kp_dev_api_key_para_desarrollo_local_no_usar_en_prod'
) ON CONFLICT (tenant_id) DO NOTHING;

-- Tienda de prueba asociada a la licencia de desarrollo
INSERT INTO tenant_stores (
    license_id, store_name, cms_type, cms_url,
    bsale_integration_id, bsale_access_token,
    bsale_price_list_id, bsale_office_id
) VALUES (
    (SELECT id FROM licenses WHERE tenant_id = 'dev-tenant-001'),
    'Tienda Dev Local',
    'prestashop',
    'http://localhost:8080',
    1,                              -- Reemplazar con cpnId real del sandbox Bsale
    'BSALE_SANDBOX_TOKEN_AQUI',    -- Reemplazar con token del sandbox Bsale
    1,                              -- Lista de precios #1
    NULL                            -- NULL = todas las sucursales
) ON CONFLICT DO NOTHING;

-- Job de sync automatico de productos (hourly fallback polling)
INSERT INTO scheduled_jobs (store_id, entity_type, cron_expression, active)
SELECT
    id,
    'products',
    '0 * * * *',  -- Cada hora
    true
FROM tenant_stores
WHERE store_name = 'Tienda Dev Local'
ON CONFLICT DO NOTHING;

INSERT INTO scheduled_jobs (store_id, entity_type, cron_expression, active)
SELECT
    id,
    'stock',
    '*/15 * * * *',  -- Cada 15 minutos
    true
FROM tenant_stores
WHERE store_name = 'Tienda Dev Local'
ON CONFLICT DO NOTHING;

-- Eventos de sync de ejemplo para ver en el historial
INSERT INTO sync_events (tenant_id, store_id, sync_type, entity_type, status, records_updated, duration_ms)
SELECT
    'dev-tenant-001',
    ts.id,
    'manual',
    'products',
    'success',
    142,
    3240
FROM tenant_stores ts
WHERE ts.store_name = 'Tienda Dev Local';

INSERT INTO sync_events (tenant_id, store_id, sync_type, entity_type, status, records_updated, duration_ms)
SELECT
    'dev-tenant-001',
    ts.id,
    'auto',
    'stock',
    'partial',
    89,
    1100
FROM tenant_stores ts
WHERE ts.store_name = 'Tienda Dev Local';

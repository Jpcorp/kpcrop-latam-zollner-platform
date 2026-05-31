-- Seed de desarrollo para el módulo bsalesync
-- Apunta al bot-miki corriendo en el host local (localhost:3000)
-- host.docker.internal resuelve al host desde dentro de Docker Desktop (Windows/Mac)
--
-- API Key: debe coincidir con el seed de bot-miki (002_seed_dev.sql)
-- Tenant:  dev-tenant-001
--
-- ⚠️  CAMBIO DE URL — cuando quieras probar contra Railway en lugar de local:
--     daemon_api_url = 'https://kpcrop-latam-zollner-platform-production.up.railway.app'
--     daemon_api_key = 'kp_aa3bdbd46281251ae2663ffb24f72f285e1d759012b72ab9'  (tenant test-tenant-001)
-- ⚠️  CAMBIO DE URL — cuando el dominio api.kpcrop.com esté activo en Cloudflare:
--     daemon_api_url = 'https://api.kpcrop.com'

-- Configurar bsalesync para tienda por defecto (id_shop = 1)
UPDATE `PREFIX_bsalesync_config`
SET
    daemon_api_url      = 'http://host.docker.internal:3000',
    daemon_api_key      = 'kp_dev_api_key_para_desarrollo_local_no_usar_en_prod',
    bsale_price_list_id = 1,
    bsale_office_id     = NULL,      -- NULL = todas las sucursales
    sync_products       = 1,
    sync_prices         = 1,
    sync_stock          = 1,
    sync_clients        = 0,
    sync_images         = 0,
    on_inactive_product = 'deactivate'
WHERE id_shop = 1;

-- Insertar si el UPDATE no afectó filas (primer run)
INSERT IGNORE INTO `PREFIX_bsalesync_config` (
    id_shop,
    daemon_api_url,
    daemon_api_key,
    bsale_price_list_id,
    bsale_office_id,
    sync_products,
    sync_prices,
    sync_stock,
    sync_clients,
    sync_images,
    on_inactive_product
) VALUES (
    1,
    'http://host.docker.internal:3000',
    'kp_dev_api_key_para_desarrollo_local_no_usar_en_prod',
    1,
    NULL,
    1,
    1,
    1,
    0,
    0,
    'deactivate'
);

-- Insertar registros de log de ejemplo para ver el historial en el backoffice
INSERT IGNORE INTO `PREFIX_bsalesync_log`
    (id_shop, sync_type, entity_type, status, records_ok, records_fail, duration_ms, created_at)
VALUES
    (1, 'manual',  'products', 'success', 142,  0, 3240, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    (1, 'auto',    'stock',    'partial',  89,  3, 1100, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
    (1, 'manual',  'prices',   'success',  55,  0,  890, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
    (1, 'webhook', 'products', 'success',   1,  0,  210, DATE_SUB(NOW(), INTERVAL 5 MINUTE));

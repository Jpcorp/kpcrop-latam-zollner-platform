-- =============================================================================
-- Migración: bsalesync → synkrop
-- =============================================================================
-- Ejecutar en instalaciones existentes que tenían el módulo con el nombre
-- anterior "bsalesync". Las instalaciones nuevas NO necesitan este script
-- (install.sql ya usa los nombres correctos).
--
-- Uso:
--   mysql -u<user> -p<pass> <database> < migrate-from-bsalesync.sql
--
-- Asume prefijo de tablas PS = "ps_". Ajustar si tu instalación usa otro.
-- Es idempotente: se puede ejecutar más de una vez sin efectos secundarios.
-- =============================================================================

SET @db_prefix = 'ps_';

-- ─── 1. ps_module ─────────────────────────────────────────────────────────────
UPDATE ps_module
SET name = 'synkrop'
WHERE name = 'bsalesync';

-- ─── 2. ps_tab ────────────────────────────────────────────────────────────────
UPDATE ps_tab
SET class_name = 'AdminSynkrop',
    module     = 'synkrop'
WHERE class_name = 'AdminBsaleSync'
  AND module     = 'bsalesync';

-- ─── 3. ps_tab_lang ───────────────────────────────────────────────────────────
UPDATE ps_tab_lang
SET name = 'Synkrop'
WHERE id_tab IN (
    SELECT id_tab FROM ps_tab WHERE class_name = 'AdminSynkrop'
)
AND name IN ('Bsale Sync', 'BsaleSync', 'bsalesync');

-- ─── 4. ps_authorization_role ─────────────────────────────────────────────────
UPDATE ps_authorization_role SET slug = 'ROLE_MOD_MODULE_SYNKROP_CREATE' WHERE slug = 'ROLE_MOD_MODULE_BSALESYNC_CREATE';
UPDATE ps_authorization_role SET slug = 'ROLE_MOD_MODULE_SYNKROP_READ'   WHERE slug = 'ROLE_MOD_MODULE_BSALESYNC_READ';
UPDATE ps_authorization_role SET slug = 'ROLE_MOD_MODULE_SYNKROP_UPDATE' WHERE slug = 'ROLE_MOD_MODULE_BSALESYNC_UPDATE';
UPDATE ps_authorization_role SET slug = 'ROLE_MOD_MODULE_SYNKROP_DELETE' WHERE slug = 'ROLE_MOD_MODULE_BSALESYNC_DELETE';
UPDATE ps_authorization_role SET slug = 'ROLE_MOD_TAB_ADMINSYNKROP_CREATE' WHERE slug = 'ROLE_MOD_TAB_ADMINBSALESYNC_CREATE';
UPDATE ps_authorization_role SET slug = 'ROLE_MOD_TAB_ADMINSYNKROP_READ'   WHERE slug = 'ROLE_MOD_TAB_ADMINBSALESYNC_READ';
UPDATE ps_authorization_role SET slug = 'ROLE_MOD_TAB_ADMINSYNKROP_UPDATE' WHERE slug = 'ROLE_MOD_TAB_ADMINBSALESYNC_UPDATE';
UPDATE ps_authorization_role SET slug = 'ROLE_MOD_TAB_ADMINSYNKROP_DELETE' WHERE slug = 'ROLE_MOD_TAB_ADMINBSALESYNC_DELETE';

-- ─── 5. Tablas propias del módulo ─────────────────────────────────────────────
-- Solo renombrar si existen las tablas viejas y NO existen las nuevas.

DROP PROCEDURE IF EXISTS synkrop_rename_tables;

DELIMITER $$
CREATE PROCEDURE synkrop_rename_tables()
BEGIN
    -- synkrop_config
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ps_bsalesync_config')
       AND NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ps_synkrop_config')
    THEN
        RENAME TABLE ps_bsalesync_config TO ps_synkrop_config;
    END IF;

    -- synkrop_product_map
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ps_bsalesync_product_map')
       AND NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ps_synkrop_product_map')
    THEN
        RENAME TABLE ps_bsalesync_product_map TO ps_synkrop_product_map;
    END IF;

    -- synkrop_images
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ps_bsalesync_images')
       AND NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ps_synkrop_images')
    THEN
        RENAME TABLE ps_bsalesync_images TO ps_synkrop_images;
    END IF;

    -- synkrop_log
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ps_bsalesync_log')
       AND NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ps_synkrop_log')
    THEN
        RENAME TABLE ps_bsalesync_log TO ps_synkrop_log;
    END IF;

    -- synkrop_price_group_map
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ps_bsalesync_price_group_map')
       AND NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ps_synkrop_price_group_map')
    THEN
        RENAME TABLE ps_bsalesync_price_group_map TO ps_synkrop_price_group_map;
    END IF;
END$$
DELIMITER ;

CALL synkrop_rename_tables();
DROP PROCEDURE IF EXISTS synkrop_rename_tables;

-- ─── 6. Verificación ──────────────────────────────────────────────────────────
SELECT 'ps_module'             AS tabla, CONVERT(name       USING utf8mb4) AS valor FROM ps_module             WHERE name       = 'synkrop'
UNION ALL
SELECT 'ps_tab'                AS tabla, CONVERT(class_name USING utf8mb4) AS valor FROM ps_tab                WHERE class_name = 'AdminSynkrop'
UNION ALL
SELECT 'ps_authorization_role' AS tabla, CONVERT(slug       USING utf8mb4) AS valor FROM ps_authorization_role WHERE slug LIKE '%SYNKROP%'
UNION ALL
SELECT 'ps_synkrop_*'          AS tabla, CONVERT(table_name USING utf8mb4) AS valor
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name LIKE 'ps_synkrop_%';

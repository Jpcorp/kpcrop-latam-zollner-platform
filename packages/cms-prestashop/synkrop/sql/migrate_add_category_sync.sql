-- =============================================================================
-- Migración (#87): sync de categorías Bsale -> PrestaShop (modo automático)
-- =============================================================================
-- Aplicar en instalaciones existentes (install.sql ya lo trae para
-- instalaciones nuevas). Portable MySQL/MariaDB (information_schema para las
-- columnas nuevas, no "IF NOT EXISTS" de DDL — ver #112).
--
-- Uso: mysql -u<user> -p<pass> <database> < migrate_add_category_sync.sql
-- Ajustar @db_prefix si tu instalación usa un prefijo distinto de "ps_".
-- Idempotente: se puede ejecutar más de una vez sin efectos secundarios.
-- =============================================================================

SET @db_prefix = 'ps_';

-- Tabla nueva: CREATE TABLE IF NOT EXISTS ya es portable, no necesita el
-- patron de stored procedure.
SET @sql = CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @db_prefix, 'synkrop_category_map` (',
    '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,',
    '`id_shop` INT UNSIGNED NOT NULL DEFAULT 1,',
    '`bsale_type_id` INT UNSIGNED NOT NULL,',
    '`bsale_type_name` VARCHAR(128) NOT NULL,',
    '`id_ps_category` INT UNSIGNED NOT NULL,',
    '`active` TINYINT(1) NOT NULL DEFAULT 1,',
    '`created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,',
    'PRIMARY KEY (`id`),',
    'UNIQUE KEY `uk_shop_type` (`id_shop`, `bsale_type_id`)',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Columnas nuevas en synkrop_config: portable via information_schema
DROP PROCEDURE IF EXISTS synkrop_add_category_sync_columns;

DELIMITER $$
CREATE PROCEDURE synkrop_add_category_sync_columns()
BEGIN
    DECLARE tbl VARCHAR(200);
    SET tbl = CONCAT(@db_prefix, 'synkrop_config');

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = 'sync_categories'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN sync_categories TINYINT(1) NOT NULL DEFAULT 0');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = 'category_parent_id'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN category_parent_id INT UNSIGNED DEFAULT NULL');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL synkrop_add_category_sync_columns();
DROP PROCEDURE synkrop_add_category_sync_columns;

-- =============================================================================
-- Migración (#56): white-label básico — nombre/logo/color de la agencia
-- =============================================================================
-- Aplicar en instalaciones existentes (install.sql ya lo trae para
-- instalaciones nuevas). Portable MySQL/MariaDB (information_schema para las
-- columnas nuevas, no "IF NOT EXISTS" de DDL — ver #112).
--
-- Uso: mysql -u<user> -p<pass> <database> < migrate_add_agency_branding.sql
-- Ajustar @db_prefix si tu instalación usa un prefijo distinto de "ps_".
-- Idempotente: se puede ejecutar más de una vez sin efectos secundarios.
-- =============================================================================

SET @db_prefix = 'ps_';

DROP PROCEDURE IF EXISTS synkrop_add_agency_branding_columns;

DELIMITER $$
CREATE PROCEDURE synkrop_add_agency_branding_columns()
BEGIN
    DECLARE tbl VARCHAR(200);
    SET tbl = CONCAT(@db_prefix, 'synkrop_config');

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = 'agency_name'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN agency_name VARCHAR(100) DEFAULT NULL');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = 'agency_logo_url'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN agency_logo_url TEXT');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = 'agency_brand_color'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN agency_brand_color VARCHAR(7) DEFAULT NULL');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL synkrop_add_agency_branding_columns();
DROP PROCEDURE synkrop_add_agency_branding_columns;

-- =============================================================================
-- Migración: modo prueba para verificación de emisiones (v1.2.0)
-- =============================================================================
-- Con test_mode=1, checkEmissions() acepta cualquier documento manual (no
-- tributario) como emisión válida — útil en sandbox o staging, donde no
-- existen boletas/facturas electrónicas con código SII. NUNCA activar en
-- producción: se saltaría la exigencia de documento tributario real. Aplicar
-- en instalaciones existentes (install.sql ya lo trae para instalaciones
-- nuevas). Portable MySQL/MariaDB (information_schema, no "IF NOT EXISTS"
-- de DDL — ver #112).
--
-- Requiere haber corrido migrate_add_shipping_sku.sql antes (agrega la
-- columna después de shipping_sku).
--
-- Uso: mysql -u<user> -p<pass> <database> < migrate_add_test_mode.sql
-- Idempotente: se puede ejecutar más de una vez sin efectos secundarios.
-- =============================================================================

SET @db_prefix = 'ps_';

DROP PROCEDURE IF EXISTS synkrop_add_test_mode;

DELIMITER $$
CREATE PROCEDURE synkrop_add_test_mode()
BEGIN
    DECLARE tbl VARCHAR(200);
    SET tbl = CONCAT(@db_prefix, 'synkrop_config');

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = 'test_mode'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN test_mode TINYINT(1) NOT NULL DEFAULT 0');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL synkrop_add_test_mode();
DROP PROCEDURE synkrop_add_test_mode;

-- =============================================================================
-- Migración (#130): modo automático (con gate humano) para el flujo de ventas
-- =============================================================================
-- Aplicar en instalaciones existentes (install.sql ya lo trae para
-- instalaciones nuevas). Portable MySQL/MariaDB (information_schema para la
-- columna nueva, no "IF NOT EXISTS" de DDL — ver #112).
--
-- Uso: mysql -u<user> -p<pass> <database> < migrate_add_order_auto_mode.sql
-- Ajustar @db_prefix si tu instalación usa un prefijo distinto de "ps_".
-- Idempotente: se puede ejecutar más de una vez sin efectos secundarios.
-- =============================================================================

SET @db_prefix = 'ps_';

DROP PROCEDURE IF EXISTS synkrop_add_order_auto_mode_column;

DELIMITER $$
CREATE PROCEDURE synkrop_add_order_auto_mode_column()
BEGIN
    DECLARE tbl VARCHAR(200);
    SET tbl = CONCAT(@db_prefix, 'synkrop_config');

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = 'order_auto_mode'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN order_auto_mode TINYINT(1) NOT NULL DEFAULT 0');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL synkrop_add_order_auto_mode_column();
DROP PROCEDURE synkrop_add_order_auto_mode_column;

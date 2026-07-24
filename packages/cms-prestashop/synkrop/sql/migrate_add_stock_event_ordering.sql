-- =============================================================================
-- Migración (#115): last_stock_event_send en synkrop_product_map
-- =============================================================================
-- Aplicar en instalaciones existentes (install.sql ya lo trae para
-- instalaciones nuevas). Portable MySQL/MariaDB (information_schema, no
-- "IF NOT EXISTS" de DDL — ver #112).
--
-- Permite descartar eventos de stock que llegan fuera de orden: guarda el
-- timestamp (`send` del webhook de Bsale) del ultimo evento aplicado por
-- variante, para comparar contra eventos que lleguen despues pero sean
-- en realidad mas viejos (concurrency:5 en bot-miki + red variable).
--
-- Uso: mysql -u<user> -p<pass> <database> < migrate_add_stock_event_ordering.sql
-- Ajustar @db_prefix si tu instalación usa un prefijo distinto de "ps_".
-- Idempotente: se puede ejecutar más de una vez sin efectos secundarios.
-- =============================================================================

SET @db_prefix = 'ps_';

DROP PROCEDURE IF EXISTS synkrop_add_stock_event_ordering;

DELIMITER $$
CREATE PROCEDURE synkrop_add_stock_event_ordering()
BEGIN
    DECLARE tbl VARCHAR(200);
    SET tbl = CONCAT(@db_prefix, 'synkrop_product_map');

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = 'last_stock_event_send'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN last_stock_event_send INT UNSIGNED DEFAULT NULL');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL synkrop_add_stock_event_ordering();
DROP PROCEDURE synkrop_add_stock_event_ordering;

-- =============================================================================
-- Migración: SKU de despacho configurable (v1.1.0)
-- =============================================================================
-- Con SKU definido, la linea de envio de la nota de venta reutiliza esa
-- variante de servicio en Bsale en lugar de dejar que Bsale auto-cree una
-- variante nueva por documento. Aplicar en instalaciones existentes
-- (install.sql ya lo trae para instalaciones nuevas). Portable MySQL/MariaDB
-- (information_schema, no "IF NOT EXISTS" de DDL — ver #112).
--
-- Requiere haber corrido migrate_add_order_queue.sql antes (agrega la columna
-- despues de sale_doc_type_id, que esa migracion crea).
--
-- Uso: mysql -u<user> -p<pass> <database> < migrate_add_shipping_sku.sql
-- Idempotente: se puede ejecutar más de una vez sin efectos secundarios.
-- =============================================================================

SET @db_prefix = 'ps_';

DROP PROCEDURE IF EXISTS synkrop_add_shipping_sku;

DELIMITER $$
CREATE PROCEDURE synkrop_add_shipping_sku()
BEGIN
    DECLARE tbl VARCHAR(200);
    SET tbl = CONCAT(@db_prefix, 'synkrop_config');

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = 'shipping_sku'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN shipping_sku VARCHAR(64) NOT NULL DEFAULT ''''');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL synkrop_add_shipping_sku();
DROP PROCEDURE synkrop_add_shipping_sku;

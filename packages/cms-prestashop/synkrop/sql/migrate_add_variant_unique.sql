-- =============================================================================
-- Migración (#103): UNIQUE (bsale_variant_id, id_shop) en synkrop_product_map
-- =============================================================================
-- Aplicar en instalaciones existentes (install.sql ya lo trae para instalaciones
-- nuevas). Portable MySQL/MariaDB (usa information_schema, no "IF NOT EXISTS"
-- de DDL — ver hallazgo #112).
--
-- Sin esta unicidad, si el `code` de una variante cambia en Bsale, saveProductMap()
-- hace UPSERT por bsale_code y deja una fila huerfana con el mismo
-- bsale_variant_id -> findProductByBsaleVariantId() puede resolver al producto
-- equivocado. Antes de agregar la constraint hay que limpiar duplicados
-- existentes (si los hay) o el ALTER TABLE falla.
--
-- Uso: mysql -u<user> -p<pass> <database> < migrate_add_variant_unique.sql
-- Ajustar @db_prefix si tu instalación usa un prefijo distinto de "ps_".
-- Idempotente: se puede ejecutar más de una vez sin efectos secundarios.
-- =============================================================================

SET @db_prefix = 'ps_';

DROP PROCEDURE IF EXISTS synkrop_add_variant_unique;

DELIMITER $$
CREATE PROCEDURE synkrop_add_variant_unique()
BEGIN
    DECLARE tbl VARCHAR(200);
    SET tbl = CONCAT(@db_prefix, 'synkrop_product_map');

    -- 1. Limpiar filas huérfanas: por cada (bsale_variant_id, id_shop) con más de
    --    una fila, conservar solo la de `id` más alto (la más reciente).
    SET @sql = CONCAT(
        'DELETE t1 FROM ', tbl, ' t1 ',
        'INNER JOIN ', tbl, ' t2 ',
        '  ON t1.bsale_variant_id = t2.bsale_variant_id ',
        ' AND t1.id_shop = t2.id_shop ',
        ' AND t1.id < t2.id'
    );
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;

    -- 2. Agregar la UNIQUE KEY si todavía no existe.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = tbl AND index_name = 'uk_variant_shop'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD UNIQUE KEY uk_variant_shop (bsale_variant_id, id_shop)');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    -- 3. El índice viejo de una sola columna queda cubierto por el compuesto
    --    (bsale_variant_id es la columna líder) — lo eliminamos si existe.
    IF EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = tbl AND index_name = 'idx_bsale_variant'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' DROP INDEX idx_bsale_variant');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL synkrop_add_variant_unique();
DROP PROCEDURE synkrop_add_variant_unique;

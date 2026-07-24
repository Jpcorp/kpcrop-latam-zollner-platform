-- =============================================================================
-- Migración (#115): UNIQUE (id_product, source_url) en synkrop_images
-- =============================================================================
-- Aplicar en instalaciones existentes (install.sql ya lo trae para
-- instalaciones nuevas). Portable MySQL/MariaDB (information_schema, no
-- "IF NOT EXISTS" de DDL — ver #112).
--
-- La tabla existe para "evitar reimportar la misma imagen en cada sync" pero
-- no tenia ninguna constraint que lo garantizara. Antes de agregarla hay que
-- limpiar duplicados existentes (si los hay) o el ALTER TABLE falla.
--
-- Uso: mysql -u<user> -p<pass> <database> < migrate_add_images_unique.sql
-- Ajustar @db_prefix si tu instalación usa un prefijo distinto de "ps_".
-- Idempotente: se puede ejecutar más de una vez sin efectos secundarios.
-- =============================================================================

SET @db_prefix = 'ps_';

DROP PROCEDURE IF EXISTS synkrop_add_images_unique;

DELIMITER $$
CREATE PROCEDURE synkrop_add_images_unique()
BEGIN
    DECLARE tbl VARCHAR(200);
    SET tbl = CONCAT(@db_prefix, 'synkrop_images');

    -- 1. Limpiar duplicados: por cada (id_product, source_url) con mas de una
    --    fila, conservar solo la de `id` mas alto (la mas reciente).
    SET @sql = CONCAT(
        'DELETE t1 FROM ', tbl, ' t1 ',
        'INNER JOIN ', tbl, ' t2 ',
        '  ON t1.id_product = t2.id_product ',
        ' AND t1.source_url = t2.source_url ',
        ' AND t1.id < t2.id'
    );
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;

    -- 2. Agregar la UNIQUE KEY si todavia no existe.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = tbl AND index_name = 'uk_product_source'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD UNIQUE KEY uk_product_source (id_product, source_url(191))');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL synkrop_add_images_unique();
DROP PROCEDURE synkrop_add_images_unique;

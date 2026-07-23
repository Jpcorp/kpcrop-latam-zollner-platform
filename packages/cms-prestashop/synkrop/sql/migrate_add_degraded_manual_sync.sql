-- =============================================================================
-- Migración (#127): degraded_manual_sync_at en synkrop_config
-- =============================================================================
-- Aplicar en instalaciones existentes (install.sql ya lo trae para
-- instalaciones nuevas). Portable MySQL/MariaDB (information_schema, no
-- "IF NOT EXISTS" de DDL — ver #112).
--
-- Guarda la última vez que se usó el sync manual en modo degradado (licencia
-- vencida/suspendida) — limita la frecuencia para que no sea sustituto
-- gratuito del auto-sync mientras incentiva renovar.
--
-- Uso: mysql -u<user> -p<pass> <database> < migrate_add_degraded_manual_sync.sql
-- Ajustar @db_prefix si tu instalación usa un prefijo distinto de "ps_".
-- Idempotente: se puede ejecutar más de una vez sin efectos secundarios.
-- =============================================================================

SET @db_prefix = 'ps_';

DROP PROCEDURE IF EXISTS synkrop_add_degraded_manual_sync;

DELIMITER $$
CREATE PROCEDURE synkrop_add_degraded_manual_sync()
BEGIN
    DECLARE tbl VARCHAR(200);
    SET tbl = CONCAT(@db_prefix, 'synkrop_config');

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = 'degraded_manual_sync_at'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN degraded_manual_sync_at DATETIME DEFAULT NULL');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL synkrop_add_degraded_manual_sync();
DROP PROCEDURE synkrop_add_degraded_manual_sync;

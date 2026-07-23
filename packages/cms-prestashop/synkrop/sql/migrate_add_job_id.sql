-- =============================================================================
-- Migración: agregar job_id a synkrop_log (correlación con sync_events de bot-miki)
-- =============================================================================
-- #112: portable MySQL/MariaDB — usa information_schema en vez de
-- "ADD COLUMN IF NOT EXISTS" / "CREATE INDEX IF NOT EXISTS" (sintaxis solo
-- MariaDB 10.5+ / MySQL 8.0.29+, error de sintaxis en versiones anteriores).
--
-- Uso: mysql -u<user> -p<pass> <database> < migrate_add_job_id.sql
-- Ajustar @db_prefix si tu instalación usa un prefijo distinto de "ps_".
-- Idempotente: se puede ejecutar más de una vez sin efectos secundarios.
-- =============================================================================

SET @db_prefix = 'ps_';

DROP PROCEDURE IF EXISTS synkrop_add_job_id;

DELIMITER $$
CREATE PROCEDURE synkrop_add_job_id()
BEGIN
    DECLARE tbl VARCHAR(200);
    SET tbl = CONCAT(@db_prefix, 'synkrop_log');

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = 'job_id'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN job_id VARCHAR(200) DEFAULT NULL AFTER error_details');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = tbl AND index_name = 'idx_job_id'
    ) THEN
        SET @sql = CONCAT('CREATE INDEX idx_job_id ON ', tbl, ' (job_id)');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL synkrop_add_job_id();
DROP PROCEDURE synkrop_add_job_id;

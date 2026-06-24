-- Migración: agregar job_id a synkrop_log para correlacionar con sync_events de bot-miki.
-- Reemplaza PREFIX_ por el prefijo de tu instalación PS (por defecto: ps_) antes de ejecutar.
-- Idempotente — ADD COLUMN IF NOT EXISTS no falla si la columna ya existe (MariaDB 10.5+).

ALTER TABLE `PREFIX_synkrop_log`
    ADD COLUMN IF NOT EXISTS `job_id` VARCHAR(200) DEFAULT NULL AFTER `error_details`;

CREATE INDEX IF NOT EXISTS `idx_job_id` ON `PREFIX_synkrop_log` (`job_id`);

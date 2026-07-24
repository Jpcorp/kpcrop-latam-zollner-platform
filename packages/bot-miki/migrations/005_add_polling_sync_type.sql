-- Migracion 005: agrega 'polling' al CHECK constraint de sync_events.sync_type
--
-- Encontrado en pruebas manuales (23-jul-2026): SyncJobData.syncType usa
-- 'webhook' | 'polling' | 'manual' en el codigo real (sync-worker.ts,
-- scheduler/index.ts), pero el CHECK constraint original de 001_initial_schema.sql
-- solo permitia ('manual','auto','webhook','dropshipping') -- nunca incluyo
-- 'polling'. Cualquier job de polling que terminara en dead-letter (o cualquier
-- otro INSERT a sync_events con sync_type='polling') violaba el constraint,
-- y como el INSERT no estaba en un try/catch en el handler 'failed' del
-- worker, la excepcion no manejada tumbaba TODO el proceso de bot-miki.
--
-- 'auto' y 'dropshipping' se dejan (no se usan hoy pero no está mal
-- reservarlos para features futuras ya contempladas en el modelo de datos).

ALTER TABLE sync_events DROP CONSTRAINT IF EXISTS sync_events_sync_type_check;
ALTER TABLE sync_events ADD CONSTRAINT sync_events_sync_type_check
    CHECK (sync_type IN ('manual','auto','webhook','dropshipping','polling'));

-- 004 — Índices en columnas FK sin índice (#115)
-- Idempotente: CREATE INDEX IF NOT EXISTS permite re-ejecutar sin error.
--
-- scheduled_jobs.store_id y webhook_registrations.store_id referencian
-- tenant_stores(id) ON DELETE CASCADE sin índice — cualquier borrado de
-- tenant_stores hace Seq Scan de ambas tablas para encontrar las filas a
-- cascadear. scheduled_jobs.store_id ademas se usa en el join de
-- runSchedulerTick() en CADA tick del scheduler (cada 60s).

CREATE INDEX IF NOT EXISTS idx_scheduled_jobs_store
    ON scheduled_jobs (store_id);

CREATE INDEX IF NOT EXISTS idx_webhook_registrations_store
    ON webhook_registrations (store_id);

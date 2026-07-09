-- 003 — Índices de rendimiento (auditoría jul-2026)
-- Idempotente: CREATE INDEX IF NOT EXISTS permite re-ejecutar en cada arranque sin error.
-- Sin CONCURRENTLY: tenant_stores es una tabla pequeña (una fila por tienda), el build es
-- instantáneo y CONCURRENTLY no puede correr dentro del flujo de migración del boot.

-- #94: la resolución de tenant en CADA webhook de Bsale
-- (webhooks.ts → WHERE s.bsale_integration_id = cpnId) hacía Seq Scan de tenant_stores.
-- Es el request más frecuente del sistema.
CREATE INDEX IF NOT EXISTS idx_tenant_stores_bsale_integration
    ON tenant_stores (bsale_integration_id)
    WHERE bsale_integration_id IS NOT NULL;

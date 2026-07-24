-- Migracion: modo prueba para verificacion de emisiones (v1.2.0)
-- Aplicar en instalaciones existentes. Con test_mode=1, checkEmissions() acepta
-- cualquier documento manual (no tributario) como emision valida — util en sandbox
-- o staging, donde no existen boletas/facturas electronicas con codigo SII.
-- NUNCA activar en produccion: se saltaria la exigencia de documento tributario real.

ALTER TABLE `PREFIX_synkrop_config`
    ADD COLUMN `test_mode` TINYINT(1) NOT NULL DEFAULT 0 AFTER `shipping_sku`;

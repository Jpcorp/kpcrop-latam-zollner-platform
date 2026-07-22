<?php
/**
 * Upgrade 1.1.0 → 1.2.0: modo prueba para "Verificar emisiones" (sandbox/staging).
 * PrestaShop lo ejecuta solo al detectar el cambio de version del modulo.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_0(Synkrop $module): bool
{
    // Columna nueva en config (idempotente: ignora "Duplicate column")
    try {
        Db::getInstance()->execute(
            'ALTER TABLE `' . _DB_PREFIX_ . 'synkrop_config`
             ADD COLUMN `test_mode` TINYINT(1) NOT NULL DEFAULT 0 AFTER `shipping_sku`'
        );
    } catch (Exception $e) {
        if (stripos($e->getMessage(), 'Duplicate column') === false) {
            return false;
        }
    }

    return true;
}

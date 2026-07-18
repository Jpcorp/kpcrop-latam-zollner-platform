<?php
/**
 * Upgrade 1.0.0 → 1.1.0: formulario boleta/factura en checkout + SKU de despacho.
 * PrestaShop lo ejecuta solo al detectar el cambio de version del modulo.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0(Synkrop $module): bool
{
    // Columna nueva en config (idempotente: ignora "Duplicate column")
    try {
        Db::getInstance()->execute(
            'ALTER TABLE `' . _DB_PREFIX_ . 'synkrop_config`
             ADD COLUMN `shipping_sku` VARCHAR(64) NOT NULL DEFAULT \'\' AFTER `sale_doc_type_id`'
        );
    } catch (Exception $e) {
        if (stripos($e->getMessage(), 'Duplicate column') === false) {
            return false;
        }
    }

    return $module->registerHook('displayPaymentTop')
        && $module->registerHook('actionFrontControllerSetMedia');
}

<?php
/**
 * #130: aviso por email cuando hay pedidos pendientes de autorizar (modo
 * automático del flujo de ventas). Pensado para cron (ej. cada pocas horas —
 * el intervalo lo decide quien programa el cron, este script no throttlea).
 *
 * Uso (dentro del contenedor Docker de PrestaShop):
 *   php modules/synkrop/cli/order-notify.php
 *
 * Opciones:
 *   --shop=1   ID de tienda (default: 1)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse desde la línea de comandos.');
}

define('_PS_ROOT_DIR_', realpath(__DIR__ . '/../../../'));

if (!file_exists(_PS_ROOT_DIR_ . '/config/config.inc.php')) {
    fwrite(STDERR, "[ERROR] No se encontró PrestaShop en: " . _PS_ROOT_DIR_ . "\n");
    exit(1);
}

require_once _PS_ROOT_DIR_ . '/config/config.inc.php';
// NO cargar init.php: instancia un FrontController y llama a init(), que en
// contexto CLI puede terminar el proceso con Tools::redirect() (header + exit).
// Se manifiesta como exit 0 SIN NINGUNA salida — los 3 CLI de este modulo
// estuvieron rotos asi en strainmachine.com, fallando en silencio.
// Verificado 07-sep-2026: config.inc.php basta. Ninguno de estos scripts usa
// Context, Shop, Employee ni Tools, y SynkropService tampoco.
require_once _PS_MODULE_DIR_ . 'synkrop/classes/BsaleApiClient.php';
require_once _PS_MODULE_DIR_ . 'synkrop/classes/TokenCipher.php';
require_once _PS_MODULE_DIR_ . 'synkrop/classes/OrderDocumentService.php';

$opts   = getopt('', ['shop:']);
$idShop = (int)($opts['shop'] ?? 1);

$config = Db::getInstance()->getRow(
    'SELECT * FROM `' . _DB_PREFIX_ . 'synkrop_config` WHERE id_shop = ' . $idShop
);

if (empty($config)) {
    fwrite(STDERR, "[ERROR] No hay configuración de synkrop para id_shop=$idShop.\n");
    exit(1);
}

if (!(int)($config['sync_orders'] ?? 0) || !(int)($config['order_auto_mode'] ?? 0)) {
    echo "[INFO] Flujo de ventas desactivado o modo semi-manual — nada que notificar.\n";
    exit(0);
}

$token   = TokenCipher::decrypt((string)$config['bsale_api_token']);
$service = new OrderDocumentService(new BsaleApiClient($token), $idShop);

$notified = $service->notifyPendingIfAny();

if ($notified > 0) {
    echo "[INFO] Notificado: $notified pedido(s) pendiente(s) de autorizar.\n";
} else {
    echo "[INFO] Sin pedidos pendientes (o sin email de tienda configurado).\n";
}

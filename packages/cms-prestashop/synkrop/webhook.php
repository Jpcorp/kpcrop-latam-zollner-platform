<?php
/**
 * Punto de entrada para webhooks de bot-miki → Synkrop.
 * Responde 200 inmediatamente y procesa el sync en background
 * para no bloquear el timeout del caller (30s en bot-miki).
 */

if (!defined('_PS_VERSION_')) {
    define('_PS_ADMIN_DIR_', dirname(__FILE__, 3) . '/fg0qvkmjnpbs5wl5');
    $_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REQUEST_URI'] = '/';
    require_once dirname(__FILE__, 3) . '/config/config.inc.php';
}

header('Content-Type: application/json');

// ── Autenticación ─────────────────────────────────────────────────────────────

$config = Db::getInstance()->getRow(
    'SELECT daemon_api_key FROM `' . _DB_PREFIX_ . 'synkrop_config` WHERE id_shop = 1'
);

$expectedSecret = $config['daemon_api_key'] ?? '';
$receivedSecret = $_SERVER['HTTP_X_SYNKROP_SECRET'] ?? '';

if (empty($expectedSecret) || !hash_equals($expectedSecret, $receivedSecret)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── Payload ───────────────────────────────────────────────────────────────────

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$entity = $body['entity'] ?? 'stock';

if (!in_array($entity, ['products', 'stock', 'prices'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'entity inválido: ' . $entity]);
    exit;
}

// ── Responder 200 inmediatamente para no bloquear el timeout de bot-miki ──────

http_response_code(200);
echo json_encode(['success' => true, 'status' => 'accepted', 'message' => 'Sync iniciado en background']);

// Vaciar buffer y cerrar la conexión HTTP antes de procesar
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // Fallback: forzar flush y desconectar al cliente
    header('Connection: close');
    header('Content-Length: ' . ob_get_length());
    ob_end_flush();
    flush();
}

// Ahora el cliente ya recibió el 200. Procesamos en background.
ignore_user_abort(true);
set_time_limit(300);
ini_set('memory_limit', '256M');

// ── Ejecutar sync ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/synkrop.php';

$fullConfig = Db::getInstance()->getRow(
    'SELECT * FROM `' . _DB_PREFIX_ . 'synkrop_config` WHERE id_shop = 1'
);

try {
    $decryptedToken = Synkrop::decryptToken($fullConfig['bsale_api_token']);
    $bsale   = new BsaleApiClient($decryptedToken);
    $license = new LicenseClient(
        SYNKROP_DAEMON_URL,
        $fullConfig['daemon_api_key'],
        md5(SYNKROP_DAEMON_URL . $fullConfig['daemon_api_key'])
    );
    $service = new SynkropService($bsale, $license, 1);

    $result = $service->sync($entity);

    Db::getInstance()->insert('synkrop_log', [
        'id_shop'      => 1,
        'sync_type'    => 'webhook',
        'entity_type'  => pSQL($entity),
        'status'       => pSQL($result->status()),
        'records_ok'   => $result->updated,
        'records_fail' => $result->failed,
        'duration_ms'  => $result->durationMs,
        'error_details'=> $result->errors ? pSQL(json_encode($result->errors)) : null,
    ]);
} catch (Exception $e) {
    Db::getInstance()->insert('synkrop_log', [
        'id_shop'      => 1,
        'sync_type'    => 'webhook',
        'entity_type'  => pSQL($entity),
        'status'       => 'failed',
        'records_ok'   => 0,
        'records_fail' => 1,
        'duration_ms'  => 0,
        'error_details'=> pSQL($e->getMessage()),
    ]);
}

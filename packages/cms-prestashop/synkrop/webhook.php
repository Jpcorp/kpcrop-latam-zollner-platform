<?php
/**
 * Punto de entrada para webhooks de bot-miki → Synkrop.
 * bot-miki llama aquí con X-Synkrop-Secret después de recibir un evento de Bsale.
 * Ejecuta la sync del entity correspondiente y devuelve JSON.
 */

if (!defined('_PS_VERSION_')) {
    // Bootstrap mínimo de PrestaShop
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

// El secret guardado en bot-miki (cms_webhook_secret) debe coincidir con daemon_api_key
$expectedSecret = $config['daemon_api_key'] ?? '';
$receivedSecret = $_SERVER['HTTP_X_SYNKROP_SECRET'] ?? '';

if (empty($expectedSecret) || !hash_equals($expectedSecret, $receivedSecret)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── Payload ───────────────────────────────────────────────────────────────────

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$entity = $body['entity'] ?? 'products';

if (!in_array($entity, ['products', 'stock', 'prices'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'entity inválido: ' . $entity]);
    exit;
}

// ── Ejecutar sync ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/synkrop.php';

ini_set('memory_limit', '256M');
set_time_limit(120);

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

    // Guardar en historial
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

    echo json_encode([
        'success' => true,
        'status'  => $result->status(),
        'updated' => $result->updated,
        'failed'  => $result->failed,
        'message' => $result->updated . ' registros actualizados',
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

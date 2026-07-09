<?php
/**
 * Punto de entrada para webhooks de bot-miki → Synkrop.
 * Ejecuta el sync de forma síncrona y responde con el resultado.
 * (fastcgi_finish_request no está disponible en hosting compartido cPanel)
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

// ── Correlation ID de bot-miki (para trazabilidad entre sync_events y synkrop_log) ──

$jobId = $_SERVER['HTTP_X_SYNKROP_JOB_ID'] ?? null;

// ── Payload ───────────────────────────────────────────────────────────────────

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Modo quirúrgico: bot-miki resolvió el recurso concreto de Bsale
$isSurgical = isset($body['bsaleData']) && isset($body['topic']);

if (!$isSurgical) {
    // Modo bulk: compatibilidad con sync manual y fallback price
    $entity = $body['entity'] ?? 'stock';
    if (!in_array($entity, ['products', 'stock', 'prices'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'entity inválido: ' . $entity]);
        exit;
    }
}

set_time_limit(25);
ini_set('memory_limit', '256M');

// ── Ejecutar sync ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/synkrop.php';

$fullConfig = Db::getInstance()->getRow(
    'SELECT * FROM `' . _DB_PREFIX_ . 'synkrop_config` WHERE id_shop = 1'
);

$syncResult  = null;
$syncEntity  = $isSurgical ? ($body['topic'] ?? 'unknown') : ($entity ?? 'unknown');
$syncStatus  = 'failed';
$syncErrMsg  = null;

// Garantiza que siempre quede un registro aunque el proceso sea terminado abruptamente
$logWritten = false;
register_shutdown_function(function () use (&$logWritten, &$syncResult, $syncEntity, $syncStatus, $jobId) {
    if ($logWritten) return;
    $error = error_get_last();
    $msg   = $error ? ($error['message'] . ' in ' . $error['file'] . ':' . $error['line']) : 'Proceso terminado sin completar';
    try {
        Db::getInstance()->insert('synkrop_log', [
            'id_shop'      => 1,
            'sync_type'    => 'webhook',
            'entity_type'  => pSQL($syncEntity),
            'status'       => 'failed',
            'records_ok'   => 0,
            'records_fail' => 1,
            'duration_ms'  => 0,
            'error_details'=> pSQL(json_encode([['code' => 'FATAL', 'message' => $msg]])),
            'job_id'       => $jobId ? pSQL($jobId) : null,
        ]);
    } catch (\Throwable $t) {
        // Si el DB también falla, escribir al error_log del servidor
        error_log('[Synkrop] shutdown fallback DB failed: ' . $t->getMessage() . ' | original: ' . $msg);
    }
});

try {
    $decryptedToken = Synkrop::decryptToken($fullConfig['bsale_api_token']);
    $bsale   = new BsaleApiClient($decryptedToken);
    $license = new LicenseClient(
        SYNKROP_DAEMON_URL,
        $fullConfig['daemon_api_key'],
        md5(SYNKROP_DAEMON_URL . $fullConfig['daemon_api_key'])
    );
    $service = new SynkropService($bsale, $license, 1);

    $syncResult = $isSurgical
        ? $service->syncSingle($body['topic'], $body['bsaleData'])
        : $service->sync($entity);

    $syncStatus = $syncResult->status();

    Db::getInstance()->insert('synkrop_log', [
        'id_shop'      => 1,
        'sync_type'    => 'webhook',
        'entity_type'  => pSQL($syncEntity),
        'status'       => pSQL($syncStatus),
        'records_ok'   => $syncResult->updated,
        'records_fail' => $syncResult->failed,
        'duration_ms'  => $syncResult->durationMs,
        'error_details'=> $syncResult->errors ? pSQL(json_encode($syncResult->errors)) : pSQL('[]'),
        'job_id'       => $jobId ? pSQL($jobId) : null,
    ]);
    $logWritten = true;

    if (!empty($syncResult->errors)) {
        $syncErrMsg = $syncResult->errors[0]['message'] ?? null;
    }
} catch (\Throwable $e) {
    $syncErrMsg = $e->getMessage();
    Db::getInstance()->insert('synkrop_log', [
        'id_shop'      => 1,
        'sync_type'    => 'webhook',
        'entity_type'  => pSQL($syncEntity),
        'status'       => 'failed',
        'records_ok'   => 0,
        'records_fail' => 1,
        'duration_ms'  => 0,
        'error_details'=> pSQL(json_encode([['code' => get_class($e), 'message' => $e->getMessage()]])),
        'job_id'       => $jobId ? pSQL($jobId) : null,
    ]);
    $logWritten = true;
}

// ── Reportar resultado real a bot-miki (cierra el loop en sync_events) ────────

if ($jobId) {
    $topicToEntity = [
        'stock' => 'stock', 'variant' => 'products', 'product' => 'products',
        'price' => 'prices', 'products' => 'products', 'prices' => 'prices',
    ];
    $reportPayload = json_encode([
        'tenantId'       => md5(SYNKROP_DAEMON_URL . $fullConfig['daemon_api_key']),
        'syncType'       => 'webhook',
        'entityType'     => $topicToEntity[$syncEntity] ?? 'products',
        'status'         => $syncStatus,
        'recordsUpdated' => $syncResult ? $syncResult->updated : 0,
        'recordsFailed'  => $syncResult ? $syncResult->failed  : 1,
        'durationMs'     => $syncResult ? $syncResult->durationMs : 0,
        'errorMessage'   => $syncErrMsg,
        'idempotencyKey' => $jobId,
    ]);

    @file_get_contents(
        rtrim(SYNKROP_DAEMON_URL, '/') . '/v1/sync/report',
        false,
        stream_context_create(['http' => [
            'method'         => 'POST',
            'header'         => "Content-Type: application/json\r\nX-API-Key: " . $fullConfig['daemon_api_key'] . "\r\n",
            'content'        => $reportPayload,
            'timeout'        => 5,
            'ignore_errors'  => true,
        ]])
    );
}

// ── Responder al caller (bot-miki) con el resultado del sync ──────────────────

http_response_code(200);
echo json_encode([
    'success' => true,
    'status'  => $syncStatus,
    'updated' => $syncResult ? $syncResult->updated : 0,
]);

<?php
/**
 * Punto de entrada para webhooks de bot-miki → Synkrop.
 * Ejecuta el sync de forma síncrona y responde con el resultado.
 * (fastcgi_finish_request no está disponible en hosting compartido cPanel)
 */

if (!defined('_PS_VERSION_')) {
    // #115: configurable via variable de entorno del servidor
    // (SYNKROP_PS_ADMIN_DIR) para no depender de un valor fijo en el repo.
    // El fallback preserva el nombre actual de produccion (strainmachine.com)
    // para que este cambio sea 100% compatible sin tocar nada del lado del
    // servidor todavia. Pendiente (accion manual, fuera de este commit):
    // setear SYNKROP_PS_ADMIN_DIR en la config de PHP/Apache de produccion
    // con el nombre ofuscado real y, recien ahi, sacar el fallback del repo.
    $psAdminDir = getenv('SYNKROP_PS_ADMIN_DIR') ?: 'fg0qvkmjnpbs5wl5';
    define('_PS_ADMIN_DIR_', dirname(__FILE__, 3) . '/' . $psAdminDir);
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

// #109: modo delete — bot-miki no llama a Bsale (el recurso ya no existe, GET
// daria 404) y manda directo el resourceId de la variante eliminada.
$isDelete = ($body['action'] ?? null) === 'delete' && isset($body['topic']) && isset($body['resourceId']);

// Modo quirúrgico: bot-miki resolvió el recurso concreto de Bsale
$isSurgical = !$isDelete && isset($body['bsaleData']) && isset($body['topic']);

if (!$isSurgical && !$isDelete) {
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
$syncEntity  = ($isSurgical || $isDelete) ? ($body['topic'] ?? 'unknown') : ($entity ?? 'unknown');
$syncStatus  = 'failed';
$syncErrMsg  = null;
// #93: distingue fallo transitorio (excepción → bot-miki reintenta) de permanente
// (status=failed sin excepción, p.ej. variante no mapeada → bot-miki descarta).
$exceptionOccurred = false;

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
            // #100: UTC explicito. El servidor MySQL corre en UTC-5, no en UTC, y el panel
            // convierte created_at asumiendo UTC -> guardar con gmdate lo hace tz-independiente.
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $t) {
        // Si el DB también falla, escribir al error_log del servidor
        error_log('[Synkrop] shutdown fallback DB failed: ' . $t->getMessage() . ' | original: ' . $msg);
    }
});

try {
    $decryptedToken = TokenCipher::decrypt($fullConfig['bsale_api_token']);
    $bsale   = new BsaleApiClient($decryptedToken);
    // #99: LicenseClient ya no toma tenantId — nunca se usaba (la request real
    // solo manda X-API-Key), y el hash md5 no coincidia con licenses.tenant_id.
    $license = new LicenseClient(SYNKROP_DAEMON_URL, $fullConfig['daemon_api_key']);
    $service = new SynkropService($bsale, $license, 1);

    $syncResult = $isDelete
        ? $service->syncDelete($body['topic'], $body['resourceId'])
        : ($isSurgical
            ? $service->syncSingle($body['topic'], $body['bsaleData'])
            : $service->sync($entity));

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
        'created_at'   => gmdate('Y-m-d H:i:s'), // #100: UTC explicito (servidor en UTC-5)
    ]);
    $logWritten = true;

    if (!empty($syncResult->errors)) {
        $syncErrMsg = $syncResult->errors[0]['message'] ?? null;
    }
} catch (\Throwable $e) {
    $syncErrMsg = $e->getMessage();
    $exceptionOccurred = true; // #93: transitorio → señalar a bot-miki que reintente
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
        'created_at'   => gmdate('Y-m-d H:i:s'), // #100: UTC explicito (servidor en UTC-5)
    ]);
    $logWritten = true;
}

// ── Reportar resultado real a bot-miki (cierra el loop en sync_events) ────────

if ($jobId) {
    $topicToEntity = [
        'stock' => 'stock', 'variant' => 'products', 'product' => 'products',
        'price' => 'prices', 'products' => 'products', 'prices' => 'prices',
    ];
    // #99: sin tenantId — bot-miki lo ignora y lo deriva de X-API-Key (fix #91);
    // mandar un hash que no coincide con licenses.tenant_id era solo ruido.
    $reportPayload = json_encode([
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

// ── Responder al caller (bot-miki) con el resultado REAL del sync ─────────────
// #93: antes respondía siempre 200 + success:true, así que bot-miki nunca reintentaba
// ni registraba los fallos. Ahora el código HTTP y el flag `retryable` reflejan el estado:
//   - success / partial          → 200 (hubo cambios; los fallos parciales quedan logueados)
//   - failed + excepción         → 503 retryable=true  (transitorio → bot-miki reintenta)
//   - failed sin excepción       → 422 retryable=false (permanente → bot-miki descarta)

$updated = $syncResult ? $syncResult->updated : 0;

if ($syncStatus === 'success' || $syncStatus === 'partial') {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'status'  => $syncStatus,
        'updated' => $updated,
    ]);
} elseif ($exceptionOccurred) {
    http_response_code(503);
    echo json_encode([
        'success'   => false,
        'status'    => 'failed',
        'retryable' => true,
        'updated'   => $updated,
        'message'   => $syncErrMsg,
    ]);
} else {
    http_response_code(422);
    echo json_encode([
        'success'   => false,
        'status'    => 'failed',
        'retryable' => false,
        'updated'   => $updated,
        'message'   => $syncErrMsg,
    ]);
}

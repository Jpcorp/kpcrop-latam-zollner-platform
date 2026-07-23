<?php
/**
 * CLI de sincronización para catálogos grandes.
 * Sin límite de tiempo PHP — apto para +1000 productos.
 *
 * Uso (dentro del contenedor Docker de PrestaShop):
 *   php modules/synkrop/cli/sync.php products
 *   php modules/synkrop/cli/sync.php stock
 *   php modules/synkrop/cli/sync.php prices
 *   php modules/synkrop/cli/sync.php all
 *
 * Opciones:
 *   --shop=1           ID de tienda (default: 1)
 *   --dry-run          Solo muestra lo que haría, sin modificar datos
 *   --verbose          Imprime progreso detallado
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse desde la línea de comandos.');
}

set_time_limit(0);
ini_set('memory_limit', '512M');

// ── Bootstrap de PrestaShop ───────────────────────────────────────────────────

define('_PS_ROOT_DIR_', realpath(__DIR__ . '/../../../'));

if (!file_exists(_PS_ROOT_DIR_ . '/config/config.inc.php')) {
    fwrite(STDERR, "[ERROR] No se encontró PrestaShop en: " . _PS_ROOT_DIR_ . "\n");
    exit(1);
}

require_once _PS_ROOT_DIR_ . '/config/config.inc.php';
require_once _PS_ROOT_DIR_ . '/init.php';
require_once _PS_MODULE_DIR_ . 'synkrop/synkrop.php';
require_once _PS_MODULE_DIR_ . 'synkrop/classes/BsaleApiClient.php';
require_once _PS_MODULE_DIR_ . 'synkrop/classes/LicenseClient.php';
require_once _PS_MODULE_DIR_ . 'synkrop/classes/SynkropService.php';

// ── Parseo de argumentos ──────────────────────────────────────────────────────

$opts = getopt('', ['shop:', 'dry-run', 'verbose'], $restIndex);
$positional = array_slice($argv, $restIndex);
$entity  = $positional[0] ?? 'products';
$idShop  = (int)($opts['shop'] ?? 1);
$dryRun  = isset($opts['dry-run']);
$verbose = isset($opts['verbose']);

$validEntities = ['products', 'stock', 'prices', 'all'];
if (!in_array($entity, $validEntities)) {
    fwrite(STDERR, "[ERROR] Entidad inválida: '$entity'. Usa: " . implode(' | ', $validEntities) . "\n");
    exit(1);
}

// ── Logging helpers ───────────────────────────────────────────────────────────

function logInfo(string $msg, bool $verbose = false, bool $force = false): void
{
    if ($force || $verbose) {
        echo "[INFO]  " . date('H:i:s') . " $msg\n";
    }
}

function logError(string $msg): void
{
    fwrite(STDERR, "[ERROR] " . date('H:i:s') . " $msg\n");
}

function logResult(string $entity, SyncResult $result): void
{
    $icon = $result->status() === 'success' ? '✓' : ($result->status() === 'partial' ? '~' : '✗');
    echo "\n$icon  $entity: {$result->updated} actualizados, {$result->failed} errores ({$result->durationMs}ms)\n";

    if (!empty($result->errors)) {
        $shown = array_slice($result->errors, 0, 10);
        foreach ($shown as $err) {
            fwrite(STDERR, "      [FAIL] {$err['code']}: {$err['message']}\n");
        }
        if (count($result->errors) > 10) {
            fwrite(STDERR, "      ... y " . (count($result->errors) - 10) . " errores más\n");
        }
    }
}

// ── Cargar config de la tienda ────────────────────────────────────────────────

$config = Db::getInstance()->getRow(
    'SELECT * FROM `' . _DB_PREFIX_ . 'synkrop_config` WHERE id_shop = ' . $idShop
);

if (empty($config)) {
    logError("No hay configuración de synkrop para id_shop=$idShop. Ejecuta la instalación del módulo.");
    exit(1);
}

if (empty($config['bsale_api_token']) || empty($config['daemon_api_key'])) {
    logError("Configura el token de Bsale y la API Key en el backoffice antes de usar el CLI.");
    exit(1);
}

if ($dryRun) {
    echo "[DRY-RUN] No se modificarán datos.\n";
}

echo "=== synkrop CLI | tienda #$idShop | " . date('Y-m-d H:i:s') . " ===\n\n";

// ── Construir servicio ────────────────────────────────────────────────────────

$decryptedToken = Synkrop::decryptToken($config['bsale_api_token']);
$bsale   = new BsaleApiClient($decryptedToken);
$license = new LicenseClient(SYNKROP_DAEMON_URL, $config['daemon_api_key']); // #99: sin tenantId muerto

if (!$dryRun) {
    try {
        $license->getToken();
        logInfo("Licencia validada correctamente.", $verbose, true);
    } catch (LicenseException $e) {
        logError("Licencia inválida: " . $e->getMessage());
        exit(1);
    }
}

$service = new SynkropService($bsale, $license, $idShop);

// ── Ejecutar sync ─────────────────────────────────────────────────────────────

$entities = $entity === 'all' ? ['products', 'stock', 'prices'] : [$entity];
$exitCode = 0;

foreach ($entities as $ent) {
    logInfo("Iniciando sync de $ent...", $verbose, true);

    if ($dryRun) {
        echo "[DRY-RUN] Omitiría sync de $ent\n";
        continue;
    }

    try {
        $result = $service->sync($ent);
        logResult($ent, $result);

        // Registrar en log
        Db::getInstance()->insert('synkrop_log', [
            'id_shop'       => $idShop,
            'sync_type'     => pSQL('cli'),
            'entity_type'   => pSQL($ent),
            'status'        => pSQL($result->status()),
            'records_ok'    => $result->updated,
            'records_fail'  => $result->failed,
            'duration_ms'   => $result->durationMs,
            // JSON (MariaDB CHECK json_valid): nunca null/'' o el INSERT falla en silencio.
            'error_details' => pSQL(json_encode($result->errors ?: [])),
            'created_at'    => gmdate('Y-m-d H:i:s'), // #100: UTC explicito (servidor en UTC-5)
        ]);

        if ($result->status() === 'failed') {
            $exitCode = 1;
        }
    } catch (Exception $e) {
        logError("$ent falló: " . $e->getMessage());
        $exitCode = 1;
    }
}

echo "\n=== Completado (" . date('H:i:s') . ") ===\n";
exit($exitCode);

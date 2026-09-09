<?php
/**
 * CLI de retención de logs (#111) — purga filas viejas de synkrop_log.
 * Pensado para correr vía cron del servidor (ej: diario, fuera de horario pico).
 *
 * Uso (dentro del contenedor Docker de PrestaShop):
 *   php modules/synkrop/cli/retention.php [--days=90] [--limit=5000]
 *
 * cron sugerido (una vez al dia):
 *   0 4 * * * php /ruta/a/modules/synkrop/cli/retention.php >> /var/log/synkrop-retention.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse desde la línea de comandos.');
}

set_time_limit(0);

// ── Bootstrap de PrestaShop ───────────────────────────────────────────────────

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
require_once _PS_MODULE_DIR_ . 'synkrop/classes/SynkropService.php';

// ── Parseo de argumentos ──────────────────────────────────────────────────────

$opts  = getopt('', ['days:', 'limit:']);
$days  = (int)($opts['days'] ?? 90);
$limit = (int)($opts['limit'] ?? 5000);

if ($days < 1) {
    fwrite(STDERR, "[ERROR] --days debe ser >= 1\n");
    exit(1);
}

// UTC, igual que created_at en synkrop_log (el servidor no esta en UTC).
echo "=== synkrop retention CLI | " . gmdate('Y-m-d H:i:s') . " UTC | purgando >{$days} dias (lote de {$limit}) ===\n";

try {
    SynkropService::purgeOldLogs($days, $limit);
} catch (RuntimeException $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    exit(1);
}

echo "Completado.\n";

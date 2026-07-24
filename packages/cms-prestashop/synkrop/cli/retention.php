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
require_once _PS_ROOT_DIR_ . '/init.php';
require_once _PS_MODULE_DIR_ . 'synkrop/classes/SynkropService.php';

// ── Parseo de argumentos ──────────────────────────────────────────────────────

$opts  = getopt('', ['days:', 'limit:']);
$days  = (int)($opts['days'] ?? 90);
$limit = (int)($opts['limit'] ?? 5000);

if ($days < 1) {
    fwrite(STDERR, "[ERROR] --days debe ser >= 1\n");
    exit(1);
}

echo "=== synkrop retention CLI | " . date('Y-m-d H:i:s') . " | purgando >{$days} dias (lote de {$limit}) ===\n";

SynkropService::purgeOldLogs($days, $limit);

echo "Completado.\n";

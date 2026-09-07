<?php

/**
 * Runner de migraciones de Synkrop para PrestaShop.
 *
 * Reemplaza los 11 migrate_*.sql. Aquellos traen `SET @db_prefix = 'ps_'`
 * HARDCODEADO: en una tienda con otro prefijo no fallan, simplemente no hacen
 * nada — asi estuvieron 6 semanas sin aplicarse #56 y #130 en strainmachine.com
 * (prefijo real: pr_). Aca el prefijo sale de _DB_PREFIX_, que PrestaShop ya
 * conoce, y por eso el mismo archivo sirve para N tiendas sin editarlo.
 *
 * Ademas los .sql usan DELIMITER + stored procedure, directiva del cliente
 * `mysql` que obliga a aplicarlos por CLI. Esto corre con el Db de PrestaShop.
 *
 * Idempotente: consulta SHOW COLUMNS / SHOW INDEX / SHOW TABLES antes de cada
 * operacion, asi que se puede correr las veces que sea. No usa
 * information_schema porque no todo usuario MySQL de hosting compartido puede
 * leer ese catalogo, y un falso negativo reintentaria un ALTER ya aplicado.
 *
 * Uso:  php migrate.php [--dry-run]
 */

// sql/ → synkrop/ → modules/ → raiz de PrestaShop
$psRoot = getenv('PS_ROOT_DIR') ?: dirname(__DIR__, 3);
if (!is_file($psRoot . '/config/config.inc.php')) {
    fwrite(STDERR, "No encuentro config.inc.php bajo $psRoot\n");
    fwrite(STDERR, "Defini PS_ROOT_DIR con la raiz de PrestaShop.\n");
    exit(1);
}
require_once $psRoot . '/config/config.inc.php';

$dryRun = in_array('--dry-run', $argv, true);
$db     = Db::getInstance();
$p      = _DB_PREFIX_;

// ─── Helpers de introspeccion ────────────────────────────────────────────────

function tableExists(Db $db, string $table): bool
{
    return (bool) $db->executeS("SHOW TABLES LIKE '" . pSQL($table) . "'");
}

function columnExists(Db $db, string $table, string $column): bool
{
    if (!tableExists($db, $table)) {
        return false;
    }
    foreach ($db->executeS("SHOW COLUMNS FROM `$table`") as $row) {
        if ($row['Field'] === $column) {
            return true;
        }
    }
    return false;
}

function indexExists(Db $db, string $table, string $index): bool
{
    if (!tableExists($db, $table)) {
        return false;
    }
    foreach ($db->executeS("SHOW INDEX FROM `$table`") as $row) {
        if ($row['Key_name'] === $index) {
            return true;
        }
    }
    return false;
}

$migrations = require __DIR__ . '/migrations.php';

// ─── Aplicacion ──────────────────────────────────────────────────────────────

echo "Prefijo: $p", ($dryRun ? '   [DRY RUN — no escribe nada]' : ''), "\n\n";

$applied = 0;
$skipped = 0;
$missing = 0;

foreach ($migrations as $m) {
    $kind  = $m[0];
    $table = $p . $m[1];
    $name  = $m[2];
    $spec  = isset($m[3]) ? $m[3] : null;

    // Las tablas base las crea install.sql al instalar el modulo. Si no estan,
    // el modulo no esta instalado en esta tienda: avisar, no crearlas a medias.
    if ($kind !== 'table' && !tableExists($db, $table)) {
        echo "[!]    $table no existe — instala el modulo primero\n";
        $missing++;
        continue;
    }

    switch ($kind) {
        case 'column':
            if (columnExists($db, $table, $name)) {
                echo "[SKIP] $name ya existe en $table\n";
                $skipped++;
            } else {
                $dryRun || $db->execute("ALTER TABLE `$table` ADD COLUMN `$name` $spec");
                echo "[OK]   $name agregada a $table\n";
                $applied++;
            }
            break;

        case 'index':
        case 'unique':
            if (indexExists($db, $table, $name)) {
                echo "[SKIP] indice $name ya existe en $table\n";
                $skipped++;
            } else {
                $unique = $kind === 'unique' ? 'UNIQUE ' : '';
                $dryRun || $db->execute("ALTER TABLE `$table` ADD {$unique}KEY `$name` $spec");
                echo "[OK]   indice $name creado en $table\n";
                $applied++;
            }
            break;

        case 'dropindex':
            if (!indexExists($db, $table, $name)) {
                echo "[SKIP] indice $name ya no esta en $table\n";
                $skipped++;
            } else {
                $dryRun || $db->execute("ALTER TABLE `$table` DROP INDEX `$name`");
                echo "[OK]   indice $name eliminado de $table\n";
                $applied++;
            }
            break;

        case 'table':
            if (tableExists($db, $table)) {
                echo "[SKIP] tabla $table ya existe\n";
                $skipped++;
            } else {
                $dryRun || $db->execute(
                    "CREATE TABLE IF NOT EXISTS `$table` ($name)"
                    . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
                echo "[OK]   tabla $table creada\n";
                $applied++;
            }
            break;
    }
}

echo "\nResultado: $applied aplicadas, $skipped omitidas";
echo $missing ? ", $missing sin tabla base\n" : "\n";

exit($missing > 0 ? 2 : 0);

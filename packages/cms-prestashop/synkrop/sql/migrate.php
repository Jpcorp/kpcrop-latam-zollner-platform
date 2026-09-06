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

// ─── Las 11 migraciones, declarativas ────────────────────────────────────────
// Orden = el alfabetico de los .sql originales, que es el que ya corrio en
// produccion. No lo reordenes: variant_unique borra un indice que otra creo.

$migrations = array(

    // migrate_add_test_mode
    array('column', 'synkrop_config', 'test_mode', 'TINYINT(1) NOT NULL DEFAULT 0'),

    // migrate_add_shipping_sku
    array('column', 'synkrop_config', 'shipping_sku', "VARCHAR(64) NOT NULL DEFAULT ''"),

    // migrate_add_degraded_manual_sync
    array('column', 'synkrop_config', 'degraded_manual_sync_at', 'DATETIME DEFAULT NULL'),

    // migrate_add_order_queue — ventas CMS → Bsale
    array('column', 'synkrop_config', 'sync_orders', 'TINYINT(1) NOT NULL DEFAULT 0'),
    array('column', 'synkrop_config', 'order_trigger_states', "VARCHAR(100) NOT NULL DEFAULT ''"),
    array('column', 'synkrop_config', 'order_vat_rate', 'DECIMAL(5,2) NOT NULL DEFAULT 19.00'),
    array('column', 'synkrop_config', 'sale_doc_type_id', 'INT UNSIGNED DEFAULT NULL'),
    array('table', 'synkrop_order_queue', '
        `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_shop`             INT UNSIGNED NOT NULL DEFAULT 1,
        `id_order`            INT UNSIGNED NOT NULL,
        `id_cart`             INT UNSIGNED NOT NULL DEFAULT 0,
        `status`              VARCHAR(20) NOT NULL DEFAULT \'pending\',
        `bsale_doc_id`        INT UNSIGNED DEFAULT NULL,
        `bsale_doc_number`    VARCHAR(50) DEFAULT NULL,
        `bsale_doc_url`       VARCHAR(1000) DEFAULT NULL,
        `emitted_doc_id`      INT UNSIGNED DEFAULT NULL,
        `emitted_doc_number`  VARCHAR(50) DEFAULT NULL,
        `emitted_doc_url`     VARCHAR(1000) DEFAULT NULL,
        `emitted_doc_type`    VARCHAR(50) DEFAULT NULL,
        `client_code`         VARCHAR(20) DEFAULT NULL,
        `total_amount`        DECIMAL(20,2) DEFAULT NULL,
        `skus_hash`           VARCHAR(64) DEFAULT NULL,
        `error_details`       JSON DEFAULT NULL,
        `created_at`          DATETIME NOT NULL,
        `updated_at`          DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_shop_order` (`id_shop`, `id_order`),
        KEY `idx_status` (`id_shop`, `status`)'),
    array('table', 'synkrop_invoice_request', '
        `id_cart`       INT UNSIGNED NOT NULL,
        `rut`           VARCHAR(15) NOT NULL,
        `razon_social`  VARCHAR(120) NOT NULL,
        `giro`          VARCHAR(100) NOT NULL,
        `direccion`     VARCHAR(200) NOT NULL DEFAULT \'\',
        `telefono`      VARCHAR(30) NOT NULL DEFAULT \'\',
        `ciudad`        VARCHAR(50) NOT NULL DEFAULT \'\',
        `comuna`        VARCHAR(50) NOT NULL DEFAULT \'\',
        `created_at`    DATETIME NOT NULL,
        PRIMARY KEY (`id_cart`)'),

    // migrate_add_job_id — trazabilidad punta a punta
    array('column', 'synkrop_log', 'job_id', 'VARCHAR(200) DEFAULT NULL'),
    array('index',  'synkrop_log', 'idx_job_id', '(job_id)'),

    // migrate_add_stock_event_ordering (#115)
    array('column', 'synkrop_product_map', 'last_stock_event_send', 'INT UNSIGNED DEFAULT NULL'),

    // migrate_add_variant_unique — el DROP va antes del ADD a proposito
    array('dropindex', 'synkrop_product_map', 'idx_bsale_variant'),
    array('unique',    'synkrop_product_map', 'uk_variant_shop', '(bsale_variant_id, id_shop)'),

    // migrate_add_images_unique
    array('unique', 'synkrop_images', 'uk_product_source', '(id_product, source_url(191))'),

    // migrate_add_category_sync (#87)
    array('column', 'synkrop_config', 'sync_categories', 'TINYINT(1) NOT NULL DEFAULT 0'),
    array('column', 'synkrop_config', 'category_parent_id', 'INT UNSIGNED DEFAULT NULL'),
    array('table',  'synkrop_category_map', '
        `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_shop`          INT UNSIGNED NOT NULL DEFAULT 1,
        `bsale_type_id`    INT UNSIGNED NOT NULL,
        `bsale_type_name`  VARCHAR(128) NOT NULL,
        `id_ps_category`   INT UNSIGNED NOT NULL,
        `active`           TINYINT(1) NOT NULL DEFAULT 1,
        `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_shop_type` (`id_shop`, `bsale_type_id`)'),

    // migrate_add_agency_branding (#56)
    array('column', 'synkrop_config', 'agency_name', 'VARCHAR(100) DEFAULT NULL'),
    array('column', 'synkrop_config', 'agency_logo_url', 'TEXT'),
    array('column', 'synkrop_config', 'agency_brand_color', 'VARCHAR(7) DEFAULT NULL'),

    // migrate_add_order_auto_mode (#130)
    array('column', 'synkrop_config', 'order_auto_mode', 'TINYINT(1) NOT NULL DEFAULT 0'),
);

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

<?php
// migrate_add_agency_branding (#56) + migrate_add_order_auto_mode (#130)
// Los .sql del repo traen SET @db_prefix = 'ps_' y esta tienda usa 'pr_'.
// SHOW COLUMNS en vez de information_schema: no todo usuario MySQL de este hosting
// lee ese catalogo, y un falso negativo reintentaria un ALTER ya aplicado.
require_once '/home/strainma/public_html/config/config.inc.php';

$db     = Db::getInstance();
$prefix = _DB_PREFIX_;
$table  = $prefix . 'synkrop_config';
$ok = 0;
$skip = 0;

echo "Prefix: $prefix\n";

$cols = array_map(function ($r) {
    return $r['Field'];
}, $db->executeS("SHOW COLUMNS FROM `$table`"));

$pending = array(
    'agency_name'        => 'VARCHAR(100) DEFAULT NULL',
    'agency_logo_url'    => 'TEXT',
    'agency_brand_color' => 'VARCHAR(7) DEFAULT NULL',
    'order_auto_mode'    => 'TINYINT(1) NOT NULL DEFAULT 0',
);

foreach ($pending as $col => $definition) {
    if (!in_array($col, $cols, true)) {
        $db->execute("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
        echo "[OK]   $col agregada a $table\n";
        $ok++;
    } else {
        echo "[SKIP] $col ya existe en $table\n";
        $skip++;
    }
}

echo "\nResultado: $ok aplicadas, $skip omitidas.\n";
echo "Columnas finales de $table:\n";
foreach ($db->executeS("SHOW COLUMNS FROM `$table`") as $r) {
    echo '  - ', $r['Field'], ' (', $r['Type'], ")\n";
}

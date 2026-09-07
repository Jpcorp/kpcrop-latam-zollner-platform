<?php

/**
 * Las migraciones de Synkrop, como datos.
 *
 * Separado de migrate.php para que los tests puedan validarlas sin arrancar
 * PrestaShop. Cada entrada es:
 *
 *   array(tipo, tabla_sin_prefijo, nombre, spec)
 *
 * tipo: column | index | unique | dropindex | table
 *   - column     -> spec es la definicion SQL de la columna
 *   - index/uniq -> spec son las columnas, con parentesis: '(col_a, col_b)'
 *   - dropindex  -> sin spec
 *   - table      -> spec (en 'nombre') es el cuerpo del CREATE TABLE
 *
 * El prefijo NO va aca: lo pone migrate.php desde _DB_PREFIX_.
 */

// ─── Las 11 migraciones, declarativas ────────────────────────────────────────
// Orden = el alfabetico de los .sql originales, que es el que ya corrio en
// produccion. No lo reordenes: variant_unique borra un indice que otra creo.

return array(

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

    // Plan de la licencia, para mostrarlo en el panel. bot-miki ya lo devuelve en
    // /v1/license/token junto al JWT; se persiste con el mismo refresco que el
    // branding de agencia (#56) para no depender de que el daemon responda.
    array('column', 'synkrop_config', 'license_plan', 'VARCHAR(20) DEFAULT NULL'),
    array('column', 'synkrop_config', 'license_max_stores', 'INT UNSIGNED DEFAULT NULL'),
    array('column', 'synkrop_config', 'license_features', 'TEXT'),
);

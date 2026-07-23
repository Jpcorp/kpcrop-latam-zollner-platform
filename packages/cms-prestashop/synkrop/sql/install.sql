-- Configuracion del modulo por tienda PrestaShop
CREATE TABLE IF NOT EXISTS `PREFIX_synkrop_config` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop`               INT UNSIGNED NOT NULL DEFAULT 1,
    -- #112: TEXT no admite DEFAULT en MySQL <8.0.13/MariaDB <10.2 (error de sintaxis)
    -- — sin default, el INSERT inicial de abajo provee el valor explicito.
    `bsale_api_token`       TEXT NOT NULL,
    `bsale_integration_id`  INT UNSIGNED DEFAULT NULL,
    `bsale_price_list_id`   INT UNSIGNED DEFAULT NULL,
    `bsale_office_id`       INT UNSIGNED DEFAULT NULL,
    `daemon_api_url`        VARCHAR(500) NOT NULL DEFAULT 'https://kpcrop-latam-zollner-platform-production.up.railway.app',
    `daemon_api_key`        VARCHAR(64)  NOT NULL DEFAULT '',
    `license_jwt`           TEXT,
    `license_jwt_expires`   DATETIME DEFAULT NULL,
    `sync_products`         TINYINT(1) NOT NULL DEFAULT 1,
    `sync_prices`           TINYINT(1) NOT NULL DEFAULT 1,
    `sync_stock`            TINYINT(1) NOT NULL DEFAULT 1,
    `sync_clients`          TINYINT(1) NOT NULL DEFAULT 0,
    `sync_images`           TINYINT(1) NOT NULL DEFAULT 0,
    `sync_orders`           TINYINT(1) NOT NULL DEFAULT 0,
    `order_trigger_states`  VARCHAR(100) NOT NULL DEFAULT '2',
    `order_vat_rate`        DECIMAL(5,2) NOT NULL DEFAULT 19.00,
    `sale_doc_type_id`      INT UNSIGNED DEFAULT NULL,
    `shipping_sku`          VARCHAR(64) NOT NULL DEFAULT '',
    `test_mode`             TINYINT(1) NOT NULL DEFAULT 0,
    `on_inactive_product`   VARCHAR(10) NOT NULL DEFAULT 'deactivate',
    `last_sync_at`          DATETIME DEFAULT NULL,
    `last_sync_status`      VARCHAR(20) DEFAULT NULL,
    `last_sync_count`       INT NOT NULL DEFAULT 0,
    -- #127: ultima vez que se uso el sync manual en modo degradado (licencia
    -- vencida/suspendida) — limita la frecuencia para que no sea sustituto
    -- gratuito del auto-sync mientras incentiva renovar.
    `degraded_manual_sync_at` DATETIME DEFAULT NULL,
    -- #87: sync de categorias Bsale -> PS (modo automatico, MVP)
    `sync_categories`       TINYINT(1) NOT NULL DEFAULT 0,
    `category_parent_id`    INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_shop` (`id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- #87: mapeo persistente bsale_type <-> categoria PrestaShop
CREATE TABLE IF NOT EXISTS `PREFIX_synkrop_category_map` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop`         INT UNSIGNED NOT NULL DEFAULT 1,
    `bsale_type_id`   INT UNSIGNED NOT NULL,
    `bsale_type_name` VARCHAR(128) NOT NULL,
    `id_ps_category`  INT UNSIGNED NOT NULL,
    `active`          TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_shop_type` (`id_shop`, `bsale_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mapeo entre variantes Bsale y productos PrestaShop
CREATE TABLE IF NOT EXISTS `PREFIX_synkrop_product_map` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop`               INT UNSIGNED NOT NULL DEFAULT 1,
    `id_product`            INT UNSIGNED NOT NULL,
    `id_product_attribute`  INT UNSIGNED NOT NULL DEFAULT 0,
    `bsale_variant_id`      INT UNSIGNED NOT NULL,
    `bsale_code`            VARCHAR(100) NOT NULL,
    `bsale_barcode`         VARCHAR(50) DEFAULT NULL,
    `last_synced_at`        DATETIME DEFAULT NULL,
    -- #115: timestamp (`send` del webhook de Bsale) del ultimo evento de
    -- stock aplicado para esta variante — permite descartar eventos que
    -- llegan fuera de orden (concurrency:5 en bot-miki + red variable).
    `last_stock_event_send` INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_bsale_code_shop` (`bsale_code`, `id_shop`),
    -- #103: unicidad por variante — evita filas huerfanas si el `code` de una
    -- variante cambia en Bsale (findProductByBsaleVariantId asume 1 fila por variante)
    UNIQUE KEY `uk_variant_shop` (`bsale_variant_id`, `id_shop`),
    KEY `idx_product` (`id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Imagenes importadas (evita re-importar en cada sync)
CREATE TABLE IF NOT EXISTS `PREFIX_synkrop_images` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_product`    INT UNSIGNED NOT NULL,
    `id_image`      INT UNSIGNED NOT NULL,
    -- source_url puede superar 191 bytes en utf8mb4 (limite de indice en
    -- MySQL/InnoDB con ROW_FORMAT no-dynamico) — se indexa por prefijo.
    `source_url`    VARCHAR(1000) NOT NULL,
    `imported_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- #115: sin esto, nada impedia reimportar la misma imagen del mismo
    -- producto mas de una vez (justo lo que el comentario de arriba dice
    -- que esta tabla existe para evitar).
    UNIQUE KEY `uk_product_source` (`id_product`, `source_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial de sincronizaciones (para mostrar en backoffice)
CREATE TABLE IF NOT EXISTS `PREFIX_synkrop_log` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop`       INT UNSIGNED NOT NULL DEFAULT 1,
    `sync_type`     VARCHAR(20) NOT NULL DEFAULT 'manual',
    `entity_type`   VARCHAR(20) NOT NULL DEFAULT 'products',
    `status`        VARCHAR(20) NOT NULL,
    `records_ok`    INT NOT NULL DEFAULT 0,
    `records_fail`  INT NOT NULL DEFAULT 0,
    `duration_ms`   INT NOT NULL DEFAULT 0,
    `error_details` JSON DEFAULT NULL,
    `job_id`        VARCHAR(200) DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_shop_date` (`id_shop`, `created_at`),
    KEY `idx_job_id`    (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mapeo grupo de clientes PrestaShop → lista de precios Bsale
-- Permite que mayoristas, minoristas y otros grupos vean precios distintos
CREATE TABLE IF NOT EXISTS `PREFIX_synkrop_price_group_map` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop`               INT UNSIGNED NOT NULL DEFAULT 1,
    `id_group`              INT UNSIGNED NOT NULL,   -- id del grupo PS (ps_group)
    `bsale_price_list_id`   INT UNSIGNED NOT NULL,   -- id de la lista en Bsale
    `active`                TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_shop_group` (`id_shop`, `id_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cola de pedidos PS → documentos Bsale (flujo de ventas, modo automatico/semi-manual)
-- Fechas SIEMPRE en UTC via gmdate() desde PHP — sin DEFAULT CURRENT_TIMESTAMP (leccion #100:
-- el servidor MySQL de prod no corre en UTC)
CREATE TABLE IF NOT EXISTS `PREFIX_synkrop_order_queue` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop`             INT UNSIGNED NOT NULL DEFAULT 1,
    `id_order`            INT UNSIGNED NOT NULL,
    `id_cart`             INT UNSIGNED NOT NULL DEFAULT 0,
    `status`              VARCHAR(20) NOT NULL DEFAULT 'pending',
    -- pending | generated | emitted | closed | error | review | cancelled
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
    KEY `idx_status` (`id_shop`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solicitud de factura capturada en el checkout (datos tributarios del comprador).
-- Informativa en v1: la emision tributaria SIEMPRE la decide un usuario en Bsale.
CREATE TABLE IF NOT EXISTS `PREFIX_synkrop_invoice_request` (
    `id_cart`       INT UNSIGNED NOT NULL,
    `rut`           VARCHAR(15) NOT NULL,
    `razon_social`  VARCHAR(120) NOT NULL,
    `giro`          VARCHAR(100) NOT NULL,
    `direccion`     VARCHAR(200) NOT NULL DEFAULT '',
    `telefono`      VARCHAR(30) NOT NULL DEFAULT '',
    `ciudad`        VARCHAR(50) NOT NULL DEFAULT '',
    `comuna`        VARCHAR(50) NOT NULL DEFAULT '',
    `created_at`    DATETIME NOT NULL,
    PRIMARY KEY (`id_cart`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fila inicial de config para la tienda por defecto
INSERT IGNORE INTO `PREFIX_synkrop_config` (`id_shop`, `bsale_api_token`) VALUES (1, '');

-- Configuracion del modulo por tienda PrestaShop
CREATE TABLE IF NOT EXISTS `PREFIX_bsalesync_config` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop`               INT UNSIGNED NOT NULL DEFAULT 1,
    `bsale_api_token`       TEXT NOT NULL DEFAULT '',
    `bsale_integration_id`  INT UNSIGNED DEFAULT NULL,
    `bsale_price_list_id`   INT UNSIGNED DEFAULT NULL,
    `bsale_office_id`       INT UNSIGNED DEFAULT NULL,
    `daemon_api_url`        VARCHAR(500) NOT NULL DEFAULT 'https://api.kpcrop.com/v1',
    `daemon_api_key`        VARCHAR(64)  NOT NULL DEFAULT '',
    `license_jwt`           TEXT,
    `license_jwt_expires`   DATETIME DEFAULT NULL,
    `sync_products`         TINYINT(1) NOT NULL DEFAULT 1,
    `sync_prices`           TINYINT(1) NOT NULL DEFAULT 1,
    `sync_stock`            TINYINT(1) NOT NULL DEFAULT 1,
    `sync_clients`          TINYINT(1) NOT NULL DEFAULT 0,
    `sync_images`           TINYINT(1) NOT NULL DEFAULT 0,
    `on_inactive_product`   VARCHAR(10) NOT NULL DEFAULT 'deactivate',
    `last_sync_at`          DATETIME DEFAULT NULL,
    `last_sync_status`      VARCHAR(20) DEFAULT NULL,
    `last_sync_count`       INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_shop` (`id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mapeo entre variantes Bsale y productos PrestaShop
CREATE TABLE IF NOT EXISTS `PREFIX_bsalesync_product_map` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop`               INT UNSIGNED NOT NULL DEFAULT 1,
    `id_product`            INT UNSIGNED NOT NULL,
    `id_product_attribute`  INT UNSIGNED NOT NULL DEFAULT 0,
    `bsale_variant_id`      INT UNSIGNED NOT NULL,
    `bsale_code`            VARCHAR(100) NOT NULL,
    `bsale_barcode`         VARCHAR(50) DEFAULT NULL,
    `last_synced_at`        DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_bsale_code_shop` (`bsale_code`, `id_shop`),
    KEY `idx_product` (`id_product`),
    KEY `idx_bsale_variant` (`bsale_variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Imagenes importadas (evita re-importar en cada sync)
CREATE TABLE IF NOT EXISTS `PREFIX_bsalesync_images` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_product`    INT UNSIGNED NOT NULL,
    `id_image`      INT UNSIGNED NOT NULL,
    `source_url`    VARCHAR(1000) NOT NULL,
    `imported_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_product` (`id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial de sincronizaciones (para mostrar en backoffice)
CREATE TABLE IF NOT EXISTS `PREFIX_bsalesync_log` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop`       INT UNSIGNED NOT NULL DEFAULT 1,
    `sync_type`     VARCHAR(20) NOT NULL DEFAULT 'manual',
    `entity_type`   VARCHAR(20) NOT NULL DEFAULT 'products',
    `status`        VARCHAR(20) NOT NULL,
    `records_ok`    INT NOT NULL DEFAULT 0,
    `records_fail`  INT NOT NULL DEFAULT 0,
    `duration_ms`   INT NOT NULL DEFAULT 0,
    `error_details` JSON DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_shop_date` (`id_shop`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mapeo grupo de clientes PrestaShop → lista de precios Bsale
-- Permite que mayoristas, minoristas y otros grupos vean precios distintos
CREATE TABLE IF NOT EXISTS `PREFIX_bsalesync_price_group_map` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop`               INT UNSIGNED NOT NULL DEFAULT 1,
    `id_group`              INT UNSIGNED NOT NULL,   -- id del grupo PS (ps_group)
    `bsale_price_list_id`   INT UNSIGNED NOT NULL,   -- id de la lista en Bsale
    `active`                TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_shop_group` (`id_shop`, `id_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fila inicial de config para la tienda por defecto
INSERT IGNORE INTO `PREFIX_bsalesync_config` (`id_shop`) VALUES (1);

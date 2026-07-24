-- =============================================================================
-- Migración: flujo de ventas PS → Bsale (cola de pedidos + solicitud de factura)
-- =============================================================================
-- Aplicar en instalaciones existentes (install.sql ya lo trae para
-- instalaciones nuevas). Portable MySQL/MariaDB (information_schema, no
-- "IF NOT EXISTS" de DDL — ver #112).
--
-- Uso: mysql -u<user> -p<pass> <database> < migrate_add_order_queue.sql
-- Ajustar @db_prefix si tu instalación usa un prefijo distinto de "ps_".
-- Idempotente: se puede ejecutar más de una vez sin efectos secundarios.
-- =============================================================================

SET @db_prefix = 'ps_';

DROP PROCEDURE IF EXISTS synkrop_add_order_queue;

DELIMITER $$
CREATE PROCEDURE synkrop_add_order_queue()
BEGIN
    DECLARE cfgTbl VARCHAR(200);
    SET cfgTbl = CONCAT(@db_prefix, 'synkrop_config');

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = cfgTbl AND column_name = 'sync_orders'
    ) THEN
        SET @sql = CONCAT(
            'ALTER TABLE ', cfgTbl, ' ',
            'ADD COLUMN sync_orders TINYINT(1) NOT NULL DEFAULT 0 AFTER sync_images, ',
            'ADD COLUMN order_trigger_states VARCHAR(100) NOT NULL DEFAULT ''2'' AFTER sync_orders, ',
            'ADD COLUMN order_vat_rate DECIMAL(5,2) NOT NULL DEFAULT 19.00 AFTER order_trigger_states, ',
            'ADD COLUMN sale_doc_type_id INT UNSIGNED DEFAULT NULL AFTER order_vat_rate'
        );
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL synkrop_add_order_queue();
DROP PROCEDURE synkrop_add_order_queue;

-- CREATE TABLE IF NOT EXISTS ya es portable, no necesita el patron de stored procedure.
SET @sql = CONCAT('CREATE TABLE IF NOT EXISTS `', @db_prefix, 'synkrop_order_queue` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop`             INT UNSIGNED NOT NULL DEFAULT 1,
    `id_order`            INT UNSIGNED NOT NULL,
    `id_cart`             INT UNSIGNED NOT NULL DEFAULT 0,
    `status`              VARCHAR(20) NOT NULL DEFAULT ''pending'',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = CONCAT('CREATE TABLE IF NOT EXISTS `', @db_prefix, 'synkrop_invoice_request` (
    `id_cart`       INT UNSIGNED NOT NULL,
    `rut`           VARCHAR(15) NOT NULL,
    `razon_social`  VARCHAR(120) NOT NULL,
    `giro`          VARCHAR(100) NOT NULL,
    `direccion`     VARCHAR(200) NOT NULL DEFAULT '''',
    `telefono`      VARCHAR(30) NOT NULL DEFAULT '''',
    `ciudad`        VARCHAR(50) NOT NULL DEFAULT '''',
    `comuna`        VARCHAR(50) NOT NULL DEFAULT '''',
    `created_at`    DATETIME NOT NULL,
    PRIMARY KEY (`id_cart`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

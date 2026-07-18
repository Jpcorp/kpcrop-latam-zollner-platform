-- Migracion: SKU de despacho configurable (v1.1.0)
-- Aplicar en instalaciones existentes. Con SKU definido, la linea de envio de la
-- nota de venta reutiliza esa variante de servicio en Bsale en lugar de dejar que
-- Bsale auto-cree una variante nueva por documento.

ALTER TABLE `PREFIX_synkrop_config`
    ADD COLUMN `shipping_sku` VARCHAR(64) NOT NULL DEFAULT '' AFTER `sale_doc_type_id`;

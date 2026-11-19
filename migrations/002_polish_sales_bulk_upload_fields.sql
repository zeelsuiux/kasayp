-- Migration 002: Add Bulk Upload Fields to polish_sales
-- Fields: color_tag, product_tag, ac_percent, gst_percent, gst_amount
-- Idempotent: safe to run multiple times

ALTER TABLE `polish_sales`
    ADD COLUMN IF NOT EXISTS `color_tag` VARCHAR(100) NULL DEFAULT NULL AFTER `broker_id`,
    ADD COLUMN IF NOT EXISTS `product_tag` VARCHAR(100) NULL DEFAULT NULL AFTER `color_tag`,
    ADD COLUMN IF NOT EXISTS `ac_percent` DECIMAL(6,2) NULL DEFAULT 0.00 AFTER `brokerage_amount`,
    ADD COLUMN IF NOT EXISTS `gst_percent` DECIMAL(6,2) NULL DEFAULT 0.00 AFTER `ac_percent`,
    ADD COLUMN IF NOT EXISTS `gst_amount` DECIMAL(15,2) NULL DEFAULT 0.00 AFTER `gst_percent`;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('002_polish_sales_bulk_upload_fields');

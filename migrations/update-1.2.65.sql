-- Migrasi Averion v1.2.65
-- Tambahkan kolom hide_from_catalog ke tabel product_bundles

ALTER TABLE `product_bundles` ADD COLUMN `hide_from_catalog` tinyint(1) NOT NULL DEFAULT 0 AFTER `sort_order`;

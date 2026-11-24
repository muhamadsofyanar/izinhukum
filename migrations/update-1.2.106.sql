-- update-1.2.106.sql
-- Fitur "Reseller — Salespage Siap Pakai" (Bagian 1). Model DUA PRODUK: produk
-- Personal (P, dijual reseller via WA 100%) ditautkan ke produk Resell (R, dibeli
-- untuk jadi reseller). Tambah 2 kolom di products (dikelola ADMIN saat buat P):
--   salespage_enabled  aktifkan salespage /on/{slug-personal} untuk produk ini
--   resell_product_id  produk Resell (R) yang jadi target tombol Order Resell Rights
-- Harga yang tampil di salespage = products.price (harga Personal langsung).
-- Teks WA & toggle tombol Resell dikelola per-RESELLER (tabel reseller_salespage,
-- migrasi 1.2.108). Idempoten via information_schema + PREPARE. JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'salespage_enabled');
SET @s := IF(@c = 0,
  'ALTER TABLE `products` ADD COLUMN `salespage_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `sp_mode`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'resell_product_id');
SET @s := IF(@c = 0,
  'ALTER TABLE `products` ADD COLUMN `resell_product_id` INT(10) UNSIGNED DEFAULT NULL AFTER `salespage_enabled`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- update-1.2.107.sql
-- Fitur "Reseller — Akses Produk Berpassword" (Bagian 2). Tambah 1 kolom di products:
--   access_page_enabled   aktifkan halaman /akses/{slug} berpassword (delivery off-platform)
-- Password akses dikelola PER-RESELLER di dashboard (kolom reseller_salespage.access_password,
-- migrasi 1.2.108) — admin cukup mengaktifkan halamannya. Idempoten via information_schema + PREPARE.
-- JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'access_page_enabled');
SET @s := IF(@c = 0,
  'ALTER TABLE `products` ADD COLUMN `access_page_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `resell_product_id`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

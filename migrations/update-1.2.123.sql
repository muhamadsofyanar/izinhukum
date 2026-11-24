-- update-1.2.123.sql
-- Fitur: Teks Tombol Kustom per produk (products.cta_label) — seller dapat mengatur
-- sendiri teks tombol beli (mis. "Bayar Sesukamu", "Dukung Karya Ini"). Opsional,
-- maks 60 char. Kosong = default cerdas (Beli Sekarang / Ambil Gratis).
-- Idempoten via information_schema. JANGAN pakai ADD COLUMN IF NOT EXISTS (MySQL 8 menolak).

SET @hasCol := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
                  AND COLUMN_NAME = 'cta_label');
SET @s := IF(@hasCol = 0,
  'ALTER TABLE `products` ADD COLUMN `cta_label` varchar(60) DEFAULT NULL AFTER `flexible_presets`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

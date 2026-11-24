-- update-1.2.97.sql
-- Landing produk sadar-kategori: kolom editable override (opsional).
--   highlights = "Yang kamu dapat" (1 bullet per baris); kosong = default otomatis per kategori.
--   includes   = "Isi Paket" (1 bullet per baris) untuk produk non-course; kosong = section disembunyikan.
-- Idempoten via information_schema + PREPARE. JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
             AND COLUMN_NAME = 'highlights');
SET @s := IF(@c = 0,
  'ALTER TABLE `products` ADD COLUMN `highlights` TEXT NULL AFTER `ai_knowledge`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
             AND COLUMN_NAME = 'includes');
SET @s := IF(@c = 0,
  'ALTER TABLE `products` ADD COLUMN `includes` TEXT NULL AFTER `highlights`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- update-1.2.109.sql
-- Gateway Tripay (Closed Payment + pemilih channel): tambah nilai 'tripay' ke enum
-- provider tabel payment_gateways + seed satu baris nonaktif (mode sandbox).
-- Idempoten via information_schema (cek enum sudah memuat tripay) + INSERT IGNORE
-- (UNIQUE uk_provider). JANGAN taruh titik-koma di komentar.

SET @has := (SELECT LOCATE('tripay', COLUMN_TYPE) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_gateways'
               AND COLUMN_NAME = 'provider');
SET @s := IF(@has = 0,
  'ALTER TABLE `payment_gateways` MODIFY COLUMN `provider` ENUM(''duitku'',''xendit'',''moota'',''paypal'',''midtrans'',''tripay'') NOT NULL',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

INSERT IGNORE INTO `payment_gateways` (`provider`, `is_active`, `mode`) VALUES ('tripay', 0, 'sandbox');

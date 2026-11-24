-- update-1.2.99.sql
-- Booking Fee: opsi akses instan area member meski baru bayar DP (opt-in per produk).
-- Default 0 = perilaku lama (akses baru terbuka saat lunas). Akses instan bersifat
-- provisional: dicabut otomatis bila booking hangus (tenggat pelunasan lewat).
-- Idempoten via information_schema + PREPARE. JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
             AND COLUMN_NAME = 'booking_instant_access');
SET @s := IF(@c = 0,
  'ALTER TABLE `products` ADD COLUMN `booking_instant_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `booking_deadline_days`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

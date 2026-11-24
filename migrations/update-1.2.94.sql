-- update-1.2.94.sql
-- Lead Magnet: opsi WAJIB verifikasi OTP WhatsApp per magnet.
-- Default 1 = tetap wajib (perilaku lama tak berubah saat update — aman).
-- Idempoten via information_schema + PREPARE. JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_magnets'
             AND COLUMN_NAME = 'require_otp');
SET @s := IF(@c = 0,
  'ALTER TABLE `lead_magnets` ADD COLUMN `require_otp` TINYINT(1) NOT NULL DEFAULT 1 AFTER `tripwire_headline`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

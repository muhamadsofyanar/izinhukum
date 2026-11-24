-- update-1.2.100.sql
-- Lead Magnet: opsi "boleh diafiliasikan". Bila aktif, lead magnet muncul di
-- dashboard afiliasi dengan link /lp/{slug}?ref={kode}. Komisi datang dari
-- pembelian berbayar (penawaran lanjutan/tripwire) oleh prospek yang dibawa —
-- atribusi via cookie referral yang sudah ditangkap global di bootstrap.php.
-- Default 0 = tidak tampil di afiliasi (opt-in per item). Idempoten.
-- JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_magnets'
             AND COLUMN_NAME = 'affiliate_enabled');
SET @s := IF(@c = 0,
  'ALTER TABLE `lead_magnets` ADD COLUMN `affiliate_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `show_on_homepage`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

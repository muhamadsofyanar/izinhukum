-- ========================================================================
-- Migration: License Hardening (signed token Ed25519 + re-validasi periodik)
-- Target tabel: license_info
-- ------------------------------------------------------------------------
-- IDEMPOTEN dan AMAN di-re-run:
--   - MODIFY COLUMN status hanya memperluas ENUM (menambah pending_activation).
--     Re-apply definisi sama tidak mereset data. Nilai lama
--     active/expired/suspended/unknown tetap valid karena masih ada di set.
--   - ADD COLUMN dibungkus cek information_schema lewat prepared statement,
--     jadi tidak bergantung pada sintaks khusus MariaDB dan kompatibel MySQL.
--   - Tidak memakai DELIMITER atau stored procedure, jadi aman dengan runner
--     update yang memecah file per tanda titik-koma.
-- PENTING: jangan menaruh karakter titik-koma di dalam komentar mana pun,
--   karena runner akan salah memecah statement.
-- Cara menjalankan: import via phpMyAdmin atau mysql CLI, atau lewat Update Manager.
-- ========================================================================

-- 1) Perluas ENUM status (idempotent), menambah nilai pending_activation.
ALTER TABLE `license_info`
  MODIFY COLUMN `status` ENUM('active','expired','suspended','unknown','pending_activation') NOT NULL DEFAULT 'unknown';

-- 2) Kolom signed_token (TEXT). Token bertanda tangan terakhir dari server.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'license_info'
               AND COLUMN_NAME = 'signed_token');
SET @sql := IF(@col = 0,
  'ALTER TABLE `license_info` ADD COLUMN `signed_token` TEXT NULL AFTER `raw_response`',
  'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) Kolom token_valid_until (TIMESTAMP). Cache valid_until dari payload token.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'license_info'
               AND COLUMN_NAME = 'token_valid_until');
SET @sql := IF(@col = 0,
  'ALTER TABLE `license_info` ADD COLUMN `token_valid_until` TIMESTAMP NULL DEFAULT NULL AFTER `signed_token`',
  'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4) Kolom plan_effective. Plan dari payload token (sumber kebenaran).
--    Kolom plan lama menjadi cache tampilan saja. Termasuk nilai lifetime.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'license_info'
               AND COLUMN_NAME = 'plan_effective');
SET @sql := IF(@col = 0,
  'ALTER TABLE `license_info` ADD COLUMN `plan_effective` ENUM(''monthly'',''quarterly'',''yearly'',''lifetime'') NULL DEFAULT NULL AFTER `plan`',
  'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5) Kolom activation_pending_reason. Alasan singkat saat status pending_activation
--    (misalnya Timeout menghubungi server lisensi saat instalasi).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'license_info'
               AND COLUMN_NAME = 'activation_pending_reason');
SET @sql := IF(@col = 0,
  'ALTER TABLE `license_info` ADD COLUMN `activation_pending_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `status`',
  'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

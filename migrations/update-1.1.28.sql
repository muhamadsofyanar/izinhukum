-- ========================================================================
-- Update 1.1.28
-- ------------------------------------------------------------------------
-- Perubahan 1.1.28 = KODE (fix redirect auth checkout + banner update). Tak ada skema baru, sync defensif.
-- Tidak ada skema baru. SQL ini SELF-CONTAINED & IDEMPOTEN: menyamakan skema
-- ke kondisi terkini (fold-in migrasi license-hardening, lead-magnet, dll).
-- WAJIB self-contained karena apply-update client hanya menjalankan migrasi
-- versi TARGET — bila customer lompat versi, migrasi antara terlewat.
-- Aman di-re-run. Tanpa DELIMITER/stored procedure. Jangan taruh titik-koma
-- di dalam komentar.
-- ========================================================================

-- ── license_info: enum + kolom hardening ─────────────────────────────
ALTER TABLE `license_info`
  MODIFY COLUMN `plan` ENUM('monthly','quarterly','yearly','lifetime') DEFAULT NULL;
ALTER TABLE `license_info`
  MODIFY COLUMN `status` ENUM('active','expired','suspended','unknown','pending_activation') NOT NULL DEFAULT 'unknown';

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'license_info' AND COLUMN_NAME = 'plan_effective');
SET @s := IF(@c = 0,
  'ALTER TABLE `license_info` ADD COLUMN `plan_effective` ENUM(''monthly'',''quarterly'',''yearly'',''lifetime'') NULL DEFAULT NULL AFTER `plan`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'license_info' AND COLUMN_NAME = 'activation_pending_reason');
SET @s := IF(@c = 0,
  'ALTER TABLE `license_info` ADD COLUMN `activation_pending_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `status`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'license_info' AND COLUMN_NAME = 'signed_token');
SET @s := IF(@c = 0,
  'ALTER TABLE `license_info` ADD COLUMN `signed_token` TEXT NULL AFTER `raw_response`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'license_info' AND COLUMN_NAME = 'token_valid_until');
SET @s := IF(@c = 0,
  'ALTER TABLE `license_info` ADD COLUMN `token_valid_until` TIMESTAMP NULL DEFAULT NULL AFTER `signed_token`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- ── ai_chat_rate_limits: tabel bila belum ada ────────────────────────
CREATE TABLE IF NOT EXISTS `ai_chat_rate_limits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `request_count` int(10) unsigned NOT NULL DEFAULT 1,
  `window_start` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── classes: product_id nullable + lead_magnet_id + index + FK ────────
ALTER TABLE `classes`
  MODIFY COLUMN `product_id` int(10) unsigned DEFAULT NULL;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classes' AND COLUMN_NAME = 'lead_magnet_id');
SET @s := IF(@c = 0,
  'ALTER TABLE `classes` ADD COLUMN `lead_magnet_id` int(10) unsigned DEFAULT NULL AFTER `product_id`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classes' AND INDEX_NAME = 'idx_classes_lead_magnet');
SET @s := IF(@c = 0,
  'ALTER TABLE `classes` ADD KEY `idx_classes_lead_magnet` (`lead_magnet_id`)',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classes'
             AND CONSTRAINT_NAME = 'fk_classes_lead_magnet' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @s := IF(@c = 0,
  'ALTER TABLE `classes` ADD CONSTRAINT `fk_classes_lead_magnet` FOREIGN KEY (`lead_magnet_id`) REFERENCES `lead_magnets` (`id`) ON DELETE CASCADE',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- ── email_sequence_steps: kolom body_format ──────────────────────────
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_sequence_steps' AND COLUMN_NAME = 'body_format');
SET @s := IF(@c = 0,
  'ALTER TABLE `email_sequence_steps` ADD COLUMN `body_format` ENUM(''text'',''html'') NOT NULL DEFAULT ''text'' AFTER `body`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- ── materials: enum content_type tambah html ─────────────────────────
ALTER TABLE `materials`
  MODIFY COLUMN `content_type` ENUM('text','video','file','link','embed','html') NOT NULL DEFAULT 'text';

-- ── settings: setting_value mediumtext ───────────────────────────────
ALTER TABLE `settings`
  MODIFY COLUMN `setting_value` MEDIUMTEXT DEFAULT NULL;

-- ── otp_codes: kode OTP 6 digit (varchar 4 ke 6, aman MySQL 8 strict) ──
ALTER TABLE `otp_codes` MODIFY COLUMN `code` VARCHAR(6) NOT NULL;

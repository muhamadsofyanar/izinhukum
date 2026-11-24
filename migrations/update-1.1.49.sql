-- Averion v1.1.49 migrasi
-- Auto-Backup Database (tanpa perubahan skema, fitur pakai settings + file)
-- 2FA TOTP (Google Authenticator dkk) untuk login admin/staff:
--   kolom totp di users + tabel trusted device (fitur "ingat perangkat 30 hari")
-- Idempoten dan MySQL 8-safe (runner per-statement, duplikat pada re-run diabaikan)

ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL AFTER password;
ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret;
ALTER TABLE users ADD COLUMN totp_backup_codes TEXT NULL AFTER totp_enabled;

CREATE TABLE IF NOT EXISTS user_trusted_devices (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  user_id int(10) unsigned NOT NULL,
  token_hash char(64) NOT NULL,
  user_agent varchar(255) DEFAULT NULL,
  expires_at datetime NOT NULL,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uk_utd_token (token_hash),
  KEY idx_utd_user (user_id),
  CONSTRAINT fk_utd_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

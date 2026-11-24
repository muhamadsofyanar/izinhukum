-- Averion v1.1.95 migrasi
-- Batch 2 audit jalur transaksi:
-- (1) Fitur Lupa/Reset Password — tabel password_resets (token reset sekali
--     pakai, disimpan sebagai hash SHA-256, kedaluwarsa 1 jam). Sebelumnya
--     fitur reset TIDAK ADA sama sekali padahal pesan rate-limit login
--     menyuruh "reset password" — guest buyer yang kehilangan temp password
--     terkunci permanen dari produk berbayar.
-- (2) Index ip_address di login_attempts — dipakai rate limit agregat per-IP
--     (anti password-spraying) & rate limit register/checkout publik per-IP.
-- Idempoten: CREATE TABLE IF NOT EXISTS + ADD KEY dibungkus cek
-- information_schema + PREPARE (pola standar migrasi project ini).

CREATE TABLE IF NOT EXISTS password_resets (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  user_id int(10) unsigned NOT NULL,
  token_hash char(64) NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uk_pr_token (token_hash),
  KEY idx_pr_user (user_id),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'idx_la_ip') = 0,
  'ALTER TABLE login_attempts ADD KEY idx_la_ip (ip_address, created_at)',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

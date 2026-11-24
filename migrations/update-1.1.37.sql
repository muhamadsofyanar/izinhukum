-- Averion migration 1.1.37
-- Moota API Server Key V2 = JWT yang sering > 255 char. Kolom VARCHAR(255) memotong
-- token diam-diam di MySQL/MariaDB non-strict sehingga tersimpan tidak utuh dan
-- Moota menolak dengan HTTP 401 (terasa "key salah" padahal benar). Lebarkan ke TEXT
-- agar muat token panjang. Diadopsi dari averion-license (migration-gateway-key-length).
-- MODIFY aman untuk MySQL 8 dan idempoten (menjalankan ulang tak berpengaruh).
ALTER TABLE `payment_gateways`
  MODIFY COLUMN `api_key` TEXT NULL,
  MODIFY COLUMN `callback_token` TEXT NULL;

-- ========================================================================
-- Update 1.1.23 — naikkan settings.setting_value ke MEDIUMTEXT
-- Agar System Prompt AI Chat yang panjang tidak terpotong (TEXT 65KB -> 16MB).
-- Aman MySQL 8 & MariaDB. MODIFY idempotent (menjalankan ulang tidak merusak data).
-- PENTING: jangan taruh karakter titik-koma di dalam komentar mana pun
--   (runner memecah statement pakai titik-koma).
-- ========================================================================

ALTER TABLE `settings`
  MODIFY COLUMN `setting_value` MEDIUMTEXT NULL DEFAULT NULL;

-- update-1.2.147.sql
-- Fitur berhenti berlangganan email promosi (unsubscribe) per-user.
-- Tambah kolom users.email_opt_out (0 = terima email promo/broadcast, 1 = berhenti).
-- Hanya memengaruhi broadcast EMAIL; email transaksional (konfirmasi order, akses)
-- tetap terkirim. Idempoten via information_schema. JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_opt_out');
SET @s := IF(@c = 0,
  'ALTER TABLE `users` ADD COLUMN `email_opt_out` TINYINT(1) NOT NULL DEFAULT 0 AFTER `email_verified_at`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

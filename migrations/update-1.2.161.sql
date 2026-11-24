-- update-1.2.161.sql
-- Anti notifikasi DOBEL: tambah kolom notification_logs.dedup_key untuk idempotensi
-- notif transaksional per order (order_number). Dispatcher memakai kolom ini agar
-- satu order tak mengirim notif yang sama 2-4x saat ada race webhook/callback ganda.
-- Idempoten via information_schema. JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification_logs' AND COLUMN_NAME = 'dedup_key');
SET @s := IF(@c = 0,
  'ALTER TABLE `notification_logs` ADD COLUMN `dedup_key` VARCHAR(191) NULL AFTER `recipient`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification_logs' AND INDEX_NAME = 'idx_notiflog_dedup');
SET @s := IF(@i = 0,
  'ALTER TABLE `notification_logs` ADD INDEX `idx_notiflog_dedup` (`dedup_key`, `event_key`, `channel`)',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

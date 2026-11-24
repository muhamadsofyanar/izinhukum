-- update-1.2.163.sql
-- Fitur Poin Reward (loyalitas): member dapat poin tiap transaksi LUNAS, lalu poin
-- bisa ditukar jadi potongan harga di checkout login.
-- Sumber kebenaran = buku besar point_transactions (append-only, bisa diaudit).
-- users.points_balance hanya cache saldo agar pembacaan cepat.
-- Idempoten via information_schema. JANGAN taruh titik-koma di komentar.

CREATE TABLE IF NOT EXISTS `point_transactions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `order_id` int(10) unsigned DEFAULT NULL,
  `type` enum('earn','redeem','reverse_earn','refund_redeem','adjust') NOT NULL,
  `points` int(11) NOT NULL,
  `balance_after` int(11) NOT NULL DEFAULT 0,
  `note` varchar(191) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_point_order_type` (`order_id`,`type`),
  KEY `idx_point_user` (`user_id`,`created_at`),
  CONSTRAINT `fk_point_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'points_balance');
SET @s := IF(@c = 0,
  'ALTER TABLE `users` ADD COLUMN `points_balance` INT(11) NOT NULL DEFAULT 0 AFTER `email_opt_out`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'points_used');
SET @s := IF(@c = 0,
  'ALTER TABLE `orders` ADD COLUMN `points_used` INT(11) NOT NULL DEFAULT 0 AFTER `discount`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'points_discount');
SET @s := IF(@c = 0,
  'ALTER TABLE `orders` ADD COLUMN `points_discount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `points_used`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`)
VALUES ('points_enabled', '0', 'points') ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`)
VALUES ('points_label', 'Poin', 'points') ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`)
VALUES ('points_menu_label', 'Reward Saya', 'points') ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`)
VALUES ('points_earn_per_amount', '1000', 'points') ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`)
VALUES ('points_value', '50', 'points') ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`)
VALUES ('points_min_redeem', '100', 'points') ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`)
VALUES ('points_max_discount_percent', '50', 'points') ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

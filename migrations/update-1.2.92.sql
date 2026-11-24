-- update-1.2.92.sql
-- Lead Magnet di homepage: kolom show_on_homepage (opt-in per magnet, default 0).
-- Idempoten via information_schema + PREPARE (MySQL 8 & MariaDB, tanpa DELIMITER).
-- JANGAN taruh tanda titik-koma di dalam komentar (runner memecah per titik-koma).

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_magnets'
               AND COLUMN_NAME = 'show_on_homepage');
SET @sql := IF(@col = 0,
  'ALTER TABLE `lead_magnets` ADD COLUMN `show_on_homepage` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`',
  'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

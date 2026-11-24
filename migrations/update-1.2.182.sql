-- update-1.2.182.sql
-- Grup Media: upload massal ke satu grup, lalu salin semua URL sekaligus.
-- Tabel media_groups + kolom media_files.group_id (nullable). Tanpa FK — pelepasan
-- grup (ungroup) saat grup dihapus ditangani di actions/admin/media-group.php
-- (UPDATE group_id=NULL dulu). Idempoten via IF NOT EXISTS + information_schema +
-- PREPARE. JANGAN taruh titik-koma di komentar.

CREATE TABLE IF NOT EXISTS `media_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media_files'
             AND COLUMN_NAME = 'group_id');
SET @s := IF(@c = 0,
  'ALTER TABLE `media_files` ADD COLUMN `group_id` INT(10) UNSIGNED DEFAULT NULL AFTER `uploaded_by`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @k := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media_files'
             AND INDEX_NAME = 'idx_media_group');
SET @s := IF(@k = 0,
  'ALTER TABLE `media_files` ADD KEY `idx_media_group` (`group_id`)',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

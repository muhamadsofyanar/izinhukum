-- update-1.2.258.sql
-- AI Chat per-canvas: kolom ai_chat_enabled pada canvas_pages. Saklar per-halaman
-- (default MATI) menentukan apakah widget AI Chat tampil di Canvas tersebut. Widget
-- hanya muncul bila master global ai_chat_enabled juga menyala. Idempoten via
-- information_schema. JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'canvas_pages' AND COLUMN_NAME = 'ai_chat_enabled');
SET @s := IF(@c = 0,
  'ALTER TABLE `canvas_pages` ADD COLUMN `ai_chat_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `tiktok_pixel_id`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

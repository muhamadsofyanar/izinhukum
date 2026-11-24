-- update-1.2.149.sql
-- Canvas tipe "Redirect": short-link/cloaking ke URL lain (mis. link affiliate).
-- Tambah nilai enum page_type='redirect' + kolom redirect_url + redirect_keep_query
-- (teruskan query string ke target, default ON). Redirect 302; bila pixel diisi,
-- render interstitial fire pixel lalu redirect. Idempoten. JANGAN taruh ';' di komentar.

ALTER TABLE `canvas_pages`
  MODIFY `page_type` enum('standalone','homepage','product_landing','rotator','lead_magnet_landing','redirect') NOT NULL DEFAULT 'standalone';

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'canvas_pages' AND COLUMN_NAME = 'redirect_url');
SET @s := IF(@c = 0, 'ALTER TABLE `canvas_pages` ADD COLUMN `redirect_url` VARCHAR(2048) DEFAULT NULL AFTER `tiktok_pixel_id`', 'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'canvas_pages' AND COLUMN_NAME = 'redirect_keep_query');
SET @s := IF(@c = 0, 'ALTER TABLE `canvas_pages` ADD COLUMN `redirect_keep_query` TINYINT(1) NOT NULL DEFAULT 1 AFTER `redirect_url`', 'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

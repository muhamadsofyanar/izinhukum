-- update-1.2.114.sql
-- Fitur: Canvas bisa ditautkan ke LEAD MAGNET (mirror penaut produk) → jadi
-- landing page kaya untuk lead magnet; affiliate dapat link Canvas ber-?ref=.
-- Tambah kolom canvas_pages.linked_lead_magnet_id (FK lead_magnets ON DELETE SET
-- NULL) + nilai enum page_type 'lead_magnet_landing'. Idempoten via information_schema.
-- JANGAN pakai ADD COLUMN IF NOT EXISTS (MySQL 8 menolak). JANGAN titik-koma di komentar.

-- 1) Kolom linked_lead_magnet_id.
SET @hasCol := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'canvas_pages'
                  AND COLUMN_NAME = 'linked_lead_magnet_id');
SET @s1 := IF(@hasCol = 0,
  'ALTER TABLE `canvas_pages` ADD COLUMN `linked_lead_magnet_id` int(10) unsigned DEFAULT NULL AFTER `linked_product_id`',
  'DO 1');
PREPARE st1 FROM @s1;
EXECUTE st1;
DEALLOCATE PREPARE st1;

-- 2) Foreign key ke lead_magnets (ON DELETE SET NULL).
SET @hasFk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'canvas_pages'
                 AND CONSTRAINT_NAME = 'fk_canvas_lead_magnet');
SET @s2 := IF(@hasFk = 0,
  'ALTER TABLE `canvas_pages` ADD CONSTRAINT `fk_canvas_lead_magnet` FOREIGN KEY (`linked_lead_magnet_id`) REFERENCES `lead_magnets` (`id`) ON DELETE SET NULL',
  'DO 1');
PREPARE st2 FROM @s2;
EXECUTE st2;
DEALLOCATE PREPARE st2;

-- 3) Nilai enum page_type 'lead_magnet_landing'.
SET @hasEnum := (SELECT LOCATE('lead_magnet_landing', COLUMN_TYPE) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'canvas_pages'
                   AND COLUMN_NAME = 'page_type');
SET @s3 := IF(@hasEnum = 0,
  'ALTER TABLE `canvas_pages` MODIFY COLUMN `page_type` ENUM(''standalone'',''homepage'',''product_landing'',''rotator'',''lead_magnet_landing'') NOT NULL DEFAULT ''standalone''',
  'DO 1');
PREPARE st3 FROM @s3;
EXECUTE st3;
DEALLOCATE PREPARE st3;

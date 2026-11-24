-- update-1.2.210.sql
-- Landing Builder: tambah mode 'builder' ke canvas_pages (block engine visual no-code).
-- Data builder = JSON envelope {layout,theme,blocks} di kolom content (tanpa tabel baru).
-- Idempoten via information_schema + PREPARE. JANGAN taruh titik-koma di komentar.

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'canvas_pages'
               AND COLUMN_NAME = 'mode' AND COLUMN_TYPE LIKE '%builder%');
SET @s := IF(@has = 0,
  'ALTER TABLE `canvas_pages` MODIFY COLUMN `mode` enum(''upload'',''embed'',''builder'') NOT NULL DEFAULT ''upload''',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

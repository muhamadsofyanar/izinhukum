-- update-1.2.252.sql
-- Hermes Agent (broadcast_ingest): kolom external_ref pada broadcast_campaigns sebagai
-- kunci idempotensi dari sistem eksternal (Hermes). UNIQUE agar satu external_ref hanya
-- memetakan ke satu campaign (retry aman; campaign admin = external_ref NULL, boleh banyak
-- NULL). Idempoten via information_schema. JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'broadcast_campaigns' AND COLUMN_NAME = 'external_ref');
SET @s := IF(@c = 0,
  'ALTER TABLE `broadcast_campaigns` ADD COLUMN `external_ref` VARCHAR(191) DEFAULT NULL AFTER `created_at`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'broadcast_campaigns' AND INDEX_NAME = 'uk_broadcast_external_ref');
SET @s := IF(@i = 0,
  'ALTER TABLE `broadcast_campaigns` ADD UNIQUE KEY `uk_broadcast_external_ref` (`external_ref`)',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

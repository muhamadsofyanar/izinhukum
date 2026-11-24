-- update-1.2.236.sql
-- Hermes Agent: Ingest Funnel Gate (funnel_gate_ingest). Kolom external_ref pada
-- funnel_gate_forms sebagai kunci idempotensi dari sistem eksternal (Hermes). UNIQUE
-- agar satu external_ref hanya memetakan ke satu form (retry aman; form yang dibuat
-- admin = external_ref NULL, boleh banyak NULL). Idempoten via information_schema.
-- JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funnel_gate_forms' AND COLUMN_NAME = 'external_ref');
SET @s := IF(@c = 0,
  'ALTER TABLE `funnel_gate_forms` ADD COLUMN `external_ref` VARCHAR(191) DEFAULT NULL AFTER `slug`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funnel_gate_forms' AND INDEX_NAME = 'uk_fgf_external_ref');
SET @s := IF(@i = 0,
  'ALTER TABLE `funnel_gate_forms` ADD UNIQUE KEY `uk_fgf_external_ref` (`external_ref`)',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

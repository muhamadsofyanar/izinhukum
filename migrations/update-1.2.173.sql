-- update-1.2.173.sql
-- Hermes Agent Fase 2 (email_sequence_ingest): kolom external_ref pada email_sequences
-- sebagai kunci idempotensi dari sistem eksternal (Hermes). UNIQUE agar satu external_ref
-- hanya memetakan ke satu sequence (retry aman; sequence admin = external_ref NULL, boleh
-- banyak NULL). Idempoten via information_schema. JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_sequences' AND COLUMN_NAME = 'external_ref');
SET @s := IF(@c = 0,
  'ALTER TABLE `email_sequences` ADD COLUMN `external_ref` VARCHAR(191) DEFAULT NULL AFTER `provider`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_sequences' AND INDEX_NAME = 'uk_email_seq_external_ref');
SET @s := IF(@i = 0,
  'ALTER TABLE `email_sequences` ADD UNIQUE KEY `uk_email_seq_external_ref` (`external_ref`)',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

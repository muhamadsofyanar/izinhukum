-- Averion v1.1.64 migrasi
-- AI Sales Assistant Fase 1: kolom products.ai_knowledge — catatan jualan
-- per produk (poin manfaat, untuk siapa, jawaban keberatan umum) yang
-- diinjeksikan ke konteks AI Chat. Drop kondisional pola information_schema
-- + PREPARE agar idempotent murni di runner versi mana pun.

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'ai_knowledge') = 0,
  'ALTER TABLE products ADD COLUMN ai_knowledge TEXT NULL AFTER description',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

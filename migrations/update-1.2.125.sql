-- update-1.2.125.sql
-- Fitur: atribusi affiliate pada Leads Lead Magnet. Tambah leads.affiliate_id
-- (FK affiliates ON DELETE SET NULL) — dicatat saat lead masuk dari cookie ref.
-- Idempoten via information_schema. JANGAN pakai ADD COLUMN IF NOT EXISTS (MySQL 8 menolak).

-- 1) Kolom affiliate_id.
SET @hasCol := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'
                  AND COLUMN_NAME = 'affiliate_id');
SET @s1 := IF(@hasCol = 0,
  'ALTER TABLE `leads` ADD COLUMN `affiliate_id` int(10) unsigned DEFAULT NULL AFTER `promoted_user_id`',
  'DO 1');
PREPARE st1 FROM @s1;
EXECUTE st1;
DEALLOCATE PREPARE st1;

-- 2) Index (untuk join/filter).
SET @hasIdx := (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'
                  AND INDEX_NAME = 'idx_leads_affiliate');
SET @s2 := IF(@hasIdx = 0,
  'ALTER TABLE `leads` ADD KEY `idx_leads_affiliate` (`affiliate_id`)',
  'DO 1');
PREPARE st2 FROM @s2;
EXECUTE st2;
DEALLOCATE PREPARE st2;

-- 3) Foreign key ke affiliates (ON DELETE SET NULL).
SET @hasFk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'
                 AND CONSTRAINT_NAME = 'fk_leads_affiliate');
SET @s3 := IF(@hasFk = 0,
  'ALTER TABLE `leads` ADD CONSTRAINT `fk_leads_affiliate` FOREIGN KEY (`affiliate_id`) REFERENCES `affiliates` (`id`) ON DELETE SET NULL',
  'DO 1');
PREPARE st3 FROM @s3;
EXECUTE st3;
DEALLOCATE PREPARE st3;

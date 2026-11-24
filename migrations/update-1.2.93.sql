-- update-1.2.93.sql
-- Lead Magnet tripwire: produk berbayar opsional yang ditawarkan di thank-you opt-in.
-- tripwire_product_id = INT biasa (tanpa FK DB) → integritas divalidasi di app
-- (render pakai JOIN + cek status aktif). Idempoten via information_schema + PREPARE.
-- JANGAN taruh tanda titik-koma di dalam komentar (runner memecah per titik-koma).

SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_magnets'
              AND COLUMN_NAME = 'tripwire_product_id');
SET @s1 := IF(@c1 = 0,
  'ALTER TABLE `lead_magnets` ADD COLUMN `tripwire_product_id` INT(10) UNSIGNED NULL AFTER `show_on_homepage`',
  'DO 1');
PREPARE st1 FROM @s1;
EXECUTE st1;
DEALLOCATE PREPARE st1;

SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_magnets'
              AND COLUMN_NAME = 'tripwire_headline');
SET @s2 := IF(@c2 = 0,
  'ALTER TABLE `lead_magnets` ADD COLUMN `tripwire_headline` VARCHAR(200) NULL AFTER `tripwire_product_id`',
  'DO 1');
PREPARE st2 FROM @s2;
EXECUTE st2;
DEALLOCATE PREPARE st2;

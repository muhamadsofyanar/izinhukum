-- Averion v1.1.56 migrasi
-- Seragamkan skema product_upsells: fresh install v1.1.54-1.1.55 mendapat 2
-- FOREIGN KEY dari stifin-install.sql yang tidak pernah ada di migrasi update-1.1.54
-- (client upgrade tidak memilikinya). FK dihapus agar semua instalasi identik.
-- stifin-install.sql v1.1.56 juga sudah tanpa FK.
-- Pola drop KONDISIONAL via information_schema + PREPARE: tiap statement
-- sukses di instalasi manapun (dengan/tanpa FK), aman untuk runner lama yang
-- tidak menoleransi error, dan portabel MariaDB / MySQL 8.

SET @sql = (SELECT IF(COUNT(*) > 0,
  'ALTER TABLE product_upsells DROP FOREIGN KEY fk_pu_main',
  'DO 0')
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'product_upsells'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_pu_main');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) > 0,
  'ALTER TABLE product_upsells DROP FOREIGN KEY fk_pu_upsell',
  'DO 0')
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'product_upsells'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_pu_upsell');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Kategori etalase opsional per produk. Tipe produk tetap menentukan perilaku lama.
-- Idempoten pada MySQL 8 dan MariaDB, tanpa mengubah data produk yang ada.
SET @kategori_kolom := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'catalog_category');
SET @kategori_sql := IF(@kategori_kolom = 0,
    'ALTER TABLE `products` ADD COLUMN `catalog_category` VARCHAR(80) DEFAULT NULL AFTER `type`',
    'DO 1');
PREPARE kategori_stmt FROM @kategori_sql;
EXECUTE kategori_stmt;
DEALLOCATE PREPARE kategori_stmt;

SET @kategori_indeks := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
      AND INDEX_NAME = 'idx_catalog_category');
SET @kategori_sql := IF(@kategori_indeks = 0,
    'ALTER TABLE `products` ADD INDEX `idx_catalog_category` (`catalog_category`)',
    'DO 1');
PREPARE kategori_stmt FROM @kategori_sql;
EXECUTE kategori_stmt;
DEALLOCATE PREPARE kategori_stmt;

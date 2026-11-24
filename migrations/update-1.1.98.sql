-- Averion v1.1.98 migrasi
--
-- (1) Funnel Analytics: relasi Lead Magnet → Produk.
-- Tanpa kolom ini, `leads` hanya tahu lead_magnet_id dan tak ada jalan
-- menghubungkan opt-in ke produk mana pun, sehingga funnel tak bisa memakai
-- lead magnet sebagai jalur masuk (cuma angka berdiri sendiri).
-- NULL = lead magnet tak tertaut produk (perilaku lama, tetap valid) → panel
-- Kontribusi Lead Magnet otomatis disembunyikan di funnel produk.
-- FK ON DELETE SET NULL: hapus produk TIDAK ikut menghapus lead magnet-nya.
SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_magnets' AND COLUMN_NAME = 'linked_product_id') = 0,
  'ALTER TABLE lead_magnets ADD COLUMN linked_product_id INT(10) UNSIGNED NULL DEFAULT NULL AFTER slug',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_magnets' AND INDEX_NAME = 'idx_lm_product') = 0,
  'ALTER TABLE lead_magnets ADD KEY idx_lm_product (linked_product_id)',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_magnets' AND CONSTRAINT_NAME = 'fk_lm_product') = 0,
  'ALTER TABLE lead_magnets ADD CONSTRAINT fk_lm_product FOREIGN KEY (linked_product_id) REFERENCES products (id) ON DELETE SET NULL',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- (2) Produk unlisted: sembunyikan dari katalog publik.
-- Catatan: '/' (homepage) DAN '/products' merender file yang sama
-- (pages/public/products.php), jadi flag ini sengaja berlaku untuk keduanya —
-- opsi "sembunyikan dari homepage saja" akan membingungkan karena produknya
-- tetap muncul di /products yang isinya identik.
-- 0 = tampil di katalog (perilaku lama, default aman untuk semua produk yang
-- sudah ada). 1 = tersembunyi dari katalog/statistik/spotlight, TAPI tetap bisa
-- diakses via link langsung /products/{slug}, checkout, & sales page Canvas.
SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'hide_from_catalog') = 0,
  'ALTER TABLE products ADD COLUMN hide_from_catalog TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

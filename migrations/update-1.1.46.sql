-- Averion v1.1.46 migrasi
-- Konten berbagi (shared): satu modul (class) bisa diakses banyak produk sekaligus
-- (many-to-many), agar konten yang sama tak perlu dibuat 2x untuk produk berbeda.
-- Owner utama tetap di classes.product_id; class_products = produk TAMBAHAN yang berbagi.
-- MySQL 8-safe & idempoten (CREATE TABLE IF NOT EXISTS; runner per-statement).
CREATE TABLE IF NOT EXISTS class_products (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  class_id int(10) unsigned NOT NULL,
  product_id int(10) unsigned NOT NULL,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uk_class_product (class_id, product_id),
  KEY idx_cp_product (product_id),
  CONSTRAINT fk_cp_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE,
  CONSTRAINT fk_cp_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

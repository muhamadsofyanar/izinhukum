-- Averion v1.1.54 migrasi
-- Post-purchase upsell: penawaran satu produk dengan harga spesial yang tampil
-- setelah pembayaran lunas (halaman sukses / detail transaksi). 1 penawaran per
-- produk pemicu (UNIQUE main_product_id). Idempoten (CREATE IF NOT EXISTS).
CREATE TABLE IF NOT EXISTS product_upsells (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  main_product_id   INT UNSIGNED NOT NULL COMMENT 'produk pemicu (yang baru dibeli)',
  upsell_product_id INT UNSIGNED NOT NULL COMMENT 'produk yang ditawarkan',
  upsell_price      DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'harga spesial penawaran',
  headline          VARCHAR(200) NULL,
  description       TEXT NULL,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_main (main_product_id),
  KEY idx_upsell_product (upsell_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

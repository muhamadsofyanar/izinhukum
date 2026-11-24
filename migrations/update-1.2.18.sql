-- Update 1.2.18 averion — JV Partner (bagi hasil penjualan dengan partner)
-- Satu partner (user) per produk dgn persen bagi hasil. Saat order produk itu LUNAS,
-- sistem mencatat jatah partner ke ledger jv_earnings secara otomatis -- basis hitung
-- total order dikurangi komisi affiliate order tsb. Pembayaran ke partner MANUAL oleh
-- admin (transfer sendiri), sistem hanya mencatat status. Uang tetap masuk rekening
-- pemilik instalasi (rel pembayaran ID tidak mendukung split dana ala PayPal).
ALTER TABLE products
  ADD COLUMN jv_partner_user_id INT UNSIGNED NULL DEFAULT NULL AFTER dime_sold_count,
  ADD COLUMN jv_partner_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER jv_partner_user_id;

CREATE TABLE IF NOT EXISTS jv_earnings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  partner_user_id INT UNSIGNED NOT NULL,
  order_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  affiliate_commission DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  status ENUM('unpaid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  paid_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jv_order (order_id),
  KEY idx_jv_partner (partner_user_id, status),
  KEY idx_jv_product (product_id),
  CONSTRAINT fk_jv_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_jv_partner FOREIGN KEY (partner_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

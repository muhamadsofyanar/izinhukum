-- Update 1.2.38 averion -- Canvas landing bisa menaut lebih dari satu produk
-- Junction canvas_pages <-> products. Kolom linked_product_id tetap dipakai
-- sebagai produk "utama" (backward-compat + FK lama). Funnel lp_view dicatat
-- untuk SEMUA produk tertaut. CREATE IF NOT EXISTS + INSERT IGNORE -- aman diulang.
CREATE TABLE IF NOT EXISTS canvas_page_products (
  canvas_page_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (canvas_page_id, product_id),
  KEY idx_cpp_product (product_id),
  CONSTRAINT fk_cpp_canvas FOREIGN KEY (canvas_page_id) REFERENCES canvas_pages (id) ON DELETE CASCADE,
  CONSTRAINT fk_cpp_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill dari data lama (canvas product_landing yang sudah punya 1 produk).
INSERT IGNORE INTO canvas_page_products (canvas_page_id, product_id)
SELECT id, linked_product_id FROM canvas_pages
WHERE linked_product_id IS NOT NULL AND page_type = 'product_landing';

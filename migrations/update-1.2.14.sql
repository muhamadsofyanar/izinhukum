-- Update 1.2.14 averion — Dime Sale (harga naik bertahap seiring penjualan)
-- Properti harga opsional pada produk fixed: tiap kelipatan dime_step_qty penjualan LUNAS,
-- harga bertambah dime_increment (dibatasi dime_max_price bila di-set). Counter
-- dime_sold_count NAIK-SAJA (hanya bertambah saat order lunas, tak pernah turun saat
-- refund) demi harga yang monotonik. Harga efektif dihitung di aplikasi, bukan disimpan.
-- Produk lama otomatis: dime_enabled 0 (tak terpengaruh).
ALTER TABLE products
  ADD COLUMN dime_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER booking_deadline_days,
  ADD COLUMN dime_step_qty INT NOT NULL DEFAULT 10 AFTER dime_enabled,
  ADD COLUMN dime_increment DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER dime_step_qty,
  ADD COLUMN dime_max_price DECIMAL(15,2) NULL DEFAULT NULL AFTER dime_increment,
  ADD COLUMN dime_sold_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER dime_max_price;

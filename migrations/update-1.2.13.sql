-- Update 1.2.13 averion — Harga pendamping Ringgit (display-only) + bahasa label produk
-- Menambah harga alias RM (opsional, di-set manual vendor) yang TAMPIL di samping harga
-- Rupiah untuk pembeli Malaysia (mis. "Rp 57.000 / RM 13"). MURNI TAMPILAN: transaksi
-- tetap 100 persen Rupiah lewat gateway existing, TANPA konversi kurs, TANPA sentuh
-- checkout/order/gateway. Kolom language opsional hanya mengganti label CTA di halaman
-- publik produk. Produk lama otomatis dapat price_myr NULL (tak tampil alias) + language id.
ALTER TABLE products
  ADD COLUMN price_myr DECIMAL(10,2) NULL DEFAULT NULL AFTER original_price,
  ADD COLUMN language ENUM('id','ms') NOT NULL DEFAULT 'id' AFTER price_myr;

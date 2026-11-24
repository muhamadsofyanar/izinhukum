-- Update 1.2.11 averion — Bayar Fleksibel (pay-what-you-want / tip) Fase 1
-- Tipe harga baru pada produk: fixed (sekali bayar) atau flexible (pembeli tentukan
-- nominal). ORTOGONAL ke kolom `type` (kategori). Saat flexible: pembeli pilih
-- nominal di checkout (preset + custom), min opsional (0 = boleh gratis). Kupon,
-- order bump, setup fee, booking DP DIMATIKAN untuk produk flexible (ditangani di UI
-- & server, bukan skema). Nominal terpilih masuk sebagai subtotal/total order yang
-- sudah ada — tak perlu kolom order baru.
ALTER TABLE products
  ADD COLUMN pricing_type ENUM('fixed','flexible') NOT NULL DEFAULT 'fixed' AFTER type,
  ADD COLUMN flexible_label VARCHAR(60) NULL AFTER pricing_type,
  ADD COLUMN flexible_min DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER flexible_label,
  ADD COLUMN flexible_presets VARCHAR(255) NULL AFTER flexible_min;

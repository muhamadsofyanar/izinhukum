-- Update 1.2.12 averion — Bayar Fleksibel Fase 2: Halaman Dukung + Supporters Wall
-- Halaman publik turnkey (satu per situs, slug diatur admin, default /dukung) yang
-- memajang produk flexible sebagai kartu tip + dinding pendukung (Supporters Wall).
-- Reuse total mesin order/checkout/akses Fase 1. Pengaturan halaman (enable, slug, bio,
-- social, tampil-nominal) disimpan di tabel key-value `settings` (tanpa kolom baru).
-- Kolom baru di sini hanya untuk penanda produk + data pendukung per-order.
ALTER TABLE products
  ADD COLUMN show_on_support TINYINT(1) NOT NULL DEFAULT 0 AFTER flexible_presets;

-- Data pendukung per-order (diisi saat checkout produk flexible yang tampil di /dukung):
-- pesan opsional publik, opsi anonim, dan flag moderasi (admin sembunyikan pesan).
ALTER TABLE orders
  ADD COLUMN supporter_message VARCHAR(280) NULL AFTER notes,
  ADD COLUMN supporter_anonymous TINYINT(1) NOT NULL DEFAULT 0 AFTER supporter_message,
  ADD COLUMN supporter_message_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER supporter_anonymous;

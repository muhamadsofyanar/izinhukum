-- ════════════════════════════════════════════════════════════════
-- Averion update 1.2.79 — Redirect Setelah Bayar (per produk).
-- Setelah order lunas, pembeli diarahkan ke URL yang ditentukan admin,
-- mendukung placeholder data pembeli untuk handoff ke aplikasi eksternal.
-- Aman MySQL 8 (plain ADD COLUMN, runner menelan error 1060 duplikat kolom).
-- ════════════════════════════════════════════════════════════════

ALTER TABLE `products`
  ADD COLUMN `redirect_after_paid_url` varchar(500) DEFAULT NULL AFTER `external_program_code`;

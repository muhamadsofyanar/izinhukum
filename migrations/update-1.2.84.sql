-- ════════════════════════════════════════════════════════════════
-- Averion update 1.2.84 — Statistik Open/Click tracking Broadcast Email.
-- Pixel + link-wrap milik Averion sendiri (presisi per-broadcast+penerima).
-- Aman MySQL 8 (plain ADD COLUMN, runner menelan error 1060 duplikat kolom).
-- ════════════════════════════════════════════════════════════════

ALTER TABLE `broadcast_recipients`
  ADD COLUMN `opened_at` timestamp NULL DEFAULT NULL AFTER `error_message`;
ALTER TABLE `broadcast_recipients`
  ADD COLUMN `open_count` int(10) unsigned NOT NULL DEFAULT 0 AFTER `opened_at`;
ALTER TABLE `broadcast_recipients`
  ADD COLUMN `clicked_at` timestamp NULL DEFAULT NULL AFTER `open_count`;
ALTER TABLE `broadcast_recipients`
  ADD COLUMN `click_count` int(10) unsigned NOT NULL DEFAULT 0 AFTER `clicked_at`;

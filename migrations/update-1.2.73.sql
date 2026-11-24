-- ════════════════════════════════════════════════════════════════
-- Averion update 1.2.73 — Broadcast: kirim latar (cron/pump), kecualikan
-- pembeli produk, skip email gagal. Idempoten & aman MySQL 8.
-- Runner menelan errno 1060 (kolom sudah ada) → aman diulang.
-- ════════════════════════════════════════════════════════════════

-- Kolom baru broadcast_campaigns.
ALTER TABLE `broadcast_campaigns`
  ADD COLUMN `exclude_product_ids` varchar(255) DEFAULT NULL AFTER `target_lead_magnet_id`;
ALTER TABLE `broadcast_campaigns`
  ADD COLUMN `skip_failed_emails` tinyint(1) NOT NULL DEFAULT 0 AFTER `exclude_product_ids`;

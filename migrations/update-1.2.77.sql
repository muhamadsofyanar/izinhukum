-- ════════════════════════════════════════════════════════════════
-- Averion update 1.2.77 — Broadcast: target segment "Pembeli Produk".
-- Aman MySQL 8: MODIFY enum menambah nilai + ADD COLUMN (runner telan errno 1060).
-- ════════════════════════════════════════════════════════════════

ALTER TABLE `broadcast_campaigns`
  MODIFY `target_segment` enum('all_users','active_members','specific_level','custom_list','leads_all','leads_by_magnet','product_buyers') NOT NULL DEFAULT 'all_users';

ALTER TABLE `broadcast_campaigns`
  ADD COLUMN `target_product_ids` varchar(255) DEFAULT NULL AFTER `target_lead_magnet_id`;

-- ════════════════════════════════════════════════════════════════
-- Averion update 1.2.76 — Broadcast: status 'paused' (jeda & lanjut kirim).
-- Aman MySQL 8: MODIFY enum menambah nilai, nilai lama tetap.
-- ════════════════════════════════════════════════════════════════

ALTER TABLE `broadcast_campaigns`
  MODIFY `status` enum('draft','scheduled','sending','paused','sent','failed') NOT NULL DEFAULT 'draft';

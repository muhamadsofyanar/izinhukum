-- update-1.2.144.sql
-- Segmen broadcast berbasis engagement email: "Pernah buka" (email_openers) &
-- "Pernah klik link" (email_clickers). Penerima = alamat yang open/klik broadcast
-- email HTML (broadcast_recipients.opened_at/clicked_at), dedup per-email, gabung
-- user + leads. Tambah nilai enum target_segment (append di akhir, in-place cepat)
-- + kolom engagement_days (rentang waktu open/klik dalam N hari: NULL/0 = kapan saja).
-- Idempoten (MODIFY enum aman diulang, ADD COLUMN via information_schema).
-- JANGAN taruh titik-koma di komentar.

ALTER TABLE `broadcast_campaigns`
  MODIFY `target_segment` enum('all_users','active_members','specific_level','custom_list','leads_all','leads_by_magnet','product_buyers','email_openers','email_clickers') NOT NULL DEFAULT 'all_users';

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'broadcast_campaigns' AND COLUMN_NAME = 'engagement_days');
SET @s := IF(@c = 0,
  'ALTER TABLE `broadcast_campaigns` ADD COLUMN `engagement_days` SMALLINT(5) UNSIGNED DEFAULT NULL AFTER `target_product_ids`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

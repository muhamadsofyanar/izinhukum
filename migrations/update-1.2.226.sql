-- Averion update 1.2.226
-- Fitur "member sedang online": hitung member yang SEDANG membuka area member
-- secara realtime, tampil sebagai indikator "sedang online" di kartu Active
-- Members dashboard admin. Presence dicatat per-user lewat heartbeat tiap ~20
-- detik (upsert). "Online" = last_seen dalam 120 detik terakhir.
-- Idempoten: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `user_presence` (
  `user_id` int(10) unsigned NOT NULL,
  `last_seen` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  KEY `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

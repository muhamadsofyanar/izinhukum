-- ════════════════════════════════════════════════════════════════
-- Averion update 1.2.85 — Funnel "Pengunjung Aktif" akurat via heartbeat.
-- Halaman sales/checkout ping tiap ~20 dtk → presence "sekarang sedang buka".
-- Aman MySQL 8 (CREATE TABLE IF NOT EXISTS).
-- ════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `funnel_presence` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` varchar(128) NOT NULL,
  `product_id` int(10) unsigned NOT NULL DEFAULT 0,
  `bundle_id` int(10) unsigned NOT NULL DEFAULT 0,
  `stage` enum('salespage','checkout') NOT NULL DEFAULT 'salespage',
  `last_seen` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_presence` (`session_id`,`product_id`,`bundle_id`),
  KEY `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

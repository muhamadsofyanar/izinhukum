-- ════════════════════════════════════════════════════════════════
-- Averion update 1.2.78 — Integrasi Webhook Keluar (outgoing webhook).
-- Kirim event order.paid ber-HMAC ke aplikasi eksternal + antrean/retry.
-- Idempoten & aman MySQL 8 (CREATE TABLE IF NOT EXISTS, runner telan 1060).
-- ════════════════════════════════════════════════════════════════

-- Kode program eksternal per produk (Opsi A) — dipakai sebagai program_code webhook.
ALTER TABLE `products`
  ADD COLUMN `external_program_code` varchar(100) DEFAULT NULL AFTER `slug`;

-- Konfigurasi endpoint webhook keluar.
CREATE TABLE IF NOT EXISTS `outgoing_webhooks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `url` varchar(500) NOT NULL,
  `secret` varchar(80) NOT NULL,
  `event` varchar(50) NOT NULL DEFAULT 'order.paid',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ow_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Antrean & log pengiriman webhook (pola sama mailketing_queue/capi).
CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `webhook_id` int(10) unsigned NOT NULL,
  `event` varchar(50) NOT NULL,
  `payload` text NOT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `http_code` int(11) DEFAULT NULL,
  `response` varchar(500) DEFAULT NULL,
  `order_number` varchar(50) DEFAULT NULL,
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wd_status` (`status`,`next_attempt_at`),
  KEY `idx_wd_webhook` (`webhook_id`),
  CONSTRAINT `fk_wd_webhook` FOREIGN KEY (`webhook_id`) REFERENCES `outgoing_webhooks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

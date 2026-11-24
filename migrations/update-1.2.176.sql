-- Hermes Agent Fase 3: satu pekerjaan follow-up per order pending
-- request_id unik untuk retry API dan order_id unik untuk batas satu pengiriman
CREATE TABLE IF NOT EXISTS `hermes_followup_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `request_id` varchar(191) NOT NULL,
  `status` enum('followup_eligible','queued','processing','sent','failed','skipped_paid','skipped_expired','skipped_opt_out','skipped_cooldown') NOT NULL DEFAULT 'queued',
  `last_error` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `processed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hermes_followup_order` (`order_id`),
  UNIQUE KEY `uk_hermes_followup_request` (`request_id`),
  KEY `idx_hermes_followup_status` (`status`,`created_at`),
  CONSTRAINT `fk_hermes_followup_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

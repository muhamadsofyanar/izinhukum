-- Update 1.2.36 averion -- Antrean event funnel ke Meta CAPI (ViewContent/InitiateCheckout)
-- Dikirim asinkron via auto-cron (nol dampak page-load). Opt-in lewat setting
-- meta_full_funnel_enabled (default mati). CREATE IF NOT EXISTS -- aman diulang.
CREATE TABLE IF NOT EXISTS capi_event_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  platform VARCHAR(20) NOT NULL DEFAULT 'meta',
  event_name VARCHAR(50) NOT NULL,
  event_id VARCHAR(120) NOT NULL,
  payload TEXT NOT NULL,
  status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_capi_status (platform, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

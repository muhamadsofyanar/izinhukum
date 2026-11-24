-- Update 1.2.40 averion -- Integrasi Mailketing: auto add subscriber ke list
-- saat order dibuat & saat lunas. Job di-antre lalu dikirim via auto-cron
-- (nol dampak page-load). CREATE IF NOT EXISTS -- aman diulang.
CREATE TABLE IF NOT EXISTS mailketing_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event VARCHAR(20) NOT NULL,
  list_id VARCHAR(40) NOT NULL,
  email VARCHAR(255) NOT NULL,
  first_name VARCHAR(120) NULL DEFAULT NULL,
  last_name VARCHAR(120) NULL DEFAULT NULL,
  mobile VARCHAR(40) NULL DEFAULT NULL,
  status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_mkq_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stifin_test_registrations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  branch_code VARCHAR(32) NOT NULL,
  participant_name VARCHAR(160) NOT NULL,
  promoter_code VARCHAR(80) NOT NULL,
  scheduled_at DATETIME NULL,
  status ENUM('registered','scheduled','completed','cancelled') NOT NULL DEFAULT 'registered',
  official_reference VARCHAR(120) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY branch_status (branch_code, status),
  KEY branch_schedule (branch_code, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

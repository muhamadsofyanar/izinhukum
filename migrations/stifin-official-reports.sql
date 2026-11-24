CREATE TABLE IF NOT EXISTS stifin_official_report_rows (
    branch_code VARCHAR(40) NOT NULL,
    report_type VARCHAR(24) NOT NULL,
    source_ref VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    report_date DATE NOT NULL,
    promoter_code VARCHAR(40) NOT NULL,
    report_value VARCHAR(120) NOT NULL,
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (branch_code, report_type, source_ref),
    KEY report_period (branch_code, report_type, report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE stifin_official_report_rows
    ADD COLUMN IF NOT EXISTS period_only TINYINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE stifin_official_report_rows
    ADD COLUMN IF NOT EXISTS client_name VARCHAR(160) NULL,
    ADD COLUMN IF NOT EXISTS client_phone VARCHAR(40) NULL,
    ADD COLUMN IF NOT EXISTS client_email VARCHAR(254) NULL;

CREATE TABLE IF NOT EXISTS stifin_official_history (
    branch_code VARCHAR(40) NOT NULL PRIMARY KEY,
    next_month DATE NOT NULL,
    end_month DATE NOT NULL,
    status ENUM('pending', 'failed', 'complete') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    months_done INT UNSIGNED NOT NULL DEFAULT 0,
    results_seen INT UNSIGNED NOT NULL DEFAULT 0,
    retry_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE stifin_official_report_rows
    ADD COLUMN IF NOT EXISTS official_ref VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS sent_date DATE NULL;

ALTER TABLE stifin_official_history
    ADD COLUMN IF NOT EXISTS try_range TINYINT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS stifin_promoter_accounts (
    branch_code VARCHAR(32) NOT NULL,
    promoter_code VARCHAR(80) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (branch_code, promoter_code),
    UNIQUE KEY uk_stifin_promoter_account_user (user_id),
    CONSTRAINT fk_stifin_promoter_account_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

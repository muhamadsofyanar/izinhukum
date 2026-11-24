ALTER TABLE users ADD COLUMN referred_by_affiliate_id INT UNSIGNED DEFAULT NULL AFTER affiliate_code;

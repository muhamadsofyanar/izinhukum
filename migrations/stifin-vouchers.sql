CREATE TABLE IF NOT EXISTS stifin_voucher_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL UNIQUE,
    user_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    fulfilled_at DATETIME NULL,
    KEY stifin_pending (fulfilled_at, id),
    KEY stifin_user (user_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stifin_voucher_stock (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARBINARY(128) NOT NULL UNIQUE,
    queue_id BIGINT UNSIGNED NULL,
    KEY stifin_available (queue_id, id)
) ENGINE=InnoDB;

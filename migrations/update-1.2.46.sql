-- Update 1.2.46 averion -- Gateway PayPal (tagih USD). Tambah 'paypal' ke enum
-- provider + seed baris config. MODIFY enum aman MySQL 8/MariaDB, INSERT idempoten.

-- PayPal gateway -- tambah 'paypal' ke enum provider + seed baris config.
-- PayPal menagih USD (IDR bukan mata uang PayPal); nominal = total IDR / kurs (config_json).
-- MODIFY enum aman di MySQL 8 & MariaDB. INSERT ... ON DUPLICATE = idempoten.
ALTER TABLE payment_gateways
  MODIFY provider ENUM('duitku','xendit','moota','paypal') NOT NULL;

INSERT INTO payment_gateways (provider, is_active, mode)
VALUES ('paypal', 0, 'sandbox')
ON DUPLICATE KEY UPDATE provider = provider;

-- Averion v1.1.97 migrasi
-- Dukungan channel E-Wallet (GoPay/OVO/DANA/ShopeePay/LinkAja dkk) di
-- Pembayaran Manual — reuse tabel bank_accounts (SeaBank dkk tetap masuk
-- kategori 'bank' karena rekening beneran, bukan nomor HP wallet).
SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_accounts' AND COLUMN_NAME = 'channel_type') = 0,
  "ALTER TABLE bank_accounts ADD COLUMN channel_type ENUM('bank','ewallet') NOT NULL DEFAULT 'bank' AFTER bank_name",
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

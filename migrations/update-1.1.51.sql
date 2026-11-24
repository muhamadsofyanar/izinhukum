-- Averion v1.1.51 migrasi
-- Social Proof per produk: kolom products.sp_mode mengatur popup di halaman
-- produk & checkout produk tsb:
--   all = campur semua produk (perilaku lama, default)
--   own = hanya tampilkan pembelian produk ini
--   off = sembunyikan popup di halaman produk ini
-- Idempoten dan MySQL 8-safe (runner per-statement, duplikat pada re-run diabaikan)

ALTER TABLE products ADD COLUMN sp_mode ENUM('all','own','off') NOT NULL DEFAULT 'all' AFTER booking_deadline_days;

-- Rekening pencairan affiliate TERSIMPAN (disimpan sekali, prefill form payout)
ALTER TABLE affiliates ADD COLUMN bank_name VARCHAR(100) NULL AFTER total_withdrawn;
ALTER TABLE affiliates ADD COLUMN bank_account_no VARCHAR(50) NULL AFTER bank_name;
ALTER TABLE affiliates ADD COLUMN bank_account_name VARCHAR(100) NULL AFTER bank_account_no;

-- Footer badge "Powered by" opsional per template checkout (white-label)
ALTER TABLE checkout_templates ADD COLUMN footer_badge_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER show_coupon;
ALTER TABLE checkout_templates ADD COLUMN footer_badge_text VARCHAR(100) NULL AFTER footer_badge_enabled;
ALTER TABLE checkout_templates ADD COLUMN footer_badge_icon VARCHAR(500) NULL AFTER footer_badge_text;
ALTER TABLE checkout_templates ADD COLUMN footer_badge_url VARCHAR(500) NULL AFTER footer_badge_icon;

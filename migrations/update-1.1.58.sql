-- Averion v1.1.58 migrasi CATCH-UP self-contained
-- Mengulang seluruh perubahan skema v1.1.46 s.d. v1.1.56 untuk memulihkan
-- instalasi yang melompati versi memakai runner lama (pre-1.1.56) sehingga
-- migrasi di antaranya terlewat (gejala: 'Gagal memuat data produk' karena
-- tabel product_waitlist tak ada, dsb). Setiap statement idempotent MURNI
-- (ADD COLUMN / ADD KEY dibungkus cek information_schema + PREPARE) sehingga
-- aman dijalankan runner versi mana pun sejak 1.1.27 dan di instalasi sehat
-- hasilnya no-op. Digenerate otomatis dari file migrasi asli.

-- ═══════ dari update-1.1.46.sql ═══════
CREATE TABLE IF NOT EXISTS class_products (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  class_id int(10) unsigned NOT NULL,
  product_id int(10) unsigned NOT NULL,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uk_class_product (class_id, product_id),
  KEY idx_cp_product (product_id),
  CONSTRAINT fk_cp_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE,
  CONSTRAINT fk_cp_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════ dari update-1.1.47.sql ═══════
ALTER TABLE products MODIFY COLUMN status ENUM('active','inactive','draft','coming_soon') NOT NULL DEFAULT 'draft';

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'launch_at') = 0,
  'ALTER TABLE products ADD COLUMN launch_at DATETIME NULL AFTER status',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS product_waitlist (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  product_id int(10) unsigned NOT NULL,
  user_id int(10) unsigned DEFAULT NULL,
  name varchar(100) NOT NULL,
  whatsapp varchar(20) NOT NULL,
  email varchar(150) DEFAULT NULL,
  ip_address varchar(45) DEFAULT NULL,
  status enum('waiting','notified','converted') NOT NULL DEFAULT 'waiting',
  notified_at timestamp NULL DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uk_wl_product_wa (product_id, whatsapp),
  KEY idx_wl_status (status),
  CONSTRAINT fk_wl_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'booking_enabled') = 0,
  'ALTER TABLE products ADD COLUMN booking_enabled TINYINT(1) NOT NULL DEFAULT 0',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'booking_fee') = 0,
  'ALTER TABLE products ADD COLUMN booking_fee DECIMAL(15,2) NOT NULL DEFAULT 0',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'booking_deadline_days') = 0,
  'ALTER TABLE products ADD COLUMN booking_deadline_days INT NOT NULL DEFAULT 7',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'order_stage') = 0,
  'ALTER TABLE orders ADD COLUMN order_stage ENUM(''full'',''booking'',''settlement'') NOT NULL DEFAULT ''full''',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'parent_order_id') = 0,
  'ALTER TABLE orders ADD COLUMN parent_order_id INT UNSIGNED NULL',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'booking_fee') = 0,
  'ALTER TABLE orders ADD COLUMN booking_fee DECIMAL(15,2) NULL',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'due_at') = 0,
  'ALTER TABLE orders ADD COLUMN due_at DATETIME NULL',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'uk_orders_parent') = 0,
  'ALTER TABLE orders ADD UNIQUE KEY uk_orders_parent (parent_order_id)',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO notification_templates (event_key, channel, provider, subject, body, is_active) VALUES
('waitlist_joined','email','mailketing','Kamu masuk daftar tunggu {{product_name}} ✅','<p>Halo <strong>{{name}}</strong>,</p><p>Kamu berhasil masuk daftar tunggu <strong>{{product_name}}</strong>! ✅</p><p>Begitu produk ini resmi dibuka, kami akan langsung mengabari kamu lewat email dan WhatsApp. Pantau terus ya. 📩</p><p style="text-align:center;margin:28px 0"><a href="{{product_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Lihat Halaman Produk →</a></p>',1),
('waitlist_joined','whatsapp','starsender',NULL,'Halo {{name}} 👋\n\nKamu berhasil masuk daftar tunggu *{{product_name}}* ✅\n\nBegitu produk dibuka, kami langsung kabari kamu di sini.\n\nTim {{app_name}}',1),
('waitlist_launch','email','mailketing','{{product_name}} sudah dibuka! 🚀','<p>Halo <strong>{{name}}</strong>,</p><p>Kabar yang kamu tunggu: <strong>{{product_name}}</strong> sudah resmi dibuka! 🎉</p><p>Kamu termasuk yang paling awal tahu — amankan aksesmu sekarang sebelum kehabisan.</p><p style="text-align:center;margin:28px 0"><a href="{{product_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Amankan Akses Sekarang →</a></p><p style="font-size:13px;color:#8a94a6">Atau buka tautan ini: {{product_url}}</p>',1),
('waitlist_launch','whatsapp','starsender',NULL,'🚀 *{{product_name}} Sudah Dibuka!*\n\nHalo {{name}}, produk yang kamu tunggu sudah resmi rilis 🎉\n\nKamu termasuk yang paling awal tahu — amankan aksesmu sekarang:\n{{product_url}}\n\nTim {{app_name}}',1),
('booking_confirmed','email','mailketing','Booking kamu dikonfirmasi — slot {{product_name}} aman! 🔒','<p>Halo <strong>{{name}}</strong>,</p><p>Booking fee kamu untuk <strong>{{product_name}}</strong> sudah kami terima. Slot &amp; harga kamu <strong>terkunci</strong>! 🔒</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fb;border-radius:12px;margin:16px 0"><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">Booking Dibayar</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{booking_amount}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">Sisa Pelunasan</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{settlement_total}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;">Batas Pelunasan</td><td style="padding:12px 18px;font-size:14px;color:#b91c1c;font-weight:700;text-align:right;">{{due_date}}</td></tr></table><p style="text-align:center;margin:28px 0"><a href="{{settlement_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Bayar Pelunasan →</a></p><p style="font-size:13px;color:#8a94a6">Akses konten terbuka penuh setelah pelunasan. Lewat batas waktu, booking hangus.</p>',1),
('booking_confirmed','whatsapp','starsender',NULL,'🔒 *Booking Dikonfirmasi!*\n\nHalo {{name}}, booking fee kamu untuk *{{product_name}}* sudah kami terima — slot & harga kamu TERKUNCI 🎉\n\n💰 Booking dibayar: *{{booking_amount}}*\n💳 Sisa pelunasan: *{{settlement_total}}*\n⏰ Batas pelunasan: *{{due_date}}*\n\nBayar pelunasan di sini:\n{{settlement_url}}\n\nAkses konten terbuka penuh setelah lunas. Lewat batas waktu, booking hangus ya.\n\nTim {{app_name}}',1),
('booking_expired','email','mailketing','Batas pelunasan {{product_name}} terlewat ⚠️','<p>Halo <strong>{{name}}</strong>,</p><p>Sayang sekali — batas waktu pelunasan untuk <strong>{{product_name}}</strong> ({{due_date}}) sudah terlewat, sehingga booking kamu <strong>hangus</strong>. ⚠️</p><p>Masih berminat? Kamu tetap bisa memesan lagi kapan saja selama produk tersedia.</p><p style="text-align:center;margin:28px 0"><a href="{{product_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Pesan Lagi →</a></p>',1),
('booking_expired','whatsapp','starsender',NULL,'⚠️ *Booking Hangus*\n\nHalo {{name}}, batas waktu pelunasan untuk *{{product_name}}* ({{due_date}}) sudah terlewat, sehingga booking kamu hangus.\n\nMasih berminat? Kamu bisa pesan lagi di:\n{{product_url}}\n\nTim {{app_name}}',1);

-- ═══════ dari update-1.1.48.sql ═══════
SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classes' AND COLUMN_NAME = 'unlock_after_days') = 0,
  'ALTER TABLE classes ADD COLUMN unlock_after_days INT NOT NULL DEFAULT 0 AFTER sort_order',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'settlement_remind_stage') = 0,
  'ALTER TABLE orders ADD COLUMN settlement_remind_stage TINYINT NOT NULL DEFAULT 0 AFTER due_at',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE email_sequences MODIFY COLUMN trigger_event ENUM('order_paid','member_access_activated','user_register','waitlist_joined','booking_paid','lead_captured') NOT NULL DEFAULT 'order_paid';

INSERT IGNORE INTO notification_templates (event_key, channel, provider, subject, body, is_active) VALUES
('settlement_reminder','email','mailketing','Jangan sampai hangus — pelunasan {{product_name}} tinggal {{days_left}} hari lagi ⏰','<p>Halo <strong>{{name}}</strong>,</p><p>Batas waktu pelunasan booking kamu untuk <strong>{{product_name}}</strong> tinggal <strong>{{days_left}} hari</strong> lagi.</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fb;border-radius:12px;margin:16px 0"><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">Sisa Pelunasan</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{settlement_total}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;">Batas Pelunasan</td><td style="padding:12px 18px;font-size:14px;color:#b91c1c;font-weight:700;text-align:right;">{{due_date}}</td></tr></table><p>Lewat dari batas waktu, booking kamu <strong>hangus</strong> dan slot dilepas. Amankan sekarang:</p><p style="text-align:center;margin:28px 0"><a href="{{settlement_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Bayar Pelunasan Sekarang →</a></p>',1),
('settlement_reminder','whatsapp','starsender',NULL,'⏰ *Reminder Pelunasan*\n\nHalo {{name}}, batas waktu pelunasan *{{product_name}}* tinggal *{{days_left}} hari* lagi ({{due_date}}).\n\n💳 Sisa pelunasan: *{{settlement_total}}*\n\nBayar di sini sebelum hangus:\n{{settlement_url}}\n\nTim {{app_name}}',1);

-- ═══════ dari update-1.1.49.sql ═══════
SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_secret') = 0,
  'ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL AFTER password',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_enabled') = 0,
  'ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_backup_codes') = 0,
  'ALTER TABLE users ADD COLUMN totp_backup_codes TEXT NULL AFTER totp_enabled',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS user_trusted_devices (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  user_id int(10) unsigned NOT NULL,
  token_hash char(64) NOT NULL,
  user_agent varchar(255) DEFAULT NULL,
  expires_at datetime NOT NULL,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uk_utd_token (token_hash),
  KEY idx_utd_user (user_id),
  CONSTRAINT fk_utd_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════ dari update-1.1.50.sql ═══════
SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification_logs' AND COLUMN_NAME = 'subject') = 0,
  'ALTER TABLE notification_logs ADD COLUMN subject VARCHAR(255) NULL AFTER response',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification_logs' AND COLUMN_NAME = 'body') = 0,
  'ALTER TABLE notification_logs ADD COLUMN body MEDIUMTEXT NULL AFTER subject',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_templates' AND COLUMN_NAME = 'show_coupon') = 0,
  'ALTER TABLE checkout_templates ADD COLUMN show_coupon TINYINT(1) NOT NULL DEFAULT 1 AFTER show_notes',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ═══════ dari update-1.1.51.sql ═══════
SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'sp_mode') = 0,
  'ALTER TABLE products ADD COLUMN sp_mode ENUM(''all'',''own'',''off'') NOT NULL DEFAULT ''all'' AFTER booking_deadline_days',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'affiliates' AND COLUMN_NAME = 'bank_name') = 0,
  'ALTER TABLE affiliates ADD COLUMN bank_name VARCHAR(100) NULL AFTER total_withdrawn',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'affiliates' AND COLUMN_NAME = 'bank_account_no') = 0,
  'ALTER TABLE affiliates ADD COLUMN bank_account_no VARCHAR(50) NULL AFTER bank_name',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'affiliates' AND COLUMN_NAME = 'bank_account_name') = 0,
  'ALTER TABLE affiliates ADD COLUMN bank_account_name VARCHAR(100) NULL AFTER bank_account_no',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_templates' AND COLUMN_NAME = 'footer_badge_enabled') = 0,
  'ALTER TABLE checkout_templates ADD COLUMN footer_badge_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER show_coupon',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_templates' AND COLUMN_NAME = 'footer_badge_text') = 0,
  'ALTER TABLE checkout_templates ADD COLUMN footer_badge_text VARCHAR(100) NULL AFTER footer_badge_enabled',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_templates' AND COLUMN_NAME = 'footer_badge_icon') = 0,
  'ALTER TABLE checkout_templates ADD COLUMN footer_badge_icon VARCHAR(500) NULL AFTER footer_badge_text',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_templates' AND COLUMN_NAME = 'footer_badge_url') = 0,
  'ALTER TABLE checkout_templates ADD COLUMN footer_badge_url VARCHAR(500) NULL AFTER footer_badge_icon',
  'DO 0'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ═══════ dari update-1.1.54.sql ═══════
CREATE TABLE IF NOT EXISTS product_upsells (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  main_product_id   INT UNSIGNED NOT NULL COMMENT 'produk pemicu (yang baru dibeli)',
  upsell_product_id INT UNSIGNED NOT NULL COMMENT 'produk yang ditawarkan',
  upsell_price      DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'harga spesial penawaran',
  headline          VARCHAR(200) NULL,
  description       TEXT NULL,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_main (main_product_id),
  KEY idx_upsell_product (upsell_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════ dari update-1.1.56.sql ═══════
SET @sql = (SELECT IF(COUNT(*) > 0,
  'ALTER TABLE product_upsells DROP FOREIGN KEY fk_pu_main',
  'DO 0')
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'product_upsells'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_pu_main');

PREPARE stmt FROM @sql;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) > 0,
  'ALTER TABLE product_upsells DROP FOREIGN KEY fk_pu_upsell',
  'DO 0')
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'product_upsells'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_pu_upsell');

PREPARE stmt FROM @sql;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;


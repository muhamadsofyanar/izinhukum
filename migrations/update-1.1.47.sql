-- Averion v1.1.47 migrasi
-- Waitlist / Coming Soon: produk bisa diset coming_soon (+ launch_at terjadwal),
-- pengunjung daftar waitlist, notif otomatis email+WA saat produk launch
-- Idempoten dan MySQL 8-safe (runner per-statement, duplikat pada re-run diabaikan)

ALTER TABLE products MODIFY COLUMN status ENUM('active','inactive','draft','coming_soon') NOT NULL DEFAULT 'draft';
ALTER TABLE products ADD COLUMN launch_at DATETIME NULL AFTER status;

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

-- ── Booking Fee / DP: produk aktif bisa dibayar DP dulu, pelunasan menyusul ──
ALTER TABLE products ADD COLUMN booking_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN booking_fee DECIMAL(15,2) NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN booking_deadline_days INT NOT NULL DEFAULT 7;

ALTER TABLE orders ADD COLUMN order_stage ENUM('full','booking','settlement') NOT NULL DEFAULT 'full';
ALTER TABLE orders ADD COLUMN parent_order_id INT UNSIGNED NULL;
ALTER TABLE orders ADD COLUMN booking_fee DECIMAL(15,2) NULL;
ALTER TABLE orders ADD COLUMN due_at DATETIME NULL;
ALTER TABLE orders ADD UNIQUE KEY uk_orders_parent (parent_order_id);

INSERT IGNORE INTO notification_templates (event_key, channel, provider, subject, body, is_active) VALUES
('waitlist_joined','email','mailketing','Kamu masuk daftar tunggu {{product_name}} ✅','<p>Halo <strong>{{name}}</strong>,</p><p>Kamu berhasil masuk daftar tunggu <strong>{{product_name}}</strong>! ✅</p><p>Begitu produk ini resmi dibuka, kami akan langsung mengabari kamu lewat email dan WhatsApp. Pantau terus ya. 📩</p><p style="text-align:center;margin:28px 0"><a href="{{product_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Lihat Halaman Produk →</a></p>',1),
('waitlist_joined','whatsapp','starsender',NULL,'Halo {{name}} 👋\n\nKamu berhasil masuk daftar tunggu *{{product_name}}* ✅\n\nBegitu produk dibuka, kami langsung kabari kamu di sini.\n\nTim {{app_name}}',1),
('waitlist_launch','email','mailketing','{{product_name}} sudah dibuka! 🚀','<p>Halo <strong>{{name}}</strong>,</p><p>Kabar yang kamu tunggu: <strong>{{product_name}}</strong> sudah resmi dibuka! 🎉</p><p>Kamu termasuk yang paling awal tahu — amankan aksesmu sekarang sebelum kehabisan.</p><p style="text-align:center;margin:28px 0"><a href="{{product_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Amankan Akses Sekarang →</a></p><p style="font-size:13px;color:#8a94a6">Atau buka tautan ini: {{product_url}}</p>',1),
('waitlist_launch','whatsapp','starsender',NULL,'🚀 *{{product_name}} Sudah Dibuka!*\n\nHalo {{name}}, produk yang kamu tunggu sudah resmi rilis 🎉\n\nKamu termasuk yang paling awal tahu — amankan aksesmu sekarang:\n{{product_url}}\n\nTim {{app_name}}',1),
('booking_confirmed','email','mailketing','Booking kamu dikonfirmasi — slot {{product_name}} aman! 🔒','<p>Halo <strong>{{name}}</strong>,</p><p>Booking fee kamu untuk <strong>{{product_name}}</strong> sudah kami terima. Slot &amp; harga kamu <strong>terkunci</strong>! 🔒</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fb;border-radius:12px;margin:16px 0"><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">Booking Dibayar</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{booking_amount}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">Sisa Pelunasan</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{settlement_total}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;">Batas Pelunasan</td><td style="padding:12px 18px;font-size:14px;color:#b91c1c;font-weight:700;text-align:right;">{{due_date}}</td></tr></table><p style="text-align:center;margin:28px 0"><a href="{{settlement_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Bayar Pelunasan →</a></p><p style="font-size:13px;color:#8a94a6">Akses konten terbuka penuh setelah pelunasan. Lewat batas waktu, booking hangus.</p>',1),
('booking_confirmed','whatsapp','starsender',NULL,'🔒 *Booking Dikonfirmasi!*\n\nHalo {{name}}, booking fee kamu untuk *{{product_name}}* sudah kami terima — slot & harga kamu TERKUNCI 🎉\n\n💰 Booking dibayar: *{{booking_amount}}*\n💳 Sisa pelunasan: *{{settlement_total}}*\n⏰ Batas pelunasan: *{{due_date}}*\n\nBayar pelunasan di sini:\n{{settlement_url}}\n\nAkses konten terbuka penuh setelah lunas. Lewat batas waktu, booking hangus ya.\n\nTim {{app_name}}',1),
('booking_expired','email','mailketing','Batas pelunasan {{product_name}} terlewat ⚠️','<p>Halo <strong>{{name}}</strong>,</p><p>Sayang sekali — batas waktu pelunasan untuk <strong>{{product_name}}</strong> ({{due_date}}) sudah terlewat, sehingga booking kamu <strong>hangus</strong>. ⚠️</p><p>Masih berminat? Kamu tetap bisa memesan lagi kapan saja selama produk tersedia.</p><p style="text-align:center;margin:28px 0"><a href="{{product_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Pesan Lagi →</a></p>',1),
('booking_expired','whatsapp','starsender',NULL,'⚠️ *Booking Hangus*\n\nHalo {{name}}, batas waktu pelunasan untuk *{{product_name}}* ({{due_date}}) sudah terlewat, sehingga booking kamu hangus.\n\nMasih berminat? Kamu bisa pesan lagi di:\n{{product_url}}\n\nTim {{app_name}}',1);

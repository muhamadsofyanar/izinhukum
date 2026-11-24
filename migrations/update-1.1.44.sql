-- Averion v1.1.44 migrasi
-- Fitur Biaya Awal / Setup Fee produk (ditambahkan hanya pada pembelian PERTAMA).
-- products.setup_fee = konfigurasi biaya awal per produk.
-- orders.setup_fee   = nominal biaya awal yang benar-benar dibebankan pada order ini.
-- MySQL 8-safe: ADD COLUMN biasa (runner per-statement; duplikat pada re-run diabaikan).
ALTER TABLE products ADD COLUMN setup_fee decimal(15,2) NOT NULL DEFAULT 0.00 AFTER original_price;
ALTER TABLE orders ADD COLUMN setup_fee decimal(15,2) NOT NULL DEFAULT 0.00 AFTER discount;

-- Langganan Fase 1: Reminder Renewal.
-- Kolom pelacak tahap reminder di member_access (0=belum, 1=H-3 terkirim, 2=H-1 terkirim).
ALTER TABLE member_access ADD COLUMN renewal_remind_stage tinyint NOT NULL DEFAULT 0 AFTER status;

-- Template notifikasi renewal_reminder (email + WhatsApp). Idempoten via uk_event_channel.
-- Splitter migrasi quote-aware, jadi ';' di dalam body aman.
INSERT INTO notification_templates (event_key, channel, provider, subject, body, is_active) VALUES
('renewal_reminder','email','mailketing','Akses {{product_name}} akan berakhir ⏰','<p>Halo <strong>{{name}}</strong>,</p><p>Akses kamu untuk <strong>{{product_name}}</strong> akan <strong>berakhir dalam {{days_left}} hari</strong> (pada {{expiry_date}}).</p><p>Perpanjang sekarang agar akses tetap aktif tanpa terputus. 🔄</p><p style="text-align:center;margin:28px 0"><a href="{{renew_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Perpanjang Akses →</a></p><p style="font-size:13px;color:#8a94a6">Atau buka tautan ini: {{renew_url}}</p>',1),
('renewal_reminder','whatsapp','starsender',NULL,'⏰ *Akses Akan Berakhir*\n\nHalo {{name}}, akses kamu untuk *{{product_name}}* akan berakhir dalam *{{days_left}} hari* (pada {{expiry_date}}).\n\nPerpanjang sekarang agar tetap aktif:\n{{renew_url}}\n\nTim {{app_name}}',1)
ON DUPLICATE KEY UPDATE event_key = event_key;

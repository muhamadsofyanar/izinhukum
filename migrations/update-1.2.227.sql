-- Averion update 1.2.227
-- Magic-link akses instan untuk pembeli BERAKUN-LAMA (Segmen B flow checkout).
-- Pembeli yang sudah punya akun lalu checkout sebagai tamu & bayar → dikirim link
-- sekali-pakai (email + WhatsApp, kedaluwarsa 30 menit) yang langsung me-login-kan
-- mereka ke area member & membuka produk, tanpa harus ingat password. Login manual
-- TETAP berfungsi. Hanya untuk akun role 'user' (admin/staff ditolak consumer demi
-- keamanan 2FA). Idempoten: CREATE TABLE IF NOT EXISTS + INSERT IGNORE template.

CREATE TABLE IF NOT EXISTS `login_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `order_id` int(10) unsigned DEFAULT NULL,
  `product_id` int(10) unsigned DEFAULT NULL,
  `purpose` varchar(32) NOT NULL DEFAULT 'access_link',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_hash` (`token_hash`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Template notifikasi event 'access_link' (email + WhatsApp). INSERT IGNORE → aman
-- dijalankan di atas skema fresh yang sudah memuat seed (uk_event_channel menolak dobel).
INSERT IGNORE INTO notification_templates (event_key, channel, provider, subject, body, is_active) VALUES
('access_link','email','mailketing','Akses {{product_name}} sudah aktif — masuk langsung','<p>Halo <strong>{{name}}</strong>,</p><p>Pembayaran kamu untuk <strong>{{product_name}}</strong> sudah kami terima dan akses produknya sudah aktif. Klik tombol di bawah untuk langsung masuk ke area member tanpa perlu login manual:</p><p style="text-align:center;margin:28px 0"><a href="{{access_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Akses Produk Sekarang →</a></p><p style="font-size:13px;color:#8a94a6">Link ini berlaku {{expiry_minutes}} menit dan hanya bisa dipakai sekali demi keamanan akun kamu. Kalau sudah kedaluwarsa, kamu tetap bisa masuk lewat halaman login seperti biasa di {{member_url}}.</p>',1),
('access_link','whatsapp','starsender',NULL,'Halo {{name}}!\n\nPembayaran untuk *{{product_name}}* sudah diterima & akses produk sudah aktif.\n\nMasuk langsung ke area member (tanpa perlu login manual):\n{{access_url}}\n\nLink berlaku {{expiry_minutes}} menit & sekali pakai. Kalau kedaluwarsa, login biasa tetap bisa.\n\nSalam,\nTim {{app_name}}',1);

-- update-1.1.81.sql — Manual Payment Details (QR + instruksi global)
-- Reuse tabel `bank_accounts` dan `settings` yang sudah ada. TIDAK membuat
-- tabel rekening baru. Hanya seed 1 setting default (idempotent via INSERT
-- IGNORE, uk_setting_key UNIQUE). `manual_qr_image` dibuat otomatis saat admin
-- upload QR (setSetting upsert), jadi tidak perlu di-seed di sini.

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
('manual_payment_instruction', 'Boleh bayar QR Pay atau manual transfer. Lepas bayar, screenshot resit & WhatsApp untuk akses segera.', 'payment');

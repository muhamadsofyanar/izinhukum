-- update-1.2.113.sql
-- Perjelas CTA notifikasi EMAIL setelah pembayaran + buang baris "Lihat invoice kamu".
-- Berlaku ke template payment_success & access_activated (channel email). Bedah via
-- REPLACE: hanya fragmen tombol + baris invoice yang diubah, kustomisasi lain tetap.
-- Idempoten (REPLACE tak cocok = no-op; aman dijalankan berulang / fresh install yang
-- seed-nya sudah baru). WhatsApp tak tersentuh (tak punya tombol </a>).
-- JANGAN taruh titik-koma di komentar.

-- 1) Hapus baris "Lihat invoice kamu: {{invoice_url}}".
UPDATE `notification_templates`
SET `body` = REPLACE(`body`,
      '<p style="font-size:13px;color:#8a94a6">Lihat invoice kamu: {{invoice_url}}</p>', '')
WHERE `channel` = 'email' AND `event_key` IN ('payment_success', 'access_activated');

-- 2) Seragamkan teks tombol ke "Akses Produk Pembelian" (semua varian lama).
UPDATE `notification_templates`
SET `body` = REPLACE(REPLACE(REPLACE(`body`,
      '>Akses Konten Sekarang →</a>',  '>Akses Produk Pembelian →</a>'),
      '>Akses Sekarang →</a>',         '>Akses Produk Pembelian →</a>'),
      '>Mulai Belajar Sekarang →</a>', '>Akses Produk Pembelian →</a>')
WHERE `channel` = 'email' AND `event_key` IN ('payment_success', 'access_activated');

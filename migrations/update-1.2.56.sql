-- ════════════════════════════════════════════════════════════════
-- Averion update 1.2.56 — Notifikasi WhatsApp saat transaksi DIBATALKAN
-- Tambah template event `order_cancelled` (channel whatsapp). Dikirim ke pembeli
-- saat admin membatalkan transaksinya (actions/admin/update-transaction.php).
-- Idempoten: ON DUPLICATE KEY UPDATE hanya menyegarkan subject/body — provider &
-- is_active yang sudah dikustom admin TIDAK ditimpa. Runner quote-aware → aman.
-- ════════════════════════════════════════════════════════════════
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`)
VALUES ('order_cancelled','whatsapp','starsender',NULL,'❌ *Transaksi Dibatalkan*\n\nHalo {{name}}, transaksi kamu berikut telah *dibatalkan*:\n\n📋 No. Pesanan: *{{order_number}}*\n📦 Produk: {{product_name}}\n💰 Total: {{total}}\n\nJika ini bukan permintaanmu atau kamu butuh bantuan, silakan balas pesan ini.\n\nTim {{app_name}}',1)
ON DUPLICATE KEY UPDATE `subject`=VALUES(`subject`), `body`=VALUES(`body`);

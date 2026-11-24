-- update-1.2.90.sql
-- Telegram Alerts Fase 1 — channel notifikasi internal 'telegram'.
--
-- Aman dijalankan ulang (catch-up): MODIFY enum idempotent; INSERT template
-- pakai ON DUPLICATE KEY UPDATE no-op supaya tak menimpa kustomisasi admin.
-- notification_logs.channel sudah VARCHAR(20) → tak perlu diubah.

-- 1) Perluas enum channel notification_templates → tambah 'telegram'.
ALTER TABLE `notification_templates`
  MODIFY `channel` ENUM('email','whatsapp','telegram') NOT NULL;

-- 2) Template notifikasi ORDER MASUK ke admin via Telegram (editable di
--    /admin/notifications). Teks polos + emoji (Telegram tanpa parse_mode).
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`)
VALUES ('payment_admin_notify','telegram','telegram',NULL,'💰 PEMBAYARAN MASUK\n\n👤 Nama: {{name}}\n📧 Email: {{email}}\n📱 WA: {{whatsapp}}\n📦 Produk: {{product_name}}\n📋 No. Pesanan: {{order_number}}\n💵 Total: {{total}}\n📅 Waktu: {{paid_at}}\n\n🧾 Invoice: {{invoice_url}}\n\n{{app_name}}',1)
ON DUPLICATE KEY UPDATE `event_key` = `event_key`;

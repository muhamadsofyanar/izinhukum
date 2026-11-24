-- update-1.1.87.sql — Notifikasi admin saat pembayaran masuk (payment_admin_notify)
-- Event baru: WA ke admin setiap ada pembayaran berhasil (webhook gateway,
-- konfirmasi manual admin, update status transaksi). Nomor tujuan: setting
-- lead_admin_wa (sudah ada, dipakai bareng lead_admin_notify), fallback ke
-- nomor akun admin pertama. Idempoten via INSERT IGNORE (uk_event_channel).
INSERT IGNORE INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES
('payment_admin_notify','whatsapp','starsender',NULL,'💰 *Pembayaran Masuk!*\n\n👤 Nama: {{name}}\n📧 Email: {{email}}\n📋 No. Pesanan: *{{order_number}}*\n💵 Total: *{{total}}*\n📅 Tanggal: {{paid_at}}\n\n🧾 Invoice: {{invoice_url}}\n\n{{app_name}}',1);

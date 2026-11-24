-- Averion v1.1.48 migrasi
-- Drip Content: modul (classes) bisa diset terbuka setelah N hari sejak
-- akses member aktif (member_access.started_at) dengan kolom unlock_after_days
-- 0 = langsung terbuka (perilaku lama, default semua modul eksisting)
-- Idempoten dan MySQL 8-safe (runner per-statement, duplikat pada re-run diabaikan)

ALTER TABLE classes ADD COLUMN unlock_after_days INT NOT NULL DEFAULT 0 AFTER sort_order;

-- ── Reminder Pelunasan Booking (H-2 / H-1 sebelum due_at, pola staged spt renewal) ──
ALTER TABLE orders ADD COLUMN settlement_remind_stage TINYINT NOT NULL DEFAULT 0 AFTER due_at;

-- ── Trigger email sequence baru (waitlist_joined, booking_paid, lead_captured) ──
ALTER TABLE email_sequences MODIFY COLUMN trigger_event ENUM('order_paid','member_access_activated','user_register','waitlist_joined','booking_paid','lead_captured') NOT NULL DEFAULT 'order_paid';

INSERT IGNORE INTO notification_templates (event_key, channel, provider, subject, body, is_active) VALUES
('settlement_reminder','email','mailketing','Jangan sampai hangus — pelunasan {{product_name}} tinggal {{days_left}} hari lagi ⏰','<p>Halo <strong>{{name}}</strong>,</p><p>Batas waktu pelunasan booking kamu untuk <strong>{{product_name}}</strong> tinggal <strong>{{days_left}} hari</strong> lagi.</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fb;border-radius:12px;margin:16px 0"><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">Sisa Pelunasan</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{settlement_total}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;">Batas Pelunasan</td><td style="padding:12px 18px;font-size:14px;color:#b91c1c;font-weight:700;text-align:right;">{{due_date}}</td></tr></table><p>Lewat dari batas waktu, booking kamu <strong>hangus</strong> dan slot dilepas. Amankan sekarang:</p><p style="text-align:center;margin:28px 0"><a href="{{settlement_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Bayar Pelunasan Sekarang →</a></p>',1),
('settlement_reminder','whatsapp','starsender',NULL,'⏰ *Reminder Pelunasan*\n\nHalo {{name}}, batas waktu pelunasan *{{product_name}}* tinggal *{{days_left}} hari* lagi ({{due_date}}).\n\n💳 Sisa pelunasan: *{{settlement_total}}*\n\nBayar di sini sebelum hangus:\n{{settlement_url}}\n\nTim {{app_name}}',1);

-- Averion v1.2.1 — Langganan: reminder 4 lapis + win-back setelah kedaluwarsa.
--
-- TANPA perubahan struktur: member_access.renewal_remind_stage sudah tinyint
-- (muat tahap 0-4, dulu hanya 0-2). Yang baru cuma template notifikasi.
--
-- Template renewal_expired (email + WhatsApp) untuk lapis H+3 win-back. Kalimatnya
-- SENGAJA beda dari renewal_reminder: "sudah berakhir", bukan "akan berakhir".
-- Idempoten via uk_event_channel. Splitter migrasi client quote-aware, jadi ';'
-- di dalam body aman.
INSERT INTO notification_templates (event_key, channel, provider, subject, body, is_active) VALUES
('renewal_expired','email','mailketing','Akses {{product_name}} sudah berakhir — aktifkan lagi?','<p>Halo <strong>{{name}}</strong>,</p><p>Akses kamu untuk <strong>{{product_name}}</strong> sudah berakhir pada <strong>{{expiry_date}}</strong> ({{days_ago}} hari lalu).</p><p>Belum sempat perpanjang? Tenang, semua progres kamu masih tersimpan — aktifkan lagi kapan pun dan lanjutkan dari tempat terakhir kamu berhenti. 🔄</p><p style="text-align:center;margin:28px 0"><a href="{{renew_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Aktifkan Lagi →</a></p><p style="font-size:13px;color:#8a94a6">Atau buka tautan ini: {{renew_url}}</p>',1),
('renewal_expired','whatsapp','starsender',NULL,'Halo {{name}} 👋\n\nAkses kamu untuk *{{product_name}}* sudah berakhir pada {{expiry_date}} ({{days_ago}} hari lalu).\n\nProgres kamu masih tersimpan — aktifkan lagi kapan pun dan lanjut dari tempat terakhir:\n{{renew_url}}\n\nTim {{app_name}}',1)
ON DUPLICATE KEY UPDATE event_key = event_key;

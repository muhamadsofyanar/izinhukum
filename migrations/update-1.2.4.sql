-- Averion v1.2.4 — Lisensi Vendor: notifikasi admin saat penerbitan gagal.
--
-- Tanpa perubahan struktur. Yang baru cuma template notifikasi.
--
-- Event license_fulfillment_failed dikirim ke ADMIN (bukan pembeli) saat sebuah
-- pesanan lisensi menyerah: pembeli SUDAH BAYAR tapi kuncinya tak sampai — itu
-- keadaan yang tak boleh cuma mengendap di tabel antrean menunggu admin kebetulan
-- membuka halamannya.
--
-- Idempoten via uk_event_channel. Splitter migrasi client quote-aware, jadi ';'
-- di dalam body aman.
INSERT INTO notification_templates (event_key, channel, provider, subject, body, is_active) VALUES
('license_fulfillment_failed','email','mailketing','⚠️ Penerbitan lisensi GAGAL — order {{order_number}}','<p>Halo Admin,</p><p>Pesanan lisensi untuk order <strong>{{order_number}}</strong> <strong>gagal diterbitkan</strong> ke server lisensi. Pembeli sudah membayar tetapi belum menerima license key-nya.</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fb;border-radius:12px;margin:16px 0"><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">Pembeli</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{customer_name}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">Email</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{email}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">Produk</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{product_name}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;">Penyebab</td><td style="padding:12px 18px;font-size:13px;color:#b91c1c;font-weight:700;text-align:right;">{{reason}}</td></tr></table><p style="text-align:center;margin:28px 0"><a href="{{admin_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Buka Antrean Lisensi →</a></p><p style="font-size:13px;color:#8a94a6">Perbaiki penyebabnya (mis. rahasia/plan), lalu klik <strong>Coba Lagi</strong> pada baris tersebut. Aman diulang — order yang sama tak akan pernah menerbitkan lisensi dua kali.</p>',1),
('license_fulfillment_failed','whatsapp','starsender',NULL,'⚠️ *Penerbitan Lisensi GAGAL*\n\nOrder *{{order_number}}* gagal diterbitkan ke server lisensi. Pembeli SUDAH BAYAR tapi belum menerima license key.\n\n👤 {{customer_name}}\n📧 {{email}}\n📦 {{product_name}}\n\n❗ Penyebab: {{reason}}\n\nBuka antrean & klik Coba Lagi:\n{{admin_url}}\n\n{{app_name}}',1)
ON DUPLICATE KEY UPDATE event_key = event_key;

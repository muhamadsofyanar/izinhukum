-- update-1.2.153.sql
-- Notifikasi ke AFFILIATE saat ada penjualan baru via link mereka (event
-- 'affiliate_new_order'). Notifikasi pencairan komisi (payout_paid dll) sudah ada.
-- Aman dijalankan ulang: INSERT pakai ON DUPLICATE KEY UPDATE no-op
-- (uk_event_channel) sehingga tak menimpa kustomisasi admin & tak menggandakan.
-- JANGAN taruh titik-koma di dalam komentar.

INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES
('affiliate_new_order','email','mailketing','Ada penjualan baru via link kamu! 💰','<p>Halo <strong>{{name}}</strong>,</p><p>Kabar baik! 🎉 Ada penjualan baru lewat link afiliasi kamu.</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fb;border-radius:12px;margin:16px 0"><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">Produk</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{product_name}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">No. Pesanan</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{order_number}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;">Komisi Kamu</td><td style="padding:12px 18px;font-size:15px;color:#16a34a;font-weight:800;text-align:right;">{{amount}}</td></tr></table><p style="text-align:center;margin:28px 0"><a href="{{affiliate_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Lihat Dashboard Afiliasi →</a></p><p style="font-size:13px;color:#8a94a6">Terus sebar link kamu untuk komisi berikutnya! 🚀</p>',1)
ON DUPLICATE KEY UPDATE `event_key` = `event_key`;

INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES
('affiliate_new_order','whatsapp','starsender',NULL,'💰 *Penjualan Baru via Link Kamu!*\n\nHalo {{name}}, ada yang baru saja membeli *{{product_name}}* lewat link afiliasimu 🎉\n\n💵 Komisi: *{{amount}}*\n📋 No. Pesanan: {{order_number}}\n\nCek dashboard afiliasi:\n{{affiliate_url}}\n\nTerus sebar linkmu untuk komisi berikutnya! 🚀\n\nTim {{app_name}}',1)
ON DUPLICATE KEY UPDATE `event_key` = `event_key`;

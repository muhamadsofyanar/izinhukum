-- update-1.2.156.sql
-- Template editable 'abandoned_cart_bonus' — sisipan urgensi Bonus Kilat yang
-- di-APPEND ke reminder keranjang tertinggal bila order punya bonus kilat.
-- Pesan dasar 'abandoned_cart' TIDAK diubah. Placeholder: {{flash_bonus_list}}.
-- Aman diulang: ON DUPLICATE KEY UPDATE no-op (uk_event_channel) tak menimpa kustomisasi.
-- JANGAN taruh titik-koma di dalam komentar.

INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES
('abandoned_cart_bonus','email','mailketing',NULL,'<p style="margin:16px 0 0;padding:12px 14px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;color:#065f46;font-size:14px">🎁 <strong>Bonus gratis</strong> ({{flash_bonus_list}}) sudah dikunci untuk order ini — selesaikan pembayaran agar bonus tidak hangus.</p>',1)
ON DUPLICATE KEY UPDATE `event_key` = `event_key`;

INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES
('abandoned_cart_bonus','whatsapp','starsender',NULL,'🎁 *Bonus gratis* ({{flash_bonus_list}}) sudah dikunci untuk order ini — selesaikan pembayaran agar bonus tidak hangus.',1)
ON DUPLICATE KEY UPDATE `event_key` = `event_key`;

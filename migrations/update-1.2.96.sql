-- update-1.2.96.sql
-- Tiket Support — helpdesk mini member (pembeli) <-> admin.
-- Aman dijalankan ulang: CREATE TABLE IF NOT EXISTS + seed template pakai
-- ON DUPLICATE KEY UPDATE no-op (tak menimpa kustomisasi admin).
-- JANGAN taruh titik-koma di dalam komentar.

-- 1) Tabel tiket (satu baris per percakapan).
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_number` VARCHAR(32) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `subject` VARCHAR(200) NOT NULL,
  `status` ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
  `last_reply_at` DATETIME DEFAULT NULL,
  `last_reply_by` ENUM('user','admin') DEFAULT NULL,
  `user_seen_at` DATETIME DEFAULT NULL,
  `admin_seen_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_number` (`ticket_number`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_last_reply` (`last_reply_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Balasan (pesan pertama = baris reply pertama sender_type='user').
CREATE TABLE IF NOT EXISTS `support_ticket_replies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` INT UNSIGNED NOT NULL,
  `sender_type` ENUM('user','admin') NOT NULL,
  `sender_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `message` TEXT NOT NULL,
  `attachment_path` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`ticket_id`),
  CONSTRAINT `fk_ticket_reply` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Template notifikasi ke ADMIN saat ada tiket/balasan baru dari member.
--    Telegram = kanal internal admin (chat_id dari setting). WA admin = resolveAdminNotifyPhone().
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES
('support_admin_notify','telegram','telegram',NULL,'🎫 TIKET SUPPORT BARU\n\n👤 Dari: {{name}}\n📋 No. Tiket: {{ticket_number}}\n📝 Subjek: {{subject}}\n\n💬 Pesan:\n{{message}}\n\n🔗 Balas: {{ticket_url}}\n\n{{app_name}}',1)
ON DUPLICATE KEY UPDATE `event_key` = `event_key`;

INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES
('support_admin_notify','whatsapp','starsender',NULL,'🎫 *Tiket Support Baru*\n\n👤 Dari: {{name}}\n📋 No. Tiket: *{{ticket_number}}*\n📝 Subjek: {{subject}}\n\n💬 {{message}}\n\nBalas di sini:\n{{ticket_url}}\n\n{{app_name}}',1)
ON DUPLICATE KEY UPDATE `event_key` = `event_key`;

-- 4) Template notifikasi ke MEMBER saat admin membalas tiketnya.
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES
('support_reply','email','mailketing','Ada balasan untuk tiket {{ticket_number}}','<p>Halo <strong>{{name}}</strong>,</p><p>Tim kami sudah membalas tiket support kamu <strong>{{ticket_number}}</strong> — <em>{{subject}}</em>.</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fb;border-radius:12px;margin:16px 0"><tr><td style="padding:14px 18px;font-size:14px;color:#475569;line-height:1.7">{{message}}</td></tr></table><p style="text-align:center;margin:28px 0"><a href="{{ticket_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Lihat &amp; Balas Tiket →</a></p><p style="font-size:13px;color:#8a94a6">Atau buka tautan ini: {{ticket_url}}</p>',1)
ON DUPLICATE KEY UPDATE `event_key` = `event_key`;

INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES
('support_reply','whatsapp','starsender',NULL,'💬 *Balasan Tiket Support*\n\nHalo {{name}}, tim kami sudah membalas tiket *{{ticket_number}}* ({{subject}}).\n\n{{message}}\n\nLihat & balas di sini:\n{{ticket_url}}\n\nTim {{app_name}}',1)
ON DUPLICATE KEY UPDATE `event_key` = `event_key`;

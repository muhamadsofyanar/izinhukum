-- Averion v1.1.66 migrasi
-- AI Chat Fase 2 (lead capture): tabel chat_leads — lead dari widget chat
-- (nama + WhatsApp, form muncul setelah pesan ke-2) beserta transcript
-- percakapannya (hanya tersimpan bila lead tertangkap). Idempoten
-- (CREATE TABLE IF NOT EXISTS).
CREATE TABLE IF NOT EXISTS chat_leads (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL,
  whatsapp varchar(30) NOT NULL,
  token char(32) NOT NULL,
  transcript mediumtext DEFAULT NULL,
  ip_address varchar(45) DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  updated_at timestamp NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_chat_leads_token (token),
  KEY idx_chat_leads_wa (whatsapp),
  KEY idx_chat_leads_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

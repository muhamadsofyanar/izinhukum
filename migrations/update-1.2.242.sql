-- update-1.2.242.sql
-- Broadcast peserta Funnel Gate: segmen baru 'funnel_submissions' + kolom target
-- (form_id, status submission, skor minimum) pada broadcast_campaigns. Admin bisa
-- kirim email/WA tindak lanjut ke peserta funnel langsung dari halaman Submissions.
-- Idempoten via information_schema. JANGAN taruh titik-koma di komentar.

-- 1. Tambah nilai enum 'funnel_submissions' ke target_segment (bila belum ada)
SET @e := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'broadcast_campaigns' AND COLUMN_NAME = 'target_segment');
SET @s := IF(@e NOT LIKE '%funnel_submissions%',
  "ALTER TABLE `broadcast_campaigns` MODIFY COLUMN `target_segment` enum('all_users','active_members','specific_level','custom_list','leads_all','leads_by_magnet','product_buyers','email_openers','email_clickers','funnel_submissions') NOT NULL DEFAULT 'all_users'",
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. Kolom target_funnel_form_id (funnel mana yang disasar)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'broadcast_campaigns' AND COLUMN_NAME = 'target_funnel_form_id');
SET @s := IF(@c = 0,
  'ALTER TABLE `broadcast_campaigns` ADD COLUMN `target_funnel_form_id` INT(10) UNSIGNED DEFAULT NULL AFTER `target_product_ids`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. Kolom target_submission_status (status submission yang disasar; kosong = semua non-draft)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'broadcast_campaigns' AND COLUMN_NAME = 'target_submission_status');
SET @s := IF(@c = 0,
  'ALTER TABLE `broadcast_campaigns` ADD COLUMN `target_submission_status` VARCHAR(20) DEFAULT NULL AFTER `target_funnel_form_id`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4. Kolom target_min_score (skor minimum peserta; NULL/0 = tanpa batas)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'broadcast_campaigns' AND COLUMN_NAME = 'target_min_score');
SET @s := IF(@c = 0,
  'ALTER TABLE `broadcast_campaigns` ADD COLUMN `target_min_score` INT DEFAULT NULL AFTER `target_submission_status`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

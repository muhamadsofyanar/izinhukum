-- update-1.2.239.sql
-- Funnel Analytics: kolom user_agent pada funnel_events agar event bisa diaudit
-- & trafik bot bisa dibedakan dari manusia (lp_view/checkout_click). Nullable
-- (event lama tetap valid dgn NULL). Idempoten via information_schema.
-- JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funnel_events' AND COLUMN_NAME = 'user_agent');
SET @s := IF(@c = 0,
  'ALTER TABLE `funnel_events` ADD COLUMN `user_agent` VARCHAR(255) DEFAULT NULL AFTER `session_id`',
  'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

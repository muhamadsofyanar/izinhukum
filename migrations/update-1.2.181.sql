-- update-1.2.181.sql
-- Abandoned Cart Recovery: reminder bertahap (hingga 3x per order), bukan sekali.
-- Kolom recovery_remind_stage mencatat sudah berapa kali reminder dikirim (0..3).
-- recovery_sent_at kini = waktu reminder TERAKHIR (dipakai untuk jarak antar-tahap).
-- Default 0 = perilaku lama (belum ada reminder). Idempoten via information_schema
-- + PREPARE. JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
             AND COLUMN_NAME = 'recovery_remind_stage');
SET @s := IF(@c = 0,
  'ALTER TABLE `orders` ADD COLUMN `recovery_remind_stage` TINYINT NOT NULL DEFAULT 0 AFTER `recovery_sent_at`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Hapus total Hermes Fase 3 (pending_payment_followup DIBATALKAN) — follow-up order
-- belum lunas dialihkan ke Abandoned Cart Recovery native 3x di atas. Tabel tak dipakai
-- lagi. DROP aman: fitur default MATI (butuh integrasi eksternal + tier manage), praktis
-- kosong di semua instalasi; child-side FK (ON DELETE CASCADE), tak ada tabel lain
-- mereferensikannya. Idempoten lewat IF EXISTS.
DROP TABLE IF EXISTS `hermes_followup_jobs`;

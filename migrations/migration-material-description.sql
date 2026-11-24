-- Deskripsi kaya di bawah konten materi (teks + link di bawah video embed/URL).
-- Idempoten & aman MySQL 8. Dev artifact — difold ke update-<versi>.sql saat rilis.
-- (MySQL 8 tak dukung ADD COLUMN IF NOT EXISTS → app punya guard
--  ensureMaterialDescriptionColumn(); migrasi ini untuk jalur update resmi.)
ALTER TABLE `materials` ADD COLUMN `description` longtext DEFAULT NULL AFTER `content`;

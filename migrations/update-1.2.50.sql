-- ════════════════════════════════════════════════════════════════
-- Averion update 1.2.50 — Deskripsi kaya di bawah konten materi
-- Kolom materials.description (teks + link di bawah video embed/URL).
-- MySQL 8 tak dukung ADD COLUMN IF NOT EXISTS → ADD COLUMN biasa; runner
-- per-statement menelan duplikat (1060) bila kolom sudah ada (guard app
-- ensureMaterialDescriptionColumn bisa menambahkannya lebih dulu).
-- ════════════════════════════════════════════════════════════════
ALTER TABLE `materials` ADD COLUMN `description` longtext DEFAULT NULL AFTER `content`;

-- ════════════════════════════════════════════════════════════════
-- Averion update 1.2.55 — Product Bundle di Funnel Analytics
-- Kolom funnel_events.bundle_id (nullable) agar corong bundle bisa dilacak
-- terpisah dari produk. Event bundle disimpan dgn product_id=0 & bundle_id terisi.
-- MySQL 8 tak dukung ADD COLUMN IF NOT EXISTS → ADD COLUMN biasa; runner
-- per-statement menelan duplikat (1060/1061) bila kolom/index sudah ada (guard
-- app ensureFunnelBundleColumn bisa menambahkannya lebih dulu).
-- ════════════════════════════════════════════════════════════════
ALTER TABLE `funnel_events` ADD COLUMN `bundle_id` INT UNSIGNED DEFAULT NULL AFTER `product_id`;
ALTER TABLE `funnel_events` ADD INDEX `idx_bundle_event_date` (`bundle_id`, `event_type`, `created_at`);

-- ─── Info Member: tombol CTA opsional (url + teks tombol) ───────────
-- Guard app ensureAnnouncementCtaColumns bisa menambahkannya lebih dulu;
-- runner menelan duplikat (1060) bila kolom sudah ada.
ALTER TABLE `announcements` ADD COLUMN `cta_url` VARCHAR(500) DEFAULT NULL AFTER `body`;
ALTER TABLE `announcements` ADD COLUMN `cta_text` VARCHAR(80) DEFAULT NULL AFTER `cta_url`;

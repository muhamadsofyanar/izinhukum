-- update-1.2.166.sql
-- Portal Agency SEO Engine (fitur VENDOR-ONLY, digerbangi AVERION_VENDOR_INSTALL).
-- Satu pembelian paket agency = kuota 50 slot lisensi SEO lifetime. Lisensi TIDAK
-- diterbitkan 50 sekaligus, melainkan on-demand per klien lewat portal (tiap slot
-- pakai order_ref unik induk + '-SEO-NN' agar dompet AI klien terpisah di vendor).
-- Idempoten via IF NOT EXISTS. JANGAN taruh titik-koma di komentar.

CREATE TABLE IF NOT EXISTS `agency_seo_quotas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `order_id` int(10) unsigned NOT NULL,
  `order_ref` varchar(100) NOT NULL,
  `slot_total` int(10) unsigned NOT NULL DEFAULT 50,
  `slot_used` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agency_seo_order` (`order_id`),
  KEY `idx_agency_seo_user` (`user_id`),
  CONSTRAINT `fk_agency_seo_quota_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `agency_seo_licenses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `quota_id` int(10) unsigned NOT NULL,
  `slot_number` int(10) unsigned NOT NULL,
  `order_ref` varchar(120) NOT NULL,
  `client_name` varchar(150) NOT NULL,
  `client_email` varchar(191) NOT NULL,
  `client_phone` varchar(40) DEFAULT NULL,
  `license_key` varchar(191) DEFAULT NULL,
  `status` enum('pending','issued') NOT NULL DEFAULT 'pending',
  `last_error` varchar(500) DEFAULT NULL,
  `issued_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agency_seo_slot` (`quota_id`,`slot_number`),
  UNIQUE KEY `uk_agency_seo_order_ref` (`order_ref`),
  KEY `idx_agency_seo_lic_quota` (`quota_id`),
  CONSTRAINT `fk_agency_seo_lic_quota` FOREIGN KEY (`quota_id`) REFERENCES `agency_seo_quotas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`)
VALUES ('agency_seo_product_id', '0', 'agency_seo') ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`)
VALUES ('agency_seo_slot_total', '50', 'agency_seo') ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`)
VALUES ('agency_seo_topup10_url', 'https://vendor.averion.id/storefront?product=seo-topup-10', 'agency_seo') ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`)
VALUES ('agency_seo_topup30_url', 'https://vendor.averion.id/storefront?product=seo-topup-30', 'agency_seo') ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

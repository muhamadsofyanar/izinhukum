-- ════════════════════════════════════════════════════════════════
-- Averion update 1.2.49 — Canvas Rotator / Split Test + multi-pixel
-- Idempoten & aman MySQL 8: MODIFY (enum/kolom), CREATE TABLE IF NOT EXISTS.
-- Dijalankan otomatis oleh apply-update saat pembeli update.
-- ════════════════════════════════════════════════════════════════

-- Tipe halaman 'rotator' + kolom pixel multi-ID (dipisah koma).
ALTER TABLE `canvas_pages`
  MODIFY `page_type` enum('standalone','homepage','product_landing','rotator')
  NOT NULL DEFAULT 'standalone';
ALTER TABLE `canvas_pages` MODIFY `meta_pixel_id`   varchar(255) DEFAULT NULL;
ALTER TABLE `canvas_pages` MODIFY `tiktok_pixel_id` varchar(255) DEFAULT NULL;

-- Varian rotator: tiap baris = 1 canvas yang dirujuk sebagai angle + bobot.
CREATE TABLE IF NOT EXISTS `canvas_rotator_variants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rotator_id` int(10) unsigned NOT NULL,
  `variant_canvas_id` int(10) unsigned NOT NULL,
  `label` varchar(100) NOT NULL DEFAULT '',
  `weight` int(10) unsigned NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rotator` (`rotator_id`),
  KEY `fk_rv_variant` (`variant_canvas_id`),
  CONSTRAINT `fk_rv_rotator` FOREIGN KEY (`rotator_id`) REFERENCES `canvas_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rv_variant` FOREIGN KEY (`variant_canvas_id`) REFERENCES `canvas_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event split-test per-varian (impression + checkout_click). Tanpa FK ke variants
-- (simpan histori walau varian dihapus).
CREATE TABLE IF NOT EXISTS `canvas_rotator_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rotator_id` int(10) unsigned NOT NULL,
  `variant_id` int(10) unsigned NOT NULL,
  `event_type` enum('impression','checkout_click','conversion') NOT NULL,
  `session_id` varchar(128) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rot_var_evt_date` (`rotator_id`,`variant_id`,`event_type`,`created_at`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- update-1.2.110.sql
-- Fitur "Bonus Kilat": pilih produk premium GRATIS berbatas waktu di halaman
-- checkout (scarcity). Dua tabel baru, idempoten via CREATE TABLE IF NOT EXISTS.
-- Bonus terpilih disuntik ke order_items sebagai is_bump harga 0 saat checkout,
-- lalu ter-grant otomatis oleh onOrderPaid (tak butuh kolom order baru).
-- JANGAN taruh titik-koma di komentar.

CREATE TABLE IF NOT EXISTS `flash_bonus_offers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `main_product_id` int(10) unsigned NOT NULL COMMENT 'produk utama yang sedang di-checkout',
  `bonus_product_id` int(10) unsigned NOT NULL COMMENT 'produk premium yang ditawarkan GRATIS',
  `headline` varchar(200) DEFAULT NULL COMMENT 'judul promosi bonus',
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_main_bonus` (`main_product_id`,`bonus_product_id`),
  KEY `idx_fb_main` (`main_product_id`),
  KEY `fk_fb_bonus` (`bonus_product_id`),
  CONSTRAINT `fk_fb_bonus` FOREIGN KEY (`bonus_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fb_main` FOREIGN KEY (`main_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flash_bonus_settings` (
  `main_product_id` int(10) unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = blok bonus tampil di checkout produk ini',
  `duration_minutes` int(10) unsigned NOT NULL DEFAULT 60 COMMENT 'masa berlaku bonus per kunjungan (menit)',
  `max_select` int(10) unsigned NOT NULL DEFAULT 1 COMMENT 'berapa bonus yang boleh dipilih pembeli',
  `label` varchar(120) NOT NULL DEFAULT 'Bonus Gratis Hari Ini' COMMENT 'judul blok ke pembeli',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`main_product_id`),
  CONSTRAINT `fk_fbs_main` FOREIGN KEY (`main_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

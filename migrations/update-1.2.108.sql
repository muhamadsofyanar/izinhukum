-- update-1.2.108.sql
-- Fitur "Reseller — Salespage Siap Pakai": setting salespage PER-RESELLER (per user+produk).
-- Teks WhatsApp tombol Order Personal Use + toggle tampilkan tombol Order Resell Rights
-- kini dikelola reseller sendiri di dashboard /reseller (bukan admin). Bila baris tak ada
-- untuk (user,produk) → salespage pakai default sistem. CREATE TABLE IF NOT EXISTS = idempoten.
-- JANGAN taruh titik-koma di komentar.

CREATE TABLE IF NOT EXISTS `reseller_salespage` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `wa_template` varchar(500) DEFAULT NULL,
  `resell_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `access_password` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reseller_product` (`user_id`,`product_id`),
  KEY `idx_rs_product` (`product_id`),
  CONSTRAINT `fk_rs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rs_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

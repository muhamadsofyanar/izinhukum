-- update-1.2.91.sql
-- Display Produk Affiliate — tabel ringan terpisah dari `products` (tanpa FK,
-- tak menyentuh mesin checkout/order/akses). Idempotent via CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `affiliate_displays` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `image` varchar(500) DEFAULT NULL,
  `salespage_url` varchar(1000) NOT NULL,
  `checkout_url` varchar(1000) DEFAULT NULL,
  `price_text` varchar(60) DEFAULT NULL,
  `badge` varchar(40) DEFAULT NULL,
  `short_desc` varchar(300) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Averion — Skema lengkap + seed default (fresh install).
-- Regenerated: 2026-07-11 dari DB dev via tools regen (struktur mysqldump, seed dipertahankan).
-- Struktur 65 tabel + seed config (TANPA data user/transaksi, TANPA secret).
SET FOREIGN_KEY_CHECKS=0;
SET NAMES utf8mb4;

DROP TABLE IF EXISTS `abandoned_cart_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `abandoned_cart_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `channel` enum('email','whatsapp') NOT NULL DEFAULT 'email',
  `provider` varchar(50) NOT NULL DEFAULT 'mailketing',
  `recipient` varchar(200) NOT NULL,
  `status` enum('sent','failed','skipped') NOT NULL DEFAULT 'sent',
  `response` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_acl_order` (`order_id`),
  KEY `idx_acl_user` (`user_id`),
  KEY `idx_acl_sent_at` (`sent_at`),
  CONSTRAINT `abandoned_cart_logs_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `abandoned_cart_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL jika aksi sistem otomatis',
  `actor_role` enum('admin','user','system') NOT NULL DEFAULT 'system',
  `action` varchar(100) NOT NULL COMMENT 'misal: product.create, order.mark_paid, auth.login',
  `entity_type` varchar(50) DEFAULT NULL COMMENT 'misal: product, order, user',
  `entity_id` int(10) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_al_user` (`user_id`),
  KEY `idx_al_action` (`action`),
  KEY `idx_al_entity` (`entity_type`,`entity_id`),
  KEY `idx_al_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=192 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `affiliates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `affiliates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `code` varchar(20) NOT NULL,
  `custom_slug` varchar(50) DEFAULT NULL COMMENT 'custom slug untuk link /r/{slug}',
  `commission_rate` decimal(5,2) NOT NULL DEFAULT 10.00 COMMENT 'persentase komisi default',
  `total_earned` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_withdrawn` decimal(15,2) NOT NULL DEFAULT 0.00,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_no` varchar(50) DEFAULT NULL,
  `bank_account_name` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  UNIQUE KEY `uk_code` (`code`),
  UNIQUE KEY `uk_affiliate_custom_slug` (`custom_slug`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_affiliates_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `ai_chat_rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_chat_rate_limits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `request_count` int(10) unsigned NOT NULL DEFAULT 1,
  `window_start` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_window` (`window_start`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `announcement_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcement_reads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `announcement_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_annr_pair` (`announcement_id`,`user_id`),
  KEY `idx_annr_user` (`user_id`),
  CONSTRAINT `fk_annr_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_annr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `announcement_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcement_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `announcement_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ann_user` (`announcement_id`,`user_id`),
  KEY `idx_annu_user` (`user_id`),
  CONSTRAINT `fk_annu_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_annu_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `cta_url` varchar(500) DEFAULT NULL,
  `cta_text` varchar(80) DEFAULT NULL,
  `target_type` enum('global','users','product') NOT NULL DEFAULT 'global',
  `product_id` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ann_active` (`is_active`,`created_at`),
  KEY `idx_ann_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `bank_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bank_accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `bank_name` varchar(100) NOT NULL COMMENT 'Nama bank/provider: BCA, Mandiri, GoPay, OVO, dll',
  `channel_type` enum('bank','ewallet') NOT NULL DEFAULT 'bank' COMMENT 'bank = rekening beneran (termasuk SeaBank dkk); ewallet = nomor HP GoPay/OVO/DANA/dll',
  `account_number` varchar(50) NOT NULL COMMENT 'Nomor rekening / nomor HP e-wallet',
  `account_name` varchar(100) NOT NULL COMMENT 'Nama pemilik rekening',
  `bank_logo` varchar(255) DEFAULT NULL COMMENT 'URL logo bank (opsional)',
  `instructions` text DEFAULT NULL COMMENT 'Instruksi tambahan untuk user',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ba_active` (`is_active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `broadcast_campaigns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `broadcast_campaigns` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `channel` enum('email','whatsapp') NOT NULL,
  `provider` varchar(50) NOT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `body` text NOT NULL,
  `body_format` enum('text','html') NOT NULL DEFAULT 'text',
  `target_segment` enum('all_users','active_members','specific_level','custom_list','leads_all','leads_by_magnet','product_buyers','email_openers','email_clickers','funnel_submissions') NOT NULL DEFAULT 'all_users',
  `target_membership_level_id` int(10) unsigned DEFAULT NULL,
  `target_lead_magnet_id` int(10) unsigned DEFAULT NULL,
  `target_product_ids` varchar(255) DEFAULT NULL,
  `target_funnel_form_id` int(10) unsigned DEFAULT NULL,
  `target_submission_status` varchar(20) DEFAULT NULL,
  `target_min_score` int(11) DEFAULT NULL,
  `engagement_days` smallint(5) unsigned DEFAULT NULL,
  `exclude_product_ids` varchar(255) DEFAULT NULL,
  `skip_failed_emails` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('draft','scheduled','sending','paused','sent','failed') NOT NULL DEFAULT 'draft',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `total_recipients` int(11) NOT NULL DEFAULT 0,
  `total_sent` int(11) NOT NULL DEFAULT 0,
  `total_failed` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `external_ref` varchar(191) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_broadcast_external_ref` (`external_ref`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `fk_campaign_level` (`target_membership_level_id`),
  CONSTRAINT `fk_campaign_level` FOREIGN KEY (`target_membership_level_id`) REFERENCES `membership_levels` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `broadcast_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `broadcast_recipients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `lead_id` int(10) unsigned DEFAULT NULL,
  `recipient` varchar(150) NOT NULL DEFAULT '',
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `sent_at` timestamp NULL DEFAULT NULL,
  `error_message` varchar(255) DEFAULT NULL,
  `opened_at` timestamp NULL DEFAULT NULL,
  `open_count` int(10) unsigned NOT NULL DEFAULT 0,
  `clicked_at` timestamp NULL DEFAULT NULL,
  `click_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_campaign_id` (`campaign_id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_recipients_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `broadcast_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_recipients_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `canvas_pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `canvas_pages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `meta_desc` text DEFAULT NULL,
  `mode` enum('upload','embed','builder') NOT NULL DEFAULT 'upload',
  `content` longtext DEFAULT NULL,
  `path` varchar(500) DEFAULT NULL,
  `page_type` enum('standalone','homepage','product_landing','rotator','lead_magnet_landing','redirect') NOT NULL DEFAULT 'standalone',
  `linked_product_id` int(10) unsigned DEFAULT NULL,
  `linked_lead_magnet_id` int(10) unsigned DEFAULT NULL,
  `is_homepage` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `meta_pixel_id` varchar(255) DEFAULT NULL,
  `tiktok_pixel_id` varchar(255) DEFAULT NULL,
  `ai_chat_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `redirect_url` varchar(2048) DEFAULT NULL,
  `redirect_keep_query` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `fk_canvas_product` (`linked_product_id`),
  KEY `fk_canvas_lead_magnet` (`linked_lead_magnet_id`),
  CONSTRAINT `fk_canvas_lead_magnet` FOREIGN KEY (`linked_lead_magnet_id`) REFERENCES `lead_magnets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_canvas_product` FOREIGN KEY (`linked_product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `certificates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `issued_by` int(10) unsigned DEFAULT NULL,
  `certificate_no` varchar(50) NOT NULL,
  `recipient_name` varchar(150) NOT NULL,
  `product_name` varchar(200) NOT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `thumbnail_path` varchar(255) DEFAULT NULL,
  `status` enum('active','revoked') NOT NULL DEFAULT 'active',
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid` (`uuid`),
  UNIQUE KEY `uk_cert_no` (`certificate_no`),
  UNIQUE KEY `uk_user_product` (`user_id`,`product_id`),
  KEY `idx_cert_user` (`user_id`),
  KEY `idx_cert_product` (`product_id`),
  KEY `idx_cert_status` (`status`),
  KEY `issued_by` (`issued_by`),
  CONSTRAINT `certificates_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `certificates_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `certificates_ibfk_3` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `chat_leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_leads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `whatsapp` varchar(30) NOT NULL,
  `token` char(32) NOT NULL,
  `transcript` mediumtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_leads_token` (`token`),
  KEY `idx_chat_leads_wa` (`whatsapp`),
  KEY `idx_chat_leads_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `checkout_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `checkout_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `layout_type` enum('standard','minimal','two_step','sidebar_sticky') NOT NULL DEFAULT 'standard',
  `show_phone` tinyint(1) NOT NULL DEFAULT 1,
  `show_address` tinyint(1) NOT NULL DEFAULT 0,
  `show_notes` tinyint(1) NOT NULL DEFAULT 1,
  `show_coupon` tinyint(1) NOT NULL DEFAULT 1,
  `footer_badge_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `footer_badge_text` varchar(100) DEFAULT NULL,
  `footer_badge_icon` varchar(500) DEFAULT NULL,
  `footer_badge_url` varchar(500) DEFAULT NULL,
  `custom_thank_you_message` text DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `checkout_templates` VALUES (1,'Standard Checkout','minimal',1,0,1,1,0,NULL,NULL,NULL,NULL,0,'2026-06-19 12:43:23'),(2,'aksen','two_step',1,0,1,1,0,NULL,NULL,NULL,NULL,0,'2026-06-23 05:02:46');

DROP TABLE IF EXISTS `class_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `class_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_class_product` (`class_id`,`product_id`),
  KEY `idx_cp_product` (`product_id`),
  CONSTRAINT `fk_cp_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `classes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned DEFAULT NULL,
  `lead_magnet_id` int(10) unsigned DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL COMMENT 'FK ke class_categories, NULL = tanpa kategori',
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `unlock_after_days` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort_order` (`sort_order`),
  KEY `idx_classes_lead_magnet` (`lead_magnet_id`),
  CONSTRAINT `fk_classes_lead_magnet` FOREIGN KEY (`lead_magnet_id`) REFERENCES `lead_magnets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_classes_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `commission_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = berlaku semua produk',
  `membership_level_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = berlaku semua level affiliate',
  `required_product_id` int(10) unsigned DEFAULT NULL,
  `commission_rate` decimal(5,2) NOT NULL COMMENT 'persentase komisi (0-100)',
  `priority` int(11) NOT NULL DEFAULT 0 COMMENT 'rule priority lebih tinggi menang jika overlap',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cr_product` (`product_id`),
  KEY `idx_cr_level` (`membership_level_id`),
  KEY `idx_cr_required_product` (`required_product_id`),
  CONSTRAINT `fk_cr_level` FOREIGN KEY (`membership_level_id`) REFERENCES `membership_levels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cr_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cr_required_product` FOREIGN KEY (`required_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `commissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `affiliate_id` int(10) unsigned NOT NULL,
  `order_id` int(10) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `rate` decimal(5,2) NOT NULL,
  `status` enum('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
  `approved_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `admin_note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_affiliate_id` (`affiliate_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_commissions_affiliate` FOREIGN KEY (`affiliate_id`) REFERENCES `affiliates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_commissions_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `coupon_usages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `coupon_usages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `coupon_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `order_id` int(10) unsigned NOT NULL,
  `discount_amount` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cu_coupon` (`coupon_id`),
  KEY `idx_cu_user` (`user_id`),
  KEY `idx_cu_order` (`order_id`),
  CONSTRAINT `fk_coupon_usages_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coupon_usages_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coupon_usages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `coupons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `coupons` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `type` enum('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `value` decimal(15,2) NOT NULL COMMENT 'persen jika percentage, nominal jika fixed',
  `min_purchase` decimal(15,2) NOT NULL DEFAULT 0.00,
  `max_discount` decimal(15,2) DEFAULT NULL COMMENT 'cap diskon untuk type percentage',
  `usage_limit` int(11) DEFAULT NULL COMMENT 'NULL = unlimited',
  `used_count` int(11) NOT NULL DEFAULT 0,
  `applicable_product_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = berlaku semua produk',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `starts_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_coupon_code` (`code`),
  KEY `idx_coupon_status` (`status`),
  KEY `fk_coupons_product` (`applicable_product_id`),
  CONSTRAINT `fk_coupons_product` FOREIGN KEY (`applicable_product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `download_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `download_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `download_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `token_id` int(10) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `downloaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dl_download` (`download_id`),
  KEY `idx_dl_user` (`user_id`),
  KEY `idx_dl_date` (`downloaded_at`),
  CONSTRAINT `download_logs_ibfk_1` FOREIGN KEY (`download_id`) REFERENCES `product_downloads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `download_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `download_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `download_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `download_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_dt_download` (`download_id`),
  KEY `idx_dt_user` (`user_id`),
  KEY `idx_dt_expires` (`expires_at`),
  CONSTRAINT `download_tokens_ibfk_1` FOREIGN KEY (`download_id`) REFERENCES `product_downloads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `download_tokens_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `email_sequence_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_sequence_enrollments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sequence_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `trigger_ref` varchar(100) DEFAULT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','completed','unsubscribed') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_enrollment` (`sequence_id`,`user_id`),
  KEY `idx_ese_sequence` (`sequence_id`),
  KEY `idx_ese_user` (`user_id`),
  KEY `idx_ese_status` (`status`),
  CONSTRAINT `email_sequence_enrollments_ibfk_1` FOREIGN KEY (`sequence_id`) REFERENCES `email_sequences` (`id`) ON DELETE CASCADE,
  CONSTRAINT `email_sequence_enrollments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `email_sequence_sends`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_sequence_sends` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `enrollment_id` int(10) unsigned NOT NULL,
  `step_id` int(10) unsigned NOT NULL,
  `status` enum('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  `scheduled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `response` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_send` (`enrollment_id`,`step_id`),
  KEY `idx_ess_status_sched` (`status`,`scheduled_at`),
  KEY `step_id` (`step_id`),
  CONSTRAINT `email_sequence_sends_ibfk_1` FOREIGN KEY (`enrollment_id`) REFERENCES `email_sequence_enrollments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `email_sequence_sends_ibfk_2` FOREIGN KEY (`step_id`) REFERENCES `email_sequence_steps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `email_sequence_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_sequence_steps` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sequence_id` int(10) unsigned NOT NULL,
  `step_number` int(11) NOT NULL DEFAULT 1,
  `delay_days` int(11) NOT NULL DEFAULT 0,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `body_format` enum('text','html') NOT NULL DEFAULT 'text',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ess_sequence` (`sequence_id`),
  KEY `idx_ess_delay` (`delay_days`),
  CONSTRAINT `email_sequence_steps_ibfk_1` FOREIGN KEY (`sequence_id`) REFERENCES `email_sequences` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `email_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_sequences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `trigger_event` enum('order_paid','member_access_activated','user_register','waitlist_joined','booking_paid','lead_captured') NOT NULL DEFAULT 'order_paid',
  `trigger_product_id` int(10) unsigned DEFAULT NULL,
  `channel` enum('email') NOT NULL DEFAULT 'email',
  `provider` varchar(50) NOT NULL DEFAULT 'mailketing',
  `external_ref` varchar(191) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'inactive',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_es_status` (`status`),
  KEY `idx_es_trigger` (`trigger_event`),
  KEY `trigger_product_id` (`trigger_product_id`),
  UNIQUE KEY `uk_email_seq_external_ref` (`external_ref`),
  CONSTRAINT `email_sequences_ibfk_1` FOREIGN KEY (`trigger_product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `email_sequence_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_sequence_products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sequence_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `kind` enum('include','exclude') NOT NULL DEFAULT 'include',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_esp` (`sequence_id`,`product_id`,`kind`),
  KEY `idx_esp_seq` (`sequence_id`),
  KEY `idx_esp_prod` (`product_id`),
  CONSTRAINT `fk_esp_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_esp_seq` FOREIGN KEY (`sequence_id`) REFERENCES `email_sequences` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `outgoing_webhooks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `outgoing_webhooks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `url` varchar(500) NOT NULL,
  `secret` varchar(80) NOT NULL,
  `event` varchar(50) NOT NULL DEFAULT 'order.paid',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ow_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `webhook_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `webhook_deliveries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `webhook_id` int(10) unsigned NOT NULL,
  `event` varchar(50) NOT NULL,
  `payload` text NOT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `http_code` int(11) DEFAULT NULL,
  `response` varchar(500) DEFAULT NULL,
  `order_number` varchar(50) DEFAULT NULL,
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wd_status` (`status`,`next_attempt_at`),
  KEY `idx_wd_webhook` (`webhook_id`),
  CONSTRAINT `fk_wd_webhook` FOREIGN KEY (`webhook_id`) REFERENCES `outgoing_webhooks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `funnel_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `funnel_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `bundle_id` int(10) unsigned DEFAULT NULL,
  `event_type` enum('lp_view','checkout_click') NOT NULL,
  `session_id` varchar(128) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_product_event_date` (`product_id`,`event_type`,`created_at`),
  KEY `idx_bundle_event_date` (`bundle_id`,`event_type`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `funnel_presence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `funnel_presence` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` varchar(128) NOT NULL,
  `product_id` int(10) unsigned NOT NULL DEFAULT 0,
  `bundle_id` int(10) unsigned NOT NULL DEFAULT 0,
  `stage` enum('salespage','checkout') NOT NULL DEFAULT 'salespage',
  `last_seen` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_presence` (`session_id`,`product_id`,`bundle_id`),
  KEY `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `user_presence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_presence` (
  `user_id` int(10) unsigned NOT NULL,
  `last_seen` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  KEY `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `login_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `order_id` int(10) unsigned DEFAULT NULL,
  `product_id` int(10) unsigned DEFAULT NULL,
  `purpose` varchar(32) NOT NULL DEFAULT 'access_link',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_hash` (`token_hash`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `integration_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `integration_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(50) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `request` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request`)),
  `response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response`)),
  `status` enum('success','failed') NOT NULL DEFAULT 'success',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_provider` (`provider`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `jv_earnings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jv_earnings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `partner_user_id` int(10) unsigned NOT NULL,
  `order_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `affiliate_commission` decimal(15,2) NOT NULL DEFAULT 0.00,
  `percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('unpaid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jv_order` (`order_id`),
  KEY `idx_jv_partner` (`partner_user_id`,`status`),
  KEY `idx_jv_product` (`product_id`),
  CONSTRAINT `fk_jv_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jv_partner` FOREIGN KEY (`partner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `lead_magnets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lead_magnets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(160) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `linked_product_id` int(10) unsigned DEFAULT NULL,
  `headline` varchar(255) DEFAULT NULL,
  `subheadline` text DEFAULT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `badge_text` varchar(60) DEFAULT NULL,
  `cta_text` varchar(60) DEFAULT NULL,
  `bonus_membership_level_id` int(10) unsigned DEFAULT NULL,
  `fb_pixel_ids` text DEFAULT NULL,
  `thankyou_message` text DEFAULT NULL,
  `access_type` enum('course','embed') NOT NULL DEFAULT 'course',
  `embed_html` text DEFAULT NULL,
  `embed_url` varchar(500) DEFAULT NULL,
  `status` enum('draft','published','paused') NOT NULL DEFAULT 'draft',
  `show_on_homepage` tinyint(1) NOT NULL DEFAULT 0,
  `affiliate_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `tripwire_product_id` int(10) unsigned DEFAULT NULL,
  `tripwire_headline` varchar(200) DEFAULT NULL,
  `require_otp` tinyint(1) NOT NULL DEFAULT 1,
  `total_leads` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lead_magnet_slug` (`slug`),
  KEY `idx_lm_status` (`status`),
  KEY `idx_lm_created` (`created_at`),
  KEY `fk_lm_bonus_level` (`bonus_membership_level_id`),
  KEY `idx_lm_product` (`linked_product_id`),
  CONSTRAINT `fk_lm_bonus_level` FOREIGN KEY (`bonus_membership_level_id`) REFERENCES `membership_levels` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_lm_product` FOREIGN KEY (`linked_product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lead_magnet_id` int(10) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `whatsapp` varchar(30) NOT NULL,
  `email` varchar(160) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `source` varchar(80) DEFAULT NULL,
  `status` enum('new','engaged','customer') NOT NULL DEFAULT 'new',
  `promoted_user_id` int(10) unsigned DEFAULT NULL,
  `affiliate_id` int(10) unsigned DEFAULT NULL,
  `bonus_granted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_leads_magnet_created` (`lead_magnet_id`,`created_at`),
  KEY `idx_leads_magnet_status` (`lead_magnet_id`,`status`),
  KEY `idx_leads_whatsapp` (`whatsapp`),
  KEY `idx_leads_email` (`email`),
  KEY `fk_leads_user` (`promoted_user_id`),
  KEY `idx_leads_affiliate` (`affiliate_id`),
  CONSTRAINT `fk_leads_magnet` FOREIGN KEY (`lead_magnet_id`) REFERENCES `lead_magnets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_leads_user` FOREIGN KEY (`promoted_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leads_affiliate` FOREIGN KEY (`affiliate_id`) REFERENCES `affiliates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `license_activations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `license_activations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `license_id` int(10) unsigned NOT NULL,
  `string_identifier` varchar(255) NOT NULL COMMENT 'domain, IP, device ID, dll',
  `ip_address` varchar(45) DEFAULT NULL,
  `app_version` varchar(20) DEFAULT NULL,
  `status` enum('active','deactivated') NOT NULL DEFAULT 'active',
  `activated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_validated_at` timestamp NULL DEFAULT NULL,
  `deactivated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_license_string` (`license_id`,`string_identifier`),
  KEY `idx_license_id` (`license_id`),
  CONSTRAINT `license_activations_ibfk_1` FOREIGN KEY (`license_id`) REFERENCES `product_licenses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `license_api_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `license_api_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `license_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL jika key tidak ditemukan',
  `endpoint` enum('activate','validate','deactivate') NOT NULL,
  `string_identifier` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `result` enum('success','failed','not_found','suspended','expired','seats_full','domain_mismatch') NOT NULL,
  `message` varchar(255) DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_license_id` (`license_id`),
  KEY `idx_endpoint` (`endpoint`),
  KEY `idx_requested_at` (`requested_at`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `license_fulfillments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `license_fulfillments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `action` enum('new','renew') NOT NULL DEFAULT 'new',
  `plan` varchar(50) NOT NULL,
  `license_key` varchar(24) DEFAULT NULL,
  `status` enum('pending','done','failed') NOT NULL DEFAULT 'pending',
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_action` (`order_number`,`action`),
  KEY `idx_due` (`status`,`next_attempt_at`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `license_info`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `license_info` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `license_key` varchar(100) NOT NULL,
  `domain` varchar(200) DEFAULT NULL,
  `plan` enum('monthly','quarterly','yearly','lifetime') DEFAULT NULL,
  `plan_effective` enum('monthly','quarterly','yearly','lifetime') DEFAULT NULL,
  `status` enum('active','expired','suspended','unknown','pending_activation') NOT NULL DEFAULT 'unknown',
  `activation_pending_reason` varchar(255) DEFAULT NULL,
  `activated_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_checked_at` timestamp NULL DEFAULT NULL,
  `raw_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_response`)),
  `signed_token` text DEFAULT NULL,
  `token_valid_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_la_lookup` (`email`,`ip_address`,`created_at`),
  KEY `idx_la_ip` (`ip_address`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `material_completions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `material_completions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `material_id` int(10) unsigned NOT NULL,
  `completed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_completion` (`user_id`,`material_id`),
  KEY `idx_mc_user` (`user_id`),
  KEY `idx_mc_material` (`material_id`),
  CONSTRAINT `material_completions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_completions_ibfk_2` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `material_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `material_files` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `material_id` int(10) unsigned NOT NULL,
  `download_id` int(10) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_matfile` (`material_id`,`download_id`),
  KEY `idx_matfile_download` (`download_id`),
  CONSTRAINT `fk_matfile_download` FOREIGN KEY (`download_id`) REFERENCES `product_downloads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_matfile_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `material_resume`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `material_resume` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL DEFAULT 0,
  `lead_magnet_id` int(10) unsigned NOT NULL DEFAULT 0,
  `material_id` int(10) unsigned NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_resume_ctx` (`user_id`,`product_id`,`lead_magnet_id`),
  KEY `idx_resume_material` (`material_id`),
  CONSTRAINT `fk_resume_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resume_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `materials` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(10) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `content` longtext DEFAULT NULL,
  `description` longtext DEFAULT NULL,
  `content_type` enum('text','video','file','link','embed','html') NOT NULL DEFAULT 'text',
  `content_url` varchar(500) DEFAULT NULL,
  `download_id` int(10) unsigned DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_class_id` (`class_id`),
  KEY `idx_sort_order` (`sort_order`),
  KEY `idx_materials_download` (`download_id`),
  CONSTRAINT `fk_materials_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_materials_download` FOREIGN KEY (`download_id`) REFERENCES `product_downloads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `member_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_access` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `order_id` int(10) unsigned NOT NULL,
  `status` enum('active','expired','revoked') NOT NULL DEFAULT 'active',
  `renewal_remind_stage` tinyint(4) NOT NULL DEFAULT 0,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'NULL = akses selamanya (lifetime)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_product` (`user_id`,`product_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_status` (`status`),
  KEY `fk_member_access_order` (`order_id`),
  CONSTRAINT `fk_member_access_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_member_access_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_member_access_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `membership_levels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `membership_levels` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `min_spend` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'total belanja minimum untuk level ini',
  `color` varchar(20) NOT NULL DEFAULT '#94a3b8' COMMENT 'warna badge hex',
  `icon` varchar(50) DEFAULT NULL COMMENT 'medal | star | crown | trophy',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ml_name` (`name`),
  UNIQUE KEY `uk_ml_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `membership_levels` VALUES (1,'Bronze','bronze',0.00,'#a16207','medal',1,'2026-06-19 06:10:25'),(2,'Silver','silver',500000.00,'#64748b','medal',2,'2026-06-19 06:10:25'),(3,'Gold','gold',2000000.00,'#ca8a04','star',3,'2026-06-19 06:10:25'),(4,'Platinum','platinum',5000000.00,'#7c3aed','crown',4,'2026-06-19 06:10:25');

DROP TABLE IF EXISTS `notification_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_key` varchar(100) DEFAULT NULL,
  `channel` varchar(20) DEFAULT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `recipient` varchar(200) DEFAULT NULL,
  `dedup_key` varchar(191) DEFAULT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `response` text DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `body` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_event_key` (`event_key`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_notiflog_dedup` (`dedup_key`,`event_key`,`channel`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `notification_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_key` varchar(100) NOT NULL COMMENT 'register, order_created, payment_success, dll',
  `channel` enum('email','whatsapp','telegram') NOT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_event_channel` (`event_key`,`channel`)
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `notification_templates` VALUES (1,'register','email','mailketing','Selamat Datang di {{app_name}}! 🎉','<p>Halo <strong>{{name}}</strong>,</p><p>Akun kamu di <strong>{{app_name}}</strong> sudah berhasil dibuat. Selamat bergabung! 🎉</p><p>Gunakan email <strong>{{email}}</strong> untuk masuk ke dashboard kamu.</p><p style=\"text-align:center;margin:28px 0\"><a href=\"{{dashboard_url}}\" style=\"display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px\">Masuk ke Dashboard →</a></p><p style=\"font-size:13px;color:#8a94a6\">Jika kamu tidak merasa mendaftar, cukup abaikan email ini.</p>',1,'2026-06-20 12:00:45','2026-07-03 14:48:39'),(2,'register','whatsapp','starsender',NULL,'Halo {{name}}! 👋\n\nSelamat datang di *{{app_name}}* 🎉\n\nAkun kamu sudah aktif. Silakan login di:\n{{login_url}}\n\n📧 Email login: {{email}}\n\nSalam,\nTim {{app_name}}',1,'2026-06-20 12:00:45','2026-07-03 14:33:40'),(3,'order_created','email','mailketing','Pesanan Diterima — Menunggu Pembayaran','<p>Halo <strong>{{name}}</strong>,</p><p>Pesanan kamu sudah kami terima dan sedang menunggu pembayaran. ⏳</p><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f6f8fb;border-radius:12px;margin:16px 0\"><tr><td style=\"padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;\">Produk</td><td style=\"padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;\">{{product_name}}</td></tr><tr><td style=\"padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;\">No. Pesanan</td><td style=\"padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;\">{{order_number}}</td></tr><tr><td style=\"padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;\">Metode Bayar</td><td style=\"padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;\">{{payment_method}}</td></tr><tr><td style=\"padding:12px 18px;font-size:14px;color:#64748b;text-align:left;\">Total</td><td style=\"padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;\">{{total}}</td></tr></table><p style=\"text-align:center;margin:28px 0\"><a href=\"{{invoice_url}}\" style=\"display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px\">Lihat Invoice &amp; Bayar →</a></p>',1,'2026-06-20 12:00:45','2026-07-03 14:48:39'),(4,'order_created','whatsapp','starsender',NULL,'Halo {{name}}! 🛒\n\nPesanan kamu di *{{app_name}}* sudah kami terima dan menunggu pembayaran.\n\n📋 No. Pesanan: *{{order_number}}*\nProduk: *{{product_name}}*\n💳 Metode: {{payment_method}}\n💰 Total: *{{total}}*\n\nSelesaikan pembayaran di sini:\n{{invoice_url}}\n\nSalam,\nTim {{app_name}}',1,'2026-06-20 12:00:45','2026-07-03 14:33:40'),(5,'payment_success','email','mailketing','Pembayaran Berhasil! ✅','<p>Halo <strong>{{name}}</strong>,</p><p>Pembayaran kamu sudah kami konfirmasi. Terima kasih! 🎉</p><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f6f8fb;border-radius:12px;margin:16px 0\"><tr><td style=\"padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;\">No. Pesanan</td><td style=\"padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;\">{{order_number}}</td></tr><tr><td style=\"padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;\">Tanggal Bayar</td><td style=\"padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;\">{{paid_at}}</td></tr><tr><td style=\"padding:12px 18px;font-size:14px;color:#64748b;text-align:left;\">Total Dibayar</td><td style=\"padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;\">{{total}}</td></tr></table><p style=\"text-align:center;margin:28px 0\"><a href=\"{{member_url}}\" style=\"display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px\">Akses Produk Pembelian →</a></p>',1,'2026-06-20 12:00:45','2026-07-03 14:48:39'),(6,'payment_success','whatsapp','starsender',NULL,'✅ *Pembayaran Berhasil!*\n\nHalo {{name}}, pembayaran kamu sudah kami konfirmasi. Terima kasih! 🙏\n\n📋 No. Pesanan: *{{order_number}}*\n💰 Total: *{{total}}*\n📅 Tanggal: {{paid_at}}\n\n🚀 Akses konten kamu di sini:\n{{member_url}}\n\n🧾 Lihat invoice:\n{{invoice_url}}\n\nTim {{app_name}}',1,'2026-06-20 12:00:45','2026-07-03 14:33:40'),(7,'access_activated','email','mailketing','Akses Kamu Sudah Aktif! 🚀','<p>Halo <strong>{{name}}</strong>,</p><p>Akses kamu untuk <strong>{{product_name}}</strong> sudah aktif sepenuhnya! 🎉</p><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f6f8fb;border-radius:12px;margin:16px 0\"><tr><td style=\"padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;\">Produk</td><td style=\"padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;\">{{product_name}}</td></tr><tr><td style=\"padding:12px 18px;font-size:14px;color:#64748b;text-align:left;\">No. Pesanan</td><td style=\"padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;\">{{order_number}}</td></tr></table><p style=\"text-align:center;margin:28px 0\"><a href=\"{{member_url}}\" style=\"display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px\">Akses Produk Pembelian →</a></p>',1,'2026-06-20 12:00:45','2026-07-03 14:48:39'),(8,'access_activated','whatsapp','starsender',NULL,'🚀 *Akses Sudah Aktif!*\n\nHalo {{name}}, akses kamu untuk *{{product_name}}* sudah aktif sepenuhnya! 🎉\n\n📦 Pesanan: *{{order_number}}*\n\n▶️ Mulai akses konten sekarang:\n{{member_url}}\n\n🧾 Lihat invoice:\n{{invoice_url}}\n\nSelamat menikmati!\nTim {{app_name}}',1,'2026-06-20 12:00:46','2026-07-03 14:33:40'),(9,'public_checkout_welcome','email','mailketing','Akun kamu di {{app_name}} sudah siap! 🎉','<p>Halo <strong>{{name}}</strong>,</p><p>Terima kasih sudah melakukan pembelian di <strong>{{app_name}}</strong>! 🎉 Kami sudah otomatis membuatkan akun untukmu:</p><p style=\"background:#f6f8fb;border-radius:10px;padding:16px 20px;font-size:14px;color:#475569;line-height:1.9\">📧 Email: <strong>{{email}}</strong><br>🔑 Password sementara: <strong>{{temp_password}}</strong></p><p>Silakan login lalu segera ganti passwordmu demi keamanan. 🔒</p><p style=\"text-align:center;margin:28px 0\"><a href=\"{{login_url}}\" style=\"display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px\">Login Sekarang →</a></p><p style=\"font-size:13px;color:#8a94a6\">Buka area member: {{member_url}}</p>',1,'2026-06-24 05:46:17','2026-07-03 14:48:39'),(10,'lead_captured','email','mailketing','Terima kasih sudah daftar, {{name}}! 🎉','<p>Halo <strong>{{name}}</strong>,</p><p>Terima kasih sudah mendaftar di <strong>{{lead_magnet}}</strong>! 🎉</p><p>Akses dan informasi selanjutnya akan kami kirimkan ke kamu. Pantau terus email dan WhatsApp-mu ya. 📩</p>',1,'2026-06-24 16:13:55','2026-07-03 14:33:40'),(11,'lead_captured','whatsapp','starsender',NULL,'Halo {{name}} 👋\n\nTerima kasih sudah mendaftar di *{{lead_magnet}}* 🎉\nAkses & info selanjutnya akan kami kirimkan ke sini ya.\n\nTim {{app_name}}',1,'2026-06-24 16:13:55','2026-07-03 14:33:40'),(12,'lead_admin_notify','whatsapp','starsender',NULL,'🔔 *Lead Baru Masuk!*\n\n👤 Nama: {{name}}\n📱 WA: {{whatsapp}}\n📧 Email: {{email}}\n📄 Form: {{lead_magnet}}\n\n{{app_name}}',1,'2026-06-24 16:13:55','2026-07-03 14:33:40'),(13,'abandoned_cart','email','mailketing','Pesananmu belum selesai, {{name}} 🛒','<p>Halo <strong>{{name}}</strong>,</p><p>Kamu sudah mulai memesan <strong>{{product_name}}</strong> seharga <strong>{{total}}</strong>, tapi pembayarannya belum selesai. 🛒</p><p style=\"text-align:center;margin:28px 0\"><a href=\"{{checkout_url}}\" style=\"display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px\">Selesaikan Pembayaran →</a></p><p style=\"font-size:13px;color:#8a94a6\">Atau buka tautan ini: {{checkout_url}}<br>No. Pesanan: {{order_number}}</p><p style=\"font-size:13px;color:#8a94a6\">Kalau kamu sudah membayar, abaikan email ini. 🙏</p>',1,'2026-06-26 14:32:20','2026-07-03 14:48:39'),(14,'abandoned_cart','whatsapp','starsender',NULL,'Halo {{name}} 👋\n\nPesananmu untuk *{{product_name}}* ({{total}}) belum selesai nih.\n\nYuk lanjutkan pembayaran di sini:\n{{checkout_url}}\n\nKalau kamu sudah membayar, abaikan pesan ini ya. 🙏\n\nTim {{app_name}}',1,'2026-06-26 14:32:20','2026-07-03 14:33:40'),(15,'commission_approved','email','mailketing','Komisi kamu disetujui! 💰','<p>Halo <strong>{{name}}</strong>,</p><p>Kabar baik! 🎉 Komisi kamu sebesar <strong>{{amount}}</strong> dari pesanan <strong>{{order_number}}</strong> sudah <strong>disetujui</strong> dan siap dicairkan.</p><p style=\"text-align:center;margin:28px 0\"><a href=\"{{affiliate_url}}\" style=\"display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px\">Ajukan Pencairan →</a></p><p style=\"font-size:13px;color:#8a94a6\">Atau buka dashboard afiliasi: {{affiliate_url}}</p>',1,'2026-06-26 14:35:50','2026-07-03 14:48:39'),(16,'commission_approved','whatsapp','starsender',NULL,'💰 *Komisi Disetujui!*\n\nHalo {{name}}, komisi kamu sebesar *{{amount}}* dari pesanan {{order_number}} sudah disetujui dan siap dicairkan. 🎉\n\nAjukan pencairan di dashboard afiliasi:\n{{affiliate_url}}\n\nTim {{app_name}}',1,'2026-06-26 14:35:50','2026-07-03 14:33:40'),(17,'payout_processing','email','mailketing','Pencairan kamu sedang diproses ⏳','<p>Halo <strong>{{name}}</strong>,</p><p>Permintaan pencairan komisimu sebesar <strong>{{amount}}</strong> sedang kami proses. ⏳</p><p style=\"background:#f6f8fb;border-radius:10px;padding:16px 20px;font-size:14px;color:#475569;line-height:1.9\">🏦 Bank: <strong>{{bank_name}}</strong><br>🔢 No. Rekening: <strong>{{bank_account_no}}</strong><br>👤 Atas Nama: <strong>{{bank_account_name}}</strong></p><p>Kami akan mengabari lagi begitu dana selesai ditransfer. 🙏</p><p style=\"font-size:13px;color:#8a94a6\">Cek status di dashboard afiliasi: {{affiliate_url}}</p>',1,'2026-06-26 14:35:50','2026-07-03 14:33:40'),(18,'payout_paid','email','mailketing','Pencairan komisimu berhasil! ✅','<p>Halo <strong>{{name}}</strong>,</p><p>Komisi kamu sebesar <strong>{{amount}}</strong> sudah berhasil kami transfer ke rekening <strong>{{bank_name}}</strong>. 💸</p><p style=\"text-align:center;margin:28px 0\"><a href=\"{{affiliate_url}}\" style=\"display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px\">Lihat Riwayat Komisi →</a></p><p>Terima kasih sudah menjadi mitra kami! 🙏</p>',1,'2026-06-26 14:35:50','2026-07-03 14:48:39'),(19,'payout_paid','whatsapp','starsender',NULL,'✅ *Pencairan Berhasil!*\n\nHalo {{name}}, komisi kamu sebesar *{{amount}}* sudah kami transfer ke rekening *{{bank_name}}*. 💸\n\nCek riwayat di dashboard afiliasi:\n{{affiliate_url}}\n\nTerima kasih sudah menjadi mitra kami! 🙏\n\nTim {{app_name}}',1,'2026-06-26 14:35:50','2026-07-03 14:33:40'),(20,'payout_rejected','email','mailketing','Permintaan pencairan ditolak ⚠️','<p>Halo <strong>{{name}}</strong>,</p><p>Maaf, permintaan pencairan sebesar <strong>{{amount}}</strong> belum dapat kami proses. ⚠️</p><p style=\"background:#fef2f2;border-radius:10px;padding:16px 20px;font-size:14px;color:#b91c1c\">📝 Alasan: {{reason}}</p><p>Silakan periksa kembali data rekeningmu lalu ajukan pencairan ulang.</p><p style=\"text-align:center;margin:28px 0\"><a href=\"{{affiliate_url}}\" style=\"display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px\">Ajukan Ulang →</a></p>',1,'2026-06-26 14:35:50','2026-07-03 14:48:39'),(29,'public_checkout_welcome','whatsapp','starsender',NULL,'Halo {{name}}! 👋\n\nTerima kasih sudah membeli di *{{app_name}}* 🎉\nKami sudah otomatis membuatkan akun untukmu:\n\n📧 Email: {{email}}\n🔑 Password sementara: *{{temp_password}}*\n\nLogin lalu segera ganti passwordmu di:\n{{login_url}}\n\n🚀 Buka area member:\n{{member_url}}\n\nTim {{app_name}}',1,'2026-07-03 14:33:40','2026-07-03 14:33:40'),(30,'payout_processing','whatsapp','starsender',NULL,'⏳ *Pencairan Diproses*\n\nHalo {{name}}, permintaan pencairan komisimu sebesar *{{amount}}* sedang kami proses.\n\n🏦 Bank: {{bank_name}}\n🔢 No. Rekening: {{bank_account_no}}\n👤 A/N: {{bank_account_name}}\n\nKami kabari lagi begitu dana selesai ditransfer. 🙏\n\nTim {{app_name}}',1,'2026-07-03 14:33:40','2026-07-03 14:33:40'),(31,'payout_rejected','whatsapp','starsender',NULL,'⚠️ *Pencairan Ditolak*\n\nHalo {{name}}, maaf permintaan pencairan sebesar *{{amount}}* belum dapat kami proses.\n\n📝 Alasan: {{reason}}\n\nSilakan periksa kembali data rekeningmu lalu ajukan ulang di:\n{{affiliate_url}}\n\nTim {{app_name}}',1,'2026-07-03 14:33:40','2026-07-03 14:33:40');
INSERT INTO `notification_templates` VALUES (32,'renewal_reminder','email','mailketing','Akses {{product_name}} akan berakhir ⏰','<p>Halo <strong>{{name}}</strong>,</p><p>Akses kamu untuk <strong>{{product_name}}</strong> akan <strong>berakhir dalam {{days_left}} hari</strong> (pada {{expiry_date}}).</p><p>Perpanjang sekarang agar akses tetap aktif tanpa terputus. 🔄</p><p style="text-align:center;margin:28px 0"><a href="{{renew_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Perpanjang Akses →</a></p><p style="font-size:13px;color:#8a94a6">Atau buka tautan ini: {{renew_url}}</p>',1,'2026-07-05 00:00:00','2026-07-05 00:00:00'),(33,'renewal_reminder','whatsapp','starsender',NULL,'⏰ *Akses Akan Berakhir*\n\nHalo {{name}}, akses kamu untuk *{{product_name}}* akan berakhir dalam *{{days_left}} hari* (pada {{expiry_date}}).\n\nPerpanjang sekarang agar tetap aktif:\n{{renew_url}}\n\nTim {{app_name}}',1,'2026-07-05 00:00:00','2026-07-05 00:00:00');
INSERT INTO `notification_templates` VALUES (34,'waitlist_joined','email','mailketing','Kamu masuk daftar tunggu {{product_name}} ✅','<p>Halo <strong>{{name}}</strong>,</p><p>Kamu berhasil masuk daftar tunggu <strong>{{product_name}}</strong>! ✅</p><p>Begitu produk ini resmi dibuka, kami akan langsung mengabari kamu lewat email dan WhatsApp. Pantau terus ya. 📩</p><p style="text-align:center;margin:28px 0"><a href="{{product_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Lihat Halaman Produk →</a></p>',1,'2026-07-08 00:00:00','2026-07-08 00:00:00'),(35,'waitlist_joined','whatsapp','starsender',NULL,'Halo {{name}} 👋\n\nKamu berhasil masuk daftar tunggu *{{product_name}}* ✅\n\nBegitu produk dibuka, kami langsung kabari kamu di sini.\n\nTim {{app_name}}',1,'2026-07-08 00:00:00','2026-07-08 00:00:00'),(36,'waitlist_launch','email','mailketing','{{product_name}} sudah dibuka! 🚀','<p>Halo <strong>{{name}}</strong>,</p><p>Kabar yang kamu tunggu: <strong>{{product_name}}</strong> sudah resmi dibuka! 🎉</p><p>Kamu termasuk yang paling awal tahu — amankan aksesmu sekarang sebelum kehabisan.</p><p style="text-align:center;margin:28px 0"><a href="{{product_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Amankan Akses Sekarang →</a></p><p style="font-size:13px;color:#8a94a6">Atau buka tautan ini: {{product_url}}</p>',1,'2026-07-08 00:00:00','2026-07-08 00:00:00'),(37,'waitlist_launch','whatsapp','starsender',NULL,'🚀 *{{product_name}} Sudah Dibuka!*\n\nHalo {{name}}, produk yang kamu tunggu sudah resmi rilis 🎉\n\nKamu termasuk yang paling awal tahu — amankan aksesmu sekarang:\n{{product_url}}\n\nTim {{app_name}}',1,'2026-07-08 00:00:00','2026-07-08 00:00:00');
INSERT INTO `notification_templates` VALUES (38,'booking_confirmed','email','mailketing','Booking kamu dikonfirmasi — slot {{product_name}} aman! 🔒','<p>Halo <strong>{{name}}</strong>,</p><p>Booking fee kamu untuk <strong>{{product_name}}</strong> sudah kami terima. Slot &amp; harga kamu <strong>terkunci</strong>! 🔒</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fb;border-radius:12px;margin:16px 0"><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">Booking Dibayar</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{booking_amount}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">Sisa Pelunasan</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{settlement_total}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;">Batas Pelunasan</td><td style="padding:12px 18px;font-size:14px;color:#b91c1c;font-weight:700;text-align:right;">{{due_date}}</td></tr></table><p style="text-align:center;margin:28px 0"><a href="{{settlement_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Bayar Pelunasan →</a></p><p style="font-size:13px;color:#8a94a6">Akses konten terbuka penuh setelah pelunasan. Lewat batas waktu, booking hangus.</p>',1,'2026-07-08 00:00:00','2026-07-08 00:00:00'),(39,'booking_confirmed','whatsapp','starsender',NULL,'🔒 *Booking Dikonfirmasi!*\n\nHalo {{name}}, booking fee kamu untuk *{{product_name}}* sudah kami terima — slot & harga kamu TERKUNCI 🎉\n\n💰 Booking dibayar: *{{booking_amount}}*\n💳 Sisa pelunasan: *{{settlement_total}}*\n⏰ Batas pelunasan: *{{due_date}}*\n\nBayar pelunasan di sini:\n{{settlement_url}}\n\nAkses konten terbuka penuh setelah lunas. Lewat batas waktu, booking hangus ya.\n\nTim {{app_name}}',1,'2026-07-08 00:00:00','2026-07-08 00:00:00'),(40,'booking_expired','email','mailketing','Batas pelunasan {{product_name}} terlewat ⚠️','<p>Halo <strong>{{name}}</strong>,</p><p>Sayang sekali — batas waktu pelunasan untuk <strong>{{product_name}}</strong> ({{due_date}}) sudah terlewat, sehingga booking kamu <strong>hangus</strong>. ⚠️</p><p>Masih berminat? Kamu tetap bisa memesan lagi kapan saja selama produk tersedia.</p><p style="text-align:center;margin:28px 0"><a href="{{product_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Pesan Lagi →</a></p>',1,'2026-07-08 00:00:00','2026-07-08 00:00:00'),(41,'booking_expired','whatsapp','starsender',NULL,'⚠️ *Booking Hangus*\n\nHalo {{name}}, batas waktu pelunasan untuk *{{product_name}}* ({{due_date}}) sudah terlewat, sehingga booking kamu hangus.\n\nMasih berminat? Kamu bisa pesan lagi di:\n{{product_url}}\n\nTim {{app_name}}',1,'2026-07-08 00:00:00','2026-07-08 00:00:00');
INSERT INTO `notification_templates` VALUES (63,'settlement_reminder','email','mailketing','Jangan sampai hangus ÔÇö pelunasan {{product_name}} tinggal {{days_left}} hari lagi ÔÅ░','<p>Halo <strong>{{name}}</strong>,</p><p>Batas waktu pelunasan booking kamu untuk <strong>{{product_name}}</strong> tinggal <strong>{{days_left}} hari</strong> lagi.</p><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f6f8fb;border-radius:12px;margin:16px 0\"><tr><td style=\"padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;\">Sisa Pelunasan</td><td style=\"padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;\">{{settlement_total}}</td></tr><tr><td style=\"padding:12px 18px;font-size:14px;color:#64748b;text-align:left;\">Batas Pelunasan</td><td style=\"padding:12px 18px;font-size:14px;color:#b91c1c;font-weight:700;text-align:right;\">{{due_date}}</td></tr></table><p>Lewat dari batas waktu, booking kamu <strong>hangus</strong> dan slot dilepas. Amankan sekarang:</p><p style=\"text-align:center;margin:28px 0\"><a href=\"{{settlement_url}}\" style=\"display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px\">Bayar Pelunasan Sekarang ÔåÆ</a></p>',1,'2026-07-08 15:30:18','2026-07-08 15:30:18'),(64,'settlement_reminder','whatsapp','starsender',NULL,'ÔÅ░ *Reminder Pelunasan*\n\nHalo {{name}}, batas waktu pelunasan *{{product_name}}* tinggal *{{days_left}} hari* lagi ({{due_date}}).\n\n­ƒÆ│ Sisa pelunasan: *{{settlement_total}}*\n\nBayar di sini sebelum hangus:\n{{settlement_url}}\n\nTim {{app_name}}',1,'2026-07-08 15:30:18','2026-07-08 15:30:18');
INSERT INTO `notification_templates` VALUES (65,'payment_admin_notify','whatsapp','starsender',NULL,'💰 *Pembayaran Masuk!*\n\n👤 Nama: {{name}}\n📧 Email: {{email}}\n📋 No. Pesanan: *{{order_number}}*\n💵 Total: *{{total}}*\n📅 Tanggal: {{paid_at}}\n\n🧾 Invoice: {{invoice_url}}\n\n{{app_name}}',1,'2026-07-14 00:00:00','2026-07-14 00:00:00');
INSERT INTO `notification_templates` VALUES (66,'renewal_expired','email','mailketing','Akses {{product_name}} sudah berakhir — aktifkan lagi?','<p>Halo <strong>{{name}}</strong>,</p><p>Akses kamu untuk <strong>{{product_name}}</strong> sudah berakhir pada <strong>{{expiry_date}}</strong> ({{days_ago}} hari lalu).</p><p>Belum sempat perpanjang? Tenang, semua progres kamu masih tersimpan — aktifkan lagi kapan pun dan lanjutkan dari tempat terakhir kamu berhenti. 🔄</p><p style="text-align:center;margin:28px 0"><a href="{{renew_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Aktifkan Lagi →</a></p><p style="font-size:13px;color:#8a94a6">Atau buka tautan ini: {{renew_url}}</p>',1,'2026-07-17 00:00:00','2026-07-17 00:00:00'),(67,'renewal_expired','whatsapp','starsender',NULL,'Halo {{name}} 👋\n\nAkses kamu untuk *{{product_name}}* sudah berakhir pada {{expiry_date}} ({{days_ago}} hari lalu).\n\nProgres kamu masih tersimpan — aktifkan lagi kapan pun dan lanjut dari tempat terakhir:\n{{renew_url}}\n\nTim {{app_name}}',1,'2026-07-17 00:00:00','2026-07-17 00:00:00');
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('access_link','email','mailketing','Akses {{product_name}} sudah aktif — masuk langsung','<p>Halo <strong>{{name}}</strong>,</p><p>Pembayaran kamu untuk <strong>{{product_name}}</strong> sudah kami terima dan akses produknya sudah aktif. Klik tombol di bawah untuk langsung masuk ke area member tanpa perlu login manual:</p><p style="text-align:center;margin:28px 0"><a href="{{access_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Akses Produk Sekarang →</a></p><p style="font-size:13px;color:#8a94a6">Link ini berlaku {{expiry_minutes}} menit dan hanya bisa dipakai sekali demi keamanan akun kamu. Kalau sudah kedaluwarsa, kamu tetap bisa masuk lewat halaman login seperti biasa di {{member_url}}.</p>',1),('access_link','whatsapp','starsender',NULL,'Halo {{name}}!\n\nPembayaran untuk *{{product_name}}* sudah diterima & akses produk sudah aktif.\n\nMasuk langsung ke area member (tanpa perlu login manual):\n{{access_url}}\n\nLink berlaku {{expiry_minutes}} menit & sekali pakai. Kalau kedaluwarsa, login biasa tetap bisa.\n\nSalam,\nTim {{app_name}}',1);
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('order_cancelled','whatsapp','starsender',NULL,'❌ *Transaksi Dibatalkan*\n\nHalo {{name}}, transaksi kamu berikut telah *dibatalkan*:\n\n📋 No. Pesanan: *{{order_number}}*\n📦 Produk: {{product_name}}\n💰 Total: {{total}}\n\nJika ini bukan permintaanmu atau kamu butuh bantuan, silakan balas pesan ini.\n\nTim {{app_name}}',1);

INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('payment_admin_notify','telegram','telegram',NULL,'💰 PEMBAYARAN MASUK\n\n👤 Nama: {{name}}\n📧 Email: {{email}}\n📱 WA: {{whatsapp}}\n📦 Produk: {{product_name}}\n📋 No. Pesanan: {{order_number}}\n💵 Total: {{total}}\n📅 Waktu: {{paid_at}}\n\n🧾 Invoice: {{invoice_url}}\n\n{{app_name}}',1);
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('support_admin_notify','telegram','telegram',NULL,'🎫 TIKET SUPPORT BARU\n\n👤 Dari: {{name}}\n📋 No. Tiket: {{ticket_number}}\n📝 Subjek: {{subject}}\n\n💬 Pesan:\n{{message}}\n\n🔗 Balas: {{ticket_url}}\n\n{{app_name}}',1);
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('affiliate_new_order','email','mailketing','Ada penjualan baru via link kamu! 💰','<p>Halo <strong>{{name}}</strong>,</p><p>Kabar baik! 🎉 Ada penjualan baru lewat link afiliasi kamu.</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fb;border-radius:12px;margin:16px 0"><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">Produk</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{product_name}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;border-bottom:1px solid #e9edf4;">No. Pesanan</td><td style="padding:12px 18px;font-size:14px;color:#0f172a;font-weight:700;text-align:right;border-bottom:1px solid #e9edf4;">{{order_number}}</td></tr><tr><td style="padding:12px 18px;font-size:14px;color:#64748b;text-align:left;">Komisi Kamu</td><td style="padding:12px 18px;font-size:15px;color:#16a34a;font-weight:800;text-align:right;">{{amount}}</td></tr></table><p style="text-align:center;margin:28px 0"><a href="{{affiliate_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Lihat Dashboard Afiliasi →</a></p><p style="font-size:13px;color:#8a94a6">Terus sebar link kamu untuk komisi berikutnya! 🚀</p>',1) ON DUPLICATE KEY UPDATE `event_key` = `event_key`;
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('affiliate_new_order','whatsapp','starsender',NULL,'💰 *Penjualan Baru via Link Kamu!*\n\nHalo {{name}}, ada yang baru saja membeli *{{product_name}}* lewat link afiliasimu 🎉\n\n💵 Komisi: *{{amount}}*\n📋 No. Pesanan: {{order_number}}\n\nCek dashboard afiliasi:\n{{affiliate_url}}\n\nTerus sebar linkmu untuk komisi berikutnya! 🚀\n\nTim {{app_name}}',1) ON DUPLICATE KEY UPDATE `event_key` = `event_key`;
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('abandoned_cart_bonus','email','mailketing',NULL,'<p style="margin:16px 0 0;padding:12px 14px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;color:#065f46;font-size:14px">🎁 <strong>Bonus gratis</strong> ({{flash_bonus_list}}) sudah dikunci untuk order ini — selesaikan pembayaran agar bonus tidak hangus.</p>',1) ON DUPLICATE KEY UPDATE `event_key` = `event_key`;
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('abandoned_cart_bonus','whatsapp','starsender',NULL,'🎁 *Bonus gratis* ({{flash_bonus_list}}) sudah dikunci untuk order ini — selesaikan pembayaran agar bonus tidak hangus.',1) ON DUPLICATE KEY UPDATE `event_key` = `event_key`;
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('abandoned_cart_2','email','mailketing','Masih tertarik dengan {{product_name}}? 🤔','<p>Halo <strong>{{name}}</strong>,</p><p>Kami lihat pesananmu untuk <strong>{{product_name}}</strong> (<strong>{{total}}</strong>) masih menunggu pembayaran. Mungkin ada kendala saat checkout? 🤔</p><p>Kalau butuh bantuan, cukup balas email ini — kami siap membantu. Atau selesaikan sekarang lewat tombol di bawah:</p><p style="text-align:center;margin:28px 0"><a href="{{checkout_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Lanjutkan Pembayaran →</a></p><p style="font-size:13px;color:#8a94a6">No. Pesanan: {{order_number}}</p><p style="font-size:13px;color:#8a94a6">Sudah membayar? Abaikan email ini. 🙏</p>',1) ON DUPLICATE KEY UPDATE `event_key` = `event_key`;
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('abandoned_cart_2','whatsapp','starsender',NULL,'Halo {{name}} 😊\n\nPesananmu untuk *{{product_name}}* ({{total}}) masih menunggu pembayaran nih. Ada kendala saat checkout?\n\nKalau butuh bantuan, balas saja pesan ini ya. Atau lanjutkan di sini:\n{{checkout_url}}\n\nSudah bayar? Abaikan pesan ini. 🙏\n\nTim {{app_name}}',1) ON DUPLICATE KEY UPDATE `event_key` = `event_key`;
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('abandoned_cart_3','email','mailketing','Pengingat terakhir untuk {{product_name}} ⏰','<p>Halo <strong>{{name}}</strong>,</p><p>Ini pengingat <strong>terakhir</strong> dari kami. Pesananmu untuk <strong>{{product_name}}</strong> (<strong>{{total}}</strong>) masih belum dibayar dan akan segera kedaluwarsa. ⏰</p><p>Jangan sampai kesempatan ini lewat — selesaikan pembayaranmu sekarang:</p><p style="text-align:center;margin:28px 0"><a href="{{checkout_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Selesaikan Sekarang →</a></p><p style="font-size:13px;color:#8a94a6">No. Pesanan: {{order_number}}</p><p style="font-size:13px;color:#8a94a6">Ini pesan terakhir soal pesanan ini. Kalau sudah membayar, abaikan ya. 🙏</p>',1) ON DUPLICATE KEY UPDATE `event_key` = `event_key`;
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('abandoned_cart_3','whatsapp','starsender',NULL,'Halo {{name}} ⏰\n\nIni *pengingat terakhir* ya. Pesananmu untuk *{{product_name}}* ({{total}}) belum dibayar dan akan segera kedaluwarsa.\n\nSelesaikan sekarang sebelum terlambat:\n{{checkout_url}}\n\nNo. Pesanan: {{order_number}}\n\nIni pesan terakhir soal pesanan ini. Sudah bayar? Abaikan ya. 🙏\n\nTim {{app_name}}',1) ON DUPLICATE KEY UPDATE `event_key` = `event_key`;
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('support_admin_notify','whatsapp','starsender',NULL,'🎫 *Tiket Support Baru*\n\n👤 Dari: {{name}}\n📋 No. Tiket: *{{ticket_number}}*\n📝 Subjek: {{subject}}\n\n💬 {{message}}\n\nBalas di sini:\n{{ticket_url}}\n\n{{app_name}}',1);
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('support_reply','whatsapp','starsender',NULL,'💬 *Balasan Tiket Support*\n\nHalo {{name}}, tim kami sudah membalas tiket *{{ticket_number}}* ({{subject}}).\n\n{{message}}\n\nLihat & balas di sini:\n{{ticket_url}}\n\nTim {{app_name}}',1);
INSERT INTO `notification_templates` (`event_key`,`channel`,`provider`,`subject`,`body`,`is_active`) VALUES ('support_reply','email','mailketing','Ada balasan untuk tiket {{ticket_number}}','<p>Halo <strong>{{name}}</strong>,</p><p>Tim kami sudah membalas tiket support kamu <strong>{{ticket_number}}</strong> — <em>{{subject}}</em>.</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fb;border-radius:12px;margin:16px 0"><tr><td style="padding:14px 18px;font-size:14px;color:#475569;line-height:1.7">{{message}}</td></tr></table><p style="text-align:center;margin:28px 0"><a href="{{ticket_url}}" style="display:inline-block;background:{{brand_color}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Lihat &amp; Balas Tiket →</a></p><p style="font-size:13px;color:#8a94a6">Atau buka tautan ini: {{ticket_url}}</p>',1);

DROP TABLE IF EXISTS `affiliate_displays`;
CREATE TABLE `affiliate_displays` (
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

DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `product_name` varchar(200) NOT NULL COMMENT 'snapshot nama produk saat transaksi',
  `price` decimal(15,2) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `is_bump` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = item ini adalah order bump, bukan produk utama',
  `is_bundle` tinyint(1) NOT NULL DEFAULT 0,
  `bundle_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `points_used` int(11) NOT NULL DEFAULT 0,
  `points_discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `setup_fee` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','paid','failed','cancelled','expired') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `supporter_message` varchar(280) DEFAULT NULL,
  `supporter_anonymous` tinyint(1) NOT NULL DEFAULT 0,
  `supporter_message_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `paid_at` timestamp NULL DEFAULT NULL,
  `recovery_sent_at` timestamp NULL DEFAULT NULL,
  `recovery_remind_stage` tinyint NOT NULL DEFAULT 0,
  `referral_code` varchar(20) DEFAULT NULL COMMENT 'kode affiliate yang dipakai buyer',
  `coupon_id` int(10) unsigned DEFAULT NULL,
  `coupon_code` varchar(50) DEFAULT NULL,
  `payment_gateway` varchar(50) DEFAULT NULL,
  `payment_reference` varchar(150) DEFAULT NULL,
  `payment_channel` varchar(50) DEFAULT NULL,
  `tracking_meta` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `order_stage` enum('full','booking','settlement') NOT NULL DEFAULT 'full',
  `parent_order_id` int(10) unsigned DEFAULT NULL,
  `booking_fee` decimal(15,2) DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `settlement_remind_stage` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_number` (`order_number`),
  UNIQUE KEY `uk_orders_parent` (`parent_order_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `fk_orders_coupon` (`coupon_id`),
  KEY `idx_status_created_at` (`status`,`created_at`),
  KEY `idx_abandoned_cart` (`status`,`recovery_sent_at`,`created_at`),
  CONSTRAINT `fk_orders_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=76 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `otp_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `otp_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `phone` varchar(20) NOT NULL,
  `code` varchar(6) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0 COMMENT 'jumlah percobaan verifikasi salah',
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_phone` (`phone`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `otp_codes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pr_token` (`token_hash`),
  KEY `idx_pr_user` (`user_id`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `payment_channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_channels` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `gateway_id` int(10) unsigned NOT NULL,
  `channel_code` varchar(50) NOT NULL COMMENT 'misal: BC (BCA VA), OV (OVO), QRIS',
  `channel_name` varchar(100) NOT NULL,
  `channel_type` enum('virtual_account','ewallet','qris','retail') NOT NULL,
  `fee_flat` decimal(15,2) NOT NULL DEFAULT 0.00,
  `fee_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_gateway_id` (`gateway_id`),
  CONSTRAINT `fk_channels_gateway` FOREIGN KEY (`gateway_id`) REFERENCES `payment_gateways` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `payment_gateways`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_gateways` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `provider` enum('duitku','xendit','moota','paypal','midtrans','tripay') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `mode` enum('sandbox','production') NOT NULL DEFAULT 'sandbox',
  `merchant_code` varchar(100) DEFAULT NULL COMMENT 'untuk Duitku',
  `api_key` text DEFAULT NULL,
  `secret_key` varchar(255) DEFAULT NULL,
  `callback_token` text DEFAULT NULL,
  `config_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'config tambahan spesifik provider' CHECK (json_valid(`config_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider` (`provider`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `payment_gateways` VALUES (1,'duitku',0,'sandbox',NULL,NULL,NULL,NULL,NULL,'2026-06-20 00:28:36','2026-06-20 00:28:36'),(2,'xendit',0,'sandbox',NULL,NULL,NULL,NULL,NULL,'2026-06-20 00:28:36','2026-06-20 00:28:36'),(3,'moota',0,'sandbox',NULL,NULL,NULL,NULL,NULL,'2026-06-20 00:28:36','2026-06-24 05:59:08'),(4,'paypal',0,'sandbox',NULL,NULL,NULL,NULL,NULL,'2026-06-20 00:28:36','2026-06-20 00:28:36'),(5,'midtrans',0,'sandbox',NULL,NULL,NULL,NULL,NULL,'2026-06-20 00:28:36','2026-06-20 00:28:36'),(6,'tripay',0,'sandbox',NULL,NULL,NULL,NULL,NULL,'2026-06-20 00:28:36','2026-06-20 00:28:36');

DROP TABLE IF EXISTS `payment_transaction_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_transaction_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned DEFAULT NULL,
  `gateway_provider` varchar(50) NOT NULL,
  `reference_id` varchar(150) DEFAULT NULL COMMENT 'invoice id / transaction id dari provider',
  `event_type` enum('create_invoice','callback_received','status_check','refund') NOT NULL,
  `request_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_payload`)),
  `response_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_payload`)),
  `signature_valid` tinyint(1) DEFAULT NULL COMMENT 'NULL jika tidak applicable',
  `status` enum('success','failed','duplicate_ignored') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_reference` (`reference_id`),
  KEY `idx_gateway` (`gateway_provider`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `method` varchar(50) NOT NULL DEFAULT 'manual',
  `bank_account_id` int(10) unsigned DEFAULT NULL COMMENT 'Rekening yang dipilih user',
  `proof_file` varchar(255) DEFAULT NULL COMMENT 'URL file bukti transfer',
  `proof_uploaded_at` timestamp NULL DEFAULT NULL COMMENT 'Waktu upload bukti',
  `confirmed_by` int(10) unsigned DEFAULT NULL COMMENT 'Admin user_id yang konfirmasi',
  `confirmed_at` timestamp NULL DEFAULT NULL COMMENT 'Waktu konfirmasi admin',
  `rejection_reason` varchar(255) DEFAULT NULL COMMENT 'Alasan jika ditolak',
  `amount` decimal(15,2) NOT NULL,
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `gateway_ref` varchar(100) DEFAULT NULL,
  `gateway_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gateway_response`)),
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_id` (`order_id`),
  KEY `idx_status` (`status`),
  KEY `payments_bank_account_fk` (`bank_account_id`),
  CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_bank_account_fk` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `point_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `point_transactions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `order_id` int(10) unsigned DEFAULT NULL,
  `type` enum('earn','redeem','reverse_earn','refund_redeem','adjust') NOT NULL,
  `points` int(11) NOT NULL,
  `balance_after` int(11) NOT NULL DEFAULT 0,
  `note` varchar(191) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_point_order_type` (`order_id`,`type`),
  KEY `idx_point_user` (`user_id`,`created_at`),
  CONSTRAINT `fk_point_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `agency_seo_quotas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agency_seo_quotas` (
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
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `agency_seo_licenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agency_seo_licenses` (
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
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `payout_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payout_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `affiliate_id` int(10) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `bank_account_no` varchar(50) NOT NULL,
  `bank_account_name` varchar(150) NOT NULL,
  `note` varchar(500) DEFAULT NULL,
  `status` enum('pending','processing','paid','rejected') NOT NULL DEFAULT 'pending',
  `admin_note` varchar(500) DEFAULT NULL,
  `proof_filename` varchar(255) DEFAULT NULL,
  `proof_path` varchar(500) DEFAULT NULL,
  `processed_by` int(10) unsigned DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pr_affiliate` (`affiliate_id`),
  KEY `idx_pr_status` (`status`),
  KEY `idx_pr_created` (`created_at`),
  KEY `processed_by` (`processed_by`),
  CONSTRAINT `payout_requests_ibfk_1` FOREIGN KEY (`affiliate_id`) REFERENCES `affiliates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payout_requests_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `product_bumps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_bumps` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `main_product_id` int(10) unsigned NOT NULL COMMENT 'produk utama yang sedang di-checkout',
  `bump_product_id` int(10) unsigned NOT NULL COMMENT 'produk yang ditawarkan sebagai bump',
  `bump_price` decimal(15,2) DEFAULT NULL COMMENT 'harga khusus saat jadi bump, NULL = pakai harga normal produk',
  `headline` varchar(200) DEFAULT NULL COMMENT 'judul promosi bump',
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_main_bump` (`main_product_id`,`bump_product_id`),
  KEY `idx_main` (`main_product_id`),
  KEY `fk_pb_bump` (`bump_product_id`),
  CONSTRAINT `fk_pb_bump` FOREIGN KEY (`bump_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pb_main` FOREIGN KEY (`main_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `flash_bonus_offers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `flash_bonus_offers` (
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
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `flash_bonus_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `flash_bonus_settings` (
  `main_product_id` int(10) unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = blok bonus tampil di checkout produk ini',
  `duration_minutes` int(10) unsigned NOT NULL DEFAULT 60 COMMENT 'masa berlaku bonus per kunjungan (menit)',
  `max_select` int(10) unsigned NOT NULL DEFAULT 1 COMMENT 'berapa bonus yang boleh dipilih pembeli',
  `label` varchar(120) NOT NULL DEFAULT 'Bonus Gratis Hari Ini' COMMENT 'judul blok ke pembeli',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`main_product_id`),
  CONSTRAINT `fk_fbs_main` FOREIGN KEY (`main_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `product_bundle_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_bundle_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `bundle_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bundle_product` (`bundle_id`,`product_id`),
  KEY `idx_pbi_bundle` (`bundle_id`),
  KEY `idx_pbi_product` (`product_id`),
  CONSTRAINT `product_bundle_items_ibfk_1` FOREIGN KEY (`bundle_id`) REFERENCES `product_bundles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_bundle_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `product_bundles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_bundles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `tagline` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `bundle_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `original_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive','draft') NOT NULL DEFAULT 'draft',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `hide_from_catalog` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bundle_slug` (`slug`),
  KEY `idx_bundle_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `product_downloads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_downloads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `filename_orig` varchar(255) NOT NULL,
  `filename_stor` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` bigint(20) NOT NULL DEFAULT 0,
  `version` varchar(50) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `download_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pd_product` (`product_id`),
  KEY `idx_pd_status` (`status`),
  CONSTRAINT `product_downloads_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `product_licenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_licenses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `license_key` varchar(24) NOT NULL COMMENT 'Format: AVRN-XXXX-XXXX-XXXX-XXXX',
  `order_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `status` enum('active','suspended','revoked','expired') NOT NULL DEFAULT 'active',
  `max_seats` int(10) unsigned NOT NULL DEFAULT 1,
  `used_seats` int(10) unsigned NOT NULL DEFAULT 0,
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'NULL = lifetime',
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_checked_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL COMMENT 'catatan admin',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `license_key` (`license_key`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `product_licenses_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `product_licenses_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `product_licenses_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `product_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_reviews` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `rating` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `review_text` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_note` varchar(255) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_product_review` (`user_id`,`product_id`),
  KEY `idx_rv_product` (`product_id`),
  KEY `idx_rv_status` (`status`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `product_reviews_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_reviews_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_reviews_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `product_upsells`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_upsells` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `main_product_id` int(10) unsigned NOT NULL COMMENT 'produk pemicu (yang baru dibeli)',
  `upsell_product_id` int(10) unsigned NOT NULL COMMENT 'produk yang ditawarkan',
  `upsell_price` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'harga spesial penawaran',
  `headline` varchar(200) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_main` (`main_product_id`),
  KEY `idx_upsell_product` (`upsell_product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `product_waitlist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_waitlist` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `whatsapp` varchar(20) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `status` enum('waiting','notified','converted') NOT NULL DEFAULT 'waiting',
  `notified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wl_product_wa` (`product_id`,`whatsapp`),
  KEY `idx_wl_status` (`status`),
  CONSTRAINT `fk_wl_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `external_program_code` varchar(100) DEFAULT NULL,
  `redirect_after_paid_url` varchar(500) DEFAULT NULL COMMENT 'URL tujuan redirect pembeli setelah bayar lunas; dukung placeholder {order_id}{email}{name}{phone}{product_id}{product_name}{amount}{sig}. Kosong = tetap di halaman sukses',
  `description` text DEFAULT NULL,
  `ai_knowledge` text DEFAULT NULL,
  `highlights` text DEFAULT NULL,
  `includes` text DEFAULT NULL,
  `short_description` varchar(500) DEFAULT NULL,
  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `original_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `price_myr` decimal(10,2) DEFAULT NULL,
  `language` enum('id','ms') NOT NULL DEFAULT 'id',
  `setup_fee` decimal(15,2) NOT NULL DEFAULT 0.00,
  `type` enum('class','membership','ebook','software','jasa','coaching','webinar','template','other') NOT NULL DEFAULT 'other',
  `catalog_category` varchar(80) DEFAULT NULL,
  `pricing_type` enum('fixed','flexible') NOT NULL DEFAULT 'fixed',
  `flexible_label` varchar(60) DEFAULT NULL,
  `flexible_min` decimal(15,2) NOT NULL DEFAULT 0.00,
  `flexible_presets` varchar(255) DEFAULT NULL,
  `cta_label` varchar(60) DEFAULT NULL,
  `show_on_support` tinyint(1) NOT NULL DEFAULT 0,
  `thumbnail` varchar(255) DEFAULT NULL,
  `landing_page_url` varchar(500) DEFAULT NULL COMMENT 'URL sales page eksternal, kosong = fallback ke halaman produk internal',
  `lp_type` enum('none','canvas','external') NOT NULL DEFAULT 'none',
  `canvas_id` int(10) unsigned DEFAULT NULL,
  `checkout_template_id` int(10) unsigned DEFAULT NULL COMMENT 'FK ke checkout_templates, NULL = pakai template default',
  `show_coupon` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Tampilkan card input kupon di checkout (per produk)',
  `checkout_mode` enum('login','public') NOT NULL DEFAULT 'login' COMMENT 'login=wajib login dulu, public=form publik tanpa login',
  `public_form_template` enum('clean','card','split') NOT NULL DEFAULT 'clean' COMMENT 'Template UI form publik: clean, card, split',
  `status` enum('active','inactive','draft','coming_soon') NOT NULL DEFAULT 'draft',
  `hide_from_catalog` tinyint(1) NOT NULL DEFAULT 0,
  `launch_at` datetime DEFAULT NULL,
  `access_duration_days` int(11) DEFAULT NULL COMMENT 'NULL = lifetime access',
  `certificate_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `certificate_auto_issue` tinyint(1) NOT NULL DEFAULT 0,
  `certificate_title_template` varchar(255) DEFAULT NULL,
  `affiliate_commission` decimal(5,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `reviews_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `license_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = produk ini generate license key saat order paid',
  `license_seats` int(10) unsigned NOT NULL DEFAULT 1 COMMENT 'maks aktivasi bersamaan per license key',
  `license_validity_days` int(10) unsigned DEFAULT NULL COMMENT 'NULL = lifetime, angka = expire N hari sejak aktivasi',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `booking_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `booking_fee` decimal(15,2) NOT NULL DEFAULT 0.00,
  `booking_deadline_days` int(11) NOT NULL DEFAULT 7,
  `booking_instant_access` tinyint(1) NOT NULL DEFAULT 0,
  `dime_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `dime_step_qty` int(11) NOT NULL DEFAULT 10,
  `dime_increment` decimal(15,2) NOT NULL DEFAULT 0.00,
  `dime_max_price` decimal(15,2) DEFAULT NULL,
  `dime_sold_count` int(10) unsigned NOT NULL DEFAULT 0,
  `jv_partner_user_id` int(10) unsigned DEFAULT NULL,
  `jv_partner_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `sp_mode` enum('all','own','off') NOT NULL DEFAULT 'all',
  `salespage_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `resell_product_id` int(10) unsigned DEFAULT NULL,
  `access_page_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `access_type` enum('course','embed') NOT NULL DEFAULT 'course',
  `embed_html` text DEFAULT NULL,
  `embed_url` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`type`),
  KEY `idx_catalog_category` (`catalog_category`),
  KEY `idx_sort_order` (`sort_order`),
  KEY `fk_product_canvas` (`canvas_id`),
  CONSTRAINT `fk_product_canvas` FOREIGN KEY (`canvas_id`) REFERENCES `canvas_pages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `reseller_salespage`;
CREATE TABLE `reseller_salespage` (
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

DROP TABLE IF EXISTS `referrals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `referrals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `affiliate_id` int(10) unsigned NOT NULL,
  `referred_user_id` int(10) unsigned DEFAULT NULL COMMENT 'user yang daftar via link',
  `order_id` int(10) unsigned DEFAULT NULL COMMENT 'order yang terjadi dari referral',
  `status` enum('click','registered','purchased') NOT NULL DEFAULT 'click',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_affiliate_id` (`affiliate_id`),
  KEY `idx_referred_user_id` (`referred_user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_referrals_affiliate` FOREIGN KEY (`affiliate_id`) REFERENCES `affiliates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` mediumtext DEFAULT NULL,
  `setting_group` varchar(50) NOT NULL DEFAULT 'general',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`),
  KEY `idx_group` (`setting_group`)
) ENGINE=InnoDB AUTO_INCREMENT=3443 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `settings` (`setting_key`,`setting_value`,`setting_group`) VALUES
('app_name','STIFIn Mulia','branding'),
('app_logo','','branding'),
('app_favicon','','branding'),
('brand_color','#043F2B','branding'),
('brand_color_secondary','#496B59','branding'),
('dark_mode_default','0','appearance'),
('affiliate_commission_rate','35','affiliate'),
('affiliate_enabled','1','affiliate'),
('currency','IDR','payment'),
('currency_symbol','Rp','payment'),
('app_version','1.0.0','general'),
('wa_otp_login_enabled','0','auth'),
('wa_otp_provider','starsender','auth'),
('mailketing_api_key','','general'),
('mailketing_sender_email','','general'),
('mailketing_sender_name','','general'),
('fonte_api_key','','general'),
('onesender_api_key','','general'),
('starsender_api_key','','general'),
('dripsender_api_key','','general'),
('kirimdev_api_key','','general'),
('kirimdev_phone_number_id','','general'),
('wa_sender_number','','general'),
('kirimdev_otp_template_name','','general'),
('kirimdev_otp_method','template','general'),
('fonte_enabled','0','whatsapp'),
('onesender_enabled','0','whatsapp'),
('starsender_enabled','0','whatsapp'),
('dripsender_enabled','0','whatsapp'),
('kirimdev_enabled','0','whatsapp'),
('vendor_support_email','','license'),
('vendor_support_whatsapp','','license'),
('license_verified_once','0','license'),
('vendor_renew_url','','license'),
('homepage_headline','STIFIn Mulia','branding'),
('homepage_subheadline','Kantor virtual cabang untuk belajar, transaksi, dan voucher tes','branding'),
('google_oauth_enabled','0','auth'),
('google_oauth_client_id','','auth'),
('google_oauth_client_secret','','auth'),
('kirimdev_otp_template_lang','id','general'),
('sp_enabled','0','social_proof'),
('sp_show_on_checkout','1','social_proof'),
('sp_show_on_product_page','1','social_proof'),
('sp_position','br','social_proof'),
('sp_delay_first','3500','social_proof'),
('sp_interval','9000','social_proof'),
('sp_duration','5000','social_proof'),
('sp_max_popups','8','social_proof'),
('sp_template','{buyer_name} dari {city} baru saja membeli {product_name}','social_proof'),
('sp_name_mask','partial','social_proof'),
('sp_avatar_mode','product','social_proof'),
('sp_theme','light','social_proof'),
('sp_popup_style','card','social_proof'),
('sp_status_filter','paid','social_proof'),
('sp_sound_enabled','1','social_proof'),
('sp_sound_type','cash_register','social_proof'),
('sp_checkout_interaction_enabled','1','social_proof'),
('sp_integration_id','','social_proof'),
('sp_last_at','','social_proof'),
('sp_last_source','','social_proof'),
('sp_last_product','','social_proof'),
('sp_notif_order_enabled','1','social_proof'),
('sp_notif_order_sound','double_tap','social_proof'),
('footer_text','','branding'),
('homepage_hero_badge','Platform produk & kelas digital','branding'),
('homepage_hero_cta','Jelajahi Produk','branding'),
('footer_tagline','Produk & kelas digital premium. Akses instan, pembayaran aman.','branding'),
('homepage_show_spotlight','1','branding'),
('pixel_enc_key','','security'),
('license_source','direct','license'),
('order_expiry_hours','24','payment'),
('invoice_seller_name','','invoice'),
('invoice_seller_address','','invoice'),
('invoice_seller_city','','invoice'),
('invoice_seller_phone','','invoice'),
('invoice_seller_email','','invoice'),
('invoice_seller_npwp','','invoice'),
('invoice_seller_website','','invoice'),
('invoice_footer_note','Terima kasih atas kepercayaan Anda.','invoice'),
('invoice_show_npwp','0','invoice'),
('invoice_show_logo','1','invoice'),
('download_token_expiry_minutes','','downloads'),
('certificate_signatory_name','','certificate'),
('certificate_signatory_title','','certificate'),
('certificate_footer_text','Sertifikat ini diterbitkan secara digital dan dapat diverifikasi.','certificate'),
('certificate_bg_color','#1e1b4b','certificate'),
('certificate_accent_color','#6366f1','certificate'),
('abandoned_cart_enabled','1','marketing'),
('abandoned_cart_delay_hours','1','marketing'),
('abandoned_cart_channel','email','marketing'),
('abandoned_cart_provider','mailketing','marketing'),
('min_payout_amount','50000','affiliate'),
('reviews_enabled','0','reviews'),
('reviews_require_access','0','reviews'),
('reviews_per_page','10','reviews'),
('cron_secret','','general'),
('ui_theme','airy','appearance'),
('ui_sidebar_style','dark','appearance'),
('manual_transfer_enabled','1','integrations'),
('manual_transfer_instructions','Transfer sesuai nominal yang tertera. Sertakan nomor order sebagai berita/keterangan transfer.','integrations');

DROP TABLE IF EXISTS `staff_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `permission` varchar(50) NOT NULL,
  `granted_by` int(10) unsigned DEFAULT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_permission` (`user_id`,`permission`),
  KEY `idx_sp_user` (`user_id`),
  KEY `idx_sp_permission` (`permission`),
  KEY `granted_by` (`granted_by`),
  CONSTRAINT `staff_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `staff_permissions_ibfk_2` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `tracking_pixels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tracking_pixels` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `platform` enum('meta_pixel','meta_capi','tiktok_pixel','tiktok_capi') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `pixel_id` varchar(100) DEFAULT NULL,
  `access_token` varchar(255) DEFAULT NULL COMMENT 'untuk CAPI server-side',
  `test_event_code` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform` (`platform`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `tracking_pixels` (`platform`,`is_active`,`pixel_id`,`access_token`,`test_event_code`) VALUES
('meta_capi',0,'','',''),
('tiktok_pixel',0,'','',''),
('tiktok_capi',0,'','','');

DROP TABLE IF EXISTS `update_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `update_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `from_version` varchar(20) NOT NULL,
  `to_version` varchar(20) NOT NULL,
  `backup_path` varchar(500) DEFAULT NULL,
  `status` enum('success','failed','partial') DEFAULT 'success',
  `notes` text DEFAULT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_applied` (`applied_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `user_trusted_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_trusted_devices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_utd_token` (`token_hash`),
  KEY `idx_utd_user` (`user_id`),
  CONSTRAINT `fk_utd_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `totp_secret` varchar(64) DEFAULT NULL,
  `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `totp_backup_codes` text DEFAULT NULL,
  `google_id` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL COMMENT 'kota pembeli, dipakai Social Proof',
  `phone_verified` tinyint(1) NOT NULL DEFAULT 0,
  `avatar` varchar(255) DEFAULT NULL,
  `role` enum('user','admin','staff') NOT NULL DEFAULT 'user',
  `status` enum('active','inactive','banned') NOT NULL DEFAULT 'active',
  `membership_level_id` int(10) unsigned DEFAULT NULL COMMENT 'FK ke membership_levels',
  `affiliate_code` varchar(20) DEFAULT NULL,
  `referred_by_affiliate_id` int(10) unsigned DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `email_opt_out` tinyint(1) NOT NULL DEFAULT 0,
  `points_balance` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`),
  UNIQUE KEY `uk_affiliate_code` (`affiliate_code`),
  UNIQUE KEY `uk_google_id` (`google_id`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`),
  KEY `fk_users_membership_level` (`membership_level_id`),
  CONSTRAINT `fk_users_membership_level` FOREIGN KEY (`membership_level_id`) REFERENCES `membership_levels` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=93 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `webhook_idempotency`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `webhook_idempotency` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `idempotency_key` varchar(255) NOT NULL COMMENT 'hash dari provider+reference_id+event',
  `processed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_idempotency_key` (`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `capi_event_queue`;
CREATE TABLE `capi_event_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `platform` varchar(20) NOT NULL DEFAULT 'meta',
  `event_name` varchar(50) NOT NULL,
  `event_id` varchar(120) NOT NULL,
  `payload` text NOT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_capi_status` (`platform`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `canvas_page_products`;
CREATE TABLE `canvas_page_products` (
  `canvas_page_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`canvas_page_id`,`product_id`),
  KEY `idx_cpp_product` (`product_id`),
  CONSTRAINT `fk_cpp_canvas` FOREIGN KEY (`canvas_page_id`) REFERENCES `canvas_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cpp_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `canvas_rotator_variants`;
CREATE TABLE `canvas_rotator_variants` (
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

DROP TABLE IF EXISTS `canvas_rotator_events`;
CREATE TABLE `canvas_rotator_events` (
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

DROP TABLE IF EXISTS `mailketing_queue`;
CREATE TABLE `mailketing_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event` varchar(20) NOT NULL,
  `list_id` varchar(40) NOT NULL,
  `email` varchar(255) NOT NULL,
  `first_name` varchar(120) DEFAULT NULL,
  `last_name` varchar(120) DEFAULT NULL,
  `mobile` varchar(40) DEFAULT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mkq_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `funnel_gate_forms`;
CREATE TABLE `funnel_gate_forms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `slug` varchar(150) NOT NULL,
  `external_ref` varchar(191) DEFAULT NULL,
  `intro` text DEFAULT NULL,
  `submit_text` varchar(100) DEFAULT NULL,
  `status` enum('draft','published','paused') NOT NULL DEFAULT 'draft',
  `form_mode` enum('single','multi') NOT NULL DEFAULT 'single',
  `progress_type` enum('bar','number','circle','none') NOT NULL DEFAULT 'bar',
  `step_animation` enum('slide','fade','none') NOT NULL DEFAULT 'slide',
  `next_text` varchar(60) DEFAULT NULL,
  `back_text` varchar(60) DEFAULT NULL,
  `allow_back` tinyint(1) NOT NULL DEFAULT 1,
  `autosave_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `score_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `redirect_rules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`redirect_rules`)),
  `default_redirect_url` varchar(500) DEFAULT NULL,
  `success_message` text DEFAULT NULL,
  `mailketing_list_id` varchar(40) DEFAULT NULL,
  `notify_admin` tinyint(1) NOT NULL DEFAULT 1,
  `total_submissions` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fgf_slug` (`slug`),
  UNIQUE KEY `uk_fgf_external_ref` (`external_ref`),
  KEY `idx_fgf_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `funnel_gate_steps`;
CREATE TABLE `funnel_gate_steps` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `form_id` int(10) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fgstep_form` (`form_id`,`sort_order`),
  CONSTRAINT `fk_fgstep_form` FOREIGN KEY (`form_id`) REFERENCES `funnel_gate_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `funnel_gate_fields`;
CREATE TABLE `funnel_gate_fields` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `form_id` int(10) unsigned NOT NULL,
  `step_id` int(10) unsigned DEFAULT NULL,
  `field_role` enum('none','name','whatsapp','email') NOT NULL DEFAULT 'none',
  `field_key` varchar(60) NOT NULL,
  `field_type` enum('text','textarea','select','radio','checkbox','number','rating','email','phone') NOT NULL DEFAULT 'text',
  `label` varchar(255) NOT NULL,
  `placeholder` varchar(255) DEFAULT NULL,
  `help_text` varchar(500) DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options`)),
  `weight` decimal(6,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fgfld_form` (`form_id`,`sort_order`),
  KEY `fk_fgfld_step` (`step_id`),
  CONSTRAINT `fk_fgfld_form` FOREIGN KEY (`form_id`) REFERENCES `funnel_gate_forms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fgfld_step` FOREIGN KEY (`step_id`) REFERENCES `funnel_gate_steps` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `funnel_gate_submissions`;
CREATE TABLE `funnel_gate_submissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `form_id` int(10) unsigned NOT NULL,
  `session_token` char(32) DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(150) DEFAULT NULL,
  `whatsapp` varchar(30) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `answers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`answers`)),
  `score` int(11) NOT NULL DEFAULT 0,
  `status` enum('draft','submitted','approved','rejected','pending') NOT NULL DEFAULT 'draft',
  `redirect_url` varchar(500) DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `submitted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fgs_draft` (`form_id`,`session_token`),
  KEY `idx_fgs_form_status` (`form_id`,`status`,`created_at`),
  KEY `idx_fgs_user` (`user_id`),
  CONSTRAINT `fk_fgs_form` FOREIGN KEY (`form_id`) REFERENCES `funnel_gate_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `media_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `media_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `media_files`;
CREATE TABLE `media_files` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `mime` varchar(50) NOT NULL,
  `size` int(10) unsigned NOT NULL DEFAULT 0,
  `width` int(10) unsigned DEFAULT NULL,
  `height` int(10) unsigned DEFAULT NULL,
  `alt` varchar(255) DEFAULT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `group_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_media_filename` (`filename`),
  KEY `idx_media_created` (`created_at`),
  KEY `idx_media_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `support_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_tickets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_number` varchar(32) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `subject` varchar(200) NOT NULL,
  `status` enum('open','answered','closed') NOT NULL DEFAULT 'open',
  `last_reply_at` datetime DEFAULT NULL,
  `last_reply_by` enum('user','admin') DEFAULT NULL,
  `user_seen_at` datetime DEFAULT NULL,
  `admin_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_number` (`ticket_number`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_last_reply` (`last_reply_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `support_ticket_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_ticket_replies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int(10) unsigned NOT NULL,
  `sender_type` enum('user','admin') NOT NULL,
  `sender_id` int(10) unsigned NOT NULL DEFAULT 0,
  `message` text NOT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`ticket_id`),
  CONSTRAINT `fk_ticket_reply` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET FOREIGN_KEY_CHECKS=1;


CREATE TABLE IF NOT EXISTS darmutiara_kajian (
  id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title varchar(180) NOT NULL,
  speaker varchar(120) NOT NULL,
  starts_at datetime NOT NULL,
  location varchar(240) NOT NULL,
  meeting_url varchar(500) NULL,
  recording_url varchar(500) NULL,
  product_id int unsigned NULL,
  published tinyint(1) NOT NULL DEFAULT 0,
  KEY kajian_schedule (published, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS darmutiara_kajian_participants (
  kajian_id int unsigned NOT NULL,
  user_id int unsigned NOT NULL,
  registered_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (kajian_id, user_id),
  FOREIGN KEY (kajian_id) REFERENCES darmutiara_kajian(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS darmutiara_kajian_posts (
  id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kajian_id int unsigned NOT NULL,
  user_id int unsigned NOT NULL,
  body text NOT NULL,
  hidden tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY kajian_discussion (kajian_id, id),
  FOREIGN KEY (kajian_id) REFERENCES darmutiara_kajian(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

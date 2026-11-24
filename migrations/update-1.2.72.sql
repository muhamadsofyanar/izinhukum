-- ════════════════════════════════════════════════════════════════
-- Averion update 1.2.72 — Email Sequence: multi-produk target + kecualikan produk
-- Idempoten & aman MySQL 8: CREATE TABLE IF NOT EXISTS + INSERT IGNORE.
-- Dijalankan otomatis oleh apply-update saat pembeli update.
-- ════════════════════════════════════════════════════════════════

-- Junction produk per sequence: kind=include (target) / exclude (kecualikan).
-- Tanpa baris include = berlaku ke SEMUA produk. FK CASCADE agar ikut bersih
-- saat sequence / produk dihapus.
CREATE TABLE IF NOT EXISTS `email_sequence_products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sequence_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `kind` enum('include','exclude') NOT NULL DEFAULT 'include',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_esp` (`sequence_id`,`product_id`,`kind`),
  KEY `idx_esp_seq` (`sequence_id`),
  KEY `idx_esp_prod` (`product_id`),
  CONSTRAINT `fk_esp_seq` FOREIGN KEY (`sequence_id`) REFERENCES `email_sequences` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_esp_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Konversi target lama (trigger_product_id) ke baris include agar perilaku tak berubah.
INSERT IGNORE INTO `email_sequence_products` (`sequence_id`, `product_id`, `kind`)
SELECT `id`, `trigger_product_id`, 'include'
FROM `email_sequences`
WHERE `trigger_product_id` IS NOT NULL;

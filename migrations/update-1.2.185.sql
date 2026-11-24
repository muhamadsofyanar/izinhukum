-- update-1.2.185.sql
-- Toggle "Tampilkan Kode Kupon" dipindah dari checkout_templates ke PRODUK.
-- Tambah kolom products.show_coupon (TINYINT, default 1 = tampil). Saat kolom
-- baru dibuat, nilainya di-seed dari template checkout yang dipakai tiap produk
-- (template yang ditetapkan, lalu template default, lalu 1) supaya perilaku
-- pembeli yang sudah jalan TIDAK berubah. Seed hanya saat kolom pertama dibuat
-- agar pilihan admin tak tertimpa bila migrasi dijalankan ulang.
-- Idempoten via information_schema + PREPARE. JANGAN taruh titik-koma di komentar.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
             AND COLUMN_NAME = 'show_coupon');
SET @s := IF(@c = 0,
  'ALTER TABLE `products` ADD COLUMN `show_coupon` TINYINT(1) NOT NULL DEFAULT 1 AFTER `checkout_template_id`',
  'DO 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @seed := IF(@c = 0,
  'UPDATE `products` p LEFT JOIN `checkout_templates` ct ON ct.id = p.checkout_template_id LEFT JOIN `checkout_templates` dft ON dft.is_default = 1 SET p.show_coupon = COALESCE(ct.show_coupon, dft.show_coupon, 1)',
  'DO 1');
PREPARE sd FROM @seed;
EXECUTE sd;
DEALLOCATE PREPARE sd;

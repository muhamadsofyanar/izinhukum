-- update-1.2.148.sql
-- Mode akses "Embed langsung": produk/lead magnet yang isinya satu embed HTML/URL
-- (bukan kelas/modul/materi). Tambah kolom access_type + embed_html + embed_url
-- ke products & lead_magnets. Render: HTML menang bila keduanya diisi.
-- Idempoten via information_schema + PREPARE. JANGAN taruh titik-koma di komentar.

-- products.access_type
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'access_type');
SET @s := IF(@c = 0, 'ALTER TABLE `products` ADD COLUMN `access_type` enum(''course'',''embed'') NOT NULL DEFAULT ''course'' AFTER `access_page_enabled`', 'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- products.embed_html
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'embed_html');
SET @s := IF(@c = 0, 'ALTER TABLE `products` ADD COLUMN `embed_html` TEXT DEFAULT NULL AFTER `access_type`', 'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- products.embed_url
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'embed_url');
SET @s := IF(@c = 0, 'ALTER TABLE `products` ADD COLUMN `embed_url` VARCHAR(500) DEFAULT NULL AFTER `embed_html`', 'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- lead_magnets.access_type
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_magnets' AND COLUMN_NAME = 'access_type');
SET @s := IF(@c = 0, 'ALTER TABLE `lead_magnets` ADD COLUMN `access_type` enum(''course'',''embed'') NOT NULL DEFAULT ''course'' AFTER `thankyou_message`', 'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- lead_magnets.embed_html
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_magnets' AND COLUMN_NAME = 'embed_html');
SET @s := IF(@c = 0, 'ALTER TABLE `lead_magnets` ADD COLUMN `embed_html` TEXT DEFAULT NULL AFTER `access_type`', 'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- lead_magnets.embed_url
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_magnets' AND COLUMN_NAME = 'embed_url');
SET @s := IF(@c = 0, 'ALTER TABLE `lead_magnets` ADD COLUMN `embed_url` VARCHAR(500) DEFAULT NULL AFTER `embed_html`', 'DO 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

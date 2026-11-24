-- Update 1.2.28 averion — Rule komisi bersyarat kepemilikan produk
-- commission_rules.required_product_id: rule hanya berlaku bila affiliate
-- MEMILIKI akses aktif ke produk syarat (member_access belum kedaluwarsa).
-- Contoh: pembeli lisensi resell produk A mendapat komisi 100 persen saat
-- menjual produk A lisensi personal. NULL = tanpa syarat (perilaku lama).
ALTER TABLE commission_rules ADD COLUMN required_product_id INT UNSIGNED NULL DEFAULT NULL AFTER membership_level_id;

ALTER TABLE commission_rules ADD KEY idx_cr_required_product (required_product_id);

ALTER TABLE commission_rules ADD CONSTRAINT fk_cr_required_product FOREIGN KEY (required_product_id) REFERENCES products (id) ON DELETE CASCADE;

-- Averion migration 1.1.35
-- Tambah nilai 'rejected' ke commissions.status agar komisi dari order yang
-- dibatalkan/refund bisa di-reverse (dikeluarkan dari saldo pencairan afiliasi).
-- MODIFY COLUMN aman untuk MySQL 8 (idempoten bila nilai enum sudah ada).
ALTER TABLE `commissions` MODIFY COLUMN `status` ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending';

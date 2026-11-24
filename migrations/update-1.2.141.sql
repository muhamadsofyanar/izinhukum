-- Averion — Migrasi 1.2.141
-- Tambah status 'expired' ke enum orders.status.
-- Webhook Midtrans/Tripay menulis 'expired' saat pembayaran kedaluwarsa. Tanpa nilai
-- ini di enum, MySQL mode non-strict menyimpan '' (kosong) secara senyap. Nilai
-- 'expired' ditaruh di AKHIR enum (urutan lama dipertahankan, append = perubahan
-- cepat/in-place di MySQL 8). Idempoten: MODIFY ke enum yang sama aman dijalankan
-- ulang (bukan duplicate-error, hanya menerapkan definisi yang sama).

ALTER TABLE `orders`
  MODIFY `status` enum('pending','paid','failed','cancelled','expired') NOT NULL DEFAULT 'pending';

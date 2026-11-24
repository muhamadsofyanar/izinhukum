-- Averion v1.1.50 migrasi
-- Paket Kesehatan Sistem: halaman /admin/system-health (status cron +
-- kegagalan notifikasi + kirim ulang)
-- notification_logs dapat kolom subject & body: payload pesan disimpan
-- HANYA saat kirim gagal, dipakai tombol "Kirim Ulang" lalu dikosongkan
-- Idempoten dan MySQL 8-safe (runner per-statement, duplikat pada re-run diabaikan)

ALTER TABLE notification_logs ADD COLUMN subject VARCHAR(255) NULL AFTER response;
ALTER TABLE notification_logs ADD COLUMN body MEDIUMTEXT NULL AFTER subject;

-- Checkout: card Kode Kupon kini opsional per template (default tetap tampil)
ALTER TABLE checkout_templates ADD COLUMN show_coupon TINYINT(1) NOT NULL DEFAULT 1 AFTER show_notes;

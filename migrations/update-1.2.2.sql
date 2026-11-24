-- Averion v1.2.2 — Lisensi Vendor otomatis (Fulfillment): antrean penerbitan.
--
-- Order buyer TIDAK BOLEH gagal / tertahan gara-gara server vendor bermasalah.
-- Karena itu penerbitan lisensi tidak dipanggil langsung saat checkout, tapi
-- diantre di sini (dalam transaksi yang sama dgn order lunas → tak mungkin hilang)
-- lalu dikirim & di-retry oleh cron.
--
-- Tabel ini HANYA terpakai bila admin mengaktifkan integrasi Lisensi Vendor
-- (Admin -> Integrasi). Instalasi lain: tabel ada tapi selalu kosong.
CREATE TABLE IF NOT EXISTS license_fulfillments (
  id              int(10) unsigned NOT NULL AUTO_INCREMENT,
  order_id        int(10) unsigned NOT NULL,
  user_id         int(10) unsigned NOT NULL,
  product_id      int(10) unsigned NOT NULL,
  order_number    varchar(50) NOT NULL,
  action          enum('new','renew') NOT NULL DEFAULT 'new',
  plan            varchar(50) NOT NULL,
  license_key     varchar(24) DEFAULT NULL,
  status          enum('pending','done','failed') NOT NULL DEFAULT 'pending',
  attempts        int(10) unsigned NOT NULL DEFAULT 0,
  last_error      text DEFAULT NULL,
  next_attempt_at timestamp NULL DEFAULT NULL,
  created_at      timestamp NOT NULL DEFAULT current_timestamp(),
  updated_at      timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  -- Satu order = satu antrean per aksi. Bikin enqueue aman diulang (mis. webhook
  -- gateway kirim dua kali) tanpa menumpuk baris kembar.
  UNIQUE KEY uk_order_action (order_number, action),
  KEY idx_due (status, next_attempt_at),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

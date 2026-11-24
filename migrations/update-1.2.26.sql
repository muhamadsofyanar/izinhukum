-- Update 1.2.26 averion — Materi tipe File terhubung ke Download Manager
-- Materi content_type=file kini bisa menaut ke entri product_downloads
-- (file terlindungi token + cek akses) sebagai pengganti URL manual.
-- URL manual tetap didukung (kompatibel mundur) -- download_id NULL = pakai URL.
ALTER TABLE materials ADD COLUMN download_id INT UNSIGNED NULL DEFAULT NULL AFTER content_url;

ALTER TABLE materials ADD KEY idx_materials_download (download_id);

ALTER TABLE materials ADD CONSTRAINT fk_materials_download FOREIGN KEY (download_id) REFERENCES product_downloads (id) ON DELETE SET NULL;

-- Lanjutkan Belajar: materi terakhir dibuka per user per konteks belajar
-- (produk ATAU lead magnet) -- sentinel 0 dipakai agar UNIQUE key bekerja
CREATE TABLE IF NOT EXISTS material_resume (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL DEFAULT 0,
  lead_magnet_id INT UNSIGNED NOT NULL DEFAULT 0,
  material_id INT UNSIGNED NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_resume_ctx (user_id, product_id, lead_magnet_id),
  KEY idx_resume_material (material_id),
  CONSTRAINT fk_resume_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_resume_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lampiran per materi: beberapa file Download Manager per materi (semua tipe konten)
CREATE TABLE IF NOT EXISTS material_files (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  material_id INT UNSIGNED NOT NULL,
  download_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_matfile (material_id, download_id),
  KEY idx_matfile_download (download_id),
  CONSTRAINT fk_matfile_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE CASCADE,
  CONSTRAINT fk_matfile_download FOREIGN KEY (download_id) REFERENCES product_downloads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

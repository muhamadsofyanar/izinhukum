-- Update 1.2.45 averion -- Galeri Media (hosting gambar internal). Tabel media_files.
-- Idempoten (CREATE IF NOT EXISTS). Folder assets/uploads/media dibuat runtime oleh handler upload.

-- Media Library (Galeri Media) -- hosting gambar internal untuk landing/konten.
-- Gambar disimpan di assets/uploads/media/ (publik, .htaccess uploads sudah hardened:
-- blok eksekusi skrip, izinkan gambar). URL = domain pembeli sendiri, tahan update.
-- CREATE IF NOT EXISTS -- aman diulang. MariaDB 10.4 / MySQL 8.
CREATE TABLE IF NOT EXISTS media_files (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  filename VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NULL DEFAULT NULL,
  mime VARCHAR(50) NOT NULL,
  size INT UNSIGNED NOT NULL DEFAULT 0,
  width INT UNSIGNED NULL DEFAULT NULL,
  height INT UNSIGNED NULL DEFAULT NULL,
  alt VARCHAR(255) NULL DEFAULT NULL,
  uploaded_by INT UNSIGNED NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_media_filename (filename),
  KEY idx_media_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update 1.2.44 averion -- Funnel Gate (kualifikasi prospek ber-skor) + Multi Step Form.
-- Membuat 4 tabel funnel_gate_* dari nol lalu menambah mode wizard. Idempoten via
-- runner update (menelan duplikat). Bagian A = MVP, Bagian B = multi-step.

-- ===== Bagian A: MVP (forms/fields/submissions) =====
-- Funnel Gate -- Decision Engine / sistem kualifikasi prospek.
-- Landing -> Funnel Gate (form ber-skor) -> Approved -> Checkout/Onboarding.
-- 3 tabel: forms (konfigurasi), fields (pertanyaan+bobot), submissions (jawaban+skor+status).
-- CREATE IF NOT EXISTS + FK inline -- aman diulang. MariaDB 10.4 (JSON = alias LONGTEXT).

-- ─── Forms: satu "gerbang" kualifikasi, punya slug publik /apply/{slug} ───
CREATE TABLE IF NOT EXISTS funnel_gate_forms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(150) NOT NULL,
  intro TEXT NULL DEFAULT NULL,
  submit_text VARCHAR(100) NULL DEFAULT NULL,
  status ENUM('draft','published','paused') NOT NULL DEFAULT 'draft',
  score_enabled TINYINT(1) NOT NULL DEFAULT 1,
  -- Aturan redirect berbasis skor: array JSON [{min,max,url,label}] dievaluasi urut.
  redirect_rules JSON NULL DEFAULT NULL,
  default_redirect_url VARCHAR(500) NULL DEFAULT NULL,
  success_message TEXT NULL DEFAULT NULL,
  -- Bonus tie-in: submission (approved) auto masuk list Mailketing ini bila diisi.
  mailketing_list_id VARCHAR(40) NULL DEFAULT NULL,
  notify_admin TINYINT(1) NOT NULL DEFAULT 1,
  total_submissions INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_fgf_slug (slug),
  KEY idx_fgf_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Fields: pertanyaan dalam form. field_role menandai kolom kontak (kunci
--     desain: Nama + WA/Email di AWAL supaya draft yang kabur tetap bisa
--     di-follow-up). options JSON = pilihan+skor per opsi. weight = pengali skor. ─
CREATE TABLE IF NOT EXISTS funnel_gate_fields (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id INT UNSIGNED NOT NULL,
  field_role ENUM('none','name','whatsapp','email') NOT NULL DEFAULT 'none',
  field_key VARCHAR(60) NOT NULL,
  field_type ENUM('text','textarea','select','radio','checkbox','number','rating','email','phone') NOT NULL DEFAULT 'text',
  label VARCHAR(255) NOT NULL,
  placeholder VARCHAR(255) NULL DEFAULT NULL,
  help_text VARCHAR(500) NULL DEFAULT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  options JSON NULL DEFAULT NULL,
  weight DECIMAL(6,2) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fgfld_form (form_id, sort_order),
  CONSTRAINT fk_fgfld_form FOREIGN KEY (form_id) REFERENCES funnel_gate_forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Submissions: satu pengisian. status draft dibuat lebih dulu oleh autosave
--     (kontak di awal), lalu jadi submitted saat dikirim. session_token unik per
--     form supaya autosave meng-upsert baris draft yang sama (bukan menumpuk). ─
CREATE TABLE IF NOT EXISTS funnel_gate_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id INT UNSIGNED NOT NULL,
  session_token CHAR(32) NULL DEFAULT NULL,
  user_id INT UNSIGNED NULL DEFAULT NULL,
  name VARCHAR(150) NULL DEFAULT NULL,
  whatsapp VARCHAR(30) NULL DEFAULT NULL,
  email VARCHAR(255) NULL DEFAULT NULL,
  answers JSON NULL DEFAULT NULL,
  score INT NOT NULL DEFAULT 0,
  status ENUM('draft','submitted','approved','rejected','pending') NOT NULL DEFAULT 'draft',
  redirect_url VARCHAR(500) NULL DEFAULT NULL,
  internal_notes TEXT NULL DEFAULT NULL,
  ip_address VARCHAR(45) NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  submitted_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_fgs_draft (form_id, session_token),
  KEY idx_fgs_form_status (form_id, status, created_at),
  KEY idx_fgs_user (user_id),
  CONSTRAINT fk_fgs_form FOREIGN KEY (form_id) REFERENCES funnel_gate_forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Bagian B: Multi Step (kolom mode + tabel steps + fields.step_id) =====
-- Funnel Gate — Multi Step Form (wizard). Menambah mode multi-step + tabel step
-- + kolom step_id pada field. Backward-compat: form_mode default 'single' → form
-- lama berperilaku persis seperti sekarang. Runner update menelan duplikat (re-run aman).

-- ─── Kolom mode + setelan wizard di form ─────────────────────
ALTER TABLE funnel_gate_forms
  ADD COLUMN form_mode ENUM('single','multi') NOT NULL DEFAULT 'single' AFTER status;
ALTER TABLE funnel_gate_forms
  ADD COLUMN progress_type ENUM('bar','number','circle','none') NOT NULL DEFAULT 'bar' AFTER form_mode;
ALTER TABLE funnel_gate_forms
  ADD COLUMN step_animation ENUM('slide','fade','none') NOT NULL DEFAULT 'slide' AFTER progress_type;
ALTER TABLE funnel_gate_forms
  ADD COLUMN next_text VARCHAR(60) NULL DEFAULT NULL AFTER step_animation;
ALTER TABLE funnel_gate_forms
  ADD COLUMN back_text VARCHAR(60) NULL DEFAULT NULL AFTER next_text;
ALTER TABLE funnel_gate_forms
  ADD COLUMN allow_back TINYINT(1) NOT NULL DEFAULT 1 AFTER back_text;
ALTER TABLE funnel_gate_forms
  ADD COLUMN autosave_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_back;

-- ─── Tabel step (wizard). Field ditaut lewat funnel_gate_fields.step_id ──
CREATE TABLE IF NOT EXISTS funnel_gate_steps (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  description VARCHAR(500) NULL DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fgstep_form (form_id, sort_order),
  CONSTRAINT fk_fgstep_form FOREIGN KEY (form_id) REFERENCES funnel_gate_forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Kolom step_id di field (NULL = single / belum ditaut) ───
ALTER TABLE funnel_gate_fields
  ADD COLUMN step_id INT UNSIGNED NULL DEFAULT NULL AFTER form_id;
ALTER TABLE funnel_gate_fields
  ADD CONSTRAINT fk_fgfld_step FOREIGN KEY (step_id) REFERENCES funnel_gate_steps (id) ON DELETE SET NULL;

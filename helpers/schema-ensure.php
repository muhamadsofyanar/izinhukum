<?php
// ─── Self-heal skema DB ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/**
 * Pastikan kolom body_format ada di email_sequence_steps (idempotent).
 * 'text' = teks biasa (dibungkus emailLayout), 'html' = template HTML penuh.
 */
function ensureEmailStepBodyFormatColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    try {
        $pdo->query("SELECT body_format FROM email_sequence_steps LIMIT 0");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE email_sequence_steps ADD COLUMN body_format ENUM('text','html') NOT NULL DEFAULT 'text' AFTER body");
        } catch (PDOException $e2) {
            error_log('ensureEmailStepBodyFormatColumn: ' . $e2->getMessage());
        }
    }
    $checked = true;
}

/**
 * Pastikan enum materials.content_type menyertakan 'html' (idempotent).
 * 'html' = konten HTML kustom (dirender apa adanya), terpisah dari 'embed' (iframe).
 */
function ensureMaterialHtmlContentType(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM materials LIKE 'content_type'")->fetch(PDO::FETCH_ASSOC);
        if ($col && strpos($col['Type'], "'html'") === false) {
            $pdo->exec("ALTER TABLE materials MODIFY COLUMN content_type ENUM('text','video','file','link','embed','html') NOT NULL DEFAULT 'text'");
        }
    } catch (PDOException $e) {
        error_log('ensureMaterialHtmlContentType: ' . $e->getMessage());
    }
    $checked = true;
}

/**
 * Pastikan kolom `materials.description` ada (deskripsi kaya di bawah konten materi,
 * mis. teks + link di bawah video). Defensif: auto-tambah bila migrasi belum jalan.
 */
function ensureMaterialDescriptionColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM materials LIKE 'description'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $pdo->exec("ALTER TABLE materials ADD COLUMN description longtext DEFAULT NULL AFTER content");
        }
    } catch (PDOException $e) {
        error_log('ensureMaterialDescriptionColumn: ' . $e->getMessage());
    }
    $checked = true;
}

/**
 * Self-heal kolom funnel_events.bundle_id (v1.2.55) — agar Funnel Analytics bisa
 * melacak corong untuk Product Bundle, bukan hanya produk. funnel_events.product_id
 * NOT NULL → event bundle disimpan dgn product_id=0 & bundle_id terisi. Aman bila
 * migrasi update-1.2.55.sql belum sempat dijalankan (fail-soft, static-cached).
 */
function ensureFunnelBundleColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM funnel_events LIKE 'bundle_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $pdo->exec("ALTER TABLE funnel_events ADD COLUMN bundle_id INT UNSIGNED DEFAULT NULL AFTER product_id");
        }
        $idx = $pdo->query("SHOW INDEX FROM funnel_events WHERE Key_name = 'idx_bundle_event_date'")->fetch(PDO::FETCH_ASSOC);
        if (!$idx) {
            $pdo->exec("ALTER TABLE funnel_events ADD INDEX idx_bundle_event_date (bundle_id, event_type, created_at)");
        }
    } catch (PDOException $e) {
        error_log('ensureFunnelBundleColumn: ' . $e->getMessage());
    }
    $checked = true;
}

/**
 * Self-heal kolom announcements.cta_url & cta_text (v1.2.55) — tombol CTA opsional
 * (URL + teks) pada Info Member. Aman bila migrasi belum sempat jalan; dipanggil di
 * jalur admin (info-member) & jalur member (info-bell) sebelum baca kolom tsb.
 * Fail-soft, static-cached (SHOW COLUMNS sekali per proses).
 */
function ensureAnnouncementCtaColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM announcements LIKE 'cta_%'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('cta_url', $cols, true)) {
            $pdo->exec("ALTER TABLE announcements ADD COLUMN cta_url VARCHAR(500) DEFAULT NULL AFTER body");
        }
        if (!in_array('cta_text', $cols, true)) {
            $pdo->exec("ALTER TABLE announcements ADD COLUMN cta_text VARCHAR(80) DEFAULT NULL AFTER cta_url");
        }
    } catch (PDOException $e) {
        error_log('ensureAnnouncementCtaColumns: ' . $e->getMessage());
    }
    $checked = true;
}

/**
 * Self-heal kolom users.referred_by_affiliate_id untuk menyimpan keterikatan afiliasi pembeli (Kaitkan Afiliasi).
 * Dipanggil saat checkout / penentuan komisi. Fail-soft & static-cached.
 */
function ensureUserReferredByColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'referred_by_affiliate_id'")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN referred_by_affiliate_id INT UNSIGNED DEFAULT NULL AFTER affiliate_code");
        }
    } catch (PDOException $e) {
        error_log('ensureUserReferredByColumn: ' . $e->getMessage());
    }
    $checked = true;
}

/**
 * Self-heal kolom users.email_opt_out (berhenti berlangganan email promosi).
 * Dipanggil sebelum resolusi penerima broadcast / toggle admin / unsubscribe.
 * Fail-soft & static-cached.
 */
function ensureUserEmailOptOutColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_opt_out'")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN email_opt_out TINYINT(1) NOT NULL DEFAULT 0 AFTER email_verified_at");
        }
    } catch (PDOException $e) {
        error_log('ensureUserEmailOptOutColumn: ' . $e->getMessage());
    }
    $checked = true;
}

/**
 * Self-heal kolom mode akses "Embed langsung" (produk & lead magnet):
 * access_type + embed_html + embed_url. Dipanggil di editor admin, save, dan
 * halaman akses member. Fail-soft & static-cached.
 */
function ensureEmbedAccessColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $specs = [
        ['products',     'access_type', "ALTER TABLE products ADD COLUMN access_type ENUM('course','embed') NOT NULL DEFAULT 'course' AFTER access_page_enabled"],
        ['products',     'embed_html',  "ALTER TABLE products ADD COLUMN embed_html TEXT DEFAULT NULL AFTER access_type"],
        ['products',     'embed_url',   "ALTER TABLE products ADD COLUMN embed_url VARCHAR(500) DEFAULT NULL AFTER embed_html"],
        ['lead_magnets', 'access_type', "ALTER TABLE lead_magnets ADD COLUMN access_type ENUM('course','embed') NOT NULL DEFAULT 'course' AFTER thankyou_message"],
        ['lead_magnets', 'embed_html',  "ALTER TABLE lead_magnets ADD COLUMN embed_html TEXT DEFAULT NULL AFTER access_type"],
        ['lead_magnets', 'embed_url',   "ALTER TABLE lead_magnets ADD COLUMN embed_url VARCHAR(500) DEFAULT NULL AFTER embed_html"],
    ];
    foreach ($specs as [$table, $col, $ddl]) {
        try {
            $has = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetchAll(PDO::FETCH_COLUMN);
            if (empty($has)) $pdo->exec($ddl);
        } catch (PDOException $e) {
            error_log('ensureEmbedAccessColumns(' . $table . '.' . $col . '): ' . $e->getMessage());
        }
    }
    $checked = true;
}

/**
 * Self-heal Canvas tipe "Redirect": nilai enum page_type='redirect' + kolom
 * redirect_url & redirect_keep_query. Dipanggil di halaman/aksi/render canvas.
 * Fail-soft & static-cached.
 */
function ensureCanvasRedirectColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    try {
        $ct = (string) $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='canvas_pages' AND COLUMN_NAME='page_type'")->fetchColumn();
        if ($ct !== '' && strpos($ct, "'redirect'") === false) {
            $pdo->exec("ALTER TABLE canvas_pages MODIFY page_type enum('standalone','homepage','product_landing','rotator','lead_magnet_landing','redirect') NOT NULL DEFAULT 'standalone'");
        }
        $cols = [
            ['redirect_url',        "ALTER TABLE canvas_pages ADD COLUMN redirect_url VARCHAR(2048) DEFAULT NULL AFTER tiktok_pixel_id"],
            ['redirect_keep_query', "ALTER TABLE canvas_pages ADD COLUMN redirect_keep_query TINYINT(1) NOT NULL DEFAULT 1 AFTER redirect_url"],
        ];
        foreach ($cols as [$col, $ddl]) {
            $has = $pdo->query("SHOW COLUMNS FROM canvas_pages LIKE '$col'")->fetchAll(PDO::FETCH_COLUMN);
            if (empty($has)) $pdo->exec($ddl);
        }
    } catch (PDOException $e) {
        error_log('ensureCanvasRedirectColumns: ' . $e->getMessage());
    }
    $checked = true;
}

/**
 * Self-heal kolom fitur produk yang ditambahkan lewat migrasi belakangan
 * (highlights/includes v1.2.97, booking_instant_access v1.2.99). Mencegah
 * "Unknown column" saat save-product bila instalasi telat menjalankan migrasi
 * (mis. update kode tapi migrasi belum jalan) → seluruh simpan produk gagal.
 * Idempoten & murah (static-cached, cek information_schema via SHOW COLUMNS).
 */
function ensureProductFeatureColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    try {
        $add = function (string $col, string $ddl) use ($pdo): void {
            $has = $pdo->query("SHOW COLUMNS FROM products LIKE " . $pdo->quote($col))->fetchAll(PDO::FETCH_COLUMN);
            if (empty($has)) { $pdo->exec("ALTER TABLE products ADD COLUMN $ddl"); }
        };
        $add('highlights',             "`highlights` TEXT NULL AFTER `ai_knowledge`");
        $add('includes',               "`includes` TEXT NULL AFTER `highlights`");
        $add('booking_instant_access', "`booking_instant_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `booking_deadline_days`");
        // Fitur Reseller — Salespage Siap Pakai (v1.2.106). Teks WA & toggle resell
        // dikelola per-reseller di tabel reseller_salespage (bukan kolom produk).
        $add('salespage_enabled',   "`salespage_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `sp_mode`");
        $add('resell_product_id',   "`resell_product_id` INT(10) UNSIGNED DEFAULT NULL AFTER `salespage_enabled`");
        // Bagian 2 — Akses Produk Berpassword (v1.2.107). Password per-reseller
        // (reseller_salespage.access_password); admin cukup mengaktifkan halaman.
        $add('access_page_enabled', "`access_page_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `resell_product_id`");
    } catch (PDOException $e) {
        error_log('ensureProductFeatureColumns: ' . $e->getMessage());
    }
    $checked = true;
}

/**
 * Self-heal tabel reseller_salespage (setting salespage per-reseller) bila migrasi
 * 1.2.108 telat. Fail-soft & static-cached.
 */
function ensureResellerSalespageTable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `reseller_salespage` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `user_id` int(10) unsigned NOT NULL,
              `product_id` int(10) unsigned NOT NULL,
              `wa_template` varchar(500) DEFAULT NULL,
              `resell_enabled` tinyint(1) NOT NULL DEFAULT 1,
              `access_password` varchar(100) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_reseller_product` (`user_id`,`product_id`),
              KEY `idx_rs_product` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // Tabel sudah ada dari versi awal (tanpa access_password) → tambahkan kolomnya.
        $has = $pdo->query("SHOW COLUMNS FROM reseller_salespage LIKE 'access_password'")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($has)) {
            $pdo->exec("ALTER TABLE reseller_salespage ADD COLUMN `access_password` VARCHAR(100) DEFAULT NULL AFTER `resell_enabled`");
        }
    } catch (PDOException $e) {
        error_log('ensureResellerSalespageTable: ' . $e->getMessage());
    }
    $checked = true;
}

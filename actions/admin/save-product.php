<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/products');
}

// Upload thumbnail melebihi post_max_size hosting → PHP mengosongkan $_POST total
// (termasuk csrf_token) SEBELUM skrip ini jalan. Deteksi SEBELUM cek CSRF supaya
// pesannya jujur. Pola sama dgn save-canvas.php.
if (postBodyTooLarge()) {
    $limit = phpUploadLimitBytes();
    flash('error', 'File terlalu besar — melebihi batas upload server'
        . ($limit > 0 ? ' (maks ' . formatFileSize($limit) . ')' : '')
        . '. Perkecil file, atau naikkan post_max_size & upload_max_filesize di pengaturan PHP hosting.', 'error');
    redirect('/admin/products');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/admin/products');
}

// ─── Input & sanitasi ─────────────────────────────────────────
$productId   = (int) ($_POST['product_id'] ?? 0);
$isEdit      = $productId > 0;

$name        = trim($_POST['name']               ?? '');
$slug        = trim(strtolower($_POST['slug']    ?? ''));
$type        = trim($_POST['type']               ?? '');
$categoryRaw = $_POST['catalog_category'] ?? '';
$catalogCategory = is_string($categoryRaw) ? trim((string) preg_replace('/\s+/u', ' ', $categoryRaw)) : '';
$status      = trim($_POST['status']             ?? 'active');
// Jadwal launch (khusus coming_soon; format datetime-local "Y-m-dTH:i").
$launchRaw   = trim($_POST['launch_at'] ?? '');
$launchAt    = null;
if ($status === 'coming_soon' && $launchRaw !== '') {
    $ts = strtotime(str_replace('T', ' ', $launchRaw));
    if ($ts !== false) $launchAt = date('Y-m-d H:i:s', $ts);
}
$price       = max(0, (float) ($_POST['price']   ?? 0));
$origPrice   = max(0, (float) ($_POST['original_price']        ?? 0));
// Pasar Malaysia (display-only): harga alias RM (kosong = NULL) + bahasa label.
$priceMyrRaw = trim((string) ($_POST['price_myr'] ?? ''));
$priceMyr    = ($priceMyrRaw !== '') ? max(0, (float) $priceMyrRaw) : null;
if ($priceMyr === 0.0) $priceMyr = null; // 0 = anggap kosong (tak tampil alias)
$language    = in_array($_POST['language'] ?? 'id', ['id', 'ms'], true) ? $_POST['language'] : 'id';
$setupFee    = max(0, (float) ($_POST['setup_fee']            ?? 0));
$bookingEnabled  = ((int) ($_POST['booking_enabled'] ?? 0) === 1) ? 1 : 0;
$bookingFee      = max(0, (float) ($_POST['booking_fee'] ?? 0));
$bookingDeadline = max(1, (int) ($_POST['booking_deadline_days'] ?? 7));
$bookingInstant  = ((int) ($_POST['booking_instant_access'] ?? 0) === 1) ? 1 : 0;
// Booking hanya valid bila DP > 0 dan lebih kecil dari harga produk.
// Bila tak valid → auto-nonaktif + peringatan (hindari "aktif tapi diam-diam mati").
if ($bookingEnabled === 1 && ($bookingFee <= 0 || $bookingFee >= $price)) {
    $bookingEnabled = 0;
    flash('booking_warn', 'Booking Fee tidak diaktifkan: nominal DP harus lebih dari 0 dan lebih kecil dari harga produk.', 'warning');
}
// ─── Dime Sale (harga naik bertahap) ─────────────────────────
$dimeEnabled   = (isset($_POST['dime_enabled']) && $_POST['dime_enabled'] == '1') ? 1 : 0;
$dimeStep      = max(1, (int) ($_POST['dime_step_qty'] ?? 10));
$dimeIncrement = max(0, (float) ($_POST['dime_increment'] ?? 0));
$dimeMaxRaw    = trim((string) ($_POST['dime_max_price'] ?? ''));
$dimeMax       = ($dimeMaxRaw !== '') ? max(0, (float) $dimeMaxRaw) : null;
if ($dimeMax === 0.0) $dimeMax = null; // 0 = tanpa batas
// Dime valid hanya bila kenaikan > 0. Saling eksklusif dgn Booking (dime menang).
if ($dimeEnabled === 1 && $dimeIncrement <= 0) $dimeEnabled = 0;
if ($dimeEnabled === 1) {
    if ($bookingEnabled === 1) {
        flash('booking_warn', 'Booking Fee tidak diaktifkan karena Dime Sale aktif — keduanya tidak bisa dipakai bersamaan. Matikan Dime Sale dulu jika ingin memakai Booking Fee.', 'warning');
    }
    $bookingEnabled = 0; $bookingFee = 0;
}
if ($bookingEnabled !== 1) $bookingInstant = 0; // akses instan hanya relevan bila booking aktif
if ($dimeEnabled === 0) { $dimeStep = 10; $dimeIncrement = 0; $dimeMax = null; }
// ─── JV Partner (bagi hasil) ─────────────────────────────────
$jvPartnerId = (int) ($_POST['jv_partner_user_id'] ?? 0);
$jvPercent   = min(100, max(0, (float) ($_POST['jv_partner_percent'] ?? 0)));
if ($jvPartnerId > 0) {
    $chk = getDB()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $chk->execute([$jvPartnerId]);
    if (!$chk->fetchColumn()) $jvPartnerId = 0;
}
if ($jvPartnerId <= 0 || $jvPercent <= 0) { $jvPartnerId = 0; $jvPercent = 0; } // keduanya wajib terisi
$jvPartnerIdDb = $jvPartnerId > 0 ? $jvPartnerId : null;
// Popup Social Proof per produk: all = campur (default), own = produk ini saja, off = sembunyikan.
$spMode = in_array($_POST['sp_mode'] ?? 'all', ['all', 'own', 'off'], true) ? $_POST['sp_mode'] : 'all';
$durationRaw = trim($_POST['access_duration_days'] ?? '');
$duration    = ($durationRaw !== '' && (int)$durationRaw > 0) ? (int)$durationRaw : null;
$commission  = min(100, max(0, (float) ($_POST['affiliate_commission'] ?? 0)));
// Reseller — Salespage Siap Pakai (v1.2.106). Admin: aktivasi + tautan produk Resell.
// Teks WA & toggle resell dikelola per-reseller (tabel reseller_salespage).
$salespageEnabled = ((int) ($_POST['salespage_enabled'] ?? 0) === 1) ? 1 : 0;
$resellProductId  = (int) ($_POST['resell_product_id'] ?? 0);
$resellProductId  = ($resellProductId > 0 && $resellProductId !== $productId) ? $resellProductId : null; // tak boleh menaut diri sendiri
// Bagian 2 — Akses Produk Berpassword (v1.2.107). Password per-reseller (dashboard);
// admin cukup mengaktifkan halaman.
$accessEnabled = ((int) ($_POST['access_page_enabled'] ?? 0) === 1) ? 1 : 0;
// Mode akses "Embed langsung" (v1.2.148): produk yang isinya satu embed HTML/URL.
$accessType = (($_POST['access_type'] ?? 'course') === 'embed') ? 'embed' : 'course';
$embedHtml  = trim((string) ($_POST['embed_html'] ?? ''));
$embedUrl   = trim((string) ($_POST['embed_url'] ?? ''));
if ($embedUrl !== '' && !preg_match('#^https?://#i', $embedUrl)) $embedUrl = '';
$thumbnailExisting    = trim($_POST['thumbnail']             ?? '');
$lpType               = in_array($_POST['lp_type'] ?? '', ['none', 'canvas', 'external'], true)
                        ? $_POST['lp_type'] : 'none';
$canvasId             = $lpType === 'canvas'   ? ((int) ($_POST['canvas_id'] ?? 0) ?: null) : null;
$landingPageUrl       = $lpType === 'external' ? trim($_POST['landing_page_url'] ?? '') : '';
$checkoutTemplateRaw  = (int) ($_POST['checkout_template_id'] ?? 0);
$showCoupon           = isset($_POST['show_coupon']) ? 1 : 0;
$checkoutMode         = ($_POST['checkout_mode'] ?? '') === 'public' ? 'public' : 'login';
$publicFormTemplate   = in_array($_POST['public_form_template'] ?? '', ['clean', 'card', 'split'], true)
                        ? $_POST['public_form_template'] : 'clean';
$externalProgramCode  = trim($_POST['external_program_code'] ?? '');
$externalProgramCode  = $externalProgramCode !== '' ? mb_substr($externalProgramCode, 0, 100) : null;

// Redirect Setelah Bayar (opsional): normalisasi skema seperti materi (user
// sering lupa https://). Placeholder {order_id}/{email}/dst dibiarkan apa adanya.
$redirectAfterPaid    = trim($_POST['redirect_after_paid_url'] ?? '');
if ($redirectAfterPaid !== '' && !preg_match('~^[a-z][a-z0-9+.\-]*:~i', $redirectAfterPaid)) {
    $redirectAfterPaid = 'https://' . ltrim($redirectAfterPaid, '/');
}
$redirectAfterPaid    = $redirectAfterPaid !== '' ? mb_substr($redirectAfterPaid, 0, 500) : null;
$shortDesc      = trim($_POST['short_description']   ?? '');
$description    = trim($_POST['description']         ?? '');
$highlights     = mb_substr(trim($_POST['highlights'] ?? ''), 0, 2000);
$includes       = mb_substr(trim($_POST['includes']   ?? ''), 0, 2000);
$aiKnowledge    = mb_substr(trim($_POST['ai_knowledge'] ?? ''), 0, 4000);

// ─── Pengaturan Lisensi ───────────────────────────────────────
$licenseEnabled      = (isset($_POST['license_enabled']) && $_POST['license_enabled'] == '1') ? 1 : 0;
$certEnabled         = (isset($_POST['certificate_enabled']) && $_POST['certificate_enabled'] == '1') ? 1 : 0;
$certAutoIssue       = (isset($_POST['certificate_auto_issue']) && $_POST['certificate_auto_issue'] == '1') ? 1 : 0;
$certTitleTpl        = trim($_POST['certificate_title_template'] ?? '');
$reviewsEnabled      = (isset($_POST['reviews_enabled']) && $_POST['reviews_enabled'] == '1') ? 1 : 0;
// Produk unlisted: sembunyikan dari katalog publik ('/' & '/products') —
// tetap bisa dibeli lewat link langsung/sales page.
$hideFromCatalog     = (isset($_POST['hide_from_catalog']) && $_POST['hide_from_catalog'] == '1') ? 1 : 0;
$licenseSeats        = max(1, (int) ($_POST['license_seats'] ?? 1));
$licenseValidityDays = !empty($_POST['license_validity_days'])
                        ? max(1, (int) $_POST['license_validity_days'])
                        : null;

// ─── Bayar Fleksibel (pricing_type) ──────────────────────────
$pricingType = ($_POST['pricing_type'] ?? 'fixed') === 'flexible' ? 'flexible' : 'fixed';
$flexLabel   = mb_substr(trim($_POST['flexible_label'] ?? ''), 0, 60);
$flexMin     = max(0, (float) ($_POST['flexible_min'] ?? 0));
// Teks tombol kustom (semua produk, opsional maks 60). Kosong = default cerdas.
$ctaLabel    = mb_substr(trim($_POST['cta_label'] ?? ''), 0, 60);
$flexPresets = '';
if (($_POST['flexible_presets'] ?? '') !== '') {
    // Ambil angka > 0, unik, urut naik, maks 6 → CSV bersih.
    $nums = array_filter(array_map(
        fn($x) => (int) preg_replace('/[^0-9]/', '', (string) $x),
        explode(',', (string) $_POST['flexible_presets'])
    ), fn($n) => $n > 0);
    $nums = array_values(array_unique($nums));
    sort($nums);
    $flexPresets = implode(',', array_slice($nums, 0, 6));
}
// Fase 2: tampilkan produk sebagai kartu tip di Halaman Dukung (hanya bermakna flexible).
$showOnSupport = (isset($_POST['show_on_support']) && $_POST['show_on_support'] == '1') ? 1 : 0;
if ($pricingType === 'flexible') {
    // Fase 1: harga dinamis → matikan harga fix, setup, booking, dime; akses one-time; komisi off.
    $price = 0; $origPrice = 0; $setupFee = 0;
    $bookingEnabled = 0; $bookingFee = 0; $bookingInstant = 0;
    $duration = null;
    $commission = 0;
    $dimeEnabled = 0; $dimeStep = 10; $dimeIncrement = 0; $dimeMax = null;
} else {
    $flexLabel = ''; $flexMin = 0; $flexPresets = '';
    $showOnSupport = 0; // produk non-flexible tak bisa tampil di Halaman Dukung
}

// ─── Validasi ─────────────────────────────────────────────────
$errors = [];

if (strlen($name) < 2) {
    $errors[] = 'Nama produk minimal 2 karakter.';
} elseif (strlen($name) > 200) {
    $errors[] = 'Nama produk maksimal 200 karakter.';
}

// Normalisasi slug — hanya huruf kecil, angka, dash
$slug = preg_replace('/[^a-z0-9\-]/', '', str_replace(' ', '-', $slug));
$slug = preg_replace('/-+/', '-', trim($slug, '-'));

if (strlen($slug) < 2) {
    $errors[] = 'Slug minimal 2 karakter.';
} elseif (strlen($slug) > 220) {
    $errors[] = 'Slug maksimal 220 karakter.';
}

$allowedTypes = ['class', 'membership', 'ebook', 'software', 'jasa', 'coaching', 'webinar', 'template', 'other']; // match DB ENUM
if (!in_array($type, $allowedTypes, true)) {
    $errors[] = 'Tipe produk tidak valid.';
}
if (!is_string($categoryRaw) || ($categoryRaw !== '' && $catalogCategory === '')) {
    $errors[] = 'Nama kategori etalase tidak valid.';
} elseif (mb_strlen($catalogCategory, 'UTF-8') > 80) {
    $errors[] = 'Nama kategori etalase maksimal 80 karakter.';
}

$allowedStatuses = ['active', 'inactive', 'draft', 'coming_soon'];
if (!in_array($status, $allowedStatuses, true)) {
    $errors[] = 'Status tidak valid.';
}

// ─── Thumbnail: file upload OR keep existing URL ──────────────
$thumbnailPath = $thumbnailExisting;

if (isset($_FILES['thumbnail_file']) && $_FILES['thumbnail_file']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['thumbnail_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Terjadi kesalahan saat upload thumbnail (kode: ' . $file['error'] . ').';
    } else {
        $finfo       = new finfo(FILEINFO_MIME_TYPE);
        $mime        = $finfo->file($file['tmp_name']);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

        if (!in_array($mime, $allowedMimes, true)) {
            $errors[] = 'Tipe file thumbnail tidak didukung. Gunakan JPG, PNG, atau WebP.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Ukuran file thumbnail maksimal 2MB.';
        } else {
            $ext       = match($mime) { 'image/jpeg' => 'jpg', 'image/png' => 'png', default => 'webp' };
            $filename  = 'prod_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $uploadDir = rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                       . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
                       . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR;

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                compressUploadedImage($uploadDir . $filename); // resize+kompres in-place, format tetap
                $thumbnailPath = rtrim(APP_URL, '/') . '/assets/uploads/products/' . $filename;
            } else {
                $errors[] = 'Gagal menyimpan file thumbnail ke server.';
            }
        }
    }
} elseif ($thumbnailExisting !== '' && !filter_var($thumbnailExisting, FILTER_VALIDATE_URL)) {
    $errors[] = 'URL thumbnail tidak valid.';
}

// ─── Landing Page (lp_type: none | canvas | external) ─────────
if ($lpType === 'canvas') {
    if (!$canvasId) {
        $errors[] = 'Pilih Canvas terlebih dahulu.';
    } else {
        try {
            $cvChk = getDB()->prepare("SELECT id FROM canvas_pages WHERE id = ? LIMIT 1");
            $cvChk->execute([$canvasId]);
            if (!$cvChk->fetch()) {
                $errors[] = 'Canvas yang dipilih tidak ditemukan.';
            }
        } catch (Exception $e) {
            $errors[] = 'Canvas tidak dapat divalidasi.';
        }
    }
} elseif ($lpType === 'external') {
    if ($landingPageUrl === '') {
        $errors[] = 'Masukkan URL landing page.';
    } elseif (!filter_var($landingPageUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'Format Link Landing Page tidak valid.';
    } else {
        $scheme = parse_url($landingPageUrl, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            $errors[] = 'Link Landing Page harus diawali http:// atau https://';
        }
    }
}

// Resolve checkout_template_id — verify exists if provided
$checkoutTemplateId = null;
if ($checkoutTemplateRaw > 0) {
    try {
        $ctChk = getDB()->prepare("SELECT id FROM checkout_templates WHERE id = ? LIMIT 1");
        $ctChk->execute([$checkoutTemplateRaw]);
        if ($ctChk->fetch()) {
            $checkoutTemplateId = $checkoutTemplateRaw;
        }
    } catch (Exception $e) {
        // Ignore — leave as null
    }
}

if (!empty($errors)) {
    flash('error', implode(' ', $errors), 'error');
    redirect('/admin/products');
}

// ─── Proses DB ────────────────────────────────────────────────
try {
    $pdo = getDB();
    // Self-heal kolom fitur baru (highlights/includes/booking_instant_access) bila
    // instalasi belum menjalankan migrasi → cegah "Unknown column" yang menggagalkan
    // SELURUH simpan produk (termasuk Booking Fee).
    ensureProductFeatureColumns($pdo);
    if (function_exists('ensureEmbedAccessColumns')) ensureEmbedAccessColumns($pdo);

    // Gunakan ejaan kategori yang sudah ada agar filter tidak memecah nama serupa.
    if ($catalogCategory !== '') {
        $knownCategory = $pdo->prepare('SELECT catalog_category FROM products WHERE catalog_category = ? LIMIT 1');
        $knownCategory->execute([$catalogCategory]);
        $catalogCategory = (string) ($knownCategory->fetchColumn() ?: $catalogCategory);
    }
    $catalogCategory = $catalogCategory !== '' ? $catalogCategory : null;

    // Pastikan slug unik — dedup jika perlu
    $checkSlug = $pdo->prepare(
        'SELECT COUNT(*) FROM products WHERE slug = ? AND id != ?'
    );
    $checkSlug->execute([$slug, $productId]);
    $slugExists = (int) $checkSlug->fetchColumn();

    if ($slugExists > 0) {
        // Tambahkan suffix numerik sampai unik
        $baseSlug = $slug;
        $suffix   = 1;
        do {
            $slug = $baseSlug . '-' . $suffix;
            $checkSlug->execute([$slug, $productId]);
            $slugExists = (int) $checkSlug->fetchColumn();
            $suffix++;
        } while ($slugExists > 0);
    }

    if ($isEdit) {
        // Verifikasi produk ada
        $chk = $pdo->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
        $chk->execute([$productId]);
        if (!$chk->fetch()) {
            flash('error', 'Produk tidak ditemukan.', 'error');
            redirect('/admin/products');
        }

        $stmt = $pdo->prepare(
            'UPDATE products
             SET name = ?, slug = ?, type = ?, catalog_category = ?, status = ?, hide_from_catalog = ?, launch_at = ?,
                 booking_enabled = ?, booking_fee = ?, booking_deadline_days = ?, booking_instant_access = ?, sp_mode = ?,
                 dime_enabled = ?, dime_step_qty = ?, dime_increment = ?, dime_max_price = ?,
                 jv_partner_user_id = ?, jv_partner_percent = ?,
                 price = ?, original_price = ?, price_myr = ?, language = ?, setup_fee = ?, access_duration_days = ?,
                 pricing_type = ?, flexible_label = ?, flexible_min = ?, flexible_presets = ?, cta_label = ?, show_on_support = ?,
                 affiliate_commission = ?, thumbnail = ?,
                 landing_page_url = ?, lp_type = ?, canvas_id = ?,
                 checkout_template_id = ?, show_coupon = ?, checkout_mode = ?, public_form_template = ?, external_program_code = ?,
                 redirect_after_paid_url = ?,
                 short_description = ?, description = ?, highlights = ?, includes = ?, ai_knowledge = ?,
                 license_enabled = ?, license_seats = ?, license_validity_days = ?,
                 certificate_enabled = ?, certificate_auto_issue = ?, certificate_title_template = ?,
                 reviews_enabled = ?,
                 salespage_enabled = ?, resell_product_id = ?,
                 access_page_enabled = ?,
                 access_type = ?, embed_html = ?, embed_url = ?,
                 updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $name, $slug, $type, $catalogCategory, $status, $hideFromCatalog, $launchAt,
            $bookingEnabled, $bookingFee, $bookingDeadline, $bookingInstant, $spMode,
            $dimeEnabled, $dimeStep, $dimeIncrement, $dimeMax,
            $jvPartnerIdDb, $jvPercent,
            $price, $origPrice, $priceMyr, $language, $setupFee, $duration,
            $pricingType, ($flexLabel !== '' ? $flexLabel : null), $flexMin, ($flexPresets !== '' ? $flexPresets : null), ($ctaLabel !== '' ? $ctaLabel : null), $showOnSupport,
            $commission, $thumbnailPath ?: null,
            $landingPageUrl ?: null, $lpType, $canvasId,
            $checkoutTemplateId, $showCoupon, $checkoutMode, $publicFormTemplate, $externalProgramCode,
            $redirectAfterPaid,
            $shortDesc ?: null, $description ?: null, $highlights ?: null, $includes ?: null, $aiKnowledge ?: null,
            $licenseEnabled, $licenseSeats, $licenseValidityDays,
            $certEnabled, $certAutoIssue, ($certTitleTpl !== '' ? $certTitleTpl : null),
            $reviewsEnabled,
            $salespageEnabled, $resellProductId,
            $accessEnabled,
            $accessType, ($embedHtml !== '' ? $embedHtml : null), ($embedUrl !== '' ? $embedUrl : null),
            $productId,
        ]);
        logActivity('admin', 'product.update', 'product', $productId, 'Produk: ' . $name);
        flash('success', 'Produk "' . $name . '" berhasil diperbarui.');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO products
             (name, slug, type, catalog_category, status, hide_from_catalog, launch_at,
              booking_enabled, booking_fee, booking_deadline_days, booking_instant_access, sp_mode,
              dime_enabled, dime_step_qty, dime_increment, dime_max_price,
              jv_partner_user_id, jv_partner_percent,
              price, original_price, price_myr, language, setup_fee,
              access_duration_days, pricing_type, flexible_label, flexible_min, flexible_presets, cta_label, show_on_support,
              affiliate_commission, thumbnail,
              landing_page_url, lp_type, canvas_id, checkout_template_id, show_coupon,
              checkout_mode, public_form_template, external_program_code,
              redirect_after_paid_url,
              short_description, description, highlights, includes, ai_knowledge,
              license_enabled, license_seats, license_validity_days,
              certificate_enabled, certificate_auto_issue, certificate_title_template,
              reviews_enabled,
              salespage_enabled, resell_product_id,
              access_page_enabled,
              access_type, embed_html, embed_url)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name, $slug, $type, $catalogCategory, $status, $hideFromCatalog, $launchAt,
            $bookingEnabled, $bookingFee, $bookingDeadline, $bookingInstant, $spMode,
            $dimeEnabled, $dimeStep, $dimeIncrement, $dimeMax,
            $jvPartnerIdDb, $jvPercent,
            $price, $origPrice, $priceMyr, $language, $setupFee, $duration,
            $pricingType, ($flexLabel !== '' ? $flexLabel : null), $flexMin, ($flexPresets !== '' ? $flexPresets : null), ($ctaLabel !== '' ? $ctaLabel : null), $showOnSupport,
            $commission, $thumbnailPath ?: null,
            $landingPageUrl ?: null, $lpType, $canvasId, $checkoutTemplateId, $showCoupon,
            $checkoutMode, $publicFormTemplate, $externalProgramCode,
            $redirectAfterPaid,
            $shortDesc ?: null, $description ?: null, $highlights ?: null, $includes ?: null, $aiKnowledge ?: null,
            $licenseEnabled, $licenseSeats, $licenseValidityDays,
            $certEnabled, $certAutoIssue, ($certTitleTpl !== '' ? $certTitleTpl : null),
            $reviewsEnabled,
            $salespageEnabled, $resellProductId,
            $accessEnabled,
            $accessType, ($embedHtml !== '' ? $embedHtml : null), ($embedUrl !== '' ? $embedUrl : null),
        ]);
        $productId = (int) $pdo->lastInsertId();
        logActivity('admin', 'product.create', 'product', $productId, 'Produk: ' . $name);
        flash('success', 'Produk "' . $name . '" berhasil ditambahkan.');
    }

    redirect('/admin/products');

} catch (Exception $e) {
    error_log('Save product error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menyimpan produk. Silakan coba lagi.', 'error');
    redirect('/admin/products');
}

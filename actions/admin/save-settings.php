<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/settings');
}

// Upload logo/favicon (grup branding) melebihi post_max_size hosting → PHP
// mengosongkan $_POST total (termasuk csrf_token) SEBELUM skrip ini jalan. Deteksi
// SEBELUM cek CSRF supaya pesannya jujur. Pola sama dgn save-canvas.php. Aman utk
// grup lain (tanpa upload) — postBodyTooLarge() praktis tak pernah true tanpa file.
if (postBodyTooLarge()) {
    $limit = phpUploadLimitBytes();
    saveResult(false, 'File terlalu besar — melebihi batas upload server'
        . ($limit > 0 ? ' (maks ' . formatFileSize($limit) . ')' : '')
        . '. Perkecil file, atau naikkan post_max_size & upload_max_filesize di pengaturan PHP hosting.', '/admin/settings');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    saveResult(false, 'Token keamanan tidak valid. Silakan coba lagi.', '/admin/settings');
}

$group    = trim($_POST['group'] ?? 'general');
$postVals = $_POST['settings'] ?? [];

$allowedGroups = ['branding', 'appearance', 'points', 'affiliate', 'payment', 'general', 'integrations', 'auth', 'invoice', 'reviews', 'member'];
if (!in_array($group, $allowedGroups, true)) {
    saveResult(false, 'Grup pengaturan tidak valid.', '/admin/settings');
}

// Whitelist key yang diizinkan per group (keamanan)
$allowedKeys = [
    'branding'      => ['app_name', 'brand_color', 'brand_color_secondary', 'homepage_headline', 'homepage_subheadline', 'footer_text', 'homepage_hero_badge', 'homepage_hero_cta', 'homepage_show_spotlight', 'homepage_show_new_release', 'footer_tagline', 'homepage_show_affiliate', 'homepage_affiliate_title', 'homepage_affiliate_badge_text', 'homepage_show_leadmagnet', 'homepage_leadmagnet_title', 'pwa_enabled', 'pwa_icon', 'show_sold_count'],
    'appearance'    => ['dark_mode_default', 'ui_theme', 'ui_sidebar_style', 'ui_topbar_style'],
    'points'        => ['points_enabled', 'points_label', 'points_menu_label', 'points_earn_per_amount', 'points_value', 'points_min_redeem', 'points_max_discount_percent'],
    'affiliate'     => ['affiliate_enabled', 'affiliate_commission_rate', 'min_payout_amount', 'affiliate_mode', 'affiliate_leaderboard_enabled', 'affiliate_general_cookie', 'affiliate_cookie_days', 'affiliate_lock_customer', 'affiliate_zero_no_commission', 'affiliate_menu_show_all'],
    'payment'       => ['currency', 'currency_symbol', 'order_expiry_hours', 'order_confirmation_whatsapp'],
    'general'       => ['app_version'],
    'integrations'  => [
        'payment_active_gateway',
        'duitku_merchant_code', 'duitku_api_key', 'duitku_sandbox',
        'xendit_secret_key', 'xendit_webhook_token',
        'moota_api_key',
        'mailketing_api_key', 'mailketing_sender_email', 'mailketing_sender_name',
        'wa_provider', 'wa_api_key', 'wa_sender_number',
        'manual_transfer_enabled', 'manual_transfer_instructions', 'manual_unique_code_enabled',
    ],
    // Catatan: 'google_oauth_client_secret' SENGAJA tidak masuk whitelist generik.
    // Secret ditangani terpisah agar tidak terhapus saat form disubmit kosong.
    'auth'          => ['google_oauth_enabled', 'google_oauth_client_id'],
    'invoice'       => [
        'invoice_seller_name', 'invoice_seller_address', 'invoice_seller_city',
        'invoice_seller_phone', 'invoice_seller_email', 'invoice_seller_npwp',
        'invoice_seller_website', 'invoice_footer_note', 'invoice_show_npwp', 'invoice_show_logo',
    ],
    'reviews'       => ['reviews_enabled', 'reviews_require_access', 'reviews_per_page'],
    'member'        => ['member_menu_downloads', 'member_menu_certificates', 'member_offers_enabled', 'member_offers_floating', 'member_offers_title'],
];

// Gabung branding + appearance karena form branding mengirim keduanya
if ($group === 'branding') {
    $allowedKeys['branding'] = array_merge(
        $allowedKeys['branding'],
        $allowedKeys['appearance']
    );
}

if ($group === 'payment' && isset($postVals['order_confirmation_whatsapp'])) {
    $number = trim((string) $postVals['order_confirmation_whatsapp']);
    if ($number !== '' && !preg_match('/^[1-9][0-9]{7,14}$/D', $number)) {
        saveResult(false, 'Masukkan nomor WhatsApp admin dengan kode negara dan angka saja.', '/admin/settings');
    }
    $postVals['order_confirmation_whatsapp'] = $number;
}

$permitted = $allowedKeys[$group] ?? [];

try {
    $pdo = getDB();

    // ── Upload file (logo & favicon) ────────────────────────
    $uploadFields = [
        'logo_file'     => 'app_logo',
        'favicon_file'  => 'app_favicon',
        'pwa_icon_file' => 'pwa_icon',
    ];

    foreach ($uploadFields as $inputName => $settingKey) {
        if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $file = $_FILES[$inputName];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Gagal upload file ' . $inputName . '. Kode error: ' . $file['error'], 'error');
            redirect('/admin/branding');
        }

        if ($file['size'] > MAX_UPLOAD_SIZE) {
            flash('error', 'Ukuran file terlalu besar. Maksimal 2MB.', 'error');
            redirect('/admin/branding');
        }

        // Validasi MIME type. SVG SENGAJA TIDAK diizinkan: file .svg bisa memuat
        // <script> (stored XSS) dan disajikan ke semua pengunjung. Pakai PNG/JPG/WEBP/ICO.
        $mimeToExt = [
            'image/png'                    => 'png',
            'image/jpeg'                   => 'jpg',
            'image/webp'                   => 'webp',
            'image/x-icon'                 => 'ico',
            'image/vnd.microsoft.icon'     => 'ico',
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!isset($mimeToExt[$mime])) {
            flash('error', 'Format file tidak diizinkan. Gunakan PNG, JPG, WEBP, atau ICO.', 'error');
            redirect('/admin/branding');
        }

        // Nama file unik; ekstensi diturunkan dari MIME tervalidasi (bukan nama
        // kiriman user) agar tidak ada ekstensi yang dikontrol penyerang.
        $ext      = $mimeToExt[$mime];
        $filename = $settingKey . '_' . date('Ymd_His') . '.' . $ext;
        $dest     = rtrim(UPLOAD_PATH, '/') . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            error_log('save-settings: gagal memindahkan file ke ' . $dest);
            flash('error', 'Gagal menyimpan file. Periksa permission folder uploads.', 'error');
            redirect('/admin/branding');
        }
        compressUploadedImage($dest, 1024); // logo/favicon tak perlu > 1024px; .ico dilewati otomatis

        $postVals[$settingKey] = rtrim(UPLOAD_URL, '/') . '/' . $filename;
        $permitted[]           = $settingKey;
    }

    // Reset ikon PWA ke bawaan (centang "hapus ikon kustom"), hanya bila tak ada upload baru.
    if (($_POST['pwa_icon_reset'] ?? '') === '1'
        && (!isset($_FILES['pwa_icon_file']) || $_FILES['pwa_icon_file']['error'] === UPLOAD_ERR_NO_FILE)) {
        $postVals['pwa_icon'] = '';
        $permitted[]          = 'pwa_icon';
    }

    // ── Simpan settings ke DB ──────────────────────────────
    $stmt = $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value, setting_group)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
           setting_value = VALUES(setting_value),
           updated_at    = NOW()"
    );

    $savedCount = 0;
    foreach ($postVals as $key => $value) {
        $key = trim($key);
        if (!in_array($key, $permitted, true)) {
            continue; // Skip keys yang tidak diizinkan
        }

        // Sanitasi value
        $value = trim((string) $value);

        // Validasi spesifik
        if ($key === 'brand_color' || $key === 'brand_color_secondary') {
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                continue; // Skip warna tidak valid
            }
        }
        if ($key === 'affiliate_commission_rate') {
            $value = (string) min(100, max(0, (float) $value));
        }
        if ($key === 'affiliate_cookie_days') {
            // Input non-numerik (POST manual "abc"/kosong) DIABAIKAN → pertahankan nilai
            // lama, jangan sampai (int)"abc"=0 diam-diam mengubah cookie jadi lifetime.
            // Nilai numerik di-clamp int 0..3650 (sinkron input UI). 0 = lifetime
            // (disengaja oleh admin), >0 = jumlah hari.
            if (!is_numeric($value)) {
                continue;
            }
            $value = (string) min(3650, max(0, (int) $value));
        }
        if ($key === 'order_expiry_hours') {
            $value = (string) min(720, max(0, (int) $value));
        }
        if ($key === 'min_payout_amount') {
            $value = (string) max(0, (int) $value);
        }
        if (in_array($key, ['affiliate_enabled', 'affiliate_zero_no_commission', 'affiliate_menu_show_all', 'dark_mode_default', 'duitku_sandbox', 'google_oauth_enabled', 'homepage_show_spotlight', 'homepage_show_new_release', 'homepage_show_affiliate', 'homepage_show_leadmagnet', 'show_sold_count', 'invoice_show_npwp', 'invoice_show_logo', 'reviews_enabled', 'reviews_require_access', 'manual_transfer_enabled', 'manual_unique_code_enabled', 'member_menu_downloads', 'member_menu_certificates', 'member_offers_enabled', 'member_offers_floating'], true)) {
            $value = in_array($value, ['0', '1'], true) ? $value : '0';
        }
        if ($key === 'homepage_affiliate_title' || $key === 'homepage_leadmagnet_title') {
            $value = mb_substr(strip_tags($value), 0, 60);
        }
        if ($key === 'homepage_affiliate_badge_text') {
            $value = mb_substr(strip_tags($value), 0, 30);
        }
        if ($key === 'ui_theme') {
            // Validasi dari daftar preset (sumber tunggal, helpers/theme-config.php)
            // — daftar hardcode lama sempat menolak theme baru (bug Vivid).
            require_once __DIR__ . '/../../helpers/theme-config.php';
            if (!in_array($value, array_keys(getAllThemePresets()), true)) {
                continue; // theme tidak valid → abaikan
            }
        }
        if ($key === 'ui_sidebar_style' && !in_array($value, ['light', 'dark', 'colored'], true)) {
            continue; // sidebar style tidak valid → abaikan
        }
        if ($key === 'ui_topbar_style' && !in_array($value, ['default', 'colored', 'dark', 'floating'], true)) {
            continue; // topbar style tidak valid → abaikan
        }
        if ($key === 'homepage_headline') {
            $value = mb_substr(strip_tags($value), 0, 100);
        }
        if ($key === 'homepage_subheadline') {
            $value = mb_substr(strip_tags($value), 0, 200);
        }
        if ($key === 'footer_text') {
            $value = mb_substr(strip_tags($value), 0, 200);
        }
        if ($key === 'homepage_hero_badge') {
            $value = mb_substr(strip_tags($value), 0, 60);
        }
        if ($key === 'homepage_hero_cta') {
            $value = mb_substr(strip_tags($value), 0, 40);
        }
        if ($key === 'footer_tagline') {
            $value = mb_substr(strip_tags($value), 0, 200);
        }
        if ($key === 'member_offers_title') {
            $value = mb_substr(strip_tags($value), 0, 60);
        }

        // Determine group from key
        $keyGroup = $group;
        if (in_array($key, ['dark_mode_default', 'ui_theme', 'ui_sidebar_style', 'ui_topbar_style'], true)) {
            $keyGroup = 'appearance';
        }
        if (in_array($key, ['app_logo', 'app_favicon', 'app_name', 'brand_color', 'brand_color_secondary'], true)) {
            $keyGroup = 'branding';
        }

        $stmt->execute([$key, $value, $keyGroup]);
        $savedCount++;
    }

    // ── Google OAuth Client Secret (perlakuan khusus) ──────────
    // Hanya disimpan bila diisi; dikosongkan = pertahankan nilai lama di DB.
    if ($group === 'auth') {
        $newSecret = trim((string) ($postVals['google_oauth_client_secret'] ?? ''));
        if ($newSecret !== '') {
            setSetting('google_oauth_client_secret', $newSecret, 'auth');
            $savedCount++;
        }
    }

    $redirect = match($group) {
        'branding'     => '/admin/branding',
        'integrations' => '/admin/integrations',
        default        => '/admin/settings',
    };
    // Form pengaturan tampilan penawaran dikirim dari /admin/upsells → kembali ke sana
    // (whitelist path internal; tak menerima URL sembarang dari client).
    if (($_POST['return'] ?? '') === 'upsells') {
        $redirect = '/admin/upsells';
    }
    logActivity('admin', 'settings.update', 'settings', null, 'Pengaturan diperbarui: group ' . $group);
    saveResult(true, 'Pengaturan berhasil disimpan.', $redirect);

} catch (Exception $e) {
    error_log('save-settings error: ' . $e->getMessage());
    saveResult(false, 'Terjadi kesalahan saat menyimpan pengaturan. Silakan coba lagi.', '/admin/settings');
}

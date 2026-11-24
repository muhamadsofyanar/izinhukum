<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/lead-magnets');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/lead-magnets');
}

// ─── Slug sanitizer ───────────────────────────────────────────
function lm_sanitize_slug(string $raw): string
{
    $slug = strtolower(trim($raw));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    // Hindari bentrok dengan route inti / segmen sistem.
    $reserved = ['admin', 'dashboard', 'login', 'register', 'logout', 'checkout',
                 'products', 'member', 'affiliate', 'api', 'assets', 'actions',
                 'pages', 'uploads', 'webhook', 'webhooks', 'cron', 'go', 'r',
                 'auth', 'transactions', 'profile', 'login-otp', 'license-expired', 'lp'];
    if (in_array($slug, $reserved, true)) $slug = '';
    return $slug;
}

// ─── Thumbnail upload (JPG/PNG/WEBP, maks 2MB) ────────────────
function lm_upload_thumbnail(?array $file): array
{
    if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null]; // tidak ada file → bukan error
    }
    if ((int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return [null, 'Gagal mengunggah thumbnail.'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return [null, 'Berkas thumbnail tidak valid.'];
    }
    if ((int) ($file['size'] ?? 0) > MAX_UPLOAD_SIZE) {
        return [null, 'Ukuran thumbnail maksimal 2MB.'];
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $fi   = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string) finfo_file($fi, $tmp) : '';
        if ($fi) finfo_close($fi);
    }
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($map[$mime])) {
        return [null, 'Format thumbnail harus JPG, PNG, atau WEBP.'];
    }

    $dirAbs = rtrim(UPLOAD_PATH, '/\\') . '/lead-magnets';
    if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
        return [null, 'Gagal menyiapkan folder upload.'];
    }
    $fname = 'lm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $map[$mime];
    if (!@move_uploaded_file($tmp, $dirAbs . '/' . $fname)) {
        return [null, 'Gagal menyimpan thumbnail.'];
    }
    compressUploadedImage($dirAbs . '/' . $fname); // resize+kompres in-place, format tetap
    return ['assets/uploads/lead-magnets/' . $fname, null];
}

// ─── Input ────────────────────────────────────────────────────
$id          = (int) ($_POST['id'] ?? 0);
$title       = trim($_POST['title'] ?? '');
$slug        = lm_sanitize_slug($_POST['slug'] ?? ($title !== '' ? $title : ''));
$headline    = trim($_POST['headline'] ?? '');
$subheadline = trim($_POST['subheadline'] ?? '');
$badgeText   = trim($_POST['badge_text'] ?? '');
$ctaText     = trim($_POST['cta_text'] ?? '');
$thankyou    = trim($_POST['thankyou_message'] ?? '');
// Mode akses "Embed langsung" (v1.2.148).
$accessType  = (($_POST['access_type'] ?? 'course') === 'embed') ? 'embed' : 'course';
$embedHtml   = trim((string) ($_POST['embed_html'] ?? ''));
$embedUrl    = trim((string) ($_POST['embed_url'] ?? ''));
if ($embedUrl !== '' && !preg_match('#^https?://#i', $embedUrl)) $embedUrl = '';
$pixelIds    = trim($_POST['fb_pixel_ids'] ?? '');
$bonusLevel  = (int) ($_POST['bonus_membership_level_id'] ?? 0) ?: null;
// Produk yang "diberi makan" lead magnet ini — dipakai Funnel Analytics untuk
// menyambung tahap opt-in → sales page → bayar. NULL = tak tertaut (tahap Lead
// Magnet otomatis disembunyikan di funnel produk).
$linkedProduct = (int) ($_POST['linked_product_id'] ?? 0) ?: null;
$status      = $_POST['status'] ?? 'draft';
if (!in_array($status, ['draft', 'published', 'paused'], true)) $status = 'draft';
// Tampilkan sebagai kartu di section "Gratis" homepage (opt-in per magnet).
// Hanya berpengaruh bila status='published' (query homepage memfilter itu).
$showHome    = !empty($_POST['show_on_homepage']) ? 1 : 0;
$affEnabled  = !empty($_POST['affiliate_enabled']) ? 1 : 0;
// Tripwire: produk berbayar opsional ditawarkan di thank-you opt-in. Product Bumps
// produk itu tampil otomatis di checkout-nya (reuse engine checkout, nol duplikasi).
$tripwireProduct  = ((int) ($_POST['tripwire_product_id'] ?? 0)) ?: null;
$tripwireHeadline = mb_substr(strip_tags(trim($_POST['tripwire_headline'] ?? '')), 0, 200);
// Wajib OTP WhatsApp sebelum lead anonim masuk dashboard (default 1 = wajib).
$requireOtp       = !empty($_POST['require_otp']) ? 1 : 0;

// Pixel: simpan hanya angka, pisah koma
if ($pixelIds !== '') {
    $parts = array_filter(array_map(fn($p) => preg_replace('/[^0-9]/', '', $p), explode(',', $pixelIds)));
    $pixelIds = implode(',', $parts);
}

// ─── Validasi ─────────────────────────────────────────────────
if ($title === '') {
    flash('error', 'Judul lead magnet wajib diisi.', 'error');
    redirect('/admin/lead-magnets');
}
if ($slug === '') {
    flash('error', 'Slug tidak valid atau bentrok dengan route sistem. Coba slug lain.', 'error');
    redirect('/admin/lead-magnets');
}

$pdo = getDB();
if (function_exists('ensureEmbedAccessColumns')) ensureEmbedAccessColumns($pdo);

// Validasi membership level milik vendor (jika diisi)
if ($bonusLevel !== null) {
    try {
        $chk = $pdo->prepare('SELECT id FROM membership_levels WHERE id = ? LIMIT 1');
        $chk->execute([$bonusLevel]);
        if (!$chk->fetch()) $bonusLevel = null;
    } catch (Exception $e) {
        $bonusLevel = null;
    }
}

// Validasi produk tripwire ada (jika diisi) — cegah simpan id sampah.
if ($tripwireProduct !== null) {
    try {
        $chk = $pdo->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
        $chk->execute([$tripwireProduct]);
        if (!$chk->fetch()) $tripwireProduct = null;
    } catch (Exception $e) {
        $tripwireProduct = null;
    }
}

// ─── Upload thumbnail (opsional) ─────────────────────────────
[$newThumb, $thumbErr] = lm_upload_thumbnail($_FILES['thumbnail'] ?? null);
if ($thumbErr) {
    flash('error', $thumbErr, 'error');
    redirect('/admin/lead-magnets');
}

try {
    // Cek duplikat slug (kecuali milik sendiri)
    $dup = $pdo->prepare('SELECT id FROM lead_magnets WHERE slug = ? AND id != ? LIMIT 1');
    $dup->execute([$slug, $id]);
    if ($dup->fetch()) {
        flash('error', 'Slug "' . $slug . '" sudah digunakan. Pakai slug lain.', 'error');
        redirect('/admin/lead-magnets');
    }

    if ($id > 0) {
        // Pertahankan thumbnail lama jika tidak upload baru
        $thumb = $newThumb;
        if ($thumb === null) {
            $cur = $pdo->prepare('SELECT thumbnail FROM lead_magnets WHERE id = ? LIMIT 1');
            $cur->execute([$id]);
            $thumb = $cur->fetchColumn() ?: null;
        }
        $stmt = $pdo->prepare(
            'UPDATE lead_magnets SET
               title = ?, slug = ?, linked_product_id = ?, headline = ?, subheadline = ?, thumbnail = ?,
               badge_text = ?, cta_text = ?, bonus_membership_level_id = ?,
               fb_pixel_ids = ?, thankyou_message = ?, status = ?, show_on_homepage = ?, affiliate_enabled = ?,
               tripwire_product_id = ?, tripwire_headline = ?, require_otp = ?,
               access_type = ?, embed_html = ?, embed_url = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $title, $slug, $linkedProduct, ($headline ?: null), ($subheadline ?: null), $thumb,
            ($badgeText ?: null), ($ctaText ?: null), $bonusLevel,
            ($pixelIds ?: null), ($thankyou ?: null), $status, $showHome, $affEnabled,
            $tripwireProduct, ($tripwireHeadline ?: null), $requireOtp,
            $accessType, ($embedHtml !== '' ? $embedHtml : null), ($embedUrl !== '' ? $embedUrl : null), $id,
        ]);
        if (function_exists('logActivity')) {
            logActivity('admin', 'lead_magnet.updated', 'lead_magnets', $id, 'Lead magnet "' . $title . '" diperbarui.');
        }
        flash('success', 'Lead magnet berhasil diperbarui.');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO lead_magnets
               (title, slug, linked_product_id, headline, subheadline, thumbnail, badge_text, cta_text,
                bonus_membership_level_id, fb_pixel_ids, thankyou_message, status, show_on_homepage, affiliate_enabled,
                tripwire_product_id, tripwire_headline, require_otp,
                access_type, embed_html, embed_url)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $title, $slug, $linkedProduct, ($headline ?: null), ($subheadline ?: null), $newThumb,
            ($badgeText ?: null), ($ctaText ?: null), $bonusLevel,
            ($pixelIds ?: null), ($thankyou ?: null), $status, $showHome, $affEnabled,
            $tripwireProduct, ($tripwireHeadline ?: null), $requireOtp,
            $accessType, ($embedHtml !== '' ? $embedHtml : null), ($embedUrl !== '' ? $embedUrl : null),
        ]);
        $newId = (int) $pdo->lastInsertId();
        if (function_exists('logActivity')) {
            logActivity('admin', 'lead_magnet.created', 'lead_magnets', $newId, 'Lead magnet "' . $title . '" dibuat.');
        }
        flash('success', 'Lead magnet berhasil dibuat. URL publik: /lp/' . $slug);
    }
} catch (Exception $e) {
    error_log('Save lead magnet error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menyimpan lead magnet. Pastikan migration sudah dijalankan.', 'error');
}

redirect('/admin/lead-magnets');

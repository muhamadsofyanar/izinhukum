<?php
// POST /actions/admin/save-affiliate-display — buat/ubah Display Produk Affiliate.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/affiliate-displays');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/affiliate-displays');
}

$id           = (int) ($_POST['id'] ?? 0);
$name         = trim($_POST['name'] ?? '');
$image        = trim($_POST['image'] ?? '');
$salespageUrl = trim($_POST['salespage_url'] ?? '');
$checkoutUrl  = trim($_POST['checkout_url'] ?? '');
$priceText    = trim($_POST['price_text'] ?? '');
$badge        = trim($_POST['badge'] ?? '');
$shortDesc    = trim($_POST['short_desc'] ?? '');
$sortOrder    = (int) ($_POST['sort_order'] ?? 0);
$status       = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

// URL WAJIB http/https — cegah skema berbahaya (javascript:/data:) masuk ke
// atribut href/src yang di-render ke pengunjung homepage (XSS).
$validUrl = function (string $u): bool {
    if ($u === '' || !filter_var($u, FILTER_VALIDATE_URL)) return false;
    return in_array(strtolower((string) parse_url($u, PHP_URL_SCHEME)), ['http', 'https'], true);
};

$errors = [];
if ($name === '')                    $errors[] = 'Nama wajib diisi.';
if (!$validUrl($salespageUrl))       $errors[] = 'Link sales page harus URL http/https yang valid.';
if ($checkoutUrl !== '' && !$validUrl($checkoutUrl)) $errors[] = 'Link checkout harus URL http/https yang valid.';
// Gambar: boleh URL http/https ATAU path relatif dari Media Library (diawali '/').
if ($image !== '' && $image[0] !== '/' && !$validUrl($image)) $errors[] = 'URL gambar tidak valid.';

if (!empty($errors)) {
    flash('error', implode(' ', $errors), 'error');
    redirect('/admin/affiliate-displays');
}

// Batas panjang + buang tag dari field teks tampilan.
$name      = mb_substr($name, 0, 200);
$priceText = mb_substr(strip_tags($priceText), 0, 60);
$badge     = mb_substr(strip_tags($badge), 0, 40);
$shortDesc = mb_substr(strip_tags($shortDesc), 0, 300);

try {
    $pdo = getDB();
    if ($id > 0) {
        $pdo->prepare(
            "UPDATE affiliate_displays
             SET name=?, image=?, salespage_url=?, checkout_url=?, price_text=?, badge=?, short_desc=?, sort_order=?, status=?, updated_at=NOW()
             WHERE id=?"
        )->execute([$name, $image ?: null, $salespageUrl, $checkoutUrl ?: null, $priceText ?: null, $badge ?: null, $shortDesc ?: null, $sortOrder, $status, $id]);
        flash('success', 'Display affiliate diperbarui.');
    } else {
        $pdo->prepare(
            "INSERT INTO affiliate_displays (name, image, salespage_url, checkout_url, price_text, badge, short_desc, sort_order, status)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([$name, $image ?: null, $salespageUrl, $checkoutUrl ?: null, $priceText ?: null, $badge ?: null, $shortDesc ?: null, $sortOrder, $status]);
        flash('success', 'Display affiliate ditambahkan.');
    }
    logActivity('admin', 'affiliate_display.save', 'affiliate_display', $id ?: null, 'Simpan display: ' . $name);
} catch (Exception $e) {
    error_log('save-affiliate-display error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan display.', 'error');
}
redirect('/admin/affiliate-displays');

<?php
// Daftar waitlist produk coming soon (guest atau user login). Meniru pola submit-lead.
require_once __DIR__ . '/../../bootstrap.php';

$slug    = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['slug'] ?? '')));
$backUrl = $slug !== '' ? ('/products/' . $slug) : '/products';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($backUrl);
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('waitlist_form', 'Sesi kedaluwarsa. Silakan muat ulang halaman.', 'error');
    redirect($backUrl);
}

// Throttle ringan: min 3 detik antar submit per sesi.
$now = time();
if (isset($_SESSION['waitlist_last_submit']) && ($now - (int) $_SESSION['waitlist_last_submit']) < 3) {
    flash('waitlist_form', 'Tunggu sebentar sebelum mengirim lagi.', 'error');
    redirect($backUrl);
}

$productId = (int) ($_POST['product_id'] ?? 0);
$pdo = getDB();

// Produk harus ada & berstatus coming_soon (waitlist hanya untuk pra-launch).
try {
    $stmt = $pdo->prepare(
        "SELECT id, name, slug FROM products
         WHERE id = ? AND slug = ? AND status = 'coming_soon' LIMIT 1"
    );
    $stmt->execute([$productId, $slug]);
    $product = $stmt->fetch();
} catch (Exception $e) {
    error_log('Submit waitlist load product error: ' . $e->getMessage());
    $product = null;
}
if (!$product) {
    flash('waitlist_form', 'Waitlist untuk produk ini sedang tidak tersedia.', 'error');
    redirect($backUrl);
}

// Data pendaftar: dari akun bila login, dari form bila anonim.
$userId = 0;
if (isLoggedIn()) {
    $acct = null;
    try {
        $u = $pdo->prepare('SELECT id, name, email, phone FROM users WHERE id = ? LIMIT 1');
        $u->execute([(int) ($_SESSION['user_id'] ?? 0)]);
        $acct = $u->fetch() ?: null;
    } catch (Exception $e) {
        $acct = null;
    }
    if ($acct) {
        $userId   = (int) $acct['id'];
        $name     = trim((string) $acct['name']) !== '' ? trim((string) $acct['name']) : 'Member';
        $email    = trim((string) ($acct['email'] ?? ''));
        $whatsapp = lead_normalize_wa((string) ($acct['phone'] ?? ''));
        // Akun tanpa nomor WA → coba dari form (bila diisi).
        if ($whatsapp === '') $whatsapp = lead_normalize_wa(trim((string) ($_POST['whatsapp'] ?? '')));
    } else {
        $name = ''; $email = ''; $whatsapp = '';
    }
} else {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $whatsapp = lead_normalize_wa(trim($_POST['whatsapp'] ?? ''));
}

// Validasi.
if ($name === '' || mb_strlen($name) < 2) {
    flash('waitlist_form', 'Nama lengkap wajib diisi.', 'error');
    redirect($backUrl);
}
if ($whatsapp === '' || strlen($whatsapp) < 10 || strlen($whatsapp) > 15) {
    flash('waitlist_form', 'Nomor WhatsApp tidak valid.', 'error');
    redirect($backUrl);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('waitlist_form', 'Format email tidak valid.', 'error');
    redirect($backUrl);
}

// Dedup per produk: cocokkan via WA / email / user. Sudah terdaftar → perbarui ringan.
try {
    $params = [$productId, $whatsapp];
    $cond   = 'whatsapp = ?';
    if ($userId > 0)   { $cond .= ' OR user_id = ?'; $params[] = $userId; }
    if ($email !== '') { $cond .= ' OR email = ?';   $params[] = $email; }
    $dupSt = $pdo->prepare("SELECT id FROM product_waitlist WHERE product_id = ? AND ($cond) LIMIT 1");
    $dupSt->execute($params);
    $existingId = (int) ($dupSt->fetchColumn() ?: 0);
} catch (Exception $e) {
    $existingId = 0;
}

if ($existingId > 0) {
    try {
        $pdo->prepare(
            "UPDATE product_waitlist
             SET name = ?, email = COALESCE(NULLIF(?, ''), email),
                 user_id = COALESCE(?, user_id)
             WHERE id = ?"
        )->execute([$name, $email, ($userId > 0 ? $userId : null), $existingId]);
    } catch (Exception $e) {
        error_log('Submit waitlist update error: ' . $e->getMessage());
    }
} else {
    try {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $pdo->prepare(
            "INSERT INTO product_waitlist (product_id, user_id, name, whatsapp, email, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$productId, ($userId > 0 ? $userId : null), $name, $whatsapp, ($email ?: null), $ip]);
    } catch (Exception $e) {
        error_log('Submit waitlist insert error: ' . $e->getMessage());
        flash('waitlist_form', 'Terjadi kesalahan. Coba lagi sebentar.', 'error');
        redirect($backUrl);
    }

    // Konfirmasi email+WA (hanya pendaftar baru; no-op bila template nonaktif).
    try {
        require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
        NotificationDispatcher::trigger('waitlist_joined', [
            'name'         => $name,
            'product_name' => (string) $product['name'],
            'product_url'  => rtrim(APP_URL, '/') . '/products/' . $product['slug'],
        ], $email, $whatsapp);
    } catch (Throwable $e) {
        error_log('Waitlist notify error: ' . $e->getMessage());
    }

    // Email sequence trigger waitlist_joined — enrollment butuh akun user:
    // pakai user login, atau cocokkan email pendaftar ke user eksisting.
    // Pendaftar tamu tanpa akun tetap dapat notif sekali di atas, tanpa sequence.
    try {
        $seqUserId = $userId;
        if ($seqUserId <= 0 && $email !== '') {
            $uq = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $uq->execute([$email]);
            $seqUserId = (int) ($uq->fetchColumn() ?: 0);
        }
        if ($seqUserId > 0) {
            enrollUserToSequences('waitlist_joined', $seqUserId, $productId, 'waitlist:' . $productId);
        }
    } catch (Throwable $e) {
        error_log('Waitlist sequence enroll error: ' . $e->getMessage());
    }
}

$_SESSION['waitlist_last_submit'] = $now;
redirect($backUrl . '?waitlisted=1#waitlist');

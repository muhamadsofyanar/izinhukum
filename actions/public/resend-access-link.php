<?php
// ════════════════════════════════════════════════════════════════
// Kirim ulang magic-link akses — /actions/public/resend-access-link
// Dipicu dari public-thank-you (akun lama, order sudah paid). Publik, TANPA login,
// tapi digerbang: CSRF + public_order_token sesi (bukti pemilik checkout ini) +
// rate-limit. Hanya role 'user' (gate di dispatchAccessLink). PRG balik ke thank-you.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';

$orderNumber = preg_replace('/[^A-Za-z0-9_-]/', '', trim($_POST['order_number'] ?? ''));
$backUrl     = '/public-thank-you/' . $orderNumber;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($orderNumber !== '' ? $backUrl : '/login');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    redirect($backUrl . '?resent=0');
}

// Gerbang kepemilikan: token sesi harus cocok dengan order_number ini.
$token = $_SESSION['public_order_token'] ?? null;
if (!is_array($token) || empty($token['order_number'])
    || empty($token['expires_at']) || time() > (int) $token['expires_at']
    || strcasecmp((string) $token['order_number'], $orderNumber) !== 0) {
    redirect('/login');
}

// Rate-limit: 1 kirim-ulang per 60 detik (anti-spam ke email/WA pembeli).
$lastResend = (int) ($_SESSION['last_access_link_resend'] ?? 0);
if (time() - $lastResend < 60) {
    redirect($backUrl . '?resent=wait');
}

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        "SELECT o.id, o.user_id, o.status,
                (SELECT oi.product_id FROM order_items oi WHERE oi.order_id = o.id AND oi.is_bump = 0 ORDER BY oi.id LIMIT 1) AS product_id,
                (SELECT oi.product_name FROM order_items oi WHERE oi.order_id = o.id AND oi.is_bump = 0 ORDER BY oi.id LIMIT 1) AS product_name
         FROM orders o WHERE o.order_number = ? LIMIT 1"
    );
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();

    // Hanya kirim bila akses benar-benar sudah aktif (status paid). Order pending →
    // link belum relevan (dikirim otomatis saat pembayaran terkonfirmasi via webhook).
    if (!$order || $order['status'] !== 'paid') {
        redirect($backUrl . '?resent=0');
    }

    dispatchAccessLink(
        (int) $order['user_id'],
        (int) $order['id'],
        (int) ($order['product_id'] ?? 0),
        (string) ($order['product_name'] ?? '')
    );
    $_SESSION['last_access_link_resend'] = time();
    redirect($backUrl . '?resent=1');
} catch (Throwable $e) {
    error_log('resend-access-link error: ' . $e->getMessage());
    redirect($backUrl . '?resent=0');
}

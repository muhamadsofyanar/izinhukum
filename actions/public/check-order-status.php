<?php
// ─── Public Checkout: polling status order (tanpa login) ──────
// Divalidasi via $_SESSION['public_order_token'] — bukan hanya order_number,
// agar tidak bisa di-poll orang lain yang sekadar tahu nomor order.
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Metode tidak valid.', 'paid' => false]);
    exit;
}

$orderNumber = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($_POST['order_number'] ?? '')));
$token       = $_SESSION['public_order_token'] ?? null;

// Validasi token
if (!is_array($token)
    || empty($token['order_number'])
    || empty($token['expires_at'])
    || time() > (int) $token['expires_at']
    || strtoupper((string) $token['order_number']) !== $orderNumber
) {
    echo json_encode(['status' => 'invalid', 'message' => 'Sesi tidak valid atau sudah berakhir.', 'paid' => false]);
    exit;
}

try {
    $st = getDB()->prepare("SELECT status FROM orders WHERE order_number = ? LIMIT 1");
    $st->execute([$orderNumber]);
    $status = $st->fetchColumn();

    if ($status === false) {
        echo json_encode(['status' => 'invalid', 'message' => 'Order tidak ditemukan.', 'paid' => false]);
        exit;
    }

    $paid = ($status === 'paid');

    $message = match ($status) {
        'paid'      => 'Pembayaran dikonfirmasi!',
        'pending'   => 'Menunggu pembayaran',
        'failed'    => 'Pembayaran gagal',
        'cancelled' => 'Order dibatalkan',
        'expired'   => 'Order kedaluwarsa',
        default     => 'Status: ' . $status,
    };

    // Token DIBIARKAN hidup sampai kedaluwarsa alami (1 jam) — setelah paid,
    // halaman thank-you di-reload untuk menampilkan state lunas + penawaran
    // upsell; meng-unset token di sini membuat reload terpental ke /login.

    echo json_encode([
        'status'  => $status,
        'message' => $message,
        'paid'    => $paid,
    ]);

} catch (Exception $e) {
    error_log('check-order-status error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Terjadi kesalahan.', 'paid' => false]);
}
exit;

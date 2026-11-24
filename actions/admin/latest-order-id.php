<?php
/**
 * Averion — Endpoint polling: ID order terbaru (SP-05)
 * ----------------------------------------------------------------------------
 * Dipanggil tiap ~30 detik oleh halaman admin/transactions untuk mendeteksi
 * order baru. Ringan (LIMIT 1, index id DESC). Admin-only + butuh header AJAX.
 */
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Wajib request via XHR (cegah akses langsung dari address bar).
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

try {
    $stmt = getDB()->query(
        "SELECT o.id, u.name AS buyer_name, p.name AS product_name, o.total, o.created_at
         FROM orders o
         JOIN users u        ON u.id = o.user_id
         JOIN order_items oi ON oi.order_id = o.id AND oi.is_bump = 0
         JOIN products p     ON p.id = oi.product_id
         ORDER BY o.id DESC
         LIMIT 1"
    );
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(['id' => 0]);
        exit;
    }

    // Nama disingkat: kata pertama + inisial kata kedua (mis. "Budi S.").
    $name  = trim((string) $row['buyer_name']);
    $parts = preg_split('/\s+/', $name);
    $short = $parts[0] ?? 'Pembeli';
    if (!empty($parts[1])) {
        $short .= ' ' . mb_substr($parts[1], 0, 1) . '.';
    }

    echo json_encode([
        'id'               => (int) $row['id'],
        'buyer_name'       => $short,
        'product_name'     => (string) $row['product_name'],
        'amount_formatted' => formatRupiah((float) $row['total']),
        'created_at'       => (string) $row['created_at'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('latest-order-id error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
}

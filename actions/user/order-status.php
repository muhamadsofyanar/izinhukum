<?php
// ─── Polling status order (user login) ─────────────────────────
// Dipakai halaman detail transaksi untuk memperbarui status secara live
// saat buyer membayar di tab gateway. Hanya order milik user yang login.
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'paid' => false]);
    exit;
}

$orderNumber = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($_POST['order_number'] ?? '')));
if ($orderNumber === '') {
    echo json_encode(['status' => 'invalid', 'paid' => false]);
    exit;
}

try {
    $st = getDB()->prepare("SELECT status FROM orders WHERE order_number = ? AND user_id = ? LIMIT 1");
    $st->execute([$orderNumber, (int) $_SESSION['user_id']]);
    $status = $st->fetchColumn();

    if ($status === false) {
        echo json_encode(['status' => 'invalid', 'paid' => false]);
        exit;
    }

    echo json_encode(['status' => $status, 'paid' => $status === 'paid']);
} catch (Exception $e) {
    error_log('order-status error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'paid' => false]);
}
exit;

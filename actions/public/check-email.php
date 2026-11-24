<?php
// ─── Public Checkout: cek apakah email sudah terdaftar ────────
// Endpoint AJAX. Tidak perlu auth. Dipakai untuk menampilkan banner
// "email sudah terdaftar, silakan login" di form publik.
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$email = strtolower(trim($_POST['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['exists' => false]);
    exit;
}

try {
    $st = getDB()->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    echo json_encode(['exists' => (bool) $st->fetch()]);
} catch (Exception $e) {
    error_log('check-email error: ' . $e->getMessage());
    echo json_encode(['exists' => false]);
}
exit;

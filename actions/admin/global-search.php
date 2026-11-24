<?php
// GET /actions/admin/global-search?q=... — pencarian global dashboard (Ctrl+K).
// Cari produk, user, transaksi (maks 5 per kategori, prepared LIKE, indexed).
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();

header('Content-Type: application/json');

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'products' => [], 'users' => [], 'orders' => []]);
    exit;
}
$like = '%' . $q . '%';
$out  = ['success' => true, 'products' => [], 'users' => [], 'orders' => []];

try {
    $pdo = getDB();

    if (staffCan('products')) {
        $st = $pdo->prepare("SELECT id, name, slug, status FROM products WHERE name LIKE ? OR slug LIKE ? ORDER BY created_at DESC LIMIT 5");
        $st->execute([$like, $like]);
        foreach ($st->fetchAll() as $r) {
            $out['products'][] = ['label' => $r['name'], 'sub' => '/' . $r['slug'] . ' · ' . $r['status'], 'url' => '/admin/products?q=' . rawurlencode($r['name'])];
        }
    }
    if (staffCan('users')) {
        $st = $pdo->prepare("SELECT id, name, email FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY created_at DESC LIMIT 5");
        $st->execute([$like, $like]);
        foreach ($st->fetchAll() as $r) {
            $out['users'][] = ['label' => $r['name'], 'sub' => $r['email'], 'url' => '/admin/users?q=' . rawurlencode($r['email'])];
        }
    }
    if (staffCan('transactions')) {
        $st = $pdo->prepare("SELECT order_number, total, status FROM orders WHERE order_number LIKE ? ORDER BY created_at DESC LIMIT 5");
        $st->execute([$like]);
        foreach ($st->fetchAll() as $r) {
            $out['orders'][] = ['label' => $r['order_number'], 'sub' => formatRupiah((float) $r['total']) . ' · ' . $r['status'], 'url' => '/admin/transactions/' . rawurlencode($r['order_number'])];
        }
    }
} catch (Exception $e) {
    error_log('global-search: ' . $e->getMessage());
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

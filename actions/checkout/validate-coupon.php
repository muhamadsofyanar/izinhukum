<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['valid' => false, 'message' => 'Metode tidak valid.']);
    exit;
}

$code      = strtoupper(preg_replace('/[^A-Z0-9-]/', '', trim($_POST['code'] ?? '')));
$productId = (int) ($_POST['product_id'] ?? 0);
$userId    = (int) $_SESSION['user_id'];

if ($code === '') {
    echo json_encode(['valid' => false, 'message' => 'Masukkan kode kupon.']);
    exit;
}

if ($productId <= 0) {
    echo json_encode(['valid' => false, 'message' => 'Produk tidak valid.']);
    exit;
}

try {
    $pdo = getDB();

    // Load kupon
    $stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$code]);
    $coupon = $stmt->fetch();

    if (!$coupon) {
        echo json_encode(['valid' => false, 'message' => 'Kode kupon tidak ditemukan atau tidak aktif.']);
        exit;
    }

    $now = date('Y-m-d H:i:s');

    // Cek periode
    if ($coupon['starts_at'] && $now < $coupon['starts_at']) {
        echo json_encode(['valid' => false, 'message' => 'Kupon belum berlaku.']);
        exit;
    }
    if ($coupon['expires_at'] && $now > $coupon['expires_at']) {
        echo json_encode(['valid' => false, 'message' => 'Kupon sudah kedaluwarsa.']);
        exit;
    }

    // Cek limit penggunaan global
    if ($coupon['usage_limit'] !== null && $coupon['used_count'] >= $coupon['usage_limit']) {
        echo json_encode(['valid' => false, 'message' => 'Kupon sudah mencapai batas penggunaan.']);
        exit;
    }

    // Cek applicable product
    if ($coupon['applicable_product_id'] !== null && (int)$coupon['applicable_product_id'] !== $productId) {
        echo json_encode(['valid' => false, 'message' => 'Kupon tidak berlaku untuk produk ini.']);
        exit;
    }

    // Load produk untuk harga
    $pStmt = $pdo->prepare("SELECT id, price FROM products WHERE id = ? AND status = 'active' LIMIT 1");
    $pStmt->execute([$productId]);
    $product = $pStmt->fetch();

    if (!$product) {
        echo json_encode(['valid' => false, 'message' => 'Produk tidak ditemukan.']);
        exit;
    }

    $productPrice = (float) $product['price'];

    // Cek min purchase
    if ($productPrice < (float)$coupon['min_purchase']) {
        echo json_encode([
            'valid'   => false,
            'message' => 'Minimal pembelian ' . formatRupiah((float)$coupon['min_purchase']) . ' untuk kupon ini.',
        ]);
        exit;
    }

    // Cek user sudah pernah pakai kupon ini
    $uStmt = $pdo->prepare('SELECT id FROM coupon_usages WHERE coupon_id = ? AND user_id = ? LIMIT 1');
    $uStmt->execute([$coupon['id'], $userId]);
    if ($uStmt->fetch()) {
        echo json_encode(['valid' => false, 'message' => 'Kamu sudah pernah menggunakan kupon ini.']);
        exit;
    }

    // Hitung diskon
    if ($coupon['type'] === 'percentage') {
        $discount = $productPrice * (float)$coupon['value'] / 100;
        if ($coupon['max_discount'] !== null && $discount > (float)$coupon['max_discount']) {
            $discount = (float)$coupon['max_discount'];
        }
    } else {
        $discount = (float)$coupon['value'];
    }
    $discount   = min($discount, $productPrice);
    $finalPrice = max(0, $productPrice - $discount);

    echo json_encode([
        'valid'                 => true,
        'coupon_id'             => (int)$coupon['id'],
        'discount'              => $discount,
        'discount_formatted'    => formatRupiah($discount),
        'final_price'           => $finalPrice,
        'final_price_formatted' => formatRupiah($finalPrice),
        'message'               => 'Kupon berhasil diterapkan!',
    ]);

} catch (Exception $e) {
    error_log('Validate coupon error: ' . $e->getMessage());
    echo json_encode(['valid' => false, 'message' => 'Terjadi kesalahan saat memvalidasi kupon.']);
}

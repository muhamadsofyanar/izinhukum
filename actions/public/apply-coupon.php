<?php
// ─── Public Checkout: apply kupon real-time ───────────────────
// Endpoint AJAX. Tidak perlu auth (buyer belum punya akun).
// Mengikuti logika validasi kupon di actions/checkout/validate-coupon.php,
// tanpa pengecekan "1 user 1x" karena belum ada user yang login.
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metode tidak valid.']);
    exit;
}

$code      = strtoupper(preg_replace('/[^A-Z0-9-]/', '', trim($_POST['coupon_code'] ?? '')));
$productId = (int) ($_POST['product_id'] ?? 0);

if ($code === '') {
    echo json_encode(['success' => false, 'message' => 'Masukkan kode kupon.']);
    exit;
}
if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Produk tidak valid.']);
    exit;
}

try {
    $pdo = getDB();

    // Produk harus aktif. (Dulu juga mewajibkan checkout_mode='public' — dihapus agar
    // IDENTIK dengan jalur login validate-coupon.php: produk yang dijual di form publik
    // lewat jalur non-standar (mis. Dukung/flexible/embed, atau mode diubah) tetap bisa
    // divalidasi kuponnya. Pembuatan order publik punya guard-nya sendiri, jadi aman.)
    $pStmt = $pdo->prepare(
        "SELECT id, price FROM products WHERE id = ? AND status = 'active' LIMIT 1"
    );
    $pStmt->execute([$productId]);
    $product = $pStmt->fetch();
    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Produk tidak ditemukan.']);
        exit;
    }
    $productPrice = (float) $product['price'];

    // Load kupon
    $stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$code]);
    $coupon = $stmt->fetch();
    if (!$coupon) {
        echo json_encode(['success' => false, 'message' => 'Kode kupon tidak ditemukan atau tidak aktif.']);
        exit;
    }

    $now = date('Y-m-d H:i:s');

    if ($coupon['starts_at'] && $now < $coupon['starts_at']) {
        echo json_encode(['success' => false, 'message' => 'Kupon belum berlaku.']);
        exit;
    }
    if ($coupon['expires_at'] && $now > $coupon['expires_at']) {
        echo json_encode(['success' => false, 'message' => 'Kupon sudah kedaluwarsa.']);
        exit;
    }
    if ($coupon['usage_limit'] !== null && $coupon['used_count'] >= $coupon['usage_limit']) {
        echo json_encode(['success' => false, 'message' => 'Kupon sudah mencapai batas penggunaan.']);
        exit;
    }
    if ($coupon['applicable_product_id'] !== null && (int) $coupon['applicable_product_id'] !== $productId) {
        echo json_encode(['success' => false, 'message' => 'Kupon tidak berlaku untuk produk ini.']);
        exit;
    }
    if ($productPrice < (float) $coupon['min_purchase']) {
        echo json_encode([
            'success' => false,
            'message' => 'Minimal pembelian ' . formatRupiah((float) $coupon['min_purchase']) . ' untuk kupon ini.',
        ]);
        exit;
    }

    // Hitung diskon
    if ($coupon['type'] === 'percentage') {
        $discount = $productPrice * (float) $coupon['value'] / 100;
        if ($coupon['max_discount'] !== null && $discount > (float) $coupon['max_discount']) {
            $discount = (float) $coupon['max_discount'];
        }
        $label = 'Diskon ' . rtrim(rtrim(number_format((float) $coupon['value'], 1, ',', '.'), '0'), ',') . '%';
    } else {
        $discount = (float) $coupon['value'];
        $label = 'Diskon ' . formatRupiah($discount);
    }
    $discount   = min($discount, $productPrice);
    $finalPrice = max(0, $productPrice - $discount);

    echo json_encode([
        'success'           => true,
        'discount_amount'   => $discount,
        'discount_formatted'=> formatRupiah($discount),
        'discount_label'    => $label,
        'new_total'         => $finalPrice,
        'new_total_formatted' => formatRupiah($finalPrice),
    ]);

} catch (Exception $e) {
    error_log('apply-coupon error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat memvalidasi kupon.']);
}
exit;

<?php
// ─── Terima penawaran upsell (post-purchase) ───────────────────
// Membuat ORDER BARU pending berisi produk upsell dengan harga spesial.
// Dipakai member login MAUPUN tamu pemegang public_order_token (checkout
// publik dengan email yang SUDAH terdaftar sengaja tidak auto-login, jadi
// requireLogin() di sini dulu membuat mereka tak bisa menerima penawaran).
// Metode bayar mengikuti order sumber: hosted (duitku/xendit → invoice
// dibuat langsung agar tombol "Lanjut Bayar" tersedia juga untuk tamu),
// moota (kode unik nominal), selain itu instruksi manual — jadi penawaran
// TIDAK lagi bergantung pada adanya gateway hosted.
// referral_code diwariskan dari order sumber supaya komisi affiliate atas
// upsell tetap tercatat (onOrderPaid membuat komisinya saat lunas).
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Permintaan tidak valid.']);
    exit;
}

$sourceOrderNo = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($_POST['source_order'] ?? '')));

// Identitas pemohon: member login ATAU tamu pemegang token thank-you order ini.
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$tok           = $_SESSION['public_order_token'] ?? null;
$tokenValid    = is_array($tok)
    && (string) ($tok['order_number'] ?? '') === $sourceOrderNo
    && (int) ($tok['expires_at'] ?? 0) > time();

if ($sessionUserId <= 0 && !$tokenValid) {
    echo json_encode(['success' => false, 'message' => 'Sesi berakhir. Muat ulang halaman ini.']);
    exit;
}

try {
    $pdo = getDB();

    // Order sumber harus lunas; member harus pemiliknya, tamu cukup token yang cocok.
    if ($sessionUserId > 0) {
        $st = $pdo->prepare(
            "SELECT id, user_id, referral_code FROM orders
             WHERE order_number = ? AND user_id = ? AND status = 'paid' LIMIT 1"
        );
        $st->execute([$sourceOrderNo, $sessionUserId]);
    } else {
        $st = $pdo->prepare(
            "SELECT id, user_id, referral_code FROM orders
             WHERE order_number = ? AND status = 'paid' LIMIT 1"
        );
        $st->execute([$sourceOrderNo]);
    }
    $sourceOrder = $st->fetch();
    if (!$sourceOrder) {
        echo json_encode(['success' => false, 'message' => 'Order sumber tidak valid.']);
        exit;
    }
    $userId = (int) $sourceOrder['user_id'];

    // Validasi ulang penawaran server-side (jangan percaya id dari client).
    $offer = getUpsellOffer((int) $sourceOrder['id'], $userId);
    if (!$offer) {
        echo json_encode(['success' => false, 'message' => 'Penawaran sudah tidak tersedia.']);
        exit;
    }

    $redirBase = $sessionUserId > 0 ? '/transactions/' : '/public-thank-you/';

    // Anti-dobel: sudah ada order pending berisi produk ini milik user →
    // arahkan ke order itu, jangan buat baru.
    $st = $pdo->prepare(
        "SELECT o.id, o.order_number FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         WHERE o.user_id = ? AND o.status = 'pending' AND oi.product_id = ?
         ORDER BY o.id DESC LIMIT 1"
    );
    $st->execute([$userId, $offer['upsell_product_id']]);
    $existing = $st->fetch();
    if ($existing) {
        if ($sessionUserId <= 0) {
            $_SESSION['public_order_token'] = [
                'order_id'     => (int) $existing['id'],
                'order_number' => (string) $existing['order_number'],
                'buyer_email'  => (string) ($tok['buyer_email'] ?? ''),
                'expires_at'   => time() + 3600,
            ];
        }
        echo json_encode([
            'success'      => true,
            'order_number' => $existing['order_number'],
            'redirect'     => $redirBase . $existing['order_number'],
        ]);
        exit;
    }

    // Data pembeli (untuk invoice gateway).
    $bu = $pdo->prepare('SELECT name, email FROM users WHERE id = ? LIMIT 1');
    $bu->execute([$userId]);
    $buyer = $bu->fetch() ?: ['name' => '', 'email' => ''];

    // ─── Metode bayar: ikuti order sumber, mundur ke manual ────
    $pm = $pdo->prepare('SELECT method FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1');
    $pm->execute([(int) $sourceOrder['id']]);
    $parentMethod = (string) ($pm->fetchColumn() ?: '');
    $activeGw = $pdo->query('SELECT provider FROM payment_gateways WHERE is_active = 1')
        ->fetchAll(PDO::FETCH_COLUMN);

    $method = 'manual';
    if (in_array($parentMethod, ['duitku', 'xendit'], true) && in_array($parentMethod, $activeGw, true)) {
        $method = $parentMethod;
    } elseif ($parentMethod === 'moota' && in_array('moota', $activeGw, true)) {
        $method = 'moota';
    }

    $orderNumber = generateOrderNumber();
    $price       = $offer['upsell_price'];
    $finalTotal  = $price;
    $orderGw     = null;

    // Moota: kode unik nominal agar webhook auto-match (pola process.php).
    if ($method === 'moota') {
        require_once __DIR__ . '/../../payment_gateways/MootaGateway.php';
        $u          = (new MootaGateway())->generateUniqueCode($finalTotal);
        $finalTotal = $u['total_amount'];
        $orderGw    = 'moota';
    }
    // Pembayaran Manual (upsell non-gateway = 'manual'): kode unik nominal.
    elseif ($method === 'manual' && $finalTotal > 0) {
        $finalTotal = applyManualUniqueCode($pdo, (float) $finalTotal);
    }

    $pdo->beginTransaction();
    $pdo->prepare(
        "INSERT INTO orders (order_number, user_id, subtotal, total, status, payment_gateway, referral_code, notes)
         VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)"
    )->execute([
        $orderNumber, $userId, $price, $finalTotal, $orderGw,
        $sourceOrder['referral_code'] ?: null,
        'Upsell dari order ' . $sourceOrderNo,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO order_items (order_id, product_id, product_name, price, quantity, is_bump)
         VALUES (?, ?, ?, ?, 1, 0)"
    )->execute([$orderId, $offer['upsell_product_id'], $offer['product_name'], $price]);

    $pdo->prepare(
        "INSERT INTO payments (order_id, method, amount, status) VALUES (?, ?, ?, 'pending')"
    )->execute([$orderId, $method, $finalTotal]);
    $pdo->commit();

    // Hosted gateway: buat invoice SEKARANG (pola submit-public-checkout) supaya
    // tombol "Lanjut Bayar" tersedia — termasuk untuk tamu yang tak bisa memakai
    // create-payment on-demand (butuh login). Gagal buat invoice ≠ gagal order:
    // order tetap pending, member masih punya tombol bayar on-demand.
    if (in_array($method, ['duitku', 'xendit'], true)) {
        try {
            $gwClass = $method === 'duitku' ? 'DuitkuGateway' : 'XenditGateway';
            require_once __DIR__ . '/../../payment_gateways/' . $gwClass . '.php';
            $gw = new $gwClass();
            if ($gw->isConfigured()) {
                $result = $gw->createInvoice([
                    'order_number' => $orderNumber,
                    'total'        => $finalTotal,
                    'name'         => (string) $buyer['name'],
                    'email'        => (string) $buyer['email'],
                    'product_name' => $offer['product_name'],
                ]);
                if (!empty($result['success']) && !empty($result['payment_url'])) {
                    $ref = $result['reference'] ?? null;
                    $pdo->prepare('UPDATE orders SET payment_gateway = ?, payment_reference = ? WHERE id = ?')
                        ->execute([$method, $ref, $orderId]);
                    $pdo->prepare('UPDATE payments SET gateway_ref = ?, gateway_response = ? WHERE order_id = ?')
                        ->execute([
                            $ref,
                            json_encode(['provider' => $method, 'payment_url' => $result['payment_url']]),
                            $orderId,
                        ]);
                }
            }
        } catch (Throwable $gwErr) {
            error_log('accept-upsell gateway invoice: ' . $gwErr->getMessage());
        }
    }

    // Tamu: alihkan token thank-you ke order upsell agar halaman pembayarannya
    // bisa dibuka tanpa login.
    if ($sessionUserId <= 0) {
        $_SESSION['public_order_token'] = [
            'order_id'     => $orderId,
            'order_number' => $orderNumber,
            'buyer_email'  => (string) ($tok['buyer_email'] ?? $buyer['email']),
            'expires_at'   => time() + 3600,
        ];
    }

    logActivity('user', 'order.create', 'order', $orderId, 'Order upsell dibuat: ' . $orderNumber . ' (dari ' . $sourceOrderNo . ')');

    echo json_encode([
        'success'      => true,
        'order_number' => $orderNumber,
        'redirect'     => $redirBase . $orderNumber,
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('accept-upsell error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan. Coba lagi.']);
}
exit;

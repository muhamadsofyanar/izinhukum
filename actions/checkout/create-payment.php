<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $gateway = trim($_POST['gateway'] ?? '');

    if ($orderId <= 0 || empty($gateway)) {
        echo json_encode(['success' => false, 'message' => 'Parameter tidak valid']);
        exit;
    }

    $pdo = getDB();

    // Ambil order — harus milik user yg login dan masih pending
    $stmt = $pdo->prepare(
        "SELECT o.*, u.name, u.email
         FROM orders o
         JOIN users u ON o.user_id = u.id
         WHERE o.id = ? AND o.user_id = ? AND o.status = 'pending'
         LIMIT 1"
    );
    $stmt->execute([$orderId, (int) $_SESSION['user_id']]);
    $order = $stmt->fetch();

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order tidak ditemukan atau sudah diproses']);
        exit;
    }

    // Ambil nama produk utama dari order_items (snapshot saat transaksi)
    $itemStmt = $pdo->prepare(
        "SELECT product_name FROM order_items WHERE order_id = ? AND is_bump = 0 LIMIT 1"
    );
    $itemStmt->execute([$orderId]);
    $productName = $itemStmt->fetchColumn() ?: 'Produk Averion';

    /**
     * Simpan payment_url ke payments.gateway_response (pola sama dengan
     * checkout publik) — halaman transaksi memakainya untuk tombol
     * "Bayar Sekarang" yang persisten (tahan refresh / buka ulang).
     */
    $storePaymentUrl = function (string $provider, ?string $ref, string $url) use ($pdo, $orderId): void {
        $json = json_encode(['provider' => $provider, 'payment_url' => $url]);
        $upd  = $pdo->prepare(
            "UPDATE payments SET method = ?, gateway_ref = ?, gateway_response = ? WHERE order_id = ?"
        );
        $upd->execute([$provider, $ref, $json, $orderId]);
        if ($upd->rowCount() === 0) {
            // Order tanpa baris payments (mis. order pelunasan lama) → buat.
            $chk = $pdo->prepare("SELECT id FROM payments WHERE order_id = ? LIMIT 1");
            $chk->execute([$orderId]);
            if (!$chk->fetchColumn()) {
                $pdo->prepare(
                    "INSERT INTO payments (order_id, method, amount, status, gateway_ref, gateway_response)
                     VALUES (?, ?, ?, 'pending', ?, ?)"
                )->execute([$orderId, $provider, 0, $ref, $json]);
            }
        }
    };

    if ($gateway === 'duitku') {
        require_once __DIR__ . '/../../payment_gateways/DuitkuGateway.php';

        $duitku = new DuitkuGateway();

        if (!$duitku->isConfigured()) {
            echo json_encode(['success' => false, 'message' => 'Duitku belum dikonfigurasi oleh admin']);
            exit;
        }

        $result = $duitku->createInvoice([
            'order_number' => $order['order_number'],
            'total'        => $order['total'],
            'name'         => $order['name'],
            'email'        => $order['email'],
            'product_name' => $productName,
        ]);

        if ($result['success']) {
            // Simpan referensi gateway ke order
            $pdo->prepare(
                "UPDATE orders SET payment_gateway = 'duitku', payment_reference = ? WHERE id = ?"
            )->execute([$result['reference'], $orderId]);
            $storePaymentUrl('duitku', $result['reference'] ?? null, $result['payment_url']);

            echo json_encode([
                'success'      => true,
                'payment_url'  => $result['payment_url'],
                'order_number' => $order['order_number'],
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Gagal membuat pembayaran Duitku',
            ]);
        }

    } elseif ($gateway === 'xendit') {
        require_once __DIR__ . '/../../payment_gateways/XenditGateway.php';

        $xendit = new XenditGateway();

        if (!$xendit->isConfigured()) {
            echo json_encode(['success' => false, 'message' => 'Xendit belum dikonfigurasi oleh admin']);
            exit;
        }

        $result = $xendit->createInvoice([
            'order_number' => $order['order_number'],
            'total'        => $order['total'],
            'name'         => $order['name'],
            'email'        => $order['email'],
            'product_name' => $productName,
        ]);

        if ($result['success']) {
            $pdo->prepare(
                "UPDATE orders SET payment_gateway = 'xendit', payment_reference = ? WHERE id = ?"
            )->execute([$result['reference'], $orderId]);
            $storePaymentUrl('xendit', $result['reference'] ?? null, $result['payment_url']);

            echo json_encode([
                'success'      => true,
                'payment_url'  => $result['payment_url'],
                'order_number' => $order['order_number'],
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Gagal membuat pembayaran Xendit',
            ]);
        }

    } elseif ($gateway === 'paypal') {
        require_once __DIR__ . '/../../payment_gateways/PayPalGateway.php';

        $paypal = new PayPalGateway();

        if (!$paypal->isConfigured()) {
            echo json_encode(['success' => false, 'message' => 'PayPal belum dikonfigurasi oleh admin']);
            exit;
        }

        $result = $paypal->createInvoice([
            'order_number' => $order['order_number'],
            'total'        => $order['total'],
            'name'         => $order['name'],
            'email'        => $order['email'],
            'product_name' => $productName,
        ]);

        if ($result['success']) {
            // Simpan referensi order PayPal + detail USD (dipakai return handler utk verifikasi).
            $pdo->prepare(
                "UPDATE orders SET payment_gateway = 'paypal', payment_reference = ? WHERE id = ?"
            )->execute([$result['reference'], $orderId]);

            $ppJson = json_encode([
                'provider'        => 'paypal',
                'payment_url'     => $result['payment_url'],
                'paypal_order_id' => $result['reference'],
                'currency'        => 'USD',
                'usd_amount'      => $result['usd_amount'],
                'rate'            => $result['rate'],
                'expected_idr'    => (float) $order['total'],
            ]);
            $upd = $pdo->prepare("UPDATE payments SET method = 'paypal', gateway_ref = ?, gateway_response = ? WHERE order_id = ?");
            $upd->execute([$result['reference'], $ppJson, $orderId]);
            if ($upd->rowCount() === 0) {
                $chk = $pdo->prepare("SELECT id FROM payments WHERE order_id = ? LIMIT 1");
                $chk->execute([$orderId]);
                if (!$chk->fetchColumn()) {
                    $pdo->prepare(
                        "INSERT INTO payments (order_id, method, amount, status, gateway_ref, gateway_response)
                         VALUES (?, 'paypal', ?, 'pending', ?, ?)"
                    )->execute([$orderId, (float) $order['total'], $result['reference'], $ppJson]);
                }
            }

            echo json_encode([
                'success'      => true,
                'payment_url'  => $result['payment_url'],
                'order_number' => $order['order_number'],
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Gagal membuat pembayaran PayPal',
            ]);
        }

    } elseif ($gateway === 'midtrans') {
        require_once __DIR__ . '/../../payment_gateways/MidtransGateway.php';

        $midtrans = new MidtransGateway();

        if (!$midtrans->isConfigured()) {
            echo json_encode(['success' => false, 'message' => 'Midtrans belum dikonfigurasi oleh admin']);
            exit;
        }

        $result = $midtrans->createInvoice([
            'order_number' => $order['order_number'],
            'total'        => $order['total'],
            'name'         => $order['name'],
            'email'        => $order['email'],
            'product_name' => $productName,
        ]);

        if ($result['success']) {
            $pdo->prepare(
                "UPDATE orders SET payment_gateway = 'midtrans', payment_reference = ? WHERE id = ?"
            )->execute([$result['reference'], $orderId]);
            $storePaymentUrl('midtrans', $result['reference'] ?? null, $result['payment_url']);

            echo json_encode([
                'success'      => true,
                'payment_url'  => $result['payment_url'],
                'order_number' => $order['order_number'],
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Gagal membuat pembayaran Midtrans',
            ]);
        }

    } elseif ($gateway === 'tripay') {
        require_once __DIR__ . '/../../payment_gateways/TripayGateway.php';

        $tripay  = new TripayGateway();
        $channel = trim($_POST['channel'] ?? '');

        if (!$tripay->isConfigured()) {
            echo json_encode(['success' => false, 'message' => 'Tripay belum dikonfigurasi oleh admin']);
            exit;
        }
        if ($channel === '' || !$tripay->isValidChannel($channel)) {
            echo json_encode(['success' => false, 'message' => 'Pilih metode pembayaran Tripay yang tersedia.']);
            exit;
        }

        $result = $tripay->createInvoice([
            'order_number' => $order['order_number'],
            'total'        => $order['total'],
            'name'         => $order['name'],
            'email'        => $order['email'],
            'phone'        => $order['phone'] ?? '',
            'product_name' => $productName,
        ], $channel);

        if ($result['success']) {
            $pdo->prepare(
                "UPDATE orders SET payment_gateway = 'tripay', payment_reference = ? WHERE id = ?"
            )->execute([$result['reference'], $orderId]);
            $storePaymentUrl('tripay', $result['reference'] ?? null, $result['payment_url']);

            echo json_encode([
                'success'      => true,
                'payment_url'  => $result['payment_url'],
                'order_number' => $order['order_number'],
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Gagal membuat pembayaran Tripay',
            ]);
        }

    } else {
        echo json_encode(['success' => false, 'message' => 'Gateway tidak didukung']);
    }

} catch (Exception $e) {
    error_log('create-payment.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem']);
}

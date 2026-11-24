<?php
// Handler checkout bundle. Di-require dari process.php (bootstrap + CSRF + requireLogin sudah dijalankan).
// Catatan: bundle dibayar via transfer manual / gratis. Konfirmasi paid (admin/manual) mengaktifkan
// SEMUA item bundle karena update-transaction.php meng-activateAccess setiap order_item.

$bundleId = (int) ($_POST['bundle_id'] ?? 0);
$userId   = (int) $_SESSION['user_id'];
$refCode  = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($_POST['referral_code'] ?? '')));

$gatewayMethod = trim($_POST['payment_gateway_method'] ?? '');
$isGatewayFlow = in_array($gatewayMethod, ['duitku', 'xendit', 'midtrans', 'tripay'], true); // alur AJAX/JSON
$isMoota       = ($gatewayMethod === 'moota');
if ($isGatewayFlow) header('Content-Type: application/json');

try {
    $pdo = getDB();

    $bStmt = $pdo->prepare("SELECT * FROM product_bundles WHERE id = ? AND status = 'active' LIMIT 1");
    $bStmt->execute([$bundleId]);
    $bundle = $bStmt->fetch();
    if (!$bundle) {
        if ($isGatewayFlow) {
            echo json_encode(['error' => 'Bundle tidak ditemukan.']);
            exit;
        }
        flash('error', 'Bundle tidak ditemukan.', 'error');
        redirect('/products');
    }

    // ─── GUARD KEAMANAN: bundle tanpa harga tak boleh di-checkout gratis ──────
    // bundle_price<=0 → $isFree auto-'paid' + aktifkan SEMUA item bundle (akses gratis).
    // Cegah akibat salah konfig harga. Selaras guard produk tunggal (orderProductNotSellableFree).
    if ((float) ($bundle['bundle_price'] ?? 0) <= 0) {
        error_log('[checkout-guard] tolak order gratis bundle price<=0: bundle_id=' . $bundleId . ' name=' . ($bundle['name'] ?? ''));
        if (function_exists('logActivity')) logActivity('system', 'checkout.blocked_zero_price', 'bundle', (int) $bundleId, 'Checkout bundle Rp0 ditolak — bundle tanpa harga (salah konfig)');
        if ($isGatewayFlow) { echo json_encode(['error' => 'Bundle ini belum tersedia untuk dibeli.']); exit; }
        flash('error', 'Bundle ini belum tersedia untuk dibeli. Silakan hubungi admin.', 'error');
        redirect('/products');
    }

    $buyerPhone = preg_replace('/[^0-9]/', '', (string) ($_POST['phone'] ?? ''));
    if (!preg_match('/^[0-9]{10,15}$/', $buyerPhone)) {
        if ($isGatewayFlow) {
            echo json_encode(['error' => 'Nomor WhatsApp wajib diisi (10–15 digit).']);
            exit;
        }
        flash('error', 'Nomor WhatsApp wajib diisi (10–15 digit).', 'error');
        redirect('/bundles/' . $bundle['slug']);
    }
    $buyerPhone = normalizeWaNumber($buyerPhone);

    // Simpan/update nomor WA pembeli ke profil (dipakai lintas order & login OTP).
    try {
        $pdo->prepare("UPDATE users SET phone = ? WHERE id = ?")->execute([$buyerPhone, $userId]);
    } catch (Exception $e) {
        error_log('Checkout bundle save buyer phone error: ' . $e->getMessage());
    }

    $iStmt = $pdo->prepare(
        "SELECT p.id AS product_id, p.name, p.price FROM product_bundle_items pbi
         JOIN products p ON p.id = pbi.product_id WHERE pbi.bundle_id = ? AND p.status = 'active' ORDER BY pbi.sort_order ASC"
    );
    $iStmt->execute([$bundleId]);
    $items = $iStmt->fetchAll();
    if (!$items) {
        if ($isGatewayFlow) {
            echo json_encode(['error' => 'Bundle tidak memiliki produk aktif.']);
            exit;
        }
        flash('error', 'Bundle tidak memiliki produk aktif.', 'error');
        redirect('/bundles/' . $bundle['slug']);
    }

    // Funnel tracking: Klik Checkout untuk bundle. Semua jalur bayar (manual/moota/
    // duitku/xendit) lewat sini → satu titik catat. Dedup per sesi/hari, skip admin/staff.
    trackBundleFunnelEvent($bundleId, 'checkout_click');

    // Item yang belum dimiliki user
    $toBuy = array_values(array_filter($items, fn($it) => !hasAccess($userId, (int) $it['product_id'])));
    if (!$toBuy) {
        if ($isGatewayFlow) {
            echo json_encode(['error' => 'Kamu sudah memiliki semua produk dalam bundle ini.']);
            exit;
        }
        flash('info', 'Kamu sudah memiliki semua produk dalam bundle ini.', 'info');
        redirect('/member');
    }

    // Harga dari DB (re-validasi server-side)
    $total  = (float) $bundle['bundle_price'];
    $isFree = $total <= 0;

    // Affiliate — resolve 3-tier customer binding & cookie rules
    $affRes      = resolveAffiliateForCheckout($pdo, $userId, $refCode, null, $bundleId);
    $affiliateId = $affRes['affiliate_id'];
    if ($affRes['affiliate_code'] !== null) {
        $refCode = $affRes['affiliate_code'];
    }
    if ($affiliateId !== null && $userId > 0) {
        bindCustomerToAffiliate($pdo, $userId, $affiliateId);
    }

    $pdo->beginTransaction();

    do {
        $orderNumber = generateOrderNumber();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE order_number = ?');
        $dup->execute([$orderNumber]);
    } while ((int) $dup->fetchColumn() > 0);

    $status = $isFree ? 'paid' : 'pending';
    $paidAt = $isFree ? date('Y-m-d H:i:s') : null;

    $pdo->prepare(
        'INSERT INTO orders (order_number, user_id, subtotal, discount, total, status, paid_at, referral_code)
         VALUES (?, ?, ?, 0, ?, ?, ?, ?)'
    )->execute([$orderNumber, $userId, $total, $total, $status, $paidAt, $refCode ?: null]);
    $orderId = (int) $pdo->lastInsertId();

    try {
        $pdo->prepare("UPDATE orders SET tracking_meta = ? WHERE id = ?")->execute([
            json_encode(['fbp' => substr((string)($_COOKIE['_fbp']??''),0,255),'fbc' => meta_resolve_fbc(),'ip' => substr(client_real_ip(),0,45),'ua' => substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)], JSON_UNESCAPED_SLASHES),
            $orderId,
        ]);
    } catch (Throwable $e) {}

    // Mailketing: antre pembeli ke list "Order" (order dibuat). Fail-soft.
    mailketing_enqueue_order_created($userId);

    $itemIns = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, price, quantity, is_bump, is_bundle, bundle_id)
         VALUES (?, ?, ?, ?, 1, 0, 1, ?)'
    );
    foreach ($toBuy as $it) {
        $itemIns->execute([$orderId, (int) $it['product_id'], $it['name'], (float) $it['price'], $bundleId]);
    }

    // Moota: tambah kode unik nominal + tandai gateway agar webhook auto-match
    $finalTotal = $total;
    if ($isMoota && !$isFree && $finalTotal > 0) {
        require_once __DIR__ . '/../../payment_gateways/MootaGateway.php';
        $u = (new MootaGateway())->generateUniqueCode($finalTotal);
        $finalTotal = $u['total_amount'];
        $pdo->prepare("UPDATE orders SET total = ?, payment_gateway = 'moota' WHERE id = ?")->execute([$finalTotal, $orderId]);
    }
    // Pembayaran Manual (bundle non-gateway = 'manual'): kode unik nominal.
    elseif (!$isGatewayFlow && !$isFree && $finalTotal > 0) {
        $withCode = applyManualUniqueCode($pdo, (float) $finalTotal);
        if ($withCode != $finalTotal) {
            $finalTotal = $withCode;
            $pdo->prepare("UPDATE orders SET total = ? WHERE id = ?")->execute([$finalTotal, $orderId]);
        }
    }
    $payMethod = $isMoota ? 'moota' : ($isGatewayFlow ? $gatewayMethod : 'manual');
    $pdo->prepare('INSERT INTO payments (order_id, method, amount, status, paid_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$orderId, $payMethod, $finalTotal, $isFree ? 'success' : 'pending', $paidAt]);

    if ($isFree) {
        foreach ($toBuy as $it) {
            activateAccess($userId, (int) $it['product_id'], $orderId);
            issueLicense($orderId, (int) $it['product_id'], $userId);
        }
        enrollUserToSequences('order_paid', $userId, 0, $orderNumber);
    }

    // Komisi affiliate (dari total bundle; product_id=0 → rate default)
    if ($affiliateId !== null && $total > 0) {
        $au = $pdo->prepare("SELECT user_id FROM affiliates WHERE id = ? LIMIT 1");
        $au->execute([$affiliateId]);
        if (($auRow = $au->fetch()) && affiliateEligibleUser((int) $auRow['user_id'])) {
            $rate = resolveCommissionRate((int) $auRow['user_id'], 0);
            $comm = round($total * $rate / 100, 2);
            if ($comm > 0) {
                $pdo->prepare('INSERT INTO commissions (affiliate_id, order_id, amount, rate, status) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$affiliateId, $orderId, $comm, $rate, $isFree ? 'approved' : 'pending']);
                if ($isFree) $pdo->prepare('UPDATE affiliates SET total_earned = total_earned + ? WHERE id = ?')->execute([$comm, $affiliateId]);
            }
        }
    }

    $pdo->commit();

    if ($isFree) updateUserMembershipLevel($userId);

    try {
        require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
        $buyerPhone = (string) ($pdo->query("SELECT phone FROM users WHERE id = " . $userId)->fetchColumn() ?: '');
        $invoiceUrl = baseUrl('transactions/' . $orderNumber);
        $memberUrl  = baseUrl('member');
        $payLabel   = match ($payMethod) {
            'moota'            => 'Transfer Bank (Otomatis)',
            'xendit', 'duitku' => 'Pembayaran Online',
            default            => 'Transfer Manual',
        };
        NotificationDispatcher::trigger('order_created', [
            'name'           => $_SESSION['name'] ?? '',
            'email'          => $_SESSION['email'] ?? '',
            'order_number'   => $orderNumber,
            'product_name'   => 'Bundle ' . (string) ($bundle['name'] ?? ''),
            'total'          => formatRupiah($total),
            'payment_method' => $payLabel,
            'invoice_url'    => $invoiceUrl,
        ], $_SESSION['email'] ?? '', $buyerPhone);
        if ($isFree) {
            NotificationDispatcher::trigger('payment_success', [
                'name'         => $_SESSION['name'] ?? '',
                'email'        => $_SESSION['email'] ?? '',
                'order_number' => $orderNumber,
                'total'        => formatRupiah($total),
                'paid_at'      => date('d M Y, H:i', strtotime($paidAt ?? date('Y-m-d H:i:s'))),
                'invoice_url'  => $invoiceUrl,
                'member_url'   => $memberUrl,
            ], $_SESSION['email'] ?? '', $buyerPhone);
        }
    } catch (Exception $e) { error_log('bundle notif error: ' . $e->getMessage()); }

    logActivity('user', 'order.create', 'order', $orderId, 'Order bundle dibuat: ' . $orderNumber);
    unset($_SESSION['ref_code']);

    // Gateway (Duitku/Xendit): balikan JSON agar JS lanjut ke create-payment
    if ($isGatewayFlow) {
        echo json_encode(['order_id' => $orderId, 'order_number' => $orderNumber]);
        if (defined('BYPASS_LOGIN_REQUIRED') || defined('TESTING')) {
            // Jangan exit di testing
        } else {
            exit;
        }
    }

    $isFree
        ? flash('success', 'Selamat! Semua produk dalam bundle "' . $bundle['name'] . '" sudah aktif.')
        : flash('success', 'Order bundle dibuat. Selesaikan pembayaran untuk mengaktifkan akses.');
    redirect('/transactions/' . $orderNumber);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('process-bundle error: ' . $e->getMessage());
    if ($isGatewayFlow) { echo json_encode(['error' => 'Terjadi kesalahan saat memproses bundle.']); exit; }
    flash('error', 'Terjadi kesalahan saat memproses bundle.', 'error');
    redirect('/products');
}

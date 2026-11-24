<?php
// ════════════════════════════════════════════════════════════════
// Input Transaksi Manual (admin). Buat order + item + payment.
// Bila status 'paid' → aktivasi akses + update membership + notifikasi,
// konsisten dengan actions/admin/update-transaction.php.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('transactions')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/transactions');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/transactions');
}

$buyerMode = (($_POST['buyer_mode'] ?? 'existing') === 'new') ? 'new' : 'existing';
$userId    = (int) ($_POST['user_id'] ?? 0);
$productId = (int) ($_POST['product_id'] ?? 0);
$price     = round((float) ($_POST['price'] ?? 0), 2);
$qty       = max(1, (int) ($_POST['quantity'] ?? 1));
$status    = in_array($_POST['status'] ?? '', ['pending', 'paid'], true) ? $_POST['status'] : 'pending';
$notes     = trim($_POST['notes'] ?? '');
$includeSetup = isset($_POST['include_setup_fee']) && $_POST['include_setup_fee'] === '1';
$muteNotif    = isset($_POST['mute_notifications']) && $_POST['mute_notifications'] === '1';
// Terbitkan lisensi vendor ke server lisensi (produk Averion berlisensi). Opt-in: jalur
// webhook selalu enqueue, jalur manual hanya bila admin mencentang — supaya migrasi data
// lama (pembeli sudah punya lisensi) tak menerbitkan lisensi baru yang boros slot.
$issueVendorLicense = isset($_POST['issue_vendor_license']) && $_POST['issue_vendor_license'] === '1';

// Assign komisi ke affiliate tertentu (opsional). Menutup celah atribusi saat cookie
// referral tak ter-set (mis. pembeli order dari perangkat lain). 0 = tanpa assign.
$affiliateId = (int) ($_POST['affiliate_id'] ?? 0);

// Tanggal mulai langganan (opsional) — utk migrasi langganan yg sudah berjalan
// sebelumnya (mis. dari Sejoli). Kosong = perilaku normal (mulai hari ini).
// Validasi longgar: harus tanggal valid & tak lebih dari hari ini (bukan masa depan).
$accessStartDateRaw = trim($_POST['access_start_date'] ?? '');
$accessStartDate = null;
if ($accessStartDateRaw !== '') {
    $ts = strtotime($accessStartDateRaw);
    if ($ts !== false && $ts > 0 && $ts <= time()) {
        $accessStartDate = date('Y-m-d 00:00:00', $ts);
    }
}

// Data member baru (buyer_mode=new)
$newName  = trim(strip_tags($_POST['buyer_name'] ?? ''));
$newEmail = strtolower(trim($_POST['buyer_email'] ?? ''));
$newWa    = preg_replace('/[^0-9]/', '', $_POST['buyer_wa'] ?? '');

// ─── Validasi dasar ───────────────────────────────────────────
if ($productId <= 0) { flash('error', 'Pilih produk.', 'error'); redirect('/admin/transactions'); }
if ($price < 0)      { flash('error', 'Harga tidak valid.', 'error'); redirect('/admin/transactions'); }
if ($buyerMode === 'existing' && $userId <= 0) {
    flash('error', 'Pilih user pembeli (Member Lama) atau isi data Member Baru.', 'error'); redirect('/admin/transactions');
}
if ($buyerMode === 'new' && ($newName === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL))) {
    flash('error', 'Nama & email member baru wajib diisi dengan benar.', 'error'); redirect('/admin/transactions');
}

try {
    $pdo = getDB();

    $newUserCreated  = false;
    $newTempPassword = '';

    // ─── Resolusi pembeli ─────────────────────────────────────
    if ($buyerMode === 'new') {
        // Cari akun existing by email; bila belum ada → buat baru (pola public checkout).
        $ex = $pdo->prepare('SELECT id, name, email, phone FROM users WHERE email = ? LIMIT 1');
        $ex->execute([$newEmail]);
        $user = $ex->fetch();
        if ($user) {
            $userId = (int) $user['id'];
            if (($user['phone'] ?? '') === '' && $newWa !== '') {
                $pdo->prepare("UPDATE users SET phone = ? WHERE id = ?")->execute([$newWa, $userId]);
            }
        } else {
            $newTempPassword = substr(str_shuffle('abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 10);
            $affCode = generateAffiliateCode();
            $pdo->prepare(
                "INSERT INTO users (name, email, phone, password, affiliate_code, role, status)
                 VALUES (?, ?, ?, ?, ?, 'user', 'active')"
            )->execute([$newName, $newEmail, $newWa, password_hash($newTempPassword, PASSWORD_BCRYPT), $affCode]);
            $userId = (int) $pdo->lastInsertId();
            $defaultRate = (float) (getSetting('affiliate_commission_rate', '10') ?: 10);
            $pdo->prepare("INSERT INTO affiliates (user_id, code, commission_rate, status) VALUES (?, ?, ?, 'active')")
                ->execute([$userId, $affCode, $defaultRate]);
            $user = ['id' => $userId, 'name' => $newName, 'email' => $newEmail, 'phone' => $newWa];
            $newUserCreated = true;
        }
    } else {
        $u = $pdo->prepare('SELECT id, name, email, phone FROM users WHERE id = ? LIMIT 1');
        $u->execute([$userId]);
        $user = $u->fetch();
        if (!$user) { flash('error', 'User tidak ditemukan.', 'error'); redirect('/admin/transactions'); }
    }

    $p = $pdo->prepare("SELECT id, name, setup_fee, access_duration_days FROM products WHERE id = ? AND status = 'active' LIMIT 1");
    $p->execute([$productId]);
    $product = $p->fetch();
    if (!$product) { flash('error', 'Produk tidak valid / tidak aktif.', 'error'); redirect('/admin/transactions'); }

    // Biaya awal (opsional, dicentang admin) + total.
    $setupFee = ($includeSetup && (float) $product['setup_fee'] > 0) ? round((float) $product['setup_fee'], 2) : 0.0;
    $subtotal = round($price * $qty, 2);
    $total    = round($subtotal + $setupFee, 2);

    // order_number unik
    do {
        $orderNumber = generateOrderNumber();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE order_number = ?');
        $dup->execute([$orderNumber]);
    } while ((int) $dup->fetchColumn() > 0);

    $paidAt = $status === 'paid' ? date('Y-m-d H:i:s') : null;

    $pdo->beginTransaction();

    $pdo->prepare(
        "INSERT INTO orders (order_number, user_id, subtotal, discount, setup_fee, total, status, notes, paid_at, payment_gateway, payment_channel)
         VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, 'manual', 'Manual (Admin)')"
    )->execute([
        $orderNumber, $userId, $subtotal, $setupFee, $total, $status,
        ($notes !== '' ? $notes : 'Transaksi manual oleh admin'), $paidAt,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, price, quantity, is_bump)
         VALUES (?, ?, ?, ?, ?, 0)'
    )->execute([$orderId, $productId, $product['name'], $price, $qty]);

    // ─── Assign komisi ke affiliate tertentu (dipilih admin) ──────────────
    // Simpan referral_code di order (kolom Affiliate di /admin/transactions ikut
    // terisi) + catat komisi via helper BERSAMA orderRecordCommission (rate &
    // eligibility SAMA dgn checkout → status 'pending' utk order berbayar, masuk
    // approval biasa; saat order pending ini dilunasi nanti, onOrderPaid meng-
    // approve otomatis via referral_code). Guard: affiliate aktif & BUKAN pembeli
    // sendiri (cegah komisi beli-sendiri).
    if ($affiliateId > 0) {
        $affRowStmt = $pdo->prepare("SELECT id, code, user_id FROM affiliates WHERE id = ? AND status = 'active' LIMIT 1");
        $affRowStmt->execute([$affiliateId]);
        $aff = $affRowStmt->fetch();
        if ($aff && (int) $aff['user_id'] !== $userId) {
            $pdo->prepare("UPDATE orders SET referral_code = ? WHERE id = ?")->execute([$aff['code'], $orderId]);
            if (function_exists('orderRecordCommission')) {
                orderRecordCommission($pdo, (int) $aff['id'], $orderId, $productId, $total, $total <= 0);
            }
        }
    }

    $pdo->prepare(
        'INSERT INTO payments (order_id, method, amount, status, paid_at)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $orderId, 'manual', $total,
        ($status === 'paid' ? 'success' : 'pending'),
        ($status === 'paid' ? $paidAt : null),
    ]);

    // Bila langsung lunas → aktivasi akses produk
    $accessActivated = true; // relevan hanya saat 'paid'
    if ($status === 'paid') {
        bumpDimeCounter($pdo, $userId, $productId); // dime: hitung sale sebelum aktivasi
        jvRecordEarning($pdo, ['id' => $orderId, 'user_id' => $userId, 'total' => $total], $productId);
        // activateAccess menangkap error-nya sendiri & return false (tak melempar) — tanpa
        // memeriksa nilai balik ini, order bisa jadi "Lunas" DIAM-DIAM tanpa akses member
        // (pernah menggigit: produk embed/agency, member tak muncul di Area Member). Nilai
        // balik dicek setelah commit → admin diberi peringatan, bukan dibiarkan senyap.
        $accessActivated = activateAccess($userId, $productId, $orderId, $accessStartDate);
        issueLicense($orderId, $productId, $userId); // license key jika produk license_enabled
        // Lisensi VENDOR (produk Averion berlisensi) — hanya bila admin mencentang. Beda dari
        // jalur webhook (onOrderPaid) yang selalu vfEnqueue. Diantre DI DALAM transaksi ini,
        // sama seperti checkout. vfEnqueue self-guard: no-op bila produk tak dipetakan / vendor
        // mati, jadi aman walau tercentang untuk produk non-vendor.
        if ($issueVendorLicense && function_exists('vfEnqueue')) {
            vfEnqueue($pdo, ['id' => $orderId, 'order_number' => $orderNumber], $productId, $userId);
        }
        // Paket Agency SEO (vendor-only): catat kuota 50 slot bila produk paket agency.
        // Self-guard + fail-soft; tak bergantung centang lisensi vendor (jalur berbeda).
        if (function_exists('agencySeoProvisionOnPaid')) {
            agencySeoProvisionOnPaid($pdo, ['id' => $orderId, 'order_number' => $orderNumber], $productId, $userId);
        }
    }

    $pdo->commit();

    // Member baru → kirim info akun (email + WA) berisi password sementara.
    // Disenyapkan juga bila mute_notifications dicentang (mis. migrasi data lama) —
    // admin perlu sampaikan kredensial secara manual bila akun baru dibuat senyap.
    if ($newUserCreated && !$muteNotif) {
        try {
            require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
            NotificationDispatcher::trigger('public_checkout_welcome', [
                'name'          => $user['name'] ?? '',
                'email'         => $user['email'] ?? '',
                'temp_password' => $newTempPassword,
                'login_url'     => baseUrl('login'),
                'member_url'    => baseUrl('member'),
            ], $user['email'] ?? '', $user['phone'] ?? '');
        } catch (Exception $e) {
            error_log('Create-transaction welcome notif error: ' . $e->getMessage());
        }
    }

    // Efek samping pasca-commit (sama dengan update-transaction)
    if ($status === 'paid') {
        updateUserMembershipLevel($userId);
        if (!$muteNotif) {
            try {
                require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
                $invoiceUrl = baseUrl('transactions/' . $orderNumber);
                $memberUrl  = baseUrl('member');
                NotificationDispatcher::trigger('payment_success', [
                    'name'         => $user['name'] ?? '',
                    'email'        => $user['email'] ?? '',
                    'order_number' => $orderNumber,
                    'total'        => formatRupiah($total),
                    'paid_at'      => date('d M Y, H:i'),
                    'invoice_url'  => $invoiceUrl,
                    'member_url'   => $memberUrl,
                ], $user['email'] ?? '', $user['phone'] ?? '');
                NotificationDispatcher::trigger('access_activated', [
                    'name'         => $user['name'] ?? '',
                    'email'        => $user['email'] ?? '',
                    'product_name' => $product['name'],
                    'order_number' => $orderNumber,
                    'member_url'   => $memberUrl,
                    'invoice_url'  => $invoiceUrl,
                ], $user['email'] ?? '', $user['phone'] ?? '');

                // Magic-link akses instan (Segmen B) utk pembeli AKUN LAMA yang diinput
                // manual. Akun BARU tak perlu — sudah dapat welcome berisi password.
                // Fail-soft, hanya role 'user'. Hormati mute (sudah di dalam !$muteNotif).
                if (!$newUserCreated && function_exists('dispatchAccessLink')) {
                    dispatchAccessLink($userId, $orderId, $productId, (string) ($product['name'] ?? ''));
                }
            } catch (Exception $e) {
                error_log('Create-transaction notif error: ' . $e->getMessage());
            }
        }
    }

    if (function_exists('logActivity')) {
        logActivity('admin', 'order.manual_create', 'order', $orderId,
            'Transaksi manual: ' . $orderNumber . ' (' . $status . ') utk user #' . $userId
            . ($muteNotif ? ' [notif disenyapkan]' : '')
            . ($accessStartDate ? ' [mulai langganan: ' . date('d M Y', strtotime($accessStartDate)) . ']' : ''));
    }

    flash('success', 'Transaksi manual ' . $orderNumber . ' berhasil dibuat' . ($status === 'paid' ? ' & ditandai LUNAS.' : ' (pending).'));

    // Order Lunas tapi aktivasi akses GAGAL → jangan senyap: member tak akan bisa
    // membuka produk & tak muncul di Area Member. Beri tahu admin agar bisa segera
    // menindak (cek storage/logs, lalu aktifkan akses manual untuk member tsb).
    if ($status === 'paid' && !$accessActivated) {
        flash('access_warn',
            'PERHATIAN: order ' . $orderNumber . ' ditandai LUNAS, tetapi AKTIVASI AKSES MEMBER GAGAL. '
            . 'Member belum bisa mengakses produk "' . $product['name'] . '". Periksa storage/logs lalu aktifkan akses manual.',
            'warning');
        error_log('create-transaction: activateAccess GAGAL utk order ' . $orderNumber
            . ' (user #' . $userId . ', produk #' . $productId . ') — order tetap Lunas tanpa akses.');
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Create transaction error: ' . $e->getMessage());
    flash('error', 'Gagal membuat transaksi manual.', 'error');
}

redirect('/admin/transactions');

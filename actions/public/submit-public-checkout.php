<?php
// ════════════════════════════════════════════════════════════════
// Public Checkout — handler form order publik (tanpa login)
// Membuat (atau memakai) akun buyer, lalu membuat order mengikuti
// PERSIS alur actions/checkout/process.php.
// Tidak butuh requireLogin/requireAdmin. CSRF tetap wajib.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/products');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('public_checkout_error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/products');
}

// ─── Rate limiting sederhana (60 detik per session) ───────────
$lastSubmit = (int) ($_SESSION['last_public_checkout_submit'] ?? 0);
if ($lastSubmit > 0 && (time() - $lastSubmit) < 60) {
    flash('public_checkout_error', 'Terlalu banyak request. Tunggu sebentar lalu coba lagi.', 'error');
    $slugBack = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['product_slug'] ?? ''));
    redirect($slugBack !== '' ? '/order/' . $slugBack : '/products');
}

// Lapis kedua: rate limit per-IP — throttle session di atas gampang di-bypass
// dengan membuang cookie session tiap request; ini yang menahan spam order/akun.
$pcIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkIpRateLimit('pubco', $pcIp, 6, 15)) {
    flash('public_checkout_error', 'Terlalu banyak order dari jaringan kamu. Tunggu beberapa menit lalu coba lagi.', 'error');
    $slugBack = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['product_slug'] ?? ''));
    redirect($slugBack !== '' ? '/order/' . $slugBack : '/products');
}

// ─── Anti-bot: honeypot + jebakan waktu ───────────────────────
// Bot spam membuat order/akun via checkout (guest checkout MEMBUAT akun user).
// Bot mengisi field honeypot tersembunyi & mem-POST hampir seketika → ditolak
// DIAM-DIAM (pesan generik) sebelum membuat akun/order. Nol friksi bagi pembeli
// asli (honeypot tak terlihat; manusia mengisi form > 2 detik). Melengkapi
// rate-limit per-IP di atas (tak cukup vs bot multi-IP).
$pcHp   = trim((string) ($_POST['website'] ?? '')) !== '';
$pcTs   = (int) ($_SESSION['pubco_form_ts'] ?? 0);
$pcFast = $pcTs > 0 && (time() - $pcTs) < 2;
if ($pcHp || $pcFast) {
    error_log('[pubco] ditolak anti-bot (' . ($pcHp ? 'honeypot' : 'terlalu-cepat') . ') ip=' . $pcIp);
    flash('public_checkout_error', 'Order tidak dapat diproses. Silakan coba lagi.', 'error');
    $slugBack = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['product_slug'] ?? ''));
    redirect($slugBack !== '' ? '/order/' . $slugBack : '/products');
}

// ─── LANGKAH 1: Sanitasi & Validasi input ─────────────────────
$productId     = (int) ($_POST['product_id'] ?? 0);
$buyerName     = trim(strip_tags($_POST['buyer_name'] ?? ''));
$buyerWa       = preg_replace('/[^0-9]/', '', $_POST['buyer_wa'] ?? '');
$buyerEmail    = strtolower(trim($_POST['buyer_email'] ?? ''));
$couponCode    = strtoupper(preg_replace('/[^A-Z0-9-]/', '', trim($_POST['coupon_code'] ?? '')));
$paymentMethod = trim($_POST['payment_method'] ?? '');
$affiliateCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($_POST['affiliate_code'] ?? '')));

// Fallback affiliate dari cookie 'aff_code' bila field kosong
if ($affiliateCode === '' && !empty($_COOKIE['aff_code'])) {
    $affiliateCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_COOKIE['aff_code']));
}

$errors = [];
if ($buyerName === '' || mb_strlen($buyerName) > 100) {
    $errors[] = 'Nama lengkap wajib diisi (maksimal 100 karakter).';
}
if (!filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Format email tidak valid.';
}
if (!preg_match('/^[0-9]{10,15}$/', $buyerWa)) {
    $errors[] = 'Nomor WhatsApp tidak valid (10–15 digit).';
} else {
    // Normalisasi ke format internasional SAAT SIMPAN — nomor tanpa 0 ("8xxx")
    // menetap salah di users.phone dan menggagalkan semua WA berikutnya.
    $buyerWa = normalizeWaNumber($buyerWa);
}

$redirectBack = function () use ($productId) {
    try {
        $st = getDB()->prepare("SELECT slug FROM products WHERE id = ? LIMIT 1");
        $st->execute([$productId]);
        $slug = $st->fetchColumn();
        if ($slug) redirect('/order/' . $slug);
    } catch (Exception $e) { /* ignore */ }
    redirect('/products');
};

if (!empty($errors)) {
    flash('public_checkout_error', implode(' ', $errors), 'error');
    $redirectBack();
}

try {
    $pdo = getDB();

    // ─── Load & validasi produk (harus aktif) ───
    // Produk Halaman Dukung (flexible + show_on_support) boleh dibayar sebagai TAMU walau
    // checkout_mode='login' — mirror guard di public-checkout.php (cegah "jalur pembeli
    // beda"). Produk lain WAJIB checkout_mode='public' (postur keamanan guest dipertahankan).
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    $allowGuestSupport = $product && ($product['pricing_type'] ?? '') === 'flexible' && (int) ($product['show_on_support'] ?? 0) === 1;
    if (!$product || ($product['slug'] ?? '') === 'voucher-tes-stifin' || (($product['checkout_mode'] ?? 'login') !== 'public' && !$allowGuestSupport)) {
        flash('public_checkout_error', 'Produk tidak ditemukan atau tidak tersedia.', 'error');
        redirect('/products');
    }

    // Jika buyer ternyata sudah login → pakai checkout login biasa
    if (isLoggedIn()) {
        redirect('/checkout?product_id=' . $productId);
    }

    // ─── Validasi gateway aktif (mirror checkout.php) ─────────
    $duitkuActive = $xenditActive = false;
    $mootaActive  = false;
    $midtransActive = $tripayActive = false;
    $paypalActive = false;
    try {
        $gwRows = $pdo->query(
            "SELECT provider, is_active, config_json FROM payment_gateways WHERE provider IN ('duitku','xendit','moota','midtrans','tripay','paypal')"
        )->fetchAll();
        foreach ($gwRows as $gwRow) {
            if ($gwRow['provider'] === 'duitku')   $duitkuActive   = (bool) $gwRow['is_active'];
            if ($gwRow['provider'] === 'xendit')   $xenditActive   = (bool) $gwRow['is_active'];
            if ($gwRow['provider'] === 'midtrans') $midtransActive = (bool) $gwRow['is_active'];
            if ($gwRow['provider'] === 'tripay')   $tripayActive   = (bool) $gwRow['is_active'];
            if ($gwRow['provider'] === 'moota') {
                $cfg = json_decode($gwRow['config_json'] ?? '', true) ?: [];
                $mootaActive = ((bool) $gwRow['is_active']) && trim($cfg['bank_info'] ?? '') !== '';
            }
            // PayPal: aktif hanya bila kurs IDR→USD terisi (ditagih USD). Sama syarat login.
            if ($gwRow['provider'] === 'paypal') {
                $cfg = json_decode($gwRow['config_json'] ?? '', true) ?: [];
                $paypalActive = ((bool) $gwRow['is_active']) && ((float) ($cfg['idr_usd_rate'] ?? 0)) > 0;
            }
        }
    } catch (Exception $e) {
        error_log('Public checkout gateway check error: ' . $e->getMessage());
    }

    $price  = (float) $product['price'];

    // ─── Bayar Fleksibel: nominal ditentukan tamu — VALIDASI SERVER-SIDE WAJIB ──
    $isFlexible = ($product['pricing_type'] ?? 'fixed') === 'flexible';
    if ($isFlexible) {
        $flexFloor = max((float) ($product['flexible_min'] ?? 0), 1); // Fase 1: nominal > 0
        $flexAmt   = (float) preg_replace('/[^0-9]/', '', (string) ($_POST['flexible_amount'] ?? '0'));
        if ($flexAmt < $flexFloor) {
            flash('public_checkout_error', 'Nominal pembayaran minimal ' . formatRupiah($flexFloor) . '.', 'error');
            redirect('/order/' . $product['slug']);
        }
        $price = min($flexAmt, 100000000.0); // cap wajar (anti-typo)
    }

    // ─── Dime Sale: pakai harga TERKUNCI saat checkout dibuka (fallback hitung ulang).
    if (!$isFlexible && !empty($product['dime_enabled'])) {
        $locked = dimeLockedPrice((int) $product['id']);
        $price  = $locked !== null ? $locked : dimeEffectivePrice($product);
    }

    $isFree = $price <= 0;

    // ─── GUARD KEAMANAN: produk tanpa harga TIDAK boleh di-checkout gratis ─────
    // Produk price<=0 & non-flexible = kemungkinan salah konfig / placeholder migrasi
    // (mis. Sejoli). Tanpa guard ini, $isFree memicu order auto-'paid' + akses aktif →
    // pembeli dapat produk berbayar GRATIS tanpa transaksi nyata. Gratis SAH (kupon 100%
    // pada produk berharga, atau flexible) tak terpengaruh.
    if (orderProductNotSellableFree($product)) {
        error_log('[checkout-guard] tolak order gratis produk price<=0 (guest): product_id=' . $productId . ' name=' . ($product['name'] ?? ''));
        if (function_exists('logActivity')) logActivity('system', 'checkout.blocked_zero_price', 'product', (int) $productId, 'Checkout Rp0 ditolak (guest) — produk tanpa harga; kemungkinan migrasi/salah konfig');
        flash('error', 'Produk ini belum tersedia untuk dibeli. Silakan hubungi admin.', 'error');
        redirect('/products');
    }

    // Transfer Bank Manual (rekening/QR) tersedia? (server-side, jangan percaya form)
    $manualTransferEnabled = getSetting('manual_transfer_enabled', '1') === '1';
    $activeBankCount = 0;
    if ($manualTransferEnabled) {
        try { $activeBankCount = (int) getDB()->query("SELECT COUNT(*) FROM bank_accounts WHERE is_active = 1")->fetchColumn(); }
        catch (Exception $e) { $activeBankCount = 0; }
    }
    $manualQrSet = $manualTransferEnabled && !empty(getSetting('manual_qr_image'));
    $manualTransferAvailable = $manualTransferEnabled && ($activeBankCount > 0 || $manualQrSet);

    // Tentukan metode bayar valid; default 'manual' bila tak ada gateway
    $allowedMethods = ['manual'];
    if ($duitkuActive)   $allowedMethods[] = 'duitku';
    if ($xenditActive)   $allowedMethods[] = 'xendit';
    if ($midtransActive) $allowedMethods[] = 'midtrans';
    if ($tripayActive)   $allowedMethods[] = 'tripay';
    if ($paypalActive)   $allowedMethods[] = 'paypal';
    if ($mootaActive)  $allowedMethods[] = 'moota';
    if ($manualTransferAvailable) $allowedMethods[] = 'manual_transfer';
    if (!in_array($paymentMethod, $allowedMethods, true)) {
        $paymentMethod = 'manual';
    }
    $isMoota          = ($paymentMethod === 'moota');
    $isManualTransfer = ($paymentMethod === 'manual_transfer');
    $isGatewayFlow    = in_array($paymentMethod, ['duitku', 'xendit', 'midtrans', 'tripay', 'paypal'], true);

    // Rekening tujuan (opsional bila bayar via QR) — validasi milik rekening aktif.
    $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
    if ($isManualTransfer && $bankAccountId > 0) {
        try {
            $ok = (int) getDB()->query("SELECT COUNT(*) FROM bank_accounts WHERE id = " . $bankAccountId . " AND is_active = 1")->fetchColumn();
            if ($ok === 0) $bankAccountId = 0;
        } catch (Exception $e) { $bankAccountId = 0; }
    } else {
        $bankAccountId = 0;
    }

    // ─── LANGKAH 2: Resolve harga & kupon (server-side) ───────
    $subtotal       = $price;
    $couponId       = null;
    $couponCodeUsed = null;
    $couponDiscount = 0.0;

    if (!$isFree && !$isFlexible && $couponCode !== '') {
        $cStmt = $pdo->prepare("SELECT * FROM coupons WHERE code = ? AND status = 'active' LIMIT 1");
        $cStmt->execute([$couponCode]);
        $coupon = $cStmt->fetch();
        // Validasi field bersama (helpers/order-service.php). Cek pemakaian per-user
        // TIDAK dilakukan di sini (jalur guest) — ditangani anti-race di dalam transaksi.
        if ($coupon && orderCouponFieldsValid($coupon, $subtotal, $productId)) {
            $couponDiscount = orderCouponDiscount($coupon, $subtotal);
            $couponId       = (int) $coupon['id'];
            $couponCodeUsed = $coupon['code'];
        }
    }

    $discount = $couponDiscount;
    $total    = max(0, $subtotal - $discount);

    // ─── LANGKAH 3: Resolve atau buat akun buyer ──────────────
    $isNewUser    = false;
    $tempPassword = null;

    $exStmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ? LIMIT 1");
    $exStmt->execute([$buyerEmail]);
    $existingUser = $exStmt->fetch();

    if ($existingUser) {
        $userId = (int) $existingUser['id'];
        // Lengkapi phone bila masih kosong (jangan timpa data lama)
        $pdo->prepare(
            "UPDATE users SET phone = ? WHERE id = ? AND (phone IS NULL OR phone = '')"
        )->execute([$buyerWa, $userId]);
        // Nama placeholder migrasi ("Member Sejoli") atau kosong → adopsi nama yang
        // pembeli isi di checkout. Tanpa ini, notif reminder (abandoned cart) yang
        // membaca users.name akan menyapa "Member Sejoli", bukan nama asli pembeli.
        // Konservatif: HANYA timpa bila kosong/placeholder — jangan sentuh nama sah.
        $exName = trim((string) ($existingUser['name'] ?? ''));
        if ($buyerName !== '' && ($exName === '' || $exName === 'Member Sejoli')) {
            $pdo->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$buyerName, $userId]);
        }
    } else {
        $isNewUser    = true;
        $tempPassword = substr(str_shuffle('abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#'), 0, 10);
        $hashedPassword = password_hash($tempPassword, PASSWORD_BCRYPT);
        $newAffCode     = generateAffiliateCode();

        $pdo->prepare(
            "INSERT INTO users (name, email, phone, password, affiliate_code, role, status)
             VALUES (?, ?, ?, ?, ?, 'user', 'active')"
        )->execute([$buyerName, $buyerEmail, $buyerWa, $hashedPassword, $newAffCode]);
        $userId = (int) $pdo->lastInsertId();

        // Auto-create affiliate record (mengikuti pola register.php)
        $defaultRate = (float) (getSetting('affiliate_commission_rate', '10') ?: 10);
        $pdo->prepare(
            "INSERT INTO affiliates (user_id, code, commission_rate, status)
             VALUES (?, ?, ?, 'active')"
        )->execute([$userId, $newAffCode, $defaultRate]);
    }

    // Biaya awal (setup fee) — hanya untuk pembelian PERTAMA produk ini (0 bila user
    // sudah pernah beli; tamu baru selalu pembelian pertama). userId sudah teresolusi.
    $setupFee = $isFlexible ? 0.0 : productSetupFee($product, $userId);
    $total    = max(0, $subtotal - $discount) + $setupFee;
    $isFree   = ($total <= 0);

    // ─── Mode Booking Fee (DP) — validasi server-side (mirror process.php) ───
    $isBooking  = false;
    $bookingFee = 0.0;
    if (($_POST['payment_mode'] ?? 'full') === 'booking') {
        $bookingFee = (float) ($product['booking_fee'] ?? 0);
        if ((int) ($product['booking_enabled'] ?? 0) === 1
            && $bookingFee > 0 && $bookingFee < ((float) $product['price'] + $setupFee)) {
            $isBooking = true;
            // DP saja yang ditagih; subtotal & setup_fee tetap harga penuh (terkunci),
            // kupon diabaikan (pelunasan = subtotal + setup_fee - DP).
            $discount = 0.0;
            $couponId = null; $couponCodeUsed = null;
            $total    = $bookingFee;
            $isFree   = false;
        } else {
            $bookingFee = 0.0;
        }
    }

    // ─── Resolve afiliasi perujuk (3-tier customer binding & cookie rules) ───
    $affRes      = resolveAffiliateForCheckout($pdo, $userId, $affiliateCode, $productId);
    $affiliateId = $affRes['affiliate_id'];
    // referral_code yang DISIMPAN = keputusan resolver (null bila tak teratribusi:
    // self-referral, afiliat nonaktif, atau General Cookie mati). JANGAN memakai
    // kode ambient mentah — bila null tapi kode lama tetap tersimpan, jalur webhook
    // onOrderPaid(commission_fallback) akan membuat komisi dari kode itu (kebocoran).
    $affiliateCode = $affRes['affiliate_code'];
    if ($affiliateId !== null && $userId > 0) {
        bindCustomerToAffiliate($pdo, $userId, $affiliateId);
    }

    // ─── LANGKAH 4: Buat transaksi (mirror process.php) ───────
    $pdo->beginTransaction();

    // ── Anti-race kupon (sama dgn process.php) ────────────────
    // Kunci baris kupon lalu re-validasi limit & pemakaian per-user di dalam lock
    // agar usage_limit tak terlampaui & tak bisa dipakai 2x oleh user yang sama.
    if ($couponId !== null && !orderCouponAntiRaceValid($pdo, $couponId, $userId)) {
        $pdo->rollBack();
        flash('public_checkout_error', 'Kupon tidak berlaku lagi (kuota habis atau sudah pernah dipakai). Silakan coba tanpa kupon.', 'error');
        $redirectBack();
    }

    do {
        $orderNumber = generateOrderNumber();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE order_number = ?');
        $dup->execute([$orderNumber]);
    } while ((int) $dup->fetchColumn() > 0);

    $initialStatus = $isFree ? 'paid' : 'pending';
    $paidAt        = $isFree ? date('Y-m-d H:i:s') : null;

    $pdo->prepare(
        'INSERT INTO orders (order_number, user_id, subtotal, discount, setup_fee, total, status, notes, paid_at, referral_code, coupon_id, coupon_code, order_stage, booking_fee)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $orderNumber, $userId, $subtotal, $discount, $setupFee, $total,
        $initialStatus, null, $paidAt, $affiliateCode ?: null,
        $couponId, $couponCodeUsed,
        $isBooking ? 'booking' : 'full', $isBooking ? $bookingFee : null,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    // Fase 2 Bayar Fleksibel: simpan pesan + opsi anonim pendukung (produk flexible).
    if ($isFlexible) {
        $supMsg  = trim(strip_tags((string) ($_POST['supporter_message'] ?? '')));
        $supMsg  = $supMsg !== '' ? mb_substr($supMsg, 0, 280) : null;
        $supAnon = (isset($_POST['supporter_anonymous']) && $_POST['supporter_anonymous'] === '1') ? 1 : 0;
        try {
            $pdo->prepare("UPDATE orders SET supporter_message = ?, supporter_anonymous = ? WHERE id = ?")
                ->execute([$supMsg, $supAnon, $orderId]);
        } catch (Throwable $e) { error_log('supporter fields (public checkout): ' . $e->getMessage()); }
    }

    // Simpan data match-quality Meta (fbp/fbc/IP/UA) utk CAPI server-side.
    try {
        $pdo->prepare("UPDATE orders SET tracking_meta = ? WHERE id = ?")->execute([
            json_encode([
                'fbp' => substr((string) ($_COOKIE['_fbp'] ?? ''), 0, 255),
                'fbc' => meta_resolve_fbc(),
                'ip'  => substr(client_real_ip(), 0, 45),
                'ua'  => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ], JSON_UNESCAPED_SLASHES),
            $orderId,
        ]);
    } catch (Throwable $e) { error_log('tracking_meta (public checkout): ' . $e->getMessage()); }

    // Mailketing: antre pembeli (guest) ke list "Order" (order dibuat). Fail-soft.
    mailketing_enqueue_order_created((int) ($userId ?? 0), ['email' => $buyerEmail, 'name' => $buyerName, 'phone' => $buyerWa]);

    $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, price, quantity)
         VALUES (?, ?, ?, ?, 1)'
    )->execute([
        $orderId, $productId,
        $product['name'] . ($isBooking ? ' — Booking Fee' : ''),
        $isBooking ? $bookingFee : $price,
    ]);

    // ─── Order Bump — hanya order berbayar & bukan booking/flexible ───
    // Logika bersama dgn jalur checkout login → helpers/order-service.php. Harga bump
    // dari DB (anti-manipulasi form); bump yang sudah dimiliki di-skip. Akses diberikan
    // saat pembayaran terkonfirmasi (onOrderPaid; atau inline di bawah utk order gratis).
    $bumpRes        = orderProcessBumps(
        $pdo, $orderId, $productId, $userId,
        (array) ($_POST['bump_ids'] ?? []),
        ($isFree || $isBooking || $isFlexible)
    );
    $bumpProductIds = $bumpRes['ids'];
    $total         += $bumpRes['total'];

    // ─── Bonus Kilat (produk premium GRATIS berbatas waktu) ──
    // Logika bersama dgn jalur checkout login → helpers/order-service.php. Bonus valid
    // disuntik sbg is_bump harga 0 → ter-grant onOrderPaid (berbayar) atau blok $isFree
    // di bawah. Nonaktif utk booking & flexible (bonus gratis boleh saat main gratis).
    $flashBonusIds = orderProcessFlashBonuses(
        $pdo, $orderId, $productId, $userId,
        (array) ($_POST['flash_bonus_ids'] ?? []),
        $bumpProductIds,
        ($isBooking || $isFlexible)
    );

    $finalTotal = $total;

    // ─── Moota: tambah kode unik nominal + tandai gateway ─────
    if ($isMoota && !$isFree && $finalTotal > 0) {
        require_once __DIR__ . '/../../payment_gateways/MootaGateway.php';
        $u          = (new MootaGateway())->generateUniqueCode($finalTotal);
        $finalTotal = $u['total_amount'];
        $pdo->prepare("UPDATE orders SET total = ?, payment_gateway = 'moota' WHERE id = ?")
            ->execute([$finalTotal, $orderId]);
    }
    // ─── Pembayaran Manual (transfer bank / manual / QR): kode unik nominal ───
    elseif (!$isGatewayFlow && !$isFree && $finalTotal > 0) {
        $withCode = applyManualUniqueCode($pdo, (float) $finalTotal);
        if ($withCode != $finalTotal) {
            $finalTotal = $withCode;
            $pdo->prepare("UPDATE orders SET total = ? WHERE id = ?")->execute([$finalTotal, $orderId]);
        }
    }

    // INSERT payment record
    $paymentStatus = $isFree ? 'success' : 'pending';
    $paymentPaidAt = $isFree ? date('Y-m-d H:i:s') : null;
    $paymentMethodDb = $isMoota ? 'moota'
                     : ($isGatewayFlow ? $paymentMethod // 'xendit'/'duitku'
                     : ($isManualTransfer ? 'manual_transfer' : 'manual'));
    // Rekening tersimpan hanya untuk manual_transfer dgn rekening dipilih (QR-only → NULL).
    $payBankId = ($isManualTransfer && $bankAccountId > 0) ? $bankAccountId : null;
    $pdo->prepare(
        'INSERT INTO payments (order_id, method, amount, status, paid_at, bank_account_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$orderId, $paymentMethodDb, $finalTotal, $paymentStatus, $paymentPaidAt, $payBankId]);

    // Free → aktivasi akses langsung
    if ($isFree) {
        bumpDimeCounter($pdo, $userId, $productId); // dime: hitung sale sebelum aktivasi
        activateAccess($userId, $productId, $orderId);
        // Email sequence 'order_paid': order gratis TETAP order lunas. Jalur ini
        // dulu tak meng-enroll — padahal checkout login (process.php) & jalur
        // gateway (onOrderPaid) melakukannya → pembeli gratis lewat form publik
        // diam-diam tak pernah masuk sequence.
        enrollUserToSequences('order_paid', $userId, $productId, $orderNumber);
        issueLicense($orderId, $productId, $userId); // license key jika produk license_enabled
        // Bonus Kilat (GRATIS): aktifkan aksesnya sekalian saat order langsung paid.
        foreach ($flashBonusIds as $fbId) {
            activateAccess($userId, $fbId, $orderId);
            issueLicense($orderId, $fbId, $userId);
        }
    }

    // Komisi afiliasi + pemakaian kupon (logika bersama → helpers/order-service.php,
    // atomik dalam transaksi ini). couponCodeUsed dipakai di kolom orders; usage pakai couponId.
    orderRecordCommission($pdo, $affiliateId, $orderId, $productId, (float) $finalTotal, $isFree);
    orderRecordCouponUsage($pdo, $couponId, $userId, $orderId, (float) $couponDiscount);

    $pdo->commit();

    if ($isFree) {
        updateUserMembershipLevel($userId);
    }

    // Tandai rate-limit setelah order sukses dibuat (session + per-IP)
    $_SESSION['last_public_checkout_submit'] = time();
    recordIpAction('pubco', $pcIp);

    // Notifikasi (welcome + order_created) DIPINDAH ke setelah respons — lihat
    // blok "Kerja background" di bawah. Sebelumnya 2–4 panggilan email/WA
    // sinkron di sini menahan redirect ke halaman invoice beberapa detik.

    logActivity('user', 'order.create', 'order', $orderId, 'Order publik dibuat: ' . $orderNumber);

    // ─── LANGKAH 5: Buat invoice gateway (Duitku/Xendit) ──────
    // Gateway memakai halaman pembayaran hosted (paymentUrl/invoice_url).
    // Simpan URL ke payments.gateway_response agar halaman thank-you bisa
    // menampilkan tombol "Lanjut Bayar" (buyer tidak di-redirect paksa).
    if ($isGatewayFlow && $paymentMethod === 'paypal') {
        // PayPal = internasional (ditagih USD dari total IDR via kurs). gateway_response
        // KAYA (usd_amount/rate/expected_idr) wajib: dipakai paypal-return utk verifikasi
        // nominal. Pola IDENTIK create-payment.php (jalur login) agar return handler sama.
        require_once __DIR__ . '/../../payment_gateways/PayPalGateway.php';
        $paypal = new PayPalGateway();
        if ($paypal->isConfigured()) {
            $result = $paypal->createInvoice([
                'order_number' => $orderNumber,
                'total'        => $finalTotal,
                'name'         => $buyerName,
                'email'        => $buyerEmail,
                'product_name' => $product['name'],
            ]);
            if (!empty($result['success']) && !empty($result['payment_url'])) {
                $ppJson = json_encode([
                    'provider'        => 'paypal',
                    'payment_url'     => $result['payment_url'],
                    'paypal_order_id' => $result['reference'],
                    'currency'        => 'USD',
                    'usd_amount'      => $result['usd_amount'],
                    'rate'            => $result['rate'],
                    'expected_idr'    => (float) $finalTotal,
                ]);
                $pdo->prepare("UPDATE orders SET payment_gateway = 'paypal', payment_reference = ? WHERE id = ?")
                    ->execute([$result['reference'], $orderId]);
                $pdo->prepare("UPDATE payments SET method = 'paypal', gateway_ref = ?, gateway_response = ? WHERE order_id = ?")
                    ->execute([$result['reference'], $ppJson, $orderId]);
            } else {
                error_log('Public checkout PayPal invoice failed for order ' . $orderNumber . ': ' . ($result['message'] ?? ''));
            }
        } else {
            error_log('Public checkout PayPal not configured for order ' . $orderNumber);
        }
    } elseif ($isGatewayFlow) {
        $gwClass = ['duitku' => 'DuitkuGateway', 'xendit' => 'XenditGateway', 'midtrans' => 'MidtransGateway', 'tripay' => 'TripayGateway'][$paymentMethod] ?? 'XenditGateway';
        require_once __DIR__ . '/../../payment_gateways/' . $gwClass . '.php';
        $gw = new $gwClass();

        if ($gw->isConfigured()) {
            $invoiceData = [
                'order_number' => $orderNumber,
                'total'        => $finalTotal,
                'name'         => $buyerName,
                'email'        => $buyerEmail,
                'phone'        => $buyerWa,
                'product_name' => $product['name'],
            ];
            // Tripay wajib channel (createInvoice($data, $method)); gateway lain tak pakai.
            if ($paymentMethod === 'tripay') {
                $tripayChannel = trim($_POST['tripay_channel'] ?? '');
                $result = $gw->createInvoice($invoiceData, $tripayChannel);
            } else {
                $result = $gw->createInvoice($invoiceData);
            }
            if (!empty($result['success']) && !empty($result['payment_url'])) {
                $ref = $result['reference'] ?? null;
                $pdo->prepare(
                    "UPDATE orders SET payment_gateway = ?, payment_reference = ? WHERE id = ?"
                )->execute([$paymentMethod, $ref, $orderId]);
                $pdo->prepare(
                    "UPDATE payments SET method = ?, gateway_ref = ?, gateway_response = ? WHERE order_id = ?"
                )->execute([
                    $paymentMethod, $ref,
                    json_encode(['provider' => $paymentMethod, 'payment_url' => $result['payment_url']]),
                    $orderId,
                ]);
            } else {
                error_log('Public checkout gateway invoice failed for order ' . $orderNumber);
            }
        } else {
            error_log('Public checkout gateway not configured for order ' . $orderNumber);
        }
    }

    // ─── Auto-login HANYA untuk akun baru ─────────────────────
    // Akun lama tidak di-login otomatis (cegah account takeover dari
    // sekadar tahu email orang lain).
    if ($isNewUser) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['name']    = $buyerName;
        $_SESSION['email']   = $buyerEmail;
        $_SESSION['role']    = 'user';
    }

    // ─── Token akses halaman thank-you publik (tanpa login) ───
    // Berlaku 1 jam. session_regenerate_id mempertahankan data session,
    // jadi token ini aman di-set setelah auto-login.
    // is_new_account + new_account_password: untuk menampilkan kartu kredensial
    // inline di halaman thank-you (buyer baru yang sudah auto-login) — menutup
    // gap "email telat → buyer buntu". Plaintext hanya di SESSION server-side,
    // berumur 1 jam (ikut token), tak pernah masuk DB/URL/log. Akun LAMA: null.
    $_SESSION['public_order_token'] = [
        'order_id'             => $orderId,
        'order_number'         => $orderNumber,
        'buyer_email'          => $buyerEmail,
        'is_new_account'       => $isNewUser,
        'new_account_password' => $isNewUser ? $tempPassword : null,
        'expires_at'           => time() + 3600,
    ];
    $_SESSION['last_public_checkout_submit'] = time();

    if ($isFree) {
        flash('success', 'Selamat! Akses ke "' . $product['name'] . '" sudah aktif. Detail login dikirim ke email kamu.');
    }

    // ─── Balas browser SEKARANG — buyer langsung ke halaman invoice ────────
    // (semua penulisan session — auto-login, token thank-you, flash — sudah
    // selesai di atas; lsRespondThenContinue menutup session lalu melepas
    // koneksi, dan notifikasi dikirim di background proses ini.)
    lsRespondThenContinue('/public-thank-you/' . $orderNumber);

    // ─── Kerja background: welcome (akun baru) + order_created ─────────────
    if ($isNewUser) {
        try {
            require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
            NotificationDispatcher::trigger('public_checkout_welcome', [
                'name'          => $buyerName,
                'email'         => $buyerEmail,
                'temp_password' => $tempPassword,
                'login_url'     => baseUrl('login'),
                'member_url'    => baseUrl('member'),
            ], $buyerEmail, $buyerWa);
        } catch (Exception $e) {
            error_log('Public checkout welcome email error: ' . $e->getMessage());
        }
    }

    try {
        require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
        $invoiceUrl = baseUrl('transactions/' . $orderNumber);
        $memberUrl  = baseUrl('member');
        $payLabel   = ($paymentMethod === 'moota') ? 'Transfer Bank (Otomatis)'
                    : ($paymentMethod === 'paypal' ? 'PayPal'
                    : (in_array($paymentMethod, ['duitku', 'xendit', 'midtrans', 'tripay'], true) ? 'Pembayaran Online' : 'Transfer Manual'));
        NotificationDispatcher::trigger('order_created', [
            'name'           => $buyerName,
            'email'          => $buyerEmail,
            'order_number'   => $orderNumber,
            'product_name'   => (string) $product['name'],
            'total'          => formatRupiah($finalTotal),
            'payment_method' => $payLabel,
            'invoice_url'    => $invoiceUrl,
        ], $buyerEmail, $buyerWa);

        if ($isFree) {
            NotificationDispatcher::trigger('payment_success', [
                'name'         => $buyerName,
                'email'        => $buyerEmail,
                'order_number' => $orderNumber,
                'total'        => formatRupiah($finalTotal),
                'paid_at'      => date('d M Y, H:i', strtotime($paidAt ?? date('Y-m-d H:i:s'))),
                'invoice_url'  => $invoiceUrl,
                'member_url'   => $memberUrl,
            ], $buyerEmail, $buyerWa);
            NotificationDispatcher::trigger('access_activated', [
                'name'         => $buyerName,
                'email'        => $buyerEmail,
                'product_name' => $product['name'],
                'order_number' => $orderNumber,
                'member_url'   => $memberUrl,
                'invoice_url'  => $invoiceUrl,
            ], $buyerEmail, $buyerWa);

            // Magic-link akses instan (Segmen B) utk pembeli BERAKUN-LAMA. Akun BARU
            // tak perlu — sudah auto-login di perangkat ini + welcome berisi password.
            // Fail-soft, hanya role 'user'. Jalur async (pending→webhook) ditangani di
            // onOrderPaidPostCommit; di sini khusus order gratis yang langsung paid.
            if (!$isNewUser && function_exists('dispatchAccessLink')) {
                dispatchAccessLink($userId, $orderId, $productId, (string) $product['name']);
            }
        }
    } catch (Exception $e) {
        error_log('Public checkout notification error: ' . $e->getMessage());
    }

    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Public checkout submit error: ' . $e->getMessage());
    flash('public_checkout_error', 'Terjadi kesalahan saat memproses order. Silakan coba lagi.', 'error');
    $redirectBack();
}

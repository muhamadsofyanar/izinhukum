<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/products');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/products');
}

// Bundle: delegasikan ke handler terpisah agar flow produk tunggal tidak terpengaruh.
if ((int) ($_POST['bundle_id'] ?? 0) > 0) {
    require __DIR__ . '/process-bundle.php';
    exit;
}

$productId      = (int) ($_POST['product_id'] ?? 0);
$notes          = trim($_POST['notes'] ?? '');
$refCode        = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($_POST['referral_code'] ?? '')));
$gatewayMethod  = trim($_POST['payment_gateway_method'] ?? '');  // 'duitku'/'xendit'/'paypal'/'moota', kosong = manual
// Gateway hosted (Duitku/Xendit/Midtrans/Tripay/PayPal) pakai alur AJAX/JSON:
// process.php balas JSON {order_id} → JS panggil create-payment → redirect invoice.
// Moota = transfer bank manual → submit normal → redirect ke halaman transaksi.
// PENTING: paypal WAJIB di sini. Tanpa ini order paypal salah diperlakukan sebagai
// 'manual' (kena kode unik IDR) + balas redirect HTML (bukan JSON) → JS gagal parse →
// pembeli dilempar balik ke /checkout (tak sampai invoice), lalu percobaan berikut
// jatuh ke gateway default (mis. xendit). PayPal ditagih USD, jangan kena kode unik.
$isGatewayFlow  = in_array($gatewayMethod, ['duitku', 'xendit', 'midtrans', 'tripay', 'paypal'], true);
$isMoota        = ($gatewayMethod === 'moota');
// Manual Transfer (multi rekening): order pending, user upload bukti, admin konfirmasi.
$isManualTransfer = (($_POST['payment_method'] ?? '') === 'manual_transfer');
$bankAccountId    = (int) ($_POST['bank_account_id'] ?? 0);

// Jika dipanggil oleh JS gateway flow, response dalam JSON
if ($isGatewayFlow) {
    header('Content-Type: application/json');
}

if ($productId <= 0) {
    if ($isGatewayFlow) {
        echo json_encode(['error' => 'Produk tidak valid.']);
        exit;
    }
    flash('error', 'Produk tidak valid.', 'error');
    redirect('/products');
}

$userId = (int) $_SESSION['user_id'];

// Nomor WhatsApp WAJIB (dipakai untuk notifikasi order via WA & login OTP).
// Disimpan ke users.phone di bawah, lalu jadi tujuan notifikasi.
$buyerPhone = preg_replace('/[^0-9]/', '', (string) ($_POST['phone'] ?? ''));
if (!preg_match('/^[0-9]{10,15}$/', $buyerPhone)) {
    if ($isGatewayFlow) {
        echo json_encode(['error' => 'Nomor WhatsApp wajib diisi (10–15 digit).']);
        exit;
    }
    flash('error', 'Nomor WhatsApp wajib diisi (10–15 digit).', 'error');
    redirect('/checkout?product_id=' . $productId);
}
// Normalisasi ke format internasional SAAT SIMPAN (bukan hanya saat kirim) —
// nomor yang diketik tanpa 0 (mis. "8xxx" karena form ber-prefix +62 visual)
// menetap salah di users.phone dan menggagalkan semua WA berikutnya.
$buyerPhone = normalizeWaNumber($buyerPhone);

try {
    $pdo = getDB();

    // Load produk
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        if ($isGatewayFlow) {
            echo json_encode(['error' => 'Produk tidak ditemukan atau tidak tersedia.']);
            exit;
        }
        flash('error', 'Produk tidak ditemukan atau tidak tersedia.', 'error');
        redirect('/products');
    }

    $isVoucher = ($product['slug'] ?? '') === 'voucher-tes-stifin';
    $quantity = $isVoucher ? stifinVoucherQuantity($_POST['voucher_quantity'] ?? null) : 1;
    if ($isVoucher && ($productId !== stifinVoucherProductId() || !$quantity || !empty($_SESSION['impersonating']))) {
        $message = 'Pembelian voucher tidak tersedia atau jumlah di luar rentang 1–1.000.';
        if ($isGatewayFlow) {
            echo json_encode(['error' => $message]);
            exit;
        }
        flash('error', $message, 'error');
        redirect('/checkout?product_id=' . $productId);
    }
    if ($isVoucher && stifinVoucherTransferEnabled() && stifinLinkedPromoterCode($pdo, $userId) === null) {
        $message = 'Hubungi admin untuk menautkan akun promotor STIFIn sebelum membeli voucher.';
        if ($isGatewayFlow) {
            echo json_encode(['error' => $message]);
            exit;
        }
        flash('error', $message, 'error');
        redirect('/member/voucher-tes');
    }

    // Cek akses ganda — HANYA blokir produk SEKALI-BAYAR. Produk BERLANGGANAN
    // (access_duration_days !== null) boleh order lagi meski masa aktif belum habis =
    // PERPANJANGAN (activateAccess menumpuk masa aktif dari tanggal habis, bukan reset).
    // Konsisten dgn halaman checkout.php yang sudah menampilkan UI perpanjangan — dulu
    // guard ini menolaknya sehingga tombol "Perpanjang" berujung "sudah memiliki akses".
    if (!$isVoucher && $product['access_duration_days'] === null && hasAccess($userId, $productId)) {
        if ($isGatewayFlow) {
            echo json_encode(['error' => 'Kamu sudah memiliki akses ke produk ini.']);
            exit;
        }
        flash('success', 'Kamu sudah memiliki akses ke produk ini.');
        redirect('/member');
    }

    // Simpan/update nomor WA pembeli ke profil (dipakai lintas order & login OTP).
    try {
        $pdo->prepare("UPDATE users SET phone = ? WHERE id = ?")->execute([$buyerPhone, $userId]);
    } catch (Exception $e) {
        error_log('Checkout save buyer phone error: ' . $e->getMessage());
    }

    $price    = (float) $product['price'];

    // ─── Bayar Fleksibel: nominal ditentukan pembeli — VALIDASI SERVER-SIDE WAJIB ──
    $isFlexible = !$isVoucher && ($product['pricing_type'] ?? 'fixed') === 'flexible';
    if ($isFlexible) {
        $flexFloor = max((float) ($product['flexible_min'] ?? 0), 1); // Fase 1: nominal > 0
        $flexAmt   = (float) preg_replace('/[^0-9]/', '', (string) ($_POST['flexible_amount'] ?? '0')); // digit saja (rupiah bulat)
        if ($flexAmt < $flexFloor) {
            if ($isGatewayFlow) {
                echo json_encode(['error' => 'Nominal pembayaran minimal ' . formatRupiah($flexFloor) . '.']);
                exit;
            }
            flash('error', 'Nominal pembayaran minimal ' . formatRupiah($flexFloor) . '.', 'error');
            redirect('/checkout?product_id=' . $productId);
        }
        $price = min($flexAmt, 100000000.0); // cap wajar (anti-typo)
    }

    // ─── Dime Sale: pakai harga TERKUNCI saat checkout dibuka (pembeli bayar yang dilihat).
    // Fallback hitung ulang bila kunci hilang/kedaluwarsa. Dime eksklusif dgn flexible.
    if (!$isVoucher && !$isFlexible && !empty($product['dime_enabled'])) {
        $locked = dimeLockedPrice($productId);
        $price  = $locked !== null ? $locked : dimeEffectivePrice($product);
    }

    $subtotal = $price * $quantity;
    $discount = 0.0;
    // Biaya awal (setup fee) — hanya dibebankan pada pembelian PERTAMA produk ini
    // (0 untuk perpanjangan; user sampai di sini berarti hasAccess sudah false).
    // Produk fleksibel: setup fee tidak berlaku.
    $setupFee = ($isVoucher || $isFlexible) ? 0.0 : productSetupFee($product, $userId);
    $isFree   = ($price + $setupFee) <= 0;

    // ─── GUARD KEAMANAN: produk tanpa harga TIDAK boleh di-checkout gratis ─────
    // Sama seperti jalur guest — produk price<=0 & non-flexible = salah konfig/placeholder
    // migrasi. Tanpa ini, $isFree bikin order auto-'paid' + akses aktif (gratis). Gratis
    // SAH (kupon 100% / flexible) tetap lolos.
    if (orderProductNotSellableFree($product)) {
        error_log('[checkout-guard] tolak order gratis produk price<=0 (login): product_id=' . $productId . ' name=' . ($product['name'] ?? ''));
        if (function_exists('logActivity')) logActivity('system', 'checkout.blocked_zero_price', 'product', (int) $productId, 'Checkout Rp0 ditolak (login) — produk tanpa harga; kemungkinan migrasi/salah konfig');
        flash('error', 'Produk ini belum tersedia untuk dibeli. Silakan hubungi admin.', 'error');
        redirect('/products');
    }

    // ─── Mode Booking Fee (DP) — validasi server-side ─────────
    // Booking: bayar DP dulu (slot + harga terkunci), pelunasan menyusul via
    // order settlement (dibuat otomatis saat DP paid). Tanpa kupon & tanpa bump.
    $isBooking  = false;
    $bookingFee = 0.0;
    if (!$isVoucher && ($_POST['payment_mode'] ?? 'full') === 'booking') {
        $bookingFee = (float) ($product['booking_fee'] ?? 0);
        if ((int) ($product['booking_enabled'] ?? 0) === 1
            && $bookingFee > 0 && $bookingFee < ($price + $setupFee)) {
            $isBooking = true;
        } else {
            $bookingFee = 0.0; // konfigurasi tak valid → fallback bayar penuh
        }
    }

    // Validasi referral code & kaitkan afiliasi
    $affRes      = resolveAffiliateForCheckout($pdo, $userId, $refCode, $productId);
    $affiliateId = $affRes['affiliate_id'];
    // referral_code yang DISIMPAN = keputusan resolver (null bila tak teratribusi:
    // self-referral, afiliat nonaktif, atau General Cookie mati). JANGAN memakai
    // kode ambient mentah — bila null tapi kode lama tetap tersimpan, jalur webhook
    // onOrderPaid(commission_fallback) akan membuat komisi dari kode itu (kebocoran).
    $refCode = $affRes['affiliate_code'];

    // Bind pembeli ke afiliat ini untuk transaksi mendatang (jika belum terikat)
    if ($affiliateId !== null && $userId > 0) {
        bindCustomerToAffiliate($pdo, $userId, $affiliateId);
    }

    // ─── Validasi & Hitung Kupon (server-side) ───────────────
    $couponId   = null;
    $couponCode = null;
    $couponDiscount = 0.0;

    if (!$isVoucher && !$isFree && !$isBooking && !$isFlexible) {
        $rawCouponId  = (int) ($_POST['coupon_id'] ?? 0);
        if ($rawCouponId > 0) {
            $cStmt = $pdo->prepare(
                "SELECT * FROM coupons WHERE id = ? AND status = 'active' LIMIT 1"
            );
            $cStmt->execute([$rawCouponId]);
            $coupon = $cStmt->fetch();

            // Validasi field bersama (helpers/order-service.php) + pre-check 1-user-1x
            // KHUSUS jalur login: kupon yang sudah dipakai user di-drop diam → order
            // tetap lanjut tanpa diskon (beda dgn jalur guest yg menolak di anti-race).
            if ($coupon && orderCouponFieldsValid($coupon, $subtotal, $productId)) {
                $uChk = $pdo->prepare('SELECT id FROM coupon_usages WHERE coupon_id = ? AND user_id = ? LIMIT 1');
                $uChk->execute([$coupon['id'], $userId]);
                if (!$uChk->fetch()) {
                    $couponDiscount = orderCouponDiscount($coupon, $subtotal);
                    $couponId   = (int) $coupon['id'];
                    $couponCode = $coupon['code'];
                }
            }
        }
    }

    $discount = $couponDiscount;
    // Biaya awal ditambahkan SETELAH diskon (kupon tak mendiskon biaya awal).
    $total    = max(0, $subtotal - $discount) + $setupFee;
    $isFree   = ($total <= 0);

    // Booking: yang ditagih sekarang = DP saja. subtotal & setup_fee tetap harga
    // penuh (TERKUNCI) sebagai dasar hitung pelunasan; sisa = subtotal + setup_fee - DP.
    if ($isBooking) {
        $discount = 0.0;
        $total    = $bookingFee;
        $isFree   = false;
    }

    // ─── Transaksi DB ─────────────────────────────────────────
    $pdo->beginTransaction();

    // ── Anti-race kupon ───────────────────────────────────────
    // Kunci baris kupon (FOR UPDATE) lalu re-validasi limit & pemakaian per-user
    // DI DALAM lock. Checkout kupon yang sama jadi terserialisasi → usage_limit
    // tak bisa terlampaui & kupon tak bisa dipakai 2x oleh user yang sama via
    // request paralel. Lock dilepas saat commit (transaksi pendek, semua tulis DB).
    if ($couponId !== null && !orderCouponAntiRaceValid($pdo, $couponId, $userId)) {
        $pdo->rollBack();
        flash('coupon_error', 'Kupon tidak berlaku lagi (kuota habis atau sudah pernah kamu pakai). Silakan checkout tanpa kupon.', 'error');
        redirect('/checkout?product_id=' . $productId);
    }

    // ── Poin Reward: tukar poin jadi potongan (DI DALAM transaksi) ──────────
    // Saldo DIKUNCI (FOR UPDATE) lalu rencana potongan dihitung ULANG dari saldo
    // terkunci — angka dari layar tidak dipercaya, dan 2 tab checkout tak bisa
    // memakai poin yang sama. Potongan dibatasi persen subtotal (pointsPlanRedeem)
    // sehingga total TIDAK PERNAH jatuh ke Rp 0 lewat poin (jalur "gratis" tetap
    // tertutup — lihat guard checkout Rp0 di atas).
    $pointsUsed     = 0;
    $pointsDiscount = 0.0;
    if (!$isVoucher && ($_POST['use_points'] ?? '') === '1'
        && function_exists('pointsEnabled') && pointsEnabled()
        && !$isFree && !$isBooking && !$isFlexible) {
        try {
            $pLock = $pdo->prepare('SELECT points_balance FROM users WHERE id = ? FOR UPDATE');
            $pLock->execute([$userId]);
            $pBal  = (int) $pLock->fetchColumn();
            $pPlan = pointsPlanRedeem($pBal, $subtotal, $couponDiscount);
            $pointsUsed     = (int) $pPlan['points'];
            $pointsDiscount = (float) $pPlan['discount'];
        } catch (Throwable $e) {
            // Kolom/tabel poin belum ada (pra-migrasi) → checkout jalan tanpa poin.
            error_log('checkout points plan: ' . $e->getMessage());
            $pointsUsed = 0; $pointsDiscount = 0.0;
        }
    }
    if ($pointsUsed > 0) {
        $total  = max(0, $subtotal - $discount - $pointsDiscount) + $setupFee;
        $isFree = ($total <= 0);
    }

    // Generate order number unik
    do {
        $orderNumber = generateOrderNumber();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE order_number = ?');
        $dup->execute([$orderNumber]);
    } while ((int) $dup->fetchColumn() > 0);

    // Manual Transfer: pastikan rekening tujuan valid & aktif (defense-in-depth; JS sudah validasi).
    if ($isManualTransfer && !$isFree) {
        if ($bankAccountId < 1) {
            flash('error', 'Pilih rekening tujuan transfer terlebih dahulu.', 'error');
            redirect('/checkout');
        }
        $baStmt = $pdo->prepare("SELECT id FROM bank_accounts WHERE id = ? AND is_active = 1 LIMIT 1");
        $baStmt->execute([$bankAccountId]);
        if (!$baStmt->fetch()) {
            flash('error', 'Rekening tujuan tidak valid.', 'error');
            redirect('/checkout');
        }
    }

    $initialStatus = $isFree ? 'paid' : 'pending';
    $paidAt        = $isFree ? date('Y-m-d H:i:s') : null;

    // INSERT order
    $stmt = $pdo->prepare(
        'INSERT INTO orders (order_number, user_id, subtotal, discount, setup_fee, total, status, notes, paid_at, referral_code, coupon_id, coupon_code, order_stage, booking_fee)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $orderNumber, $userId, $subtotal, $discount, $setupFee, $total,
        $initialStatus, $notes ?: null, $paidAt, $refCode ?: null,
        $couponId, $couponCode,
        $isBooking ? 'booking' : 'full', $isBooking ? $bookingFee : null,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    // Poin Reward: potong saldo (ledger) + simpan jejak di order. Masih di dalam
    // transaksi yang sama dengan pembuatan order → atomik: order & pemotongan poin
    // jadi/batal bersama. Kolom points_* hanya disentuh saat poin BENAR-BENAR
    // dipakai, supaya instalasi yang belum menjalankan migrasi tak terganggu.
    if ($pointsUsed > 0) {
        if (pointsRecord($pdo, $userId, $orderId, 'redeem', -$pointsUsed, 'Potongan ' . $orderNumber)) {
            try {
                $pdo->prepare('UPDATE orders SET points_used = ?, points_discount = ? WHERE id = ?')
                    ->execute([$pointsUsed, $pointsDiscount, $orderId]);
            } catch (Throwable $e) { error_log('checkout points columns: ' . $e->getMessage()); }
        } else {
            // Gagal memotong (saldo berubah / duplikat) → batalkan potongannya agar
            // pembeli tidak dapat diskon tanpa poin berkurang.
            $pointsUsed = 0; $pointsDiscount = 0.0;
            $total = max(0, $subtotal - $discount) + $setupFee;
            $pdo->prepare('UPDATE orders SET total = ? WHERE id = ?')->execute([$total, $orderId]);
        }
    }

    // Fase 2 Bayar Fleksibel: simpan pesan + opsi anonim pendukung (produk flexible).
    // Field hanya dirender di checkout produk yang tampil di Halaman Dukung; capture
    // di sini aman krn produk non-support tak mengirim field ini (POST kosong).
    if ($isFlexible) {
        $supMsg  = trim(strip_tags((string) ($_POST['supporter_message'] ?? '')));
        $supMsg  = $supMsg !== '' ? mb_substr($supMsg, 0, 280) : null;
        $supAnon = (isset($_POST['supporter_anonymous']) && $_POST['supporter_anonymous'] === '1') ? 1 : 0;
        try {
            $pdo->prepare("UPDATE orders SET supporter_message = ?, supporter_anonymous = ? WHERE id = ?")
                ->execute([$supMsg, $supAnon, $orderId]);
        } catch (Throwable $e) { error_log('supporter fields (login checkout): ' . $e->getMessage()); }
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
    } catch (Throwable $e) { error_log('tracking_meta (login checkout): ' . $e->getMessage()); }

    // Mailketing: antre pembeli ke list "Order" (order dibuat). Fail-soft.
    mailketing_enqueue_order_created($userId);

    // INSERT order item — snapshot nama produk saat transaksi
    $stmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, price, quantity)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $orderId, $productId,
        $product['name'] . ($isBooking ? ' — Booking Fee' : ''),
        $isBooking ? $bookingFee : $price, $quantity,
    ]);

    // ─── Process Order Bumps (server-side re-validation) ─────
    // Logika bersama dgn jalur guest → helpers/order-service.php. Booking/Fleksibel/
    // GRATIS: bump diabaikan (DP hanya produk utama; flexible tanpa bump; produk gratis
    // tak proses bump agar bump berbayar tak lolos "lunas" tanpa bayar).
    $bumpRes             = orderProcessBumps(
        $pdo, $orderId, $productId, $userId,
        (array) ($_POST['bump_ids'] ?? []),
        ($isVoucher || $isBooking || $isFlexible || $isFree)
    );
    $validBumpProductIds = $bumpRes['ids'];
    $bumpTotal           = $bumpRes['total'];

    // ─── Bonus Kilat (produk premium GRATIS berbatas waktu) ──
    // Logika bersama dgn jalur guest → helpers/order-service.php. Bonus valid disuntik
    // ke order_items sbg is_bump harga 0 → ter-grant otomatis oleh onOrderPaid (paid)
    // atau blok $isFree di bawah. Nonaktif utk booking & flexible.
    $validFlashBonusIds = orderProcessFlashBonuses(
        $pdo, $orderId, $productId, $userId,
        (array) ($_POST['flash_bonus_ids'] ?? []),
        $validBumpProductIds,
        ($isVoucher || $isBooking || $isFlexible)
    );

    $finalTotal = $total + $bumpTotal;

    // ─── Moota: tambah kode unik nominal + tandai gateway agar webhook auto-match ───
    if ($isMoota && !$isFree && $finalTotal > 0) {
        require_once __DIR__ . '/../../payment_gateways/MootaGateway.php';
        $u          = (new MootaGateway())->generateUniqueCode($finalTotal);
        $finalTotal = $u['total_amount']; // base + kode unik
        $pdo->prepare("UPDATE orders SET total = ?, payment_gateway = 'moota' WHERE id = ?")
            ->execute([$finalTotal, $orderId]);
    }
    // ─── Pembayaran Manual (transfer bank / manual / QR): kode unik nominal ───
    // Non-Moota & non-gateway otomatis = jalur manual. Kode unik memudahkan
    // penjual mencocokkan mutasi. Digerbang setting manual_unique_code_enabled.
    elseif (!$isGatewayFlow && !$isFree && $finalTotal > 0) {
        $withCode = applyManualUniqueCode($pdo, (float) $finalTotal);
        if ($withCode != $finalTotal) {
            $finalTotal = $withCode;
            $pdo->prepare("UPDATE orders SET total = ? WHERE id = ?")->execute([$finalTotal, $orderId]);
        }
    }

    // INSERT payment record (pakai finalTotal setelah bump + kode unik Moota)
    $paymentStatus = $isFree ? 'success' : 'pending';
    $paymentPaidAt = $isFree ? date('Y-m-d H:i:s') : null;
    if ($isMoota)                 $paymentMethod = 'moota';
    elseif ($isGatewayFlow)       $paymentMethod = $gatewayMethod; // 'xendit'/'duitku'
    elseif ($isManualTransfer && !$isFree) $paymentMethod = 'manual_transfer';
    else                          $paymentMethod = 'manual';
    // Rekening opsional bila pembeli bayar via QR Pay (tanpa memilih rekening) →
    // simpan NULL, jangan 0 (0 bukan FK valid di bank_accounts).
    $payBankId = ($paymentMethod === 'manual_transfer' && $bankAccountId > 0) ? $bankAccountId : null;
    $stmt = $pdo->prepare(
        'INSERT INTO payments (order_id, method, amount, status, paid_at, bank_account_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$orderId, $paymentMethod, $finalTotal, $paymentStatus, $paymentPaidAt, $payBankId]);

    // Jika gratis/free: aktivasi akses langsung (main + bumps)
    if ($isFree) {
        bumpDimeCounter($pdo, $userId, $productId); // dime: hitung sale sebelum aktivasi
        activateAccess($userId, $productId, $orderId);
        enrollUserToSequences('order_paid', $userId, $productId, $orderNumber);
        issueLicense($orderId, $productId, $userId); // license key jika produk license_enabled
        foreach ($validBumpProductIds as $bpId) {
            activateAccess($userId, $bpId, $orderId);
            issueLicense($orderId, $bpId, $userId);
        }
        // Bonus Kilat (GRATIS): aktifkan aksesnya sekalian saat order langsung paid.
        foreach ($validFlashBonusIds as $fbId) {
            activateAccess($userId, $fbId, $orderId);
            issueLicense($orderId, $fbId, $userId);
        }
    }

    // Catat komisi afiliasi + pemakaian kupon (logika bersama → helpers/order-service.php,
    // atomik dalam transaksi ini).
    orderRecordCommission($pdo, $affiliateId, $orderId, $productId, (float) $finalTotal, $isFree);
    orderRecordCouponUsage($pdo, $couponId, $userId, $orderId, (float) $couponDiscount);

    $pdo->commit();

    // ─── Update membership level jika order langsung paid (produk gratis) ───
    if ($isFree) {
        updateUserMembershipLevel($userId);
    }

    // Snapshot data notifikasi SEBELUM session ditutup oleh lsRespondThenContinue.
    $notifName  = $_SESSION['name']  ?? '';
    $notifEmail = $_SESSION['email'] ?? '';

    logActivity('user', 'order.create', 'order', $orderId, 'Order dibuat: ' . $orderNumber);

    // Bersihkan ref_code dari session setelah dipakai
    unset($_SESSION['ref_code']);

    if ($isFree) {
        flash('success', 'Selamat! Kamu sekarang memiliki akses ke "' . $product['name'] . '".');
    } elseif ($isMoota) {
        flash('success', 'Order dibuat. Transfer TEPAT sebesar ' . formatRupiah($finalTotal) . ' sesuai instruksi di bawah — pembayaran terkonfirmasi otomatis.');
    } else {
        flash('success', 'Order berhasil dibuat. Selesaikan pembayaran untuk mengaktifkan akses.');
    }

    // ─── Balas browser SEKARANG — buyer langsung pindah ke invoice ─────────
    // Notifikasi email/WA (HTTP eksternal, bisa lambat) dikirim SETELAH ini di
    // background proses yang sama. Sebelumnya notifikasi sinkron menahan
    // redirect invoice beberapa detik (paling terasa di moota).
    if ($isGatewayFlow) {
        lsRespondThenContinue(null, json_encode(['order_id' => $orderId, 'order_number' => $orderNumber]));
    } else {
        lsRespondThenContinue('/transactions/' . $orderNumber);
    }

    // ─── Kerja background: trigger notifikasi ──────────────────────────────
    try {
        require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';

        // Nomor WA pembeli untuk notifikasi WhatsApp — sudah divalidasi & disimpan
        // di atas ($buyerPhone), di-normalisasi lebih lanjut oleh adapter WA.
        $paymentMethodLabel = match ($paymentMethod ?? 'manual') {
            'moota'                => 'Transfer Bank (Otomatis)',
            'xendit', 'duitku', 'midtrans', 'tripay' => 'Pembayaran Online',
            'paypal'               => 'PayPal',
            'manual_transfer'      => 'Transfer Manual',
            default                => 'Transfer Manual',
        };
        $invoiceUrl = baseUrl('transactions/' . $orderNumber);
        $memberUrl  = baseUrl('member');

        NotificationDispatcher::trigger('order_created', [
            'name'           => $notifName,
            'email'          => $notifEmail,
            'order_number'   => $orderNumber,
            'product_name'   => (string) $product['name'],
            'total'          => formatRupiah($finalTotal),
            'payment_method' => $paymentMethodLabel,
            'invoice_url'    => $invoiceUrl,
        ], $notifEmail, $buyerPhone);

        if ($isFree) {
            NotificationDispatcher::trigger('payment_success', [
                'name'         => $notifName,
                'email'        => $notifEmail,
                'order_number' => $orderNumber,
                'total'        => formatRupiah($finalTotal),
                'paid_at'      => date('d M Y, H:i', strtotime($paidAt ?? date('Y-m-d H:i:s'))),
                'invoice_url'  => $invoiceUrl,
                'member_url'   => $memberUrl,
            ], $notifEmail, $buyerPhone);

            NotificationDispatcher::trigger('access_activated', [
                'name'         => $notifName,
                'email'        => $notifEmail,
                'product_name' => $product['name'],
                'order_number' => $orderNumber,
                'member_url'   => $memberUrl,
                'invoice_url'  => $invoiceUrl,
            ], $notifEmail, $buyerPhone);
        }
    } catch (Exception $e) {
        error_log('Checkout notification trigger error: ' . $e->getMessage());
    }

    if (defined('BYPASS_LOGIN_REQUIRED') || defined('TESTING')) {
        // Jangan exit di testing agar sisa script bisa dilanjutkan
    } else {
        exit;
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Checkout process error: ' . $e->getMessage());
    if ($isGatewayFlow) {
        echo json_encode(['error' => 'Terjadi kesalahan saat memproses order.']);
        exit;
    }
    flash('error', 'Terjadi kesalahan saat memproses order. Silakan coba lagi.', 'error');
    redirect('/checkout?product_id=' . $productId);
}

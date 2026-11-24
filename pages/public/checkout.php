<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

// ─── Load produk ──────────────────────────────────────────────
$productId = (int) ($_GET['product_id'] ?? 0);
if ($productId <= 0) {
    flash('error', 'Produk tidak ditemukan.', 'error');
    redirect('/products');
}

$product = null;
try {
    $stmt = getDB()->prepare(
        "SELECT * FROM products WHERE id = ? AND status = 'active' LIMIT 1"
    );
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
} catch (Exception $e) {
    error_log('Checkout load product error: ' . $e->getMessage());
}

if (!$product) {
    flash('error', 'Produk tidak ditemukan atau tidak tersedia.', 'error');
    redirect('/products');
}

$isVoucher = ($product['slug'] ?? '') === 'voucher-tes-stifin';
$voucherQuantity = $isVoucher ? stifinVoucherQuantity($_GET['jumlah'] ?? '1') : 1;
if ($isVoucher && $productId !== stifinVoucherProductId()) {
    flash('error', 'Pembelian voucher belum tersedia.', 'error');
    redirect('/products');
}
if ($isVoucher && !$voucherQuantity) {
    flash('error', 'Masukkan jumlah voucher antara 1 dan 1.000.', 'error');
    redirect('/checkout?product_id=' . $productId);
}
if ($isVoucher && stifinVoucherTransferEnabled() && stifinLinkedPromoterCode(getDB(), (int) $_SESSION['user_id']) === null) {
    flash('error', 'Hubungi admin untuk menautkan akun promotor STIFIn sebelum membeli voucher.', 'error');
    redirect('/member/voucher-tes');
}
if ($isVoucher) {
    $product['setup_fee'] = 0;
    $product['dime_enabled'] = 0;
    $product['booking_enabled'] = 0;
    $product['pricing_type'] = 'fixed';
    $product['original_price'] = 0;
    $product['show_coupon'] = 0;
}

// GUARD: produk tanpa harga (price<=0, non-flexible) tak boleh di-checkout — cegah akses
// gratis produk berbayar (placeholder migrasi / salah konfig). Selaras guard process.php.
if (orderProductNotSellableFree($product)) {
    flash('error', 'Produk ini belum tersedia untuk dibeli. Silakan hubungi admin.', 'error');
    redirect('/products');
}

// ─── Sudah punya akses? ───────────────────────────────────────
// Untuk produk BERLANGGANAN, "sudah punya akses" BUKAN alasan menolak — justru
// begitulah perpanjangan bekerja: reminder H-7/H-3/H-1 dikirim saat akses MASIH
// AKTIF. Guard lama menendang mereka ke /member, jadi tombol "Perpanjang Akses"
// di email/WA selalu berujung buntu; satu-satunya saat link itu jalan adalah
// setelah akses telanjur mati. Produk sekali-bayar tetap ditolak (beli 2x sia-sia).
$isRenewal       = false;
$renewCurrentExp = null;
if (!$isVoucher && hasAccess((int) $_SESSION['user_id'], $productId)) {
    if ($product['access_duration_days'] !== null) {
        $isRenewal = true;
        try {
            $rStmt = getDB()->prepare(
                "SELECT expires_at FROM member_access
                 WHERE user_id = ? AND product_id = ? AND status = 'active' LIMIT 1"
            );
            $rStmt->execute([(int) $_SESSION['user_id'], $productId]);
            $renewCurrentExp = $rStmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            error_log('Checkout renewal expiry error: ' . $e->getMessage());
        }
    } else {
        flash('success', 'Kamu sudah memiliki akses ke produk ini.');
        redirect('/member');
    }
}

// ─── Funnel tracking: checkout_click ─────────────────────────
require_once __DIR__ . '/../../actions/public/track-checkout-click.php';
trackCheckoutClick($productId);
trackRotatorCheckoutClick(); // atribusi per-varian Canvas Rotator (Split Test)

// ─── Referral code ────────────────────────────────────────────
// Hanya tampilkan/prefill saat atribusi ambient aktif (program afiliasi hidup &
// General Cookie nyala) — konsisten dgn resolveAffiliateForCheckout. Saat mati,
// jangan prefill hidden field referral_code (badge pun tak muncul) supaya UI tak
// menjanjikan atribusi yang tak akan dicatat.
$refCode = affiliateAmbientEnabled()
    ? strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($_SESSION['ref_code'] ?? '')))
    : '';
// Nama affiliate pemilik kode (untuk ditampilkan di form — biar affiliate yakin
// referral tercatat). Fail-soft: kode tak dikenal → nama kosong (badge tampil kode).
$refAffiliateName = '';
if ($refCode !== '') {
    try {
        $rq = getDB()->prepare('SELECT u.name FROM affiliates a JOIN users u ON u.id = a.user_id WHERE a.code = ? LIMIT 1');
        $rq->execute([$refCode]);
        $refAffiliateName = (string) ($rq->fetchColumn() ?: '');
    } catch (Throwable $e) { $refAffiliateName = ''; }
}

// ─── Cek gateway aktif ────────────────────────────────────────
$duitkuActive   = false;
$xenditActive   = false;
$mootaActive    = false;
$paypalActive   = false;
$midtransActive = false;
$tripayActive   = false;
$tripayChannels = [];
$paypalRate    = 0.0;   // IDR per 1 USD (kurs admin)
$mootaBankInfo = '';
try {
    $gwRows = getDB()->query(
        "SELECT provider, is_active, config_json FROM payment_gateways WHERE provider IN ('duitku','xendit','moota','paypal','midtrans','tripay')"
    )->fetchAll();
    foreach ($gwRows as $gwRow) {
        if ($gwRow['provider'] === 'duitku')   $duitkuActive   = (bool) $gwRow['is_active'];
        if ($gwRow['provider'] === 'xendit')   $xenditActive   = (bool) $gwRow['is_active'];
        if ($gwRow['provider'] === 'midtrans') $midtransActive = (bool) $gwRow['is_active'];
        if ($gwRow['provider'] === 'tripay')   $tripayActive   = (bool) $gwRow['is_active'];
        if ($gwRow['provider'] === 'paypal') {
            $cfg        = json_decode($gwRow['config_json'] ?? '', true) ?: [];
            $paypalRate = (float) ($cfg['idr_usd_rate'] ?? 0);
            // PayPal hanya ditawarkan jika aktif DAN kurs sudah diisi (tanpa kurs, tak bisa nagih USD).
            $paypalActive = ((bool) $gwRow['is_active']) && $paypalRate > 0;
        }
        if ($gwRow['provider'] === 'moota') {
            $cfg           = json_decode($gwRow['config_json'] ?? '', true) ?: [];
            $mootaBankInfo = trim($cfg['bank_info'] ?? '');
            // Moota hanya ditawarkan jika aktif DAN info rekening sudah diisi admin.
            $mootaActive   = ((bool) $gwRow['is_active']) && $mootaBankInfo !== '';
        }
    }
} catch (Exception $e) {
    error_log('Checkout: cek gateway error: ' . $e->getMessage());
}
// Tripay: ambil daftar channel (di-cache 1 jam) untuk ditampilkan sebagai pilihan.
if ($tripayActive) {
    try {
        require_once __DIR__ . '/../../payment_gateways/TripayGateway.php';
        $tripayChannels = (new TripayGateway())->getChannels();
        if (empty($tripayChannels)) $tripayActive = false; // tak ada channel → sembunyikan
    } catch (Exception $e) { $tripayActive = false; error_log('Checkout: tripay channels error: ' . $e->getMessage()); }
}
$hasGateway = $duitkuActive || $xenditActive || $mootaActive || $paypalActive || $midtransActive || $tripayActive;

// ─── Manual Transfer (multi rekening) ─────────────────────────
$manualTransferEnabled = getSetting('manual_transfer_enabled', '1') === '1';
$bankAccounts = [];
if ($manualTransferEnabled) {
    try {
        $bankAccounts = getDB()->query(
            "SELECT id, bank_name, channel_type, account_number, account_name, instructions FROM bank_accounts WHERE is_active = 1 ORDER BY channel_type ASC, sort_order ASC, id ASC"
        )->fetchAll();
    } catch (Exception $e) {
        error_log('Checkout: load bank accounts error: ' . $e->getMessage());
    }
}
// Detail Pembayaran Manual: QR Pay + instruksi global (opsional).
$manualQrImage           = $manualTransferEnabled ? getSetting('manual_qr_image') : null;
$manualPaymentInstruction = getSetting('manual_payment_instruction',
    'Boleh bayar QR Pay atau manual transfer. Lepas bayar, screenshot resit & WhatsApp untuk akses segera.');
// Tawarkan Manual Transfer bila aktif DAN ada rekening aktif ATAU QR terpasang.
$manualTransferAvailable = $manualTransferEnabled && (count($bankAccounts) > 0 || !empty($manualQrImage));

$pageTitle   = 'Checkout — ' . $product['name'];
$topbarTitle = 'Checkout';

$price     = (float) $product['price'] * $voucherQuantity;
$origPrice = (float) $product['original_price'];
$discount  = ($origPrice > $price) ? ($origPrice - $price) : 0.0;
// Biaya awal (setup fee) — hanya pembelian pertama (user sudah lolos hasAccess di atas).
$setupFee  = productSetupFee($product, (int) $_SESSION['user_id']);
$isFree    = ($price + $setupFee) <= 0;

// ─── Bayar Fleksibel (pembeli tentukan nominal di checkout) ──
$isFlexible  = ($product['pricing_type'] ?? 'fixed') === 'flexible';
$flexLabel   = '';
$flexMin     = 0.0;
$flexPresets = [];
if ($isFlexible) {
    $flexLabel = trim((string) ($product['flexible_label'] ?? '')) ?: 'Bayar Seikhlasnya';
    $flexMin   = (float) ($product['flexible_min'] ?? 0);
    if (!empty($product['flexible_presets'])) {
        $flexPresets = array_values(array_filter(
            array_map('intval', explode(',', (string) $product['flexible_presets'])),
            fn($n) => $n > 0
        ));
    }
    // Harga dinamis: base 0, tanpa setup/diskon. Nominal final divalidasi server.
    $price = 0.0; $origPrice = 0.0; $discount = 0.0; $setupFee = 0.0;
    $isFree = false; // selalu ada pembayaran (Fase 1 nominal > 0)
}
// ─── Dime Sale: harga efektif saat ini + KUNCI harga di sesi (pembeli bayar yg dilihat) ──
$isDime = !$isFlexible && !empty($product['dime_enabled']);
if ($isDime) {
    $price     = dimeEffectivePrice($product);
    $origPrice = $price; $discount = 0.0; // dime: tanpa harga coret
    $isFree    = ($price + $setupFee) <= 0;
    dimeLockPrice((int) $product['id'], $price);
}
// Fase 2: produk yang tampil di Halaman Dukung → checkout tampilkan pesan + opsi anonim.
$flexSupport = $isFlexible && (int) ($product['show_on_support'] ?? 0) === 1;
// Harga alias RM (display-only) — badge di bawah Total bila di-set & bukan produk fleksibel.
$pMyr = (!$isFlexible && ($product['price_myr'] ?? null) !== null && (float) $product['price_myr'] > 0) ? (float) $product['price_myr'] : null;

// ─── Bump offers aktif untuk produk ini ──────────────────────
$bumpOffers = [];
try {
    $bStmt = getDB()->prepare(
        "SELECT pb.id AS bump_config_id, pb.bump_price, pb.headline, pb.description,
                p.id AS bump_product_id, p.name AS bump_name,
                p.price AS bump_normal_price, p.thumbnail AS bump_thumbnail
         FROM product_bumps pb
         JOIN products p ON pb.bump_product_id = p.id
         WHERE pb.main_product_id = ? AND pb.status = 'active' AND p.status = 'active'
         ORDER BY pb.sort_order ASC"
    );
    $bStmt->execute([$productId]);
    $allBumps = $bStmt->fetchAll();
    // Exclude bumps user sudah punya akses
    foreach ($allBumps as $b) {
        if (!hasAccess((int)$_SESSION['user_id'], (int)$b['bump_product_id'])) {
            $bumpOffers[] = $b;
        }
    }
} catch (Exception $e) {
    error_log('Checkout bump load error: ' . $e->getMessage());
}
if ($isFlexible) { $bumpOffers = []; } // bump nonaktif utk produk fleksibel

// ─── Bonus Kilat (produk premium GRATIS berbatas waktu) ──────
// Timer per-kunjungan di-anchor via cookie (fb_start_{productId}); server memvalidasi
// ulang saat submit. Bonus terpilih disuntik ke order_items is_bump harga 0 → ter-grant
// otomatis oleh onOrderPaid. Nonaktif utk produk fleksibel (seperti bump).
$fbOffers    = [];
$fbLabel     = 'Bonus Gratis Hari Ini';
$fbMaxSelect = 1;
$fbRemaining = 0;
$fbExpired   = false;
if (!$isFlexible) {
    try {
        $fsStmt = getDB()->prepare("SELECT enabled, duration_minutes, max_select, label FROM flash_bonus_settings WHERE main_product_id = ? LIMIT 1");
        $fsStmt->execute([$productId]);
        $fbSet = $fsStmt->fetch();
        // Belum ada baris setting → default aktif (blok tetap tampil bila ada offer).
        $fbEnabled  = $fbSet ? ((int)$fbSet['enabled'] === 1) : true;
        $fbDuration = $fbSet ? max(1, (int)$fbSet['duration_minutes']) : 60;
        $fbMaxSelect = $fbSet ? max(1, (int)$fbSet['max_select']) : 1;
        $fbLabel    = $fbSet ? (string)$fbSet['label'] : 'Bonus Gratis Hari Ini';

        if ($fbEnabled) {
            $foStmt = getDB()->prepare(
                "SELECT fb.headline, fb.description,
                        p.id AS bonus_product_id, p.name AS bonus_name,
                        p.price AS bonus_normal_price, p.thumbnail AS bonus_thumbnail
                 FROM flash_bonus_offers fb
                 JOIN products p ON fb.bonus_product_id = p.id
                 WHERE fb.main_product_id = ? AND fb.status = 'active' AND p.status = 'active'
                 ORDER BY fb.sort_order ASC"
            );
            $foStmt->execute([$productId]);
            foreach ($foStmt->fetchAll() as $fb) {
                // Sembunyikan bonus yang user sudah punya aksesnya.
                if (!hasAccess((int)$_SESSION['user_id'], (int)$fb['bonus_product_id'])) {
                    $fbOffers[] = $fb;
                }
            }
            if (!empty($fbOffers)) {
                $ckName  = 'fb_start_' . $productId;
                $startTs = isset($_COOKIE[$ckName]) ? (int)$_COOKIE[$ckName] : 0;
                if ($startTs <= 0) {
                    $startTs = time();
                    setcookie($ckName, (string)$startTs, [
                        'expires'  => time() + $fbDuration * 60,
                        'path'     => '/',
                        'samesite' => 'Lax',
                    ]);
                    $_COOKIE[$ckName] = (string)$startTs; // konsisten dalam request ini
                }
                $fbRemaining = max(0, ($fbDuration * 60) - (time() - $startTs));
                $fbExpired   = $fbRemaining <= 0;
            }
        }
    } catch (Exception $e) {
        error_log('Checkout flash bonus load error: ' . $e->getMessage());
        $fbOffers = [];
    }
}

// Kupon dari session flash (jika validasi gagal dan balik ke halaman ini)
$couponError = getFlash('coupon_error');

// ─── Load checkout template ───────────────────────────────────
$_tpl = null;
try {
    $pdo = getDB();
    if (!empty($product['checkout_template_id'])) {
        $tplStmt = $pdo->prepare("SELECT * FROM checkout_templates WHERE id = ? LIMIT 1");
        $tplStmt->execute([$product['checkout_template_id']]);
        $_tpl = $tplStmt->fetch();
    }
    if (!$_tpl) {
        $tplStmt = $pdo->prepare("SELECT * FROM checkout_templates WHERE is_default = 1 LIMIT 1");
        $tplStmt->execute([]);
        $_tpl = $tplStmt->fetch();
    }
} catch (Exception $e) {
    error_log('Checkout: load template error: ' . $e->getMessage());
}

$_allowedTplTypes = ['standard', 'minimal', 'two_step', 'sidebar_sticky'];
$tplType        = in_array($_tpl['layout_type'] ?? '', $_allowedTplTypes, true) ? $_tpl['layout_type'] : 'standard';
// Nomor WhatsApp kini WAJIB di semua checkout login (dipakai untuk notifikasi WA
// & login OTP). Field selalu tampil — toggle show_phone template diabaikan.
$tplShowPhone   = true;
$tplShowAddress = (bool)(($_tpl['show_address'] ?? 0));
$tplShowNotes   = (bool)(($_tpl['show_notes']   ?? 1));
// Toggle card kupon kini per-PRODUK (kolom products.show_coupon), bukan per template.
// Pra-migrasi / kolom belum ada → default tampil (perilaku lama).
$tplShowCoupon  = (bool)(($product['show_coupon'] ?? 1));
// Footer badge "Powered by" opsional (white-label)
$tplFooterBadge     = (bool)  (($_tpl['footer_badge_enabled'] ?? 0));
$tplFooterBadgeText = (string) ($_tpl['footer_badge_text'] ?? '');
$tplFooterBadgeIcon = (string) ($_tpl['footer_badge_icon'] ?? '');
$tplFooterBadgeUrl  = (string) ($_tpl['footer_badge_url']  ?? '');

// Prefill nomor WA dari yang sudah tersimpan (mis. dari public checkout / profil).
$buyerPhone = '';
try {
    $phStmt = getDB()->prepare("SELECT phone FROM users WHERE id = ? LIMIT 1");
    $phStmt->execute([(int) $_SESSION['user_id']]);
    $buyerPhone = (string) ($phStmt->fetchColumn() ?: '');
} catch (Exception $e) {
    error_log('Checkout prefill phone error: ' . $e->getMessage());
}
$_tplFile       = __DIR__ . '/../../checkout_templates/' . $tplType . '.php';
if (!file_exists($_tplFile)) $_tplFile = __DIR__ . '/../../checkout_templates/standard.php';

generateCSRF();
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<?php include __DIR__ . '/../../checkout_templates/_partials/checkout-ui.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <?php if ($isRenewal): ?>
      <?php
      // Tanggal berlaku baru = tanggal habis + durasi (bukan hari ini + durasi) —
      // rumus yang sama dgn activateAccess(), supaya angka di layar = yang benar
      // terjadi setelah bayar.
      $_days    = (int) $product['access_duration_days'];
      $_baseTs  = $renewCurrentExp ? max(strtotime($renewCurrentExp), time()) : time();
      $_newExp  = date('d M Y', strtotime('+' . $_days . ' days', $_baseTs));
      $_curExp  = $renewCurrentExp ? date('d M Y', strtotime($renewCurrentExp)) : null;
      ?>
      <div class="max-w-5xl mx-auto mb-4">
        <div class="flex items-start gap-3 p-4 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm">
          <div class="w-9 h-9 shrink-0 rounded-lg flex items-center justify-center bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)]">
            <svg class="w-4 h-4 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>
              <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
          </div>
          <div class="min-w-0">
            <p class="text-sm font-semibold text-gray-900 dark:text-white">
              Perpanjang <?= e($product['name']) ?>
            </p>
            <?php if ($_curExp): ?>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
              Berlaku sampai <strong class="text-gray-700 dark:text-gray-200"><?= e($_curExp) ?></strong>
              &rarr; setelah perpanjang jadi
              <strong class="text-[var(--brand-color)]"><?= e($_newExp) ?></strong>
              <span class="text-gray-400">(+<?= e(subscriptionPeriodLabel($_days)) ?>)</span>
            </p>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">
              Sisa harimu tidak hangus — masa aktif ditambahkan dari tanggal berakhir, bukan dari hari ini.
            </p>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($isVoucher): ?>
        <form method="get" action="<?= e(APP_URL) ?>/checkout" class="mb-4 rounded-xl border bg-white dark:bg-gray-900 p-4">
          <input type="hidden" name="product_id" value="<?= $productId ?>">
          <label for="voucher-quantity" class="font-semibold">Jumlah voucher</label>
          <div class="mt-2 flex flex-wrap items-center gap-3">
            <input id="voucher-quantity" class="rounded-lg border px-3 py-2" type="number" name="jumlah"
                   min="1" max="1000" step="1" value="<?= $voucherQuantity ?>" required>
            <button type="submit" class="rounded-lg border px-3 py-2 font-semibold">Perbarui jumlah</button>
          </div>
          <p class="mt-2 text-sm"><?= $voucherQuantity ?> voucher × <?= e(formatRupiah((float) $product['price'])) ?></p>
        </form>
        <input type="hidden" name="voucher_quantity" form="checkout-form" value="<?= $voucherQuantity ?>">
      <?php endif; ?>
      <?php include $_tplFile; ?>

      <div class="max-w-5xl mx-auto">
        <?php include __DIR__ . '/../../checkout_templates/_partials/footer-badge.php'; ?>
      </div>

    </main>
  </div>
</div>
<script>
(function () {
    var basePrice    = <?= $isVoucher ? $price : (float) $product['price'] ?>;
    var appliedCode  = '';
    var couponDisc   = 0;  // live coupon discount amount

    var applyBtn    = document.getElementById('apply-coupon-btn');
    var resultEl    = document.getElementById('coupon-result');
    var couponInput = document.getElementById('coupon-code');
    var totalEl     = document.getElementById('order-total-display');
    var summaryRow  = document.getElementById('coupon-summary-row');
    var discDispEl  = document.getElementById('coupon-discount-display');
    var hiddenId    = document.getElementById('coupon_id_hidden');
    var hiddenAmt   = document.getElementById('discount_amount_hidden');

    // Expose coupon state for bump JS
    window._checkoutCouponDisc = function() { return couponDisc; };

    if (!applyBtn) return;

    applyBtn.addEventListener('click', async function () {
        var code      = couponInput.value.trim();
        var productId = <?= (int) $product['id'] ?>;

        if (!code) {
            resultEl.innerHTML = '<span class="text-red-500">Masukkan kode kupon terlebih dahulu.</span>';
            return;
        }

        if (applyBtn.dataset.applied === '1' && code === appliedCode) {
            resetCoupon();
            return;
        }

        applyBtn.disabled    = true;
        applyBtn.textContent = 'Memvalidasi...';
        resultEl.innerHTML   = '';

        try {
            var res = await fetch('<?= e(APP_URL) ?>/actions/checkout/validate-coupon', {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    'code=' + encodeURIComponent(code) + '&product_id=' + productId,
            });
            var data = await res.json();

            if (data.valid) {
                appliedCode     = code;
                couponDisc      = parseFloat(data.discount) || 0;
                hiddenId.value  = data.coupon_id;
                hiddenAmt.value = data.discount;

                resultEl.innerHTML = '<span class="text-green-600 dark:text-green-400 font-medium">✓ ' + data.message + ' Hemat ' + data.discount_formatted + '</span>';
                if (summaryRow) summaryRow.classList.remove('hidden');
                if (discDispEl) discDispEl.textContent = '-' + data.discount_formatted;

                applyBtn.textContent     = 'Hapus';
                applyBtn.dataset.applied = '1';
                applyBtn.classList.add('bg-red-50', 'dark:bg-red-900/20', 'text-red-600', 'border-red-300', 'dark:border-red-700');
                applyBtn.classList.remove('border-[var(--brand-color)]', 'text-[var(--brand-color)]');
            } else {
                resetCoupon();
                resultEl.innerHTML = '<span class="text-red-500">' + data.message + '</span>';
            }
        } catch (err) {
            resultEl.innerHTML = '<span class="text-red-500">Gagal terhubung. Coba lagi.</span>';
        } finally {
            applyBtn.disabled = false;
            if (applyBtn.dataset.applied !== '1') applyBtn.textContent = 'Terapkan';
        }
        // Recalc total after coupon (bumps still checked)
        document.dispatchEvent(new CustomEvent('averion:coupon-changed')); // batas poin ikut berubah
        if (window._recalcCheckoutTotal) window._recalcCheckoutTotal();
    });

    function resetCoupon() {
        couponDisc      = 0;
        hiddenId.value  = '';
        hiddenAmt.value = '0';
        appliedCode     = '';

        if (summaryRow) summaryRow.classList.add('hidden');
        if (discDispEl) discDispEl.textContent = '-Rp 0';

        applyBtn.textContent     = 'Terapkan';
        applyBtn.dataset.applied = '0';
        applyBtn.classList.remove('bg-red-50', 'dark:bg-red-900/20', 'text-red-600', 'border-red-300', 'dark:border-red-700');
        applyBtn.classList.add('border-[var(--brand-color)]', 'text-[var(--brand-color)]');

        document.dispatchEvent(new CustomEvent('averion:coupon-changed')); // batas poin ikut berubah
        if (window._recalcCheckoutTotal) window._recalcCheckoutTotal();
    }
})();

// ── Duitku Payment Gateway Handler ────────────────────────────
(function () {
    var form      = document.getElementById('checkout-form');
    var submitBtn = document.getElementById('checkout-submit-btn');
    var _origBtnHTML = submitBtn ? submitBtn.innerHTML : ''; // simpan ikon+label asli utk resetBtn
    var errEl     = document.getElementById('gateway-error');

    if (!form) return;

    function injectHidden(f, name, value) {
        var el = f.querySelector('input[name="' + name + '"]');
        if (!el) { el = document.createElement('input'); el.type = 'hidden'; el.name = name; f.appendChild(el); }
        el.value = value;
    }

    // Show/hide selektor rekening saat metode pembayaran berubah.
    document.querySelectorAll('input[name="payment_method"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            var selector = document.getElementById('bank-account-selector');
            if (selector) selector.classList.toggle('hidden', this.value !== 'manual_transfer');
            var tripaySel = document.getElementById('tripay-channels');
            if (tripaySel) tripaySel.classList.toggle('hidden', this.value !== 'tripay');
        });
    });

    form.addEventListener('submit', async function (e) {
        var methodRadio = document.querySelector('input[name="payment_method"]:checked');
        var method      = methodRadio ? methodRadio.value : 'manual';

        // Manual Transfer: submit NORMAL, tapi inject metode + rekening terpilih ke
        // dalam form (radio metode & rekening berada DI LUAR #checkout-form).
        if (method === 'manual_transfer') {
            // Rekening bank bersifat opsional bila hanya QR Pay yang dipasang.
            var hasBankOptions = document.querySelector('input[name="bank_account_id"]') !== null;
            var bankRadio      = document.querySelector('input[name="bank_account_id"]:checked');
            if (hasBankOptions && !bankRadio) {
                e.preventDefault();
                showToast('Pilih rekening tujuan transfer terlebih dahulu.', 'error');
                return;
            }
            injectHidden(form, 'payment_method', 'manual_transfer');
            if (bankRadio) injectHidden(form, 'bank_account_id', bankRadio.value);
            return; // submit normal → process.php (order pending, tunggu upload bukti)
        }

        // Moota: transfer bank auto-konfirmasi → submit NORMAL tapi tandai metodenya
        // agar process.php memberi nominal unik + payment_gateway='moota'.
        if (method === 'moota') {
            var mootaInput = form.querySelector('input[name="payment_gateway_method"]');
            if (!mootaInput) {
                mootaInput = document.createElement('input');
                mootaInput.type = 'hidden';
                mootaInput.name = 'payment_gateway_method';
                form.appendChild(mootaInput);
            }
            mootaInput.value = 'moota';
            return; // form submit normal → process.php → redirect ke /transactions
        }

        var gatewayMethods = ['duitku', 'xendit', 'paypal', 'midtrans', 'tripay'];
        if (!gatewayMethods.includes(method)) return; // submit normal untuk metode manual

        e.preventDefault();

        // Tripay: pembeli wajib pilih channel dulu.
        var tripayChannel = '';
        if (method === 'tripay') {
            var chEl = form.querySelector('input[name="tripay_channel"]:checked');
            tripayChannel = chEl ? chEl.value : '';
            if (!tripayChannel) { showGatewayError('Pilih metode pembayaran Tripay dulu.'); return; }
        }

        // Tambahkan flag method ke form sebelum submit ke process.php
        var methodInput = form.querySelector('input[name="payment_gateway_method"]');
        if (!methodInput) {
            methodInput = document.createElement('input');
            methodInput.type = 'hidden';
            methodInput.name = 'payment_gateway_method';
            form.appendChild(methodInput);
        }
        methodInput.value = method; // 'duitku' atau 'xendit'

        // Step 1: submit form ke process.php untuk buat order (return JSON)
        submitBtn.disabled    = true;
        submitBtn.textContent = 'Membuat order...';
        if (errEl) errEl.classList.add('hidden');

        var formData = new FormData(form);
        var processRes, processData;

        try {
            processRes = await fetch('<?= e(APP_URL) ?>/actions/checkout/process', {
                method: 'POST',
                body:   formData,
            });
        } catch (fetchErr) {
            showGatewayError('Gagal menghubungi server. Periksa koneksi internet kamu.');
            resetBtn();
            return;
        }
        try {
            processData = await processRes.json();
        } catch (parseErr) {
            // Respons bukan JSON = validasi gagal di server (flash + redirect HTML).
            // Muat ulang halaman checkout agar pesan error aslinya tampil — bukan
            // pesan "gagal terhubung" yang menyesatkan.
            window.location.href = '<?= e(APP_URL) ?>/checkout?product_id=<?= (int) $product['id'] ?>';
            return;
        }

        if (!processData.order_id) {
            if (processData.error) {
                showGatewayError(processData.error);
                resetBtn();
                return;
            }
            // Jika process.php return redirect HTML (bukan JSON), berarti ada error — ikuti redirect
            window.location.href = '<?= e(APP_URL) ?>/checkout?product_id=<?= (int) $product['id'] ?>';
            return;
        }

        // Step 2: buat invoice Duitku
        var gatewayLabel = method.charAt(0).toUpperCase() + method.slice(1);
        submitBtn.textContent = 'Menghubungi ' + gatewayLabel + '...';

        var payRes, payData;
        try {
            payRes  = await fetch('<?= e(APP_URL) ?>/actions/checkout/create-payment', {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    'order_id=' + encodeURIComponent(processData.order_id) + '&gateway=' + encodeURIComponent(method) + (method === 'tripay' ? '&channel=' + encodeURIComponent(tripayChannel) : ''),
            });
            payData = await payRes.json();
        } catch (fetchErr) {
            showGatewayError('Gagal menghubungi ' + gatewayLabel + '. Order sudah dibuat — coba bayar dari halaman riwayat transaksi.');
            resetBtn();
            return;
        }

        if (payData.success && payData.payment_url) {
            // Buyer TETAP di dalam Averion: mendarat di halaman transaksi
            // (panel "Menunggu Pembayaran" + tombol bayar tab baru + status live),
            // bukan dilempar ke halaman gateway.
            submitBtn.textContent = 'Order dibuat — membuka halaman pembayaran...';
            window.location.href  = '<?= e(APP_URL) ?>/transactions/' + encodeURIComponent(payData.order_number || processData.order_number);
        } else {
            showGatewayError(payData.message || 'Gagal membuat pembayaran ' + gatewayLabel + '. Silakan coba lagi.');
            resetBtn();
        }
    });

    function showGatewayError(msg) {
        if (!errEl) return;
        errEl.textContent = msg;
        errEl.classList.remove('hidden');
    }

    function resetBtn() {
        submitBtn.disabled  = false;
        submitBtn.innerHTML = _origBtnHTML; // kembalikan ikon + label asli (free/paid)
    }
})();

// ── Bump Offers JS ────────────────────────────────────────────
(function () {
    var basePrice    = <?= $isVoucher ? $price : (float) $product['price'] ?>;
    var setupFee     = <?= (float) $setupFee ?>;  // biaya awal (pembelian pertama), tak didiskon kupon
    var totalEl      = document.getElementById('order-total-display');
    var bumpRow      = document.getElementById('bump-summary-row');
    var bumpDispEl   = document.getElementById('bump-total-display');
    var bumpHidden   = document.getElementById('bump_total_hidden');
    var checkboxes   = document.querySelectorAll('.bump-checkbox');

    function fmtRupiah(n) {
        return 'Rp ' + Math.round(n).toLocaleString('id-ID');
    }

    function recalc() {
        var bumpTotal  = 0;
        checkboxes.forEach(function(cb) {
            if (cb.checked) bumpTotal += parseFloat(cb.dataset.price) || 0;
        });

        var couponDisc = (window._checkoutCouponDisc ? window._checkoutCouponDisc() : 0);
        // Potongan Poin Reward (0 bila fitur mati / toggle tak dinyalakan).
        var pointsDisc = (window._checkoutPointsDisc ? window._checkoutPointsDisc() : 0);
        var grandTotal = Math.max(0, basePrice - couponDisc - pointsDisc) + bumpTotal + setupFee;
        // Mode Booking Fee: yang ditagih sekarang hanya DP (kupon & bump tak berlaku).
        if (window._bookingMode) grandTotal = <?= (float) ($product['booking_fee'] ?? 0) ?>;

        if (bumpHidden) bumpHidden.value = bumpTotal;

        if (bumpRow) {
            if (bumpTotal > 0) {
                bumpRow.classList.remove('hidden');
                if (bumpDispEl) bumpDispEl.textContent = '+' + fmtRupiah(bumpTotal);
            } else {
                bumpRow.classList.add('hidden');
            }
        }

        if (totalEl) {
            totalEl.textContent = grandTotal <= 0 ? 'GRATIS' : fmtRupiah(grandTotal);
        }
    }

    // Expose global recalc (called by coupon JS on apply/reset)
    window._recalcCheckoutTotal = recalc;

    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', recalc);
    });
})();
</script>

<?php
require_once __DIR__ . '/../../tracking/PixelHelper.php';
$ckPrice = $isVoucher ? $price : (float) ($product['price'] ?? 0);
if (PixelHelper::metaFullFunnelEnabled() && !isAdminOrStaff()) {
    // Corong penuh: event browser + CAPI ber-eventID stabil → Meta men-dedup.
    // (sinkron dgn checkout_click di atas; admin dikecualikan sama seperti funnel)
    PixelHelper::trackFunnelServerSide('checkout', (int) $productId, ['value' => $ckPrice]);
    echo PixelHelper::renderFunnelBrowser('checkout', (int) $productId, ['value' => $ckPrice]);
} else {
    // Perilaku lama (default): AddToCart browser saja.
    echo PixelHelper::renderEvent('checkout', ['total' => $ckPrice]);
}
?>
<?php include __DIR__ . '/../../partials/sp-checkout-sound.php'; ?>
<?php
// Social Proof per produk: footer meng-include sp-popup.php yang membaca ini.
$spProductId = (int) $product['id'];
$spMode      = in_array($product['sp_mode'] ?? 'all', ['all', 'own', 'off'], true) ? $product['sp_mode'] : 'all';
// Heartbeat presence: pengunjung "live" di tahap checkout produk ini.
echo funnelHeartbeatScript('product', (int) $productId, 'checkout');
include __DIR__ . '/../../partials/footer.php'; ?>

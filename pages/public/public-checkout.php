<?php
// ════════════════════════════════════════════════════════════════
// Public Checkout — Form Order Publik (tanpa login)
// Standalone: TIDAK pakai partials/head.php atau layout admin.
// Tiga template UI: clean | card | split (dari products.public_form_template)
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';

// Anti-bot: catat waktu render form checkout guest (dicek di
// actions/public/submit-public-checkout.php — bot mem-POST hampir seketika).
$_SESSION['pubco_form_ts'] = time();

// ─── Mode Embed (form order di-iframe pada situs eksternal) ───
// Diaktifkan lewat rute /embed/checkout/{slug} (embed-checkout.php set flag ini).
// Header framing di-set via PHP juga (menutup hosting Nginx yang abaikan .htaccess).
$EMBED = !empty($GLOBALS['EMBED_MODE']);
if ($EMBED) {
    header_remove('X-Frame-Options');
    header('Content-Security-Policy: frame-ancestors *');
}

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['slug'] ?? '')));
if ($slug === '') {
    redirect('/products');
}

// ─── Load produk ──────────────────────────────────────────────
$product = null;
try {
    $stmt = getDB()->prepare(
        "SELECT p.*, ct.layout_type,
                ct.footer_badge_enabled, ct.footer_badge_text, ct.footer_badge_icon, ct.footer_badge_url
         FROM products p
         LEFT JOIN checkout_templates ct ON ct.id = p.checkout_template_id
         WHERE p.slug = ? AND p.status = 'active'
         LIMIT 1"
    );
    $stmt->execute([$slug]);
    $product = $stmt->fetch();
} catch (Exception $e) {
    error_log('Public checkout load product error: ' . $e->getMessage());
}

if (!$product) {
    redirect('/products');
}

// GUARD: produk tanpa harga (price<=0, non-flexible) tak boleh di-checkout — sembunyikan
// form checkout-nya (placeholder migrasi Sejoli / produk salah konfig). Selaras dgn guard
// di submit-public-checkout.php (mencegah akses gratis produk berbayar).
if (orderProductNotSellableFree($product)) {
    if ($EMBED) { http_response_code(404); echo 'Produk tidak tersedia.'; exit; }
    flash('error', 'Produk ini belum tersedia untuk dibeli.', 'error');
    redirect('/products');
}

// Preview admin: admin boleh melihat form publik meski sedang login (untuk tes).
$isPreview = isset($_GET['preview']) && $_GET['preview'] === '1'
    && function_exists('isAdmin') && isAdmin();

// Produk Halaman Dukung (flexible + show_on_support) SELALU boleh dibayar sebagai TAMU
// lewat form publik ini — halaman Dukung ditujukan untuk pendukung tanpa akun, jadi
// tak boleh dipaksa login walau checkout_mode produk = 'login'. Produk lain tetap ikut
// checkout_mode-nya.
$allowGuestSupport = ($product['pricing_type'] ?? '') === 'flexible' && (int) ($product['show_on_support'] ?? 0) === 1;

// Produk bukan mode publik (dan bukan produk Dukung) → selalu checkout login biasa.
if (($product['slug'] ?? '') === 'voucher-tes-stifin' || (($product['checkout_mode'] ?? 'login') !== 'public' && !$allowGuestSupport)) {
    if ($EMBED) { http_response_code(404); echo 'Form embed tidak aktif untuk produk ini.'; exit; }
    redirect('/checkout?product_id=' . (int) $product['id']);
}
// User login (di luar mode preview) → checkout login biasa.
// Di mode embed jangan redirect (halaman /checkout tak bisa di-iframe) → tetap tampilkan form tamu.
if (isLoggedIn() && !$isPreview && !$EMBED) {
    redirect('/checkout?product_id=' . (int) $product['id']);
}

// ─── Funnel: catat checkout_click utk jalur GUEST & EMBED juga ──────────────
// Dulu hanya /checkout (login) yang mencatat checkout_click → tahap "Klik Checkout"
// di Funnel Analytics UNDERCOUNT (lebih kecil dari Bayar) karena pembeli guest/embed
// / link affiliate langsung ke /order/{slug} tak pernah terhitung. Halaman ini =
// form pembayaran guest, jadi sampai di sini = tahap checkout. Admin/staff & preview
// otomatis di-exclude di dalam trackCheckoutClick(). Dedup 1x/sesi/produk/hari.
require_once __DIR__ . '/../../actions/public/track-checkout-click.php';
if (!$isPreview) {
    trackCheckoutClick((int) $product['id']);
    trackRotatorCheckoutClick(); // atribusi per-varian Canvas Rotator (Split Test)
}

// ─── Gateway aktif (untuk metode pembayaran) ──────────────────
$duitkuActive = $xenditActive = $mootaActive = $midtransActive = $tripayActive = false;
$paypalActive = false; $paypalRate = 0.0;
$tripayChannels = [];
try {
    $gwRows = getDB()->query(
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
        // PayPal: butuh kurs IDR→USD terisi (nominal ditagih USD). Sama syarat login checkout.
        if ($gwRow['provider'] === 'paypal') {
            $cfg = json_decode($gwRow['config_json'] ?? '', true) ?: [];
            $paypalRate   = (float) ($cfg['idr_usd_rate'] ?? 0);
            $paypalActive = ((bool) $gwRow['is_active']) && $paypalRate > 0;
        }
    }
} catch (Exception $e) {
    error_log('Public checkout gateway list error: ' . $e->getMessage());
}
// Tripay: daftar channel (cache 1 jam) untuk pemilih metode.
if ($tripayActive) {
    try {
        require_once __DIR__ . '/../../payment_gateways/TripayGateway.php';
        $tripayChannels = (new TripayGateway())->getChannels();
        if (empty($tripayChannels)) $tripayActive = false;
    } catch (Exception $e) { $tripayActive = false; error_log('Public checkout tripay channels error: ' . $e->getMessage()); }
}

// ─── Transfer Bank Manual (rekening + QR + instruksi) ─────────
$manualTransferEnabled = getSetting('manual_transfer_enabled', '1') === '1';
$bankAccounts = [];
if ($manualTransferEnabled) {
    try {
        $bankAccounts = getDB()->query(
            "SELECT id, bank_name, channel_type, account_number, account_name, instructions FROM bank_accounts WHERE is_active = 1 ORDER BY channel_type ASC, sort_order ASC, id ASC"
        )->fetchAll();
    } catch (Exception $e) {
        error_log('Public checkout load bank accounts error: ' . $e->getMessage());
    }
}
$manualQrImage            = $manualTransferEnabled ? getSetting('manual_qr_image') : null;
$manualPaymentInstruction = getSetting('manual_payment_instruction',
    'Boleh bayar QR Pay atau manual transfer. Lepas bayar, screenshot resit & WhatsApp untuk akses segera.');
// Tersedia bila aktif DAN ada rekening aktif ATAU QR terpasang.
$manualTransferAvailable  = $manualTransferEnabled && (count($bankAccounts) > 0 || !empty($manualQrImage));

// ─── Settings & harga ─────────────────────────────────────────
$appName    = getSetting('app_name', 'STIFIn Mulia');
$appLogo    = getSetting('app_logo', '');
$appFavicon = getSetting('app_favicon', '');
$brandColor = getSetting('brand_color', '#6366f1');

$price     = (float) $product['price'];
$origPrice = (float) $product['original_price'];
$hasDisc   = $origPrice > $price;
// Biaya awal (setup fee) — tamu diasumsikan pembelian pertama; server re-cek saat submit.
$setupFee  = productSetupFee($product, 0);
$isFree    = ($price + $setupFee) <= 0;

// ─── Bayar Fleksibel (tamu tentukan nominal) ─────────────────
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
    $price = 0.0; $origPrice = 0.0; $hasDisc = false; $setupFee = 0.0; $isFree = false;
}
// ─── Dime Sale: harga efektif saat ini + KUNCI harga di sesi (pembeli bayar yg dilihat) ──
$isDime = !$isFlexible && !empty($product['dime_enabled']);
if ($isDime) {
    $price    = dimeEffectivePrice($product);
    $origPrice = $price; $hasDisc = false;
    $isFree   = ($price + $setupFee) <= 0;
    dimeLockPrice((int) $product['id'], $price);
}
// Fase 2: produk yang tampil di Halaman Dukung → checkout tampilkan pesan + opsi anonim.
$flexSupport = $isFlexible && (int) ($product['show_on_support'] ?? 0) === 1;
// Harga alias RM (display-only) — tampil di bawah Total bila di-set & bukan produk fleksibel.
$pMyr = (!$isFlexible && $product['price_myr'] !== null && (float) $product['price_myr'] > 0) ? (float) $product['price_myr'] : null;
// Id elemen utk partial picker (public-checkout pakai id berbeda dr checkout member).
$flexTotalId       = 'order_total';
$flexSubmitId      = 'submit_btn';
$flexSubmitLabelId = 'submit_label';

// Booking Fee — dipakai UI ringkasan + JS recalc (mirror $renderSummary).
$bkFeeTop    = (float) ($product['booking_fee'] ?? 0);
$bkActiveTop = !$isFree && (int) ($product['booking_enabled'] ?? 0) === 1
             && $bkFeeTop > 0 && $bkFeeTop < ($price + $setupFee);

// ─── Order Bump: penawaran tambahan (paritas dgn checkout login) ───
// Hanya untuk order berbayar (main tak gratis) — hindari inkonsistensi
// main-gratis + bump-berbayar. Tamu belum punya akun → tak difilter hasAccess
// di sini; submit-public-checkout meng-skip bump yang sudah dimiliki user.
$bumpOffers = [];
if (!$isFree) {
    try {
        $bStmt = getDB()->prepare(
            "SELECT pb.bump_price, pb.headline, pb.description,
                    p.id AS bump_product_id, p.name AS bump_name,
                    p.price AS bump_normal_price, p.thumbnail AS bump_thumbnail
             FROM product_bumps pb
             JOIN products p ON pb.bump_product_id = p.id
             WHERE pb.main_product_id = ? AND pb.status = 'active' AND p.status = 'active'
             ORDER BY pb.sort_order ASC"
        );
        $bStmt->execute([(int) $product['id']]);
        $bumpOffers = $bStmt->fetchAll();
    } catch (Exception $e) {
        error_log('Public checkout bump load error: ' . $e->getMessage());
    }
}
if ($isFlexible) { $bumpOffers = []; } // bump nonaktif utk produk fleksibel

// ─── Bonus Kilat (produk premium GRATIS berbatas waktu) — paritas checkout login ──
// Tamu belum punya akun → tak difilter hasAccess di sini; submit-public-checkout
// meng-skip bonus yang sudah dimiliki user. Timer per-kunjungan via cookie
// fb_start_{pid} (divalidasi ulang server). Nonaktif utk produk fleksibel.
$fbOffers    = [];
$fbLabel     = 'Bonus Gratis Hari Ini';
$fbMaxSelect = 1;
$fbRemaining = 0;
$fbExpired   = false;
if (!$isFlexible) {
    try {
        $fsStmt = getDB()->prepare("SELECT enabled, duration_minutes, max_select, label FROM flash_bonus_settings WHERE main_product_id = ? LIMIT 1");
        $fsStmt->execute([(int) $product['id']]);
        $fbSet = $fsStmt->fetch();
        $fbEnabled  = $fbSet ? ((int) $fbSet['enabled'] === 1) : true;
        $fbDuration = $fbSet ? max(1, (int) $fbSet['duration_minutes']) : 60;
        $fbMaxSelect = $fbSet ? max(1, (int) $fbSet['max_select']) : 1;
        $fbLabel    = $fbSet ? (string) $fbSet['label'] : 'Bonus Gratis Hari Ini';

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
            $foStmt->execute([(int) $product['id']]);
            $fbOffers = $foStmt->fetchAll();

            if (!empty($fbOffers)) {
                $ckName  = 'fb_start_' . (int) $product['id'];
                $startTs = isset($_COOKIE[$ckName]) ? (int) $_COOKIE[$ckName] : 0;
                if ($startTs <= 0) {
                    $startTs = time();
                    setcookie($ckName, (string) $startTs, [
                        'expires'  => time() + $fbDuration * 60,
                        'path'     => '/',
                        'samesite' => 'Lax',
                    ]);
                    $_COOKIE[$ckName] = (string) $startTs;
                }
                $fbRemaining = max(0, ($fbDuration * 60) - (time() - $startTs));
                $fbExpired   = $fbRemaining <= 0;
            }
        }
    } catch (Exception $e) {
        error_log('Public checkout flash bonus load error: ' . $e->getMessage());
        $fbOffers = [];
    }
}

// Metode pembayaran tersedia
$payMethods = [];
if ($duitkuActive) $payMethods[] = ['value' => 'duitku', 'label' => 'Duitku', 'desc' => 'Bank / E-wallet / QRIS',         'badge' => 'AUTO'];
if ($xenditActive) $payMethods[] = ['value' => 'xendit', 'label' => 'Xendit', 'desc' => 'Bank / E-wallet / Kartu Kredit', 'badge' => 'AUTO'];
if ($midtransActive) $payMethods[] = ['value' => 'midtrans', 'label' => 'Midtrans', 'desc' => 'Bank / GoPay / QRIS / Kartu', 'badge' => 'AUTO'];
if ($tripayActive) $payMethods[] = ['value' => 'tripay', 'label' => 'Tripay', 'desc' => 'VA / QRIS / E-wallet / Alfamart', 'badge' => 'AUTO'];
if ($mootaActive)  $payMethods[] = ['value' => 'moota',  'label' => 'Transfer Bank', 'desc' => 'Konfirmasi otomatis',       'badge' => ''];
if ($paypalActive) $payMethods[] = ['value' => 'paypal', 'label' => 'PayPal', 'desc' => 'Kartu / saldo PayPal (ditagih USD)', 'badge' => ''];
// Transfer Bank Manual (rekening/QR) — ditawarkan bila ada rekening aktif atau QR.
if ($manualTransferAvailable) {
    $payMethods[] = ['value' => 'manual_transfer', 'label' => 'Transfer Bank Manual', 'desc' => 'Transfer / scan QR, lalu upload bukti — dikonfirmasi admin', 'badge' => ''];
}
// Transfer Manual generik = MURNI fallback terakhir: hanya ditawarkan bila tidak
// ada satu pun metode lain (selaras checkout login — payment-method.php).
if (empty($payMethods)) {
    $payMethods[] = ['value' => 'manual', 'label' => 'Transfer Manual', 'desc' => 'Instruksi dikirim setelah order', 'badge' => ''];
}

$tpl = in_array($product['public_form_template'] ?? 'clean', ['clean', 'card', 'split'], true)
     ? $product['public_form_template'] : 'clean';
// Embed: paksa template ringkas (clean) — 'split' full-screen tak cocok di dalam iframe.
if ($EMBED) $tpl = 'clean';

$couponError = getFlash('public_checkout_error');
// Hanya prefill/ tampilkan saat atribusi ambient aktif (program afiliasi hidup &
// General Cookie nyala) — konsisten dgn resolveAffiliateForCheckout. Saat mati,
// hidden field affiliate_code kosong & badge tak muncul (atribusi tetap diabaikan
// di server oleh resolver; ini menyelaraskan UI-nya).
$affCookie   = affiliateAmbientEnabled()
    ? strtoupper(preg_replace('/[^A-Z0-9]/', '', $_COOKIE['aff_code'] ?? ''))
    : '';
// Nama affiliate pemilik kode (ditampilkan di form — biar affiliate yakin referral
// tercatat). Fail-soft: kode tak dikenal → nama kosong (badge tampil kode).
$affName = '';
if ($affCookie !== '') {
    try {
        $anq = getDB()->prepare('SELECT u.name FROM affiliates a JOIN users u ON u.id = a.user_id WHERE a.code = ? LIMIT 1');
        $anq->execute([$affCookie]);
        $affName = (string) ($anq->fetchColumn() ?: '');
    } catch (Throwable $e) { $affName = ''; }
}
$csrf        = generateCSRF();
$submitUrl   = rtrim(APP_URL, '/') . '/actions/public/submit-public-checkout';

// Tujuan setelah login bila buyer ternyata sudah punya akun: checkout login
// produk ini (supaya tinggal klik). Format path + base-path, konsisten REQUEST_URI.
$loginRedirect = rawurlencode(
    rtrim((string) parse_url(APP_URL, PHP_URL_PATH), '/') . '/checkout?product_id=' . (int) $product['id']
);
// Embed: link "Login" harus buka di JENDELA ATAS — halaman login tak bisa di-iframe
// (frame-ancestors 'self') → tanpa ini muncul "refused to connect" di dalam iframe.
$loginTarget = $EMBED ? ' target="_top" rel="noopener"' : '';

// ─── Closure: snippet markup yang sama di semua template ──────
$thumb = trim($product['thumbnail'] ?? '');

// Field input (nama, WA, email + hidden)
$renderFields = function ($hideLabels = false) use ($product, $csrf, $affCookie, $affName, $isFree, $loginRedirect, $loginTarget) { ?>
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
    <input type="hidden" name="product_slug" value="<?= e($product['slug']) ?>">
    <input type="hidden" name="affiliate_code" value="<?= e($affCookie) ?>">
    <?php // Badge attribution affiliate (display-only) — nama, fallback kode.
    if ($affCookie !== '') { $refCode = $affCookie; $refAffiliateName = $affName;
        include __DIR__ . '/../../checkout_templates/_partials/referral-badge.php'; } ?>
    <?php /* Honeypot anti-bot — tersembunyi dari pembeli (off-screen/aria-hidden/
             autocomplete-off). Bot yang mengisi semua field ketahuan → ditolak di
             submit-public-checkout.php. Jangan hapus / jangan type=hidden. */ ?>
    <div aria-hidden="true" style="position:absolute;left:-9999px;top:0;height:0;width:0;overflow:hidden">
      <label>Biarkan kosong<input type="text" name="website" tabindex="-1" autocomplete="off" value=""></label>
    </div>

    <div class="space-y-3">
      <div>
        <?php if (!$hideLabels): ?><label class="block text-xs font-medium text-gray-600 mb-1.5">Nama Lengkap</label><?php endif; ?>
        <div class="relative">
          <svg class="w-[18px] h-[18px] absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <input type="text" name="buyer_name" required maxlength="100"
                 class="w-full h-11 pl-10 pr-3.5 rounded-lg text-sm border border-gray-200 bg-white text-gray-900 placeholder-gray-400 outline-none focus:ring-2 focus:border-transparent transition"
                 style="--tw-ring-color: var(--brand-color);"
                 placeholder="<?= $hideLabels ? 'Nama lengkap' : 'Nama kamu' ?>">
        </div>
      </div>
      <div>
        <?php if (!$hideLabels): ?><label class="block text-xs font-medium text-gray-600 mb-1.5">No. WhatsApp</label><?php endif; ?>
        <div class="relative">
          <svg class="w-[18px] h-[18px] absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          <input type="tel" name="buyer_wa" id="buyer_wa" required inputmode="numeric"
                 class="w-full h-11 pl-10 pr-3.5 rounded-lg text-sm border border-gray-200 bg-white text-gray-900 placeholder-gray-400 outline-none focus:ring-2 focus:border-transparent transition"
                 style="--tw-ring-color: var(--brand-color);"
                 placeholder="<?= $hideLabels ? 'No. WhatsApp (08xx / +628xx)' : '08xx atau +628xx' ?>">
        </div>
        <p id="wa_err" class="hidden text-xs text-red-500 mt-1">Nomor WhatsApp harus 10–15 digit angka.</p>
      </div>
      <div>
        <?php if (!$hideLabels): ?><label class="block text-xs font-medium text-gray-600 mb-1.5">Email</label><?php endif; ?>
        <div class="relative">
          <svg class="w-[18px] h-[18px] absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <input type="email" name="buyer_email" id="buyer_email" required
                 class="w-full h-11 pl-10 pr-3.5 rounded-lg text-sm border border-gray-200 bg-white text-gray-900 placeholder-gray-400 outline-none focus:ring-2 focus:border-transparent transition"
                 style="--tw-ring-color: var(--brand-color);"
                 placeholder="<?= $hideLabels ? 'Alamat email' : 'email@kamu.com' ?>">
        </div>
        <div id="email_exists_banner" class="hidden mt-2 rounded-lg bg-yellow-50 border border-yellow-200 px-3 py-2 text-xs text-yellow-800">
          Email ini sudah terdaftar. <a href="<?= e(APP_URL) ?>/login?redirect=<?= $loginRedirect ?>"<?= $loginTarget ?> class="font-semibold underline">Login</a> untuk checkout lebih cepat.
        </div>
      </div>
    </div>
<?php };

// Toggle card kupon kini per-PRODUK (kolom products.show_coupon), bukan per template.
// Pra-migrasi / kolom belum ada → default tampil (perilaku lama).
$tplShowCoupon = ($product['show_coupon'] ?? 1) != 0;

// Section kupon — hormati toggle admin (matikan bila tak menjalankan promo).
$renderCoupon = function () use ($isFree, $tplShowCoupon, $isFlexible, $flexSupport, $flexLabel, $flexMin, $flexPresets, $flexTotalId, $flexSubmitId, $flexSubmitLabelId) {
    // Produk fleksibel: tampilkan pemilih nominal di slot ini (kupon nonaktif).
    if (!empty($isFlexible)) { include __DIR__ . '/../../checkout_templates/_partials/flexible-amount.php'; return; }
    if ($isFree || !$tplShowCoupon) return; ?>
    <div class="mt-4" id="coupon_block_pc">
      <label class="block text-xs font-medium text-gray-600 mb-1.5">Punya Kode Kupon?</label>
      <div class="flex gap-2">
        <input type="text" name="coupon_code" id="coupon_code"
               class="flex-1 min-w-0 h-11 px-3.5 rounded-lg text-sm border border-gray-200 bg-white text-gray-900 placeholder-gray-400 uppercase outline-none focus:ring-2 focus:border-transparent transition"
               style="--tw-ring-color: var(--brand-color);"
               placeholder="KODE KUPON">
        <button type="button" id="apply_coupon_btn"
                class="shrink-0 h-11 px-4 rounded-lg text-sm font-semibold border-2 transition"
                style="border-color: var(--brand-color); color: var(--brand-color);">Terapkan</button>
      </div>
      <p id="coupon_result" class="text-xs mt-1.5"></p>
    </div>
<?php };

// Footer badge "Powered by" opsional — reuse partial bersama (scope closure).
$tplFooterBadge     = (bool)  (($product['footer_badge_enabled'] ?? 0));
$tplFooterBadgeText = (string) ($product['footer_badge_text'] ?? '');
$tplFooterBadgeIcon = (string) ($product['footer_badge_icon'] ?? '');
$tplFooterBadgeUrl  = (string) ($product['footer_badge_url']  ?? '');
$renderFooterBadge  = function () use ($tplFooterBadge, $tplFooterBadgeText, $tplFooterBadgeIcon, $tplFooterBadgeUrl) {
    include __DIR__ . '/../../checkout_templates/_partials/footer-badge.php';
};

// Section order bump (penawaran tambahan) — hormati ketiadaan bump.
$renderBumps = function () use ($bumpOffers) {
    if (empty($bumpOffers)) return; ?>
    <div class="mt-4" id="pc_bump_block">
      <label class="block text-xs font-medium text-gray-600 mb-1.5">Tingkatkan Pesanan</label>
      <div class="space-y-2">
        <?php foreach ($bumpOffers as $b):
          $bp = ($b['bump_price'] !== null) ? (float) $b['bump_price'] : (float) $b['bump_normal_price']; ?>
        <label class="flex items-start gap-2.5 p-3 rounded-lg border-2 border-dashed border-amber-200 bg-amber-50/50 cursor-pointer transition hover:border-amber-300 has-[:checked]:border-amber-400 has-[:checked]:bg-amber-50">
          <input type="checkbox" name="bump_ids[]" value="<?= (int) $b['bump_product_id'] ?>"
                 data-price="<?= $bp ?>" class="pc-bump-checkbox mt-0.5 w-4 h-4 shrink-0" style="accent-color:#f59e0b;">
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-semibold text-amber-800"><?= $b['headline'] ? e($b['headline']) : 'Tambahkan ' . e($b['bump_name']) ?></span>
            <?php if ($b['description']): ?><span class="block text-xs text-gray-500 mt-0.5 leading-relaxed"><?= e($b['description']) ?></span><?php endif; ?>
            <span class="block text-sm font-bold text-gray-900 mt-1">
              <?= $bp > 0 ? e(formatRupiah($bp)) : '<span class="text-green-600">GRATIS</span>' ?>
              <?php if ($b['bump_price'] !== null && (float) $b['bump_normal_price'] > $bp): ?>
              <span class="ml-1 text-xs text-gray-400 line-through font-normal"><?= e(formatRupiah((float) $b['bump_normal_price'])) ?></span>
              <?php endif; ?>
            </span>
          </span>
          <?php if ($b['bump_thumbnail']): ?><img src="<?= e($b['bump_thumbnail']) ?>" alt="" width="48" height="48" loading="lazy" decoding="async" class="w-12 h-12 rounded-lg object-cover shrink-0"><?php endif; ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
<?php };

// Section Bonus Kilat (produk premium GRATIS berbatas waktu) — reuse partial
// bersama dgn checkout login. Muncul di ATAS order bump. Style+JS self-contained.
$renderFlashBonus = function () use ($fbOffers, $fbLabel, $fbMaxSelect, $fbRemaining, $fbExpired) {
    if (empty($fbOffers)) return;
    // Beri jarak dari field/section di atasnya (mt-4, konsisten dgn closure lain).
    echo '<div class="mt-4">';
    include __DIR__ . '/../../checkout_templates/_partials/flash-bonus.php';
    echo '</div>';
};

// Ringkasan harga
$renderSummary = function () use ($product, $price, $hasDisc, $origPrice, $isFree, $setupFee, $isFlexible, $pMyr, $flexLabel) {
    $saveAmt = $hasDisc ? ($origPrice - $price) : 0;
    $savePct = ($hasDisc && $origPrice > 0) ? (int) round(($origPrice - $price) / $origPrice * 100) : 0;
    ?>
    <div class="mt-4 rounded-lg bg-gray-50 border border-gray-100 p-4 space-y-2 text-sm">
      <div class="flex items-center justify-between text-gray-600">
        <span><?= $isFlexible ? e($flexLabel) : 'Harga Produk' ?></span>
        <span><?= $isFlexible ? 'Fleksibel' : ($isFree ? 'GRATIS' : e(formatRupiah($hasDisc ? $origPrice : $price))) ?></span>
      </div>
      <?php if ($hasDisc && !$isFree): ?>
      <div class="flex items-center justify-between text-green-600">
        <span class="inline-flex items-center gap-1.5">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>
          Hemat <?= $savePct ?>%
        </span>
        <span>- <?= e(formatRupiah($saveAmt)) ?></span>
      </div>
      <?php endif; ?>
      <div id="discount_row" class="hidden items-center justify-between text-green-600">
        <span id="discount_label">Diskon kupon</span>
        <span id="discount_value">-<?= e(formatRupiah(0)) ?></span>
      </div>
      <div id="pc_bump_row" class="hidden items-center justify-between text-gray-700">
        <span>Tambahan pesanan</span>
        <span id="pc_bump_value">+<?= e(formatRupiah(0)) ?></span>
      </div>
      <?php if ($setupFee > 0): ?>
      <div class="flex items-center justify-between text-gray-600">
        <span>Biaya setup awal <span class="text-[11px]">(sekali)</span></span>
        <span>+<?= e(formatRupiah($setupFee)) ?></span>
      </div>
      <?php endif; ?>
      <div class="flex items-center justify-between font-bold text-gray-900 pt-2.5 border-t border-gray-200">
        <span>Total Bayar</span>
        <span id="order_total" class="text-lg" data-base="<?= (float) ($price + $setupFee) ?>"><?= $isFlexible ? 'Pilih nominal' : ($isFree ? 'GRATIS' : e(formatRupiah($price + $setupFee))) ?></span>
      </div>
      <?php if ($pMyr !== null): ?>
      <div class="flex justify-end pt-1"><?= ringgitBadge($pMyr) ?></div>
      <?php endif; ?>
      <?php if (!$isFree && (int) ($product['access_duration_days'] ?? 0) > 0): ?>
      <p class="text-[11px] text-gray-400 pt-0.5">Langganan · perpanjang <?= e(formatRupiah($price)) ?> / <?= e(subscriptionPeriodLabel((int) $product['access_duration_days'])) ?></p>
      <?php endif; ?>

      <?php
      // Pilihan Booking Fee (DP) — radio di dalam form, validasi final di server.
      $bkFee    = (float) ($product['booking_fee'] ?? 0);
      $bkFull   = $price + $setupFee;
      $bkActive = !$isFree && (int) ($product['booking_enabled'] ?? 0) === 1 && $bkFee > 0 && $bkFee < $bkFull;
      if ($bkActive):
          $bkDays = max(1, (int) ($product['booking_deadline_days'] ?? 7));
      ?>
      <div class="pt-2.5 border-t border-gray-200 space-y-2">
        <p class="text-xs font-semibold text-gray-700">Cara Bayar</p>
        <label class="flex items-start gap-2.5 p-3 rounded-lg border-2 border-gray-200 bg-white cursor-pointer transition has-[:checked]:border-[var(--brand-color)]">
          <input type="radio" name="payment_mode" value="full" checked class="mt-0.5 checkout-radio shrink-0" onchange="pcBookingApply()">
          <span class="min-w-0">
            <span class="block text-sm font-semibold text-gray-900">Bayar Penuh</span>
            <span class="block text-xs text-gray-500"><?= e(formatRupiah($bkFull)) ?> — akses langsung setelah pembayaran</span>
          </span>
        </label>
        <label class="flex items-start gap-2.5 p-3 rounded-lg border-2 border-gray-200 bg-white cursor-pointer transition has-[:checked]:border-[var(--brand-color)]">
          <input type="radio" name="payment_mode" value="booking" class="mt-0.5 checkout-radio shrink-0" onchange="pcBookingApply()">
          <span class="min-w-0">
            <span class="block text-sm font-semibold text-gray-900">Booking Fee <?= e(formatRupiah($bkFee)) ?></span>
            <span class="block text-xs text-gray-500">Kunci slot &amp; harga — sisa <?= e(formatRupiah($bkFull - $bkFee)) ?> dilunasi dalam <?= $bkDays ?> hari</span>
          </span>
        </label>
      </div>
      <script>
      function pcBookingApply() {
        var sel = document.querySelector('input[name="payment_mode"]:checked');
        var booking = !!(sel && sel.value === 'booking');
        // Mode booking: kupon & bump tak berlaku (DP saja) → sembunyikan keduanya.
        var cp = document.getElementById('coupon_block_pc');
        if (cp) cp.classList.toggle('hidden', booking);
        var bb = document.getElementById('pc_bump_block');
        if (bb) bb.classList.toggle('hidden', booking);
        if (window.pcSetBooking) window.pcSetBooking(booking);
      }
      </script>
      <?php endif; ?>
    </div>
<?php };

// Metode pembayaran
$renderPayments = function () use ($payMethods, $isFree, $bankAccounts, $manualQrImage, $manualPaymentInstruction, $manualTransferAvailable, $duitkuActive, $xenditActive, $mootaActive, $tripayChannels) {
    if ($isFree) return;
    $manualIsDefault = ($payMethods[0]['value'] ?? '') === 'manual_transfer';
    $hasBankList     = count($bankAccounts) > 0;
    $methodIcon = function (string $v): string {
        switch ($v) {
            case 'duitku':
            case 'xendit':
                return '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>';
            case 'moota':
            case 'manual_transfer':
            case 'manual':
                return '<path d="M3 21h18"/><path d="M5 21V10l7-5 7 5v11"/><line x1="9" y1="21" x2="9" y2="14"/><line x1="15" y1="21" x2="15" y2="14"/>';
            default:
                return '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>';
        }
    };
    ?>
    <div class="mt-4">
      <p class="text-xs font-medium text-gray-600 mb-2">Metode Pembayaran</p>
      <?php include __DIR__ . '/../../checkout_templates/_partials/accepted-payments.php'; ?>
      <div class="space-y-2">
        <?php foreach ($payMethods as $i => $pm): ?>
        <label class="flex items-center gap-3 p-3 rounded-lg border-2 border-gray-200 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_6%,white)] transition">
          <input type="radio" name="payment_method" value="<?= e($pm['value']) ?>" <?= $i === 0 ? 'checked' : '' ?>
                 class="checkout-radio shrink-0">
          <svg class="w-5 h-5 shrink-0 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><?= $methodIcon($pm['value']) ?></svg>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-gray-900"><?= e($pm['label']) ?></p>
            <p class="text-xs text-gray-400"><?= e($pm['desc']) ?></p>
          </div>
          <?php if ($pm['badge']): ?>
          <span class="inline-flex items-center text-[10px] font-bold px-1.5 py-0.5 rounded-md shrink-0 bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"><?= e($pm['badge']) ?></span>
          <?php endif; ?>
        </label>
        <?php if ($pm['value'] === 'tripay' && !empty($tripayChannels)): ?>
        <div id="tripay-channels" class="<?= $i === 0 ? '' : 'hidden' ?> space-y-1.5 pl-1">
          <p class="text-xs font-medium text-gray-500">Pilih metode pembayaran:</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
            <?php foreach ($tripayChannels as $ci => $ch): ?>
            <label class="flex items-center gap-2 p-2 rounded-lg border border-gray-200 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_6%,white)] transition">
              <input type="radio" name="tripay_channel" value="<?= e($ch['code']) ?>" <?= $ci === 0 ? 'checked' : '' ?> class="checkout-radio shrink-0">
              <?php if (!empty($ch['icon'])): ?><img src="<?= e($ch['icon']) ?>" alt="" width="28" height="16" loading="lazy" decoding="async" class="w-7 h-4 object-contain shrink-0"><?php endif; ?>
              <span class="text-sm text-gray-800 truncate"><?= e($ch['name']) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <?php if ($manualTransferAvailable): ?>
      <!-- Detail pembayaran manual (muncul saat "Transfer Bank Manual" dipilih) -->
      <div id="pc-bank-selector" class="<?= $manualIsDefault ? '' : 'hidden' ?> mt-3 space-y-3">

        <?php if (trim($manualPaymentInstruction) !== ''): ?>
        <p class="text-xs text-gray-600 leading-relaxed"><?= nl2br(e($manualPaymentInstruction)) ?></p>
        <?php endif; ?>

        <?php if ($hasBankList):
            // Chip ringkas — nomor/QR/atas nama tampil di halaman thank-you
            // (order sudah ada di sana). Lihat payment-method.php.
            $firstIdP = $bankAccounts[array_key_first($bankAccounts)]['id'];
        ?>
        <div>
          <p class="text-xs font-medium text-gray-500 mb-1.5">Pilih tujuan transfer:</p>
          <div class="flex flex-wrap gap-2">
            <?php foreach ($bankAccounts as $bank): $cType = $bank['channel_type'] ?? 'bank'; ?>
            <label class="flex items-center gap-2 pl-2.5 pr-3 py-1.5 rounded-lg border-2 border-gray-200 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_6%,white)] focus-within:ring-2 transition"
                   style="--tw-ring-color: color-mix(in srgb, var(--brand-color) 30%, transparent);">
              <input type="radio" name="bank_account_id" value="<?= (int) $bank['id'] ?>" <?= (int) $bank['id'] === (int) $firstIdP ? 'checked' : '' ?> class="sr-only">
              <?= paymentChannelIcon($cType, $bank['bank_name'], 'sm') ?>
              <span class="text-sm font-medium text-gray-800"><?= e($bank['bank_name']) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
          <p id="bank_err" class="hidden text-xs text-red-500 mt-1.5">Pilih dulu rekening tujuan transfer.</p>
          <p class="text-xs text-gray-400 mt-2">Nomor rekening<?= !empty($manualQrImage) ? ' &amp; QR Pay' : '' ?> tampil di halaman order berikutnya.</p>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
<?php };

// Tombol CTA
$renderCTA = function () use ($isFree, $price, $setupFee) { ?>
    <button type="submit" id="submit_btn"
            class="mt-4 w-full py-3.5 rounded-lg text-sm font-bold text-white transition hover:opacity-95 hover:-translate-y-0.5 disabled:opacity-60 flex items-center justify-center gap-2"
            style="background-color: var(--brand-color); box-shadow: 0 6px 16px color-mix(in srgb, var(--brand-color) 32%, transparent);">
      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      <span id="submit_label"><?= $isFree ? 'Ambil Sekarang' : 'Bayar ' . e(formatRupiah($price + $setupFee)) ?></span>
      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
    </button>
<?php };
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Checkout — <?= e($product['name']) ?> · <?= e($appName) ?></title>
  <?php if (!empty($appFavicon)): ?>
  <link rel="icon" href="<?= e($appFavicon) ?>">
  <?php else: ?>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%23<?= e(ltrim($brandColor, '#')) ?>'/><path d='M16 7 L25 25 L7 25 Z' fill='white'/></svg>">
  <?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
  <!-- Tailwind compiled lokal (tanpa CDN) — regenerate: tools/build-tailwind.php -->
  <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/tailwind.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/tailwind.css') ?: '1' ?>">
  <?php include __DIR__ . '/../../checkout_templates/_partials/checkout-ui.php'; ?>
  <style>
    :root { --brand-color: <?= e($brandColor) ?>; }
    body { font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, sans-serif; }
<?php if ($EMBED): ?>
    /* Embed: buang tinggi layar penuh & latar agar pas di dalam iframe (auto-resize). */
    html, body { background: transparent !important; }
    .averion-embed .min-h-screen { min-height: 0 !important; align-items: flex-start !important; padding-top: 4px !important; padding-bottom: 4px !important; }
<?php endif; ?>
  </style>
</head>
<body class="bg-gray-50 text-gray-900 antialiased <?= $EMBED ? 'averion-embed' : '' ?>">

<?php if (!empty($isPreview)): ?>
<div class="bg-amber-500 text-white text-center text-xs font-semibold py-1.5 px-4 shadow">
  Mode Preview Admin — kamu melihat form ini karena login sebagai admin. Pengunjung yang belum login akan melihat &amp; bisa men-submit form ini.
</div>
<?php endif; ?>

<?php if ($couponError): ?>
<div class="fixed top-4 left-1/2 -translate-x-1/2 z-50 w-[92%] max-w-md rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700 shadow-lg">
  <?= e($couponError['message']) ?>
</div>
<?php endif; ?>

<?php if ($tpl === 'split'): ?>
<!-- ══════════════ TEMPLATE: SPLIT ══════════════ -->
<form method="POST" action="<?= e($submitUrl) ?>" id="public_checkout_form" class="min-h-screen bg-slate-50" style="border-top: 3px solid var(--brand-color);">
  <div class="max-w-5xl mx-auto lg:grid lg:grid-cols-12 lg:min-h-screen">

    <!-- Kiri: form -->
    <div class="lg:col-span-7 bg-white px-5 sm:px-8 py-10 lg:border-r lg:border-gray-100 lg:flex lg:flex-col lg:justify-center">
      <div class="w-full max-w-md mx-auto">
        <div class="flex items-center gap-2 mb-6">
          <?php if ($appLogo): ?>
          <img src="<?= e($appLogo) ?>" alt="<?= e($appName) ?>" decoding="async" class="h-7 object-contain">
          <?php else: ?>
          <div class="w-7 h-7 rounded-lg flex items-center justify-center text-white text-xs font-bold" style="background: var(--brand-color);"><?= e(strtoupper(substr($appName, 0, 1))) ?></div>
          <?php endif; ?>
          <span class="font-bold text-sm"><?= e($appName) ?></span>
          <span class="ml-auto inline-flex items-center gap-1 text-[11px] font-medium text-green-600">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Transaksi Aman
          </span>
        </div>
        <h1 class="text-xl font-bold mb-1">Selesaikan Pesananmu</h1>
        <p class="text-sm text-gray-500 mb-5">Isi data di bawah untuk melanjutkan.</p>
        <a href="<?= e(APP_URL) ?>/login?redirect=<?= $loginRedirect ?>"<?= $loginTarget ?> class="inline-block text-xs text-gray-400 hover:text-[var(--brand-color)] mb-4">Sudah punya akun? Login</a>
        <?php $renderFields(); ?>
        <?php $renderFlashBonus(); ?>
        <?php $renderBumps(); ?>
        <?php $renderCoupon(); ?>
      </div>
    </div>

    <!-- Kanan: order rail (ber-tint brand + aksen garis) — nuansa khas template split -->
    <div class="lg:col-span-5 px-5 sm:px-8 py-10 lg:border-l-2 lg:flex lg:flex-col lg:justify-center" style="background: color-mix(in srgb, var(--brand-color) 5%, white); border-color: var(--brand-color);">
      <div class="w-full max-w-md mx-auto">
        <p class="text-[11px] font-semibold uppercase tracking-widest text-[var(--brand-color)] mb-3">Ringkasan Pesanan</p>
        <div class="flex items-center gap-3 pb-4 border-b border-gray-200">
          <?php if ($thumb): ?>
          <img src="<?= e($thumb) ?>" alt="" width="56" height="56" decoding="async" class="w-14 h-14 rounded-lg object-cover shrink-0">
          <?php else: ?>
          <div class="w-14 h-14 rounded-lg shrink-0 flex items-center justify-center" style="background: color-mix(in srgb, var(--brand-color) 12%, white); color: var(--brand-color);">
            <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
          </div>
          <?php endif; ?>
          <div class="min-w-0">
            <p class="font-semibold text-sm leading-tight"><?= e($product['name']) ?></p>
            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
              <span class="text-sm font-bold text-red-600"><?= $isFlexible ? e($flexLabel) : ($isFree ? 'GRATIS' : e(formatRupiah($price))) ?></span>
              <?php if ($hasDisc && !$isFree): ?>
              <span class="text-xs text-gray-400 line-through"><?= e(formatRupiah($origPrice)) ?></span>
              <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full" style="background: color-mix(in srgb, var(--brand-color) 12%, white); color: var(--brand-color);">-<?= (int) round(($origPrice - $price) / $origPrice * 100) ?>%</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php $renderSummary(); ?>
        <?php $renderPayments(); ?>
        <?php $renderCTA(); ?>
        <p class="mt-3 text-[11px] text-gray-400 flex items-center justify-center gap-1"><svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Data kamu aman &amp; terenkripsi</p>
        <?php $renderFooterBadge(); ?>
      </div>
    </div>

  </div>
</form>

<?php elseif ($tpl === 'card'): ?>
<!-- ══════════════ TEMPLATE: CARD ══════════════ -->
<style>
  #public_checkout_form .rounded-lg,
  #public_checkout_form input, #public_checkout_form select,
  #public_checkout_form textarea, #public_checkout_form button { border-radius: 8px !important; }
</style>
<div class="min-h-screen flex items-start sm:items-center justify-center px-4 py-10" style="background: radial-gradient(120% 120% at 50% 0%, color-mix(in srgb, var(--brand-color) 16%, #f8fafc) 0%, #eef1f6 60%);">
  <form method="POST" action="<?= e($submitUrl) ?>" id="public_checkout_form" class="w-full max-w-[520px]">
    <div class="bg-white rounded-lg shadow-2xl ring-1 ring-black/5 overflow-hidden">

      <!-- Header hero: band brand kompak — nuansa khas template card -->
      <div class="px-6 py-4 text-white" style="background: linear-gradient(135deg, var(--brand-color) 0%, color-mix(in srgb, var(--brand-color) 70%, #000) 100%);">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-2">
            <?php if ($appLogo): ?>
            <img src="<?= e($appLogo) ?>" alt="<?= e($appName) ?>" decoding="async" class="h-5 object-contain">
            <?php else: ?>
            <div class="w-5 h-5 rounded-md flex items-center justify-center text-[10px] font-bold bg-white/20"><?= e(strtoupper(substr($appName, 0, 1))) ?></div>
            <?php endif; ?>
            <span class="font-bold text-sm"><?= e($appName) ?></span>
          </div>
          <span class="inline-flex items-center gap-1 text-[11px] font-medium text-white/90">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Transaksi Aman
          </span>
        </div>
        <div class="flex items-center gap-3">
          <?php if ($thumb): ?>
          <img src="<?= e($thumb) ?>" alt="" width="48" height="48" decoding="async" class="w-12 h-12 rounded-lg object-cover shrink-0 ring-2 ring-white/30">
          <?php else: ?>
          <div class="w-12 h-12 rounded-lg shrink-0 flex items-center justify-center bg-white/15 text-white">
            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
          </div>
          <?php endif; ?>
          <div class="min-w-0">
            <h1 class="font-bold text-base leading-tight truncate"><?= e($product['name']) ?></h1>
            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
              <span class="text-base font-bold"><?= $isFlexible ? e($flexLabel) : ($isFree ? 'GRATIS' : e(formatRupiah($price))) ?></span>
              <?php if ($hasDisc && !$isFree): ?>
              <span class="text-xs text-white/60 line-through"><?= e(formatRupiah($origPrice)) ?></span>
              <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-white/20 text-white">-<?= (int) round(($origPrice - $price) / $origPrice * 100) ?>%</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Body -->
      <div class="p-6">
        <div class="flex items-center gap-2 mb-4">
          <span class="w-1 h-4 rounded-full" style="background: var(--brand-color);"></span>
          <p class="text-sm font-semibold text-gray-900">Data Pemesan</p>
        </div>
        <a href="<?= e(APP_URL) ?>/login?redirect=<?= $loginRedirect ?>"<?= $loginTarget ?> class="inline-block text-xs text-gray-400 hover:text-[var(--brand-color)] mb-3">Sudah punya akun? Login</a>
        <?php $renderFields(true); ?>
        <?php $renderFlashBonus(); ?>
        <?php $renderBumps(); ?>
        <?php $renderCoupon(); ?>
        <?php $renderSummary(); ?>
        <div class="flex items-center gap-2 mt-6 mb-1">
          <span class="w-1 h-4 rounded-full" style="background: var(--brand-color);"></span>
          <p class="text-sm font-semibold text-gray-900">Pembayaran</p>
        </div>
        <?php $renderPayments(); ?>
        <?php $renderCTA(); ?>
        <p class="mt-3 text-[11px] text-gray-400 flex items-center justify-center gap-1"><svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Data kamu aman &amp; terenkripsi</p>
        <?php $renderFooterBadge(); ?>
      </div>
    </div>
    <p class="text-center text-[11px] text-gray-400 mt-4">© <?= date('Y') ?> <?= e($appName) ?></p>
  </form>
</div>

<?php else: ?>
<!-- ══════════════ TEMPLATE: CLEAN (default) ══════════════ -->
<div class="min-h-screen flex items-start sm:items-center justify-center px-4 py-8" style="background:#f9fafb;">
  <form method="POST" action="<?= e($submitUrl) ?>" id="public_checkout_form" class="w-full max-w-[480px]">

    <!-- Header kecil -->
    <div class="flex items-center justify-between mb-4 px-1">
      <div class="flex items-center gap-2">
        <?php if ($appLogo): ?>
        <img src="<?= e($appLogo) ?>" alt="<?= e($appName) ?>" decoding="async" class="h-6 object-contain">
        <?php else: ?>
        <div class="w-6 h-6 rounded-md flex items-center justify-center text-white text-[10px] font-bold" style="background: var(--brand-color);"><?= e(strtoupper(substr($appName, 0, 1))) ?></div>
        <?php endif; ?>
        <span class="font-bold text-sm"><?= e($appName) ?></span>
      </div>
      <span class="inline-flex items-center gap-1 text-[11px] font-medium text-green-600">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Transaksi Aman
      </span>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-100 p-5 sm:p-6">
      <!-- Card produk -->
      <div class="flex items-center gap-3 pb-4 border-b border-gray-100">
        <?php if ($thumb): ?>
        <img src="<?= e($thumb) ?>" alt="" width="50" height="50" decoding="async" class="w-[50px] h-[50px] rounded-lg object-cover shrink-0">
        <?php else: ?>
        <div class="w-[50px] h-[50px] rounded-lg shrink-0 flex items-center justify-center" style="background: color-mix(in srgb, var(--brand-color) 12%, white); color: var(--brand-color);">
          <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        </div>
        <?php endif; ?>
        <div class="min-w-0 flex-1">
          <p class="font-semibold text-sm leading-tight"><?= e($product['name']) ?></p>
          <div class="flex items-center gap-2 mt-0.5 flex-wrap">
            <span class="text-sm font-bold text-red-600"><?= $isFlexible ? e($flexLabel) : ($isFree ? 'GRATIS' : e(formatRupiah($price))) ?></span>
            <?php if ($hasDisc && !$isFree): ?>
            <span class="text-xs text-gray-400 line-through"><?= e(formatRupiah($origPrice)) ?></span>
            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full" style="background: color-mix(in srgb, var(--brand-color) 12%, white); color: var(--brand-color);">-<?= (int) round(($origPrice - $price) / $origPrice * 100) ?>%</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <a href="<?= e(APP_URL) ?>/login?redirect=<?= $loginRedirect ?>"<?= $loginTarget ?> class="inline-block text-xs text-gray-400 hover:text-[var(--brand-color)] mt-3 mb-3">Sudah punya akun? Login</a>

      <?php $renderFields(); ?>
      <?php $renderFlashBonus(); ?>
      <?php $renderBumps(); ?>
        <?php $renderCoupon(); ?>
      <?php $renderSummary(); ?>
      <?php $renderPayments(); ?>
      <?php $renderCTA(); ?>
    </div>

    <p class="text-[11px] text-gray-400 mt-4 flex items-center justify-center gap-1"><svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Data anda aman &amp; terenkripsi · © <?= date('Y') ?> <?= e($appName) ?></p>
        <?php $renderFooterBadge(); ?>
  </form>
</div>
<?php endif; ?>

<script>
(function () {
  var APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
  var form    = document.getElementById('public_checkout_form');
  if (!form) return;

  var waInput     = document.getElementById('buyer_wa');
  var waErr       = document.getElementById('wa_err');
  var bankErr     = document.getElementById('bank_err');
  var emailInput  = document.getElementById('buyer_email');
  var emailBanner = document.getElementById('email_exists_banner');
  var couponInput = document.getElementById('coupon_code');
  var couponBtn   = document.getElementById('apply_coupon_btn');
  var couponResult= document.getElementById('coupon_result');
  var totalEl     = document.getElementById('order_total');
  var discRow     = document.getElementById('discount_row');
  var discLabel   = document.getElementById('discount_label');
  var discValue   = document.getElementById('discount_value');
  var submitBtn   = document.getElementById('submit_btn');
  var submitLabel = document.getElementById('submit_label');

  var PC_CURRENCY = <?= currencyJsConfig() ?>;
  function fmtRupiah(n) {
    var c = PC_CURRENCY, num = Math.abs(Number(n) || 0);
    var parts = num.toFixed(c.decimals).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thousand);
    return c.symbol + ' ' + parts.join(c.decimal);
  }
  var setupFee = <?= (float) $setupFee ?>;  // biaya awal (pembelian pertama), ditambahkan ke total, tak didiskon kupon

  // ── Kalkulator total terpusat (kupon + bump + booking) ──
  var basePrice     = <?= json_encode((float) $price) ?>;               // harga produk (tanpa setup)
  var bookingFeeJs  = <?= json_encode($bkActiveTop ? (float) $bkFeeTop : 0) ?>;
  var pcCouponPrice = null;  // harga produk SETELAH kupon (null = tanpa kupon)
  var pcBumpTotal   = 0;     // total bump tercentang
  var pcBooking     = false; // mode booking aktif
  var bumpRow  = document.getElementById('pc_bump_row');
  var bumpVal  = document.getElementById('pc_bump_value');

  window.pcRecalc = function () {
    var grand;
    if (pcBooking) {
      grand = bookingFeeJs;                                  // DP saja
    } else {
      var product = (pcCouponPrice !== null) ? pcCouponPrice : basePrice;
      grand = Math.max(0, product) + setupFee + pcBumpTotal; // kupon hanya utk produk, bukan setup/bump
    }
    if (totalEl)     totalEl.textContent     = grand <= 0 ? 'GRATIS' : fmtRupiah(grand);
    if (submitLabel) submitLabel.textContent = grand <= 0 ? 'Ambil Sekarang' : 'Bayar ' + fmtRupiah(grand);
    if (bumpRow) {
      var show = pcBumpTotal > 0 && !pcBooking;
      bumpRow.classList.toggle('hidden', !show);
      bumpRow.classList.toggle('flex', show);
      if (show && bumpVal) bumpVal.textContent = '+' + fmtRupiah(pcBumpTotal);
    }
  };
  window.pcSetBooking = function (b) { pcBooking = b; window.pcRecalc(); };

  document.querySelectorAll('.pc-bump-checkbox').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var t = 0;
      document.querySelectorAll('.pc-bump-checkbox').forEach(function (c) {
        if (c.checked) t += parseFloat(c.dataset.price) || 0;
      });
      pcBumpTotal = t;
      window.pcRecalc();
    });
  });

  // ── Transfer Bank Manual: toggle picker tujuan transfer ──
  // (handler copy dihapus bersama tombol Salin — nomor rekening kini hanya
  //  tampil & bisa disalin di halaman order/thank-you.)
  var pcBankSelector = document.getElementById('pc-bank-selector');
  var pcTripayCh     = document.getElementById('tripay-channels');
  document.querySelectorAll('input[name="payment_method"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      if (pcBankSelector) pcBankSelector.classList.toggle('hidden', this.value !== 'manual_transfer');
      if (pcTripayCh) pcTripayCh.classList.toggle('hidden', this.value !== 'tripay');
    });
  });

  // Rekening dipilih → pesan error rekening tak relevan lagi.
  document.querySelectorAll('input[name="bank_account_id"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      if (bankErr) bankErr.classList.add('hidden');
    });
  });

  // ── 1. Validasi WA real-time (hanya angka, 10–15 digit) ──
  if (waInput) {
    waInput.addEventListener('input', function () {
      this.value = this.value.replace(/[^0-9+]/g, '');
      var digits = this.value.replace(/[^0-9]/g, '');
      if (waErr) waErr.classList.toggle('hidden', digits.length >= 10 && digits.length <= 15);
    });
  }

  // ── 2. Apply kupon via fetch ──
  if (couponBtn) {
    couponBtn.addEventListener('click', async function () {
      var code = (couponInput.value || '').trim();
      if (!code) { couponResult.innerHTML = '<span class="text-red-500">Masukkan kode kupon dulu.</span>'; return; }

      // Toggle hapus jika sudah diterapkan
      if (couponBtn.dataset.applied === '1') {
        resetCoupon();
        return;
      }

      couponBtn.disabled = true; couponBtn.textContent = 'Memvalidasi...'; couponResult.innerHTML = '';
      try {
        var res = await fetch(APP_URL + '/actions/public/apply-coupon', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'coupon_code=' + encodeURIComponent(code) + '&product_id=<?= (int) $product['id'] ?>'
        });
        var data = await res.json();
        if (data.success) {
          couponResult.innerHTML = '<span class="text-green-600 font-medium">✓ ' + (data.discount_label || 'Kupon diterapkan') + ' · Hemat ' + data.discount_formatted + '</span>';
          if (discRow)   { discRow.classList.remove('hidden'); discRow.classList.add('flex'); }
          if (discLabel) discLabel.textContent = data.discount_label || 'Diskon';
          if (discValue) discValue.textContent = '-' + data.discount_formatted;
          pcCouponPrice = parseFloat(data.new_total) || 0; // produk setelah kupon (tanpa setup/bump)
          window.pcRecalc();
          couponBtn.dataset.applied = '1';
          couponBtn.textContent = 'Hapus';
          couponBtn.style.color = '#dc2626'; couponBtn.style.borderColor = '#fca5a5';
          couponInput.readOnly = true;
        } else {
          resetCoupon();
          couponResult.innerHTML = '<span class="text-red-500">' + (data.message || 'Kupon tidak valid.') + '</span>';
        }
      } catch (e) {
        couponResult.innerHTML = '<span class="text-red-500">Gagal terhubung. Coba lagi.</span>';
      } finally {
        couponBtn.disabled = false;
        if (couponBtn.dataset.applied !== '1') couponBtn.textContent = 'Terapkan';
      }
    });
  }

  function resetCoupon() {
    couponBtn.dataset.applied = '0';
    couponBtn.textContent = 'Terapkan';
    couponBtn.style.color = 'var(--brand-color)';
    couponBtn.style.borderColor = 'var(--brand-color)';
    couponInput.readOnly = false;
    if (discRow) { discRow.classList.add('hidden'); discRow.classList.remove('flex'); }
    pcCouponPrice = null;   // kembali ke harga penuh
    window.pcRecalc();      // total dihitung ulang (tetap hormati bump tercentang)
  }

  // ── 5. Cek email existing saat blur ──
  if (emailInput) {
    emailInput.addEventListener('blur', async function () {
      var email = (this.value || '').trim();
      if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
        if (emailBanner) emailBanner.classList.add('hidden');
        return;
      }
      try {
        var res = await fetch(APP_URL + '/actions/public/check-email', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'email=' + encodeURIComponent(email)
        });
        var data = await res.json();
        if (emailBanner) emailBanner.classList.toggle('hidden', !data.exists);
      } catch (e) { /* silent */ }
    });
  }

  // ── 4. Submit: validasi akhir + loading state ──
  form.addEventListener('submit', function (e) {
    var digits = (waInput.value || '').replace(/[^0-9]/g, '');
    if (digits.length < 10 || digits.length > 15) {
      e.preventDefault();
      if (waErr) waErr.classList.remove('hidden');
      waInput.focus();
      return;
    }
    // Transfer Bank Manual: wajib pilih rekening bila daftar rekening tersedia
    // (bila hanya QR tanpa rekening, tidak wajib).
    var methodSel = document.querySelector('input[name="payment_method"]:checked');
    if (methodSel && methodSel.value === 'manual_transfer') {
      var hasBankOptions = document.querySelector('input[name="bank_account_id"]') !== null;
      var bankChecked    = document.querySelector('input[name="bank_account_id"]:checked');
      if (hasBankOptions && !bankChecked) {
        e.preventDefault();
        // Pesan inline (pola sama dgn wa_err) — halaman checkout publik ini sengaja
        // ramping & TIDAK memuat app.js, jadi showToast/showConfirmModal tak ada
        // di sini. alert() bawaan browser dilarang (tampilan jadul & tak sesuai UI).
        if (bankErr) {
          bankErr.classList.remove('hidden');
          bankErr.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
        return;
      }
    }
    submitBtn.disabled = true;
    if (submitLabel) submitLabel.textContent = 'Memproses...';
  });
})();
</script>
<?php require_once __DIR__ . '/../../tracking/PixelHelper.php'; echo PixelHelper::renderEvent('checkout', ['total' => (float) $price]); ?>
<?php
$spForceContext = 'checkout';
// Social Proof per produk (products.sp_mode) — konteks produk halaman ini.
$spProductId = (int) $product['id'];
$spMode      = in_array($product['sp_mode'] ?? 'all', ['all', 'own', 'off'], true) ? $product['sp_mode'] : 'all';
include __DIR__ . '/../../partials/sp-popup.php'; ?>
<?php include __DIR__ . '/../../partials/sp-checkout-sound.php'; ?>
<?php if ($EMBED): ?>
<script>
(function () {
  var form = document.getElementById('public_checkout_form');
  if (!form) return;

  // ── Break-out: metode gateway (redirect ke halaman gateway eksternal) harus
  //    membuka di jendela atas; transfer manual/moota tetap di dalam iframe
  //    (halaman thank-you Averion satu-origin, aman di-iframe). ──
  var BREAKOUT = ['duitku', 'xendit', 'midtrans', 'tripay', 'paypal'];
  function applyTarget() {
    var sel = document.querySelector('input[name="payment_method"]:checked');
    var v = sel ? sel.value : '';
    form.target = BREAKOUT.indexOf(v) >= 0 ? '_top' : '_self';
  }
  Array.prototype.forEach.call(document.querySelectorAll('input[name="payment_method"]'), function (r) {
    r.addEventListener('change', applyTarget);
  });
  applyTarget();

  // ── Auto-resize: kirim tinggi konten ke halaman induk. ──
  var lastH = 0;
  function postHeight() {
    var h = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
    if (h !== lastH) { lastH = h; try { parent.postMessage({ averionEmbed: true, height: h }, '*'); } catch (e) {} }
  }
  window.addEventListener('load', postHeight);
  window.addEventListener('resize', postHeight);
  if (window.ResizeObserver) { try { new ResizeObserver(postHeight).observe(document.body); } catch (e) {} }
  setInterval(postHeight, 700); // fallback perubahan dinamis (kupon, daftar bank, dsb)
  postHeight();
})();
</script>
<?php endif; ?>
<?php if (!$EMBED) echo funnelHeartbeatScript('product', (int) $product['id'], 'checkout'); // embed (iframe) di-skip: fetch cross-origin ke cookie sesi ?>
</body>
</html>

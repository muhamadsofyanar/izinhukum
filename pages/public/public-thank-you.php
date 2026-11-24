<?php
// ════════════════════════════════════════════════════════════════
// Public Thank-You — konfirmasi order + instruksi bayar (tanpa login)
// Akses divalidasi via $_SESSION['public_order_token'] (berlaku 1 jam).
// Standalone HTML, style match template "clean" public-checkout.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';

// PERTAHANKAN '-'/'_' (order import/migrasi 'INV-20-...-hex'); dipakai di prepared
// statement + dibandingkan case-insensitive dengan token di bawah.
$orderNumber = preg_replace('/[^A-Za-z0-9_-]/', '', trim($_GET['order_number'] ?? ''));

// ─── Validasi token sesi ──────────────────────────────────────
$token = $_SESSION['public_order_token'] ?? null;

if (!is_array($token) || empty($token['order_number'])) {
    redirect('/login');
}
if (empty($token['expires_at']) || time() > (int) $token['expires_at']) {
    unset($_SESSION['public_order_token']);
    flash('auth_info', 'Sesi sudah berakhir. Silakan login untuk melihat order kamu.', 'info');
    redirect('/login');
}
if (strcasecmp((string) $token['order_number'], $orderNumber) !== 0) {
    redirect('/login');
}

// ─── Load order ───────────────────────────────────────────────
$order = $payment = null;
try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        "SELECT o.*, u.name AS buyer_name, u.email AS buyer_email, u.phone AS buyer_phone,
                (SELECT oi.product_name FROM order_items oi WHERE oi.order_id = o.id AND oi.is_bump = 0 ORDER BY oi.id LIMIT 1) AS product_name,
                (SELECT oi.product_id FROM order_items oi WHERE oi.order_id = o.id AND oi.is_bump = 0 ORDER BY oi.id LIMIT 1) AS product_id
         FROM orders o
         JOIN users u ON u.id = o.user_id
         WHERE o.order_number = ?
         LIMIT 1"
    );
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();

    if ($order) {
        $pStmt = $pdo->prepare("SELECT * FROM payments WHERE order_id = ? LIMIT 1");
        $pStmt->execute([(int) $order['id']]);
        $payment = $pStmt->fetch() ?: null;
    }
} catch (Exception $e) {
    error_log('Public thank-you load error: ' . $e->getMessage());
}

if (!$order) {
    unset($_SESSION['public_order_token']);
    redirect('/login');
}

// ─── Settings & branding ──────────────────────────────────────
$appName    = getSetting('app_name', 'STIFIn Mulia');
$appLogo    = getSetting('app_logo', '');
$brandColor = getSetting('brand_color', '#6366f1');
$supportWa  = trim((string) getSetting('vendor_support_whatsapp', ''));
$supportEm  = trim((string) getSetting('vendor_support_email', ''));

$status   = $order['status'];
$isPaid   = $status === 'paid';
$isFailed = in_array($status, ['failed', 'cancelled', 'expired'], true);
$total    = (float) $order['total'];
$gateway  = strtolower((string) ($order['payment_gateway'] ?? ''));
$productId = (int) ($order['product_id'] ?? 0);

// Akun baru dari checkout ini (sudah auto-login) → tampilkan kartu kredensial
// inline + tombol akses langsung. Password plaintext hanya dari session token
// (1 jam), tak pernah dari DB. Akun lama: kosong (segmen B, belum dikerjakan).
$isNewAccount    = !empty($token['is_new_account']);
$newAcctPassword = (string) ($token['new_account_password'] ?? '');
$showCredentials = $isNewAccount && $newAcctPassword !== '';
// Akun LAMA dari checkout tamu (bukan dibuat di checkout ini). Sengaja TIDAK auto-login
// (anti-takeover). Segmen B: mereka dapat magic-link akses ke email+WA (dikirim saat
// pembayaran berhasil) → masuk tanpa password. Di halaman ini: info + tombol kirim ulang.
$isExistingAccount = !$isNewAccount;
// Status kirim-ulang magic-link (dari PRG resend-access-link) — flag saja, tanpa PII.
$resentStatus = (string) ($_GET['resent'] ?? '');

// Redirect Setelah Bayar (per produk): bila diisi admin, pembeli diarahkan ke
// URL tujuan setelah lunas. Halaman ini token-gated (guest), jadi selalu aman
// diarahkan saat paid. Upsell dilewati bila redirect aktif (pilihan admin).
$paidRedirectUrl = $isPaid ? buildPaidRedirectUrl((int) $order['id']) : null;

// Gateway hosted-redirect (Duitku/Xendit/Midtrans/Tripay): ambil payment_url dari
// payments.gateway_response. Midtrans/Tripay WAJIB ikut — tanpa ini order guest
// midtrans/tripay tak menampilkan tombol "Lanjut Bayar" (jatuh ke fallback "cek
// email") padahal payment_url sudah tersimpan. (PayPal tak ditawarkan ke guest.)
$gatewayPayUrl = '';
if (!$isPaid && in_array($gateway, ['duitku', 'xendit', 'midtrans', 'tripay', 'paypal'], true) && !empty($payment['gateway_response'])) {
    $gr = json_decode($payment['gateway_response'], true);
    if (is_array($gr) && !empty($gr['payment_url'])) {
        $gatewayPayUrl = $gr['payment_url'];
    }
}

// Bonus Kilat yang sudah DIKUNCI di order ini (dipilih saat checkout, disuntik
// sbg order_items is_bump price 0). Ditampilkan di halaman pending sebagai pemicu
// "segera bayar". DISPLAY-ONLY — tidak mengubah order sama sekali. Countdown
// mengikuti kedaluwarsa order (created_at + order_expiry_hours) bila fitur expiry
// aktif; bila mati (0), bonus tetap ditampilkan tanpa timer palsu.
$flashBonuses    = [];
$bonusDeadlineTs = 0;
if (!$isPaid && !$isFailed) {
    try {
        $fbStmt = getDB()->prepare(
            "SELECT oi.product_name, COALESCE(p.price, 0) AS orig_price
             FROM order_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = ? AND oi.is_bump = 1 AND oi.price = 0
             ORDER BY oi.id"
        );
        $fbStmt->execute([(int) $order['id']]);
        $flashBonuses = $fbStmt->fetchAll();

        // Countdown MENERUSKAN timer Bonus Kilat di checkout (scarcity): cookie
        // fb_start_{main_product_id} (waktu kunjungan pertama) + duration_minutes,
        // sama persis dgn perhitungan di halaman checkout. Cookie hilang/kedaluwarsa
        // atau beda perangkat → tanpa timer (bonus tetap tampil).
        if (!empty($flashBonuses)) {
            $mpStmt = getDB()->prepare("SELECT product_id FROM order_items WHERE order_id = ? AND is_bump = 0 ORDER BY id LIMIT 1");
            $mpStmt->execute([(int) $order['id']]);
            $mainPid = (int) $mpStmt->fetchColumn();
            $startTs = ($mainPid > 0 && isset($_COOKIE['fb_start_' . $mainPid])) ? (int) $_COOKIE['fb_start_' . $mainPid] : 0;
            if ($startTs > 0) {
                $dmStmt = getDB()->prepare("SELECT duration_minutes FROM flash_bonus_settings WHERE main_product_id = ? LIMIT 1");
                $dmStmt->execute([$mainPid]);
                $durMin = max(1, (int) ($dmStmt->fetchColumn() ?: 60));
                $ts = $startTs + $durMin * 60;
                if ($ts > time()) $bonusDeadlineTs = $ts; // sudah lewat → tanpa timer
            }
        }
    } catch (Throwable $e) { $flashBonuses = []; }
}

// Moota: info rekening tujuan (free-text dari config)
$mootaBank = '';
if (!$isPaid && $gateway === 'moota') {
    require_once __DIR__ . '/../../payment_gateways/MootaGateway.php';
    $mootaBank = (new MootaGateway())->bankInfo();
}

// Transfer Bank Manual (rekening/e-wallet pilihan sendiri, konfirmasi admin
// via upload bukti): rekening/akun yang dipilih saat checkout + QR Pay global
// — dulu halaman ini TIDAK menampilkan info ini sama sekali (cuma "cek
// email"), buyer guest tanpa akses email langsung buntu.
$isManualTransferPay = !$isPaid && (($payment['method'] ?? '') === 'manual_transfer');
$mtBank = null;
$mtQrImage = null;
if ($isManualTransferPay) {
    $mtQrImage = getSetting('manual_qr_image');
    if (!empty($payment['bank_account_id'])) {
        $mtbStmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE id = ? LIMIT 1");
        $mtbStmt->execute([(int) $payment['bank_account_id']]);
        $mtBank = $mtbStmt->fetch() ?: null;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $isPaid ? 'Pembayaran Berhasil' : 'Konfirmasi Order' ?> — <?= e($appName) ?></title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚡</text></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
  <!-- Tailwind compiled lokal (tanpa CDN) — regenerate: tools/build-tailwind.php -->
  <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/tailwind.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/tailwind.css') ?: '1' ?>">
  <style>
    :root { --brand-color: <?= e($brandColor) ?>; }
    body { font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, sans-serif; }
  </style>
<?php
// Event funnel browser (dedup CAPI via eventID): paid → Purchase, pending → Lead.
require_once __DIR__ . '/../../tracking/PixelHelper.php';
if ($isPaid) {
    echo PixelHelper::renderEvent('paid', ['order_number' => $order['order_number'], 'total' => $total]);
} elseif (!$isFailed) {
    echo PixelHelper::renderEvent('invoice', ['order_number' => $order['order_number'], 'total' => $total]);
}
?>
</head>
<body class="bg-gray-50 text-gray-900 antialiased" style="background:#f9fafb;">
<div class="min-h-screen flex items-start sm:items-center justify-center px-4 py-8">
  <div class="w-full max-w-[560px]">

    <!-- Header -->
    <div class="flex items-center justify-center gap-2 mb-5">
      <?php if ($appLogo): ?>
      <img src="<?= e($appLogo) ?>" alt="<?= e($appName) ?>" class="h-7 object-contain">
      <?php else: ?>
      <div class="w-7 h-7 rounded-lg flex items-center justify-center text-white text-xs font-bold" style="background: var(--brand-color);"><?= e(strtoupper(substr($appName, 0, 1))) ?></div>
      <?php endif; ?>
      <span class="font-bold text-sm"><?= e($appName) ?></span>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-7">

      <!-- 2. STATUS CARD -->
      <div id="status_card" class="text-center pb-6 border-b border-gray-100">
        <?php if ($isPaid): ?>
        <div id="status_icon" class="w-16 h-16 mx-auto rounded-full bg-green-100 flex items-center justify-center mb-3">
          <svg class="w-8 h-8 text-green-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <h1 id="status_title" class="text-xl font-bold">Pembayaran Berhasil! 🎉</h1>
        <p id="status_sub" class="text-sm text-gray-500 mt-1">Order #<?= e($order['order_number']) ?> sudah dikonfirmasi.</p>
        <?php if ($paidRedirectUrl): ?>
        <p class="text-sm text-gray-500 mt-2 inline-flex items-center gap-2">
          <svg class="w-4 h-4 animate-spin text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
          Mengalihkan ke halaman berikutnya…
        </p>
        <?php endif; ?>
        <?php elseif ($isFailed): ?>
        <div id="status_icon" class="w-16 h-16 mx-auto rounded-full bg-red-100 flex items-center justify-center mb-3">
          <svg class="w-8 h-8 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </div>
        <h1 id="status_title" class="text-xl font-bold"><?= $status === 'cancelled' ? 'Order Dibatalkan' : ($status === 'expired' ? 'Order Kedaluwarsa' : 'Pembayaran Gagal') ?></h1>
        <p id="status_sub" class="text-sm text-gray-500 mt-1">Order #<?= e($order['order_number']) ?></p>
        <?php else: ?>
        <div id="status_icon" class="w-16 h-16 mx-auto rounded-full bg-orange-100 flex items-center justify-center mb-3">
          <svg class="w-8 h-8 text-orange-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <h1 id="status_title" class="text-xl font-bold">Menunggu Pembayaran</h1>
        <p id="status_sub" class="text-sm text-gray-500 mt-1">Selesaikan pembayaran sebelum order kedaluwarsa.</p>
        <?php endif; ?>
      </div>

      <?php if ($showCredentials): ?>
      <!-- ─── AKSES & KREDENSIAL AKUN BARU (buyer baru, sudah auto-login) ─── -->
      <div class="pt-5">
        <?php if ($isPaid): ?>
        <a href="<?= e(APP_URL) ?>/member<?= $productId > 0 ? '/' . $productId : '' ?>"
           class="flex items-center justify-center gap-2 w-full py-3 rounded-xl text-sm font-bold text-white transition hover:opacity-90 mb-4"
           style="background-color: var(--brand-color);">
          <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
          Akses <?= e($order['product_name'] ?? 'Produk') ?> Sekarang
        </a>
        <?php endif; ?>

        <div class="rounded-xl bg-gray-50 border border-gray-200 p-4">
          <p class="text-sm font-semibold text-gray-800 flex items-center gap-2">
            <svg class="w-4 h-4 shrink-0 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Akun kamu sudah dibuat
          </p>
          <p class="text-xs text-gray-500 mt-1 mb-3">Simpan detail login ini. Di perangkat ini kamu sudah otomatis masuk.</p>

          <div class="space-y-2">
            <div class="flex items-center justify-between gap-2 bg-white border border-gray-200 rounded-lg px-3 py-2">
              <span class="text-sm text-gray-700 truncate min-w-0"><span class="text-gray-400">Email:</span> <strong><?= e($order['buyer_email']) ?></strong></span>
              <button type="button" class="copy-btn text-xs font-semibold px-2 py-1 rounded-md border border-gray-300 text-gray-600 transition shrink-0" data-copy="<?= e($order['buyer_email']) ?>">Salin</button>
            </div>
            <div class="flex items-center justify-between gap-2 bg-white border border-gray-200 rounded-lg px-3 py-2">
              <span class="text-sm text-gray-700 truncate min-w-0"><span class="text-gray-400">Password:</span> <strong class="font-mono"><?= e($newAcctPassword) ?></strong></span>
              <button type="button" class="copy-btn text-xs font-semibold px-2 py-1 rounded-md border border-gray-300 text-gray-600 transition shrink-0" data-copy="<?= e($newAcctPassword) ?>">Salin</button>
            </div>
          </div>

          <p class="text-xs text-amber-600 mt-3">Demi keamanan, segera ganti password saat pertama kali login.</p>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($isExistingAccount): ?>
      <!-- ─── AKSES INSTAN AKUN LAMA (magic-link ke email+WA, Segmen B) ─── -->
      <div class="pt-5">
        <div class="rounded-xl bg-gray-50 border border-gray-200 p-4">
          <p class="text-sm font-semibold text-gray-800 flex items-center gap-2">
            <svg class="w-4 h-4 shrink-0 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 7h3a5 5 0 0 1 0 10h-3"/><path d="M9 17H6A5 5 0 0 1 6 7h3"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            Masuk ke area member tanpa password
          </p>
          <?php if ($isPaid): ?>
          <p class="text-xs text-gray-500 mt-1 mb-3">Kami sudah mengirim link akses sekali-klik ke email &amp; WhatsApp kamu (<strong><?= e($order['buyer_email']) ?></strong>). Klik link itu untuk langsung masuk — tanpa perlu ingat password. Link berlaku <?= (int) MAGIC_LOGIN_TTL_MINUTES ?> menit.</p>
          <?php if ($resentStatus === '1'): ?>
          <p class="text-xs font-medium text-emerald-600 mb-3">Link akses berhasil dikirim ulang ke email &amp; WhatsApp kamu.</p>
          <?php elseif ($resentStatus === 'wait'): ?>
          <p class="text-xs font-medium text-amber-600 mb-3">Tunggu sebentar sebelum mengirim ulang lagi ya.</p>
          <?php elseif ($resentStatus === '0'): ?>
          <p class="text-xs font-medium text-red-600 mb-3">Gagal mengirim ulang. Coba lagi beberapa saat lagi.</p>
          <?php endif; ?>
          <form method="post" action="<?= e(APP_URL) ?>/actions/public/resend-access-link">
            <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
            <input type="hidden" name="order_number" value="<?= e($orderNumber) ?>">
            <button type="submit" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl text-sm font-medium border border-gray-200 text-gray-600 transition">
              <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
              Kirim ulang link akses
            </button>
          </form>
          <?php else: ?>
          <p class="text-xs text-gray-500 mt-1">Setelah pembayaran kamu dikonfirmasi, kami kirim link akses sekali-klik ke email &amp; WhatsApp kamu — langsung masuk tanpa perlu ingat password.</p>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php
      // ─── Upsell post-purchase ───
      // Halaman ini sudah digerbangi public_order_token (divalidasi di atas),
      // jadi penawaran aman ditampilkan juga untuk TAMU yang tidak login —
      // buyer lama dari checkout publik sengaja tidak auto-login, dulu mereka
      // tak pernah melihat penawaran ini. accept-upsell menerima token yang sama.
      $upsellOffer = null;
      if ($isPaid && !$paidRedirectUrl) {
          generateCSRF();
          $upsellOffer = getUpsellOffer((int) $order['id'], (int) $order['user_id']);
      }
      include __DIR__ . '/../../partials/_upsell-offer.php';
      ?>

      <?php require __DIR__ . '/../../partials/order-confirmation-wa.php'; ?>
      <!-- 3. RINGKASAN ORDER -->
      <div class="py-5 space-y-2.5 text-sm border-b border-gray-100">
        <div class="flex items-center justify-between">
          <span class="text-gray-500">No. Order</span>
          <span class="font-mono font-medium"><?= e($order['order_number']) ?></span>
        </div>
        <div class="flex items-center justify-between gap-4">
          <span class="text-gray-500 shrink-0">Produk</span>
          <span class="font-medium text-right"><?= e($order['product_name'] ?? '-') ?></span>
        </div>
        <div class="flex items-center justify-between pt-1">
          <span class="text-gray-500">Total Bayar</span>
          <span class="text-lg font-bold" style="color: var(--brand-color);"><?= $total > 0 ? e(formatRupiah($total)) : 'GRATIS' ?></span>
        </div>
      </div>

      <!-- 3b. BONUS KILAT TERKUNCI (pemicu segera bayar — display-only) -->
      <?php if (!$isPaid && !$isFailed && !empty($flashBonuses)): ?>
      <div class="py-5 border-b border-gray-100">
        <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-4">
          <div class="flex items-center justify-between gap-2 mb-2.5">
            <p class="text-sm font-semibold text-emerald-800 flex items-center gap-1.5">
              <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
              Bonus gratis sudah dikunci untuk order ini
            </p>
            <?php if ($bonusDeadlineTs > 0): ?>
            <span id="bonus_countdown" class="text-xs font-bold text-white rounded-full px-2.5 py-1 tabular-nums shrink-0" style="background:#059669">--:--</span>
            <?php endif; ?>
          </div>
          <ul class="space-y-1.5">
            <?php foreach ($flashBonuses as $fbItem): ?>
            <li class="flex items-center justify-between gap-3 text-sm">
              <span class="text-gray-700 flex items-center gap-1.5 min-w-0">
                <svg class="w-3.5 h-3.5 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span class="truncate"><?= e($fbItem['product_name']) ?></span>
              </span>
              <?php if ((float) $fbItem['orig_price'] > 0): ?>
              <span class="text-xs text-gray-400 shrink-0"><s>Senilai <?= e(formatRupiah((float) $fbItem['orig_price'])) ?></s> <span class="text-emerald-600 font-semibold">GRATIS</span></span>
              <?php else: ?>
              <span class="text-xs text-emerald-600 font-semibold shrink-0">GRATIS</span>
              <?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
          <p class="text-xs text-emerald-700 mt-2.5">
            <?php if ($bonusDeadlineTs > 0): ?>
            Selesaikan pembayaran sebelum waktu habis — bonus gratis ini akan hangus saat hitung mundur berakhir.
            <?php else: ?>
            Selesaikan pembayaran untuk mengaktifkan produk &amp; semua bonus ini.
            <?php endif; ?>
          </p>
        </div>
      </div>
      <?php if ($bonusDeadlineTs > 0): ?>
      <script>
      (function () {
        var el = document.getElementById('bonus_countdown'); if (!el) return;
        var end = <?= (int) $bonusDeadlineTs ?> * 1000;
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        function tick() {
          var s = Math.max(0, Math.floor((end - Date.now()) / 1000));
          var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
          el.textContent = (h > 0 ? pad(h) + ':' : '') + pad(m) + ':' + pad(sec);
          if (s <= 0) { el.textContent = '00:00'; return; }
          setTimeout(tick, 1000);
        }
        tick();
      })();
      </script>
      <?php endif; ?>
      <?php endif; ?>

      <!-- 4. INSTRUKSI PEMBAYARAN (hanya jika belum lunas) -->
      <?php if (!$isPaid && !$isFailed): ?>
      <div id="payment_instructions" class="py-5 border-b border-gray-100">

        <?php if ($gateway === 'moota'): ?>
        <!-- ── MOOTA: transfer bank nominal unik ── -->
        <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-4 space-y-3">
          <p class="text-sm font-semibold text-emerald-800">Selesaikan via Transfer Bank</p>
          <div>
            <p class="text-xs text-gray-500 mb-1">Transfer <strong>TEPAT</strong> sebesar:</p>
            <div class="flex items-center gap-2">
              <p class="text-2xl font-bold text-gray-900" id="moota_amount"><?= e(formatRupiah($total)) ?></p>
              <button type="button" class="copy-btn text-xs font-semibold px-2 py-1 rounded-md border border-emerald-300 text-emerald-700 hover:bg-emerald-100 transition"
                      data-copy="<?= (int) round($total) ?>">Salin</button>
            </div>
            <p class="text-xs text-amber-600 mt-1">Nominal harus persis (termasuk 3 digit kode unik) agar terverifikasi otomatis.</p>
          </div>
          <?php if ($mootaBank !== ''): ?>
          <div>
            <p class="text-xs text-gray-500 mb-1">Ke rekening:</p>
            <p class="text-sm font-medium text-gray-900 whitespace-pre-line"><?= e($mootaBank) ?></p>
          </div>
          <?php endif; ?>
          <div class="text-xs text-gray-600 bg-white/70 rounded-lg p-2.5">
            Sertakan kode <strong class="font-mono"><?= e($order['order_number']) ?></strong> di berita transfer. Pembayaran terkonfirmasi <strong>otomatis</strong> beberapa menit setelah transfer masuk.
          </div>
        </div>

        <?php elseif (in_array($gateway, ['duitku', 'xendit', 'midtrans', 'tripay', 'paypal'], true)): ?>
        <!-- ── DUITKU / XENDIT / MIDTRANS / TRIPAY / PAYPAL: halaman pembayaran hosted ── -->
        <?php if ($gatewayPayUrl !== ''): ?>
        <p class="text-sm text-gray-600 mb-3">Klik tombol di bawah untuk menyelesaikan pembayaran (Virtual Account, e-wallet, QRIS, atau kartu) melalui halaman aman <?= e(ucfirst($gateway)) ?>.</p>
        <a href="<?= e($gatewayPayUrl) ?>" target="_blank" rel="noopener"
           class="flex items-center justify-center gap-2 w-full py-3 rounded-xl text-sm font-bold text-white transition hover:opacity-90"
           style="background-color: var(--brand-color);">
          Lanjut Bayar →
        </a>
        <p class="text-xs text-gray-400 text-center mt-2">Halaman pembayaran terbuka di tab baru.</p>
        <?php else: ?>
        <div class="rounded-xl bg-blue-50 border border-blue-200 p-4 text-sm text-blue-700">
          Link pembayaran sedang disiapkan. Silakan cek email kamu untuk instruksi pembayaran, atau klik <strong>Cek Status</strong> di bawah.
        </div>
        <?php endif; ?>

        <?php elseif ($isManualTransferPay): ?>
        <!-- ── TRANSFER BANK MANUAL: QR + rekening/akun pilihan, konfirmasi admin ── -->
        <div class="rounded-xl bg-gray-50 border border-gray-200 p-4 space-y-3">
          <p class="text-sm font-semibold text-gray-800">Selesaikan Pembayaran</p>
          <div>
            <p class="text-xs text-gray-500 mb-1">Nominal transfer:</p>
            <p class="text-2xl font-bold text-gray-900"><?= e(formatRupiah($total)) ?></p>
            <p class="text-xs text-amber-600 mt-1">Transfer <strong>tepat</strong> sesuai nominal di atas (hingga 3 digit terakhir) agar cepat diverifikasi.</p>
          </div>

          <?php if (!empty($mtQrImage)): ?>
          <div class="flex flex-col items-center rounded-xl border border-gray-200 bg-white p-4">
            <img src="<?= e(APP_URL) ?>/<?= e($mtQrImage) ?>" alt="QR Pay" class="w-40 h-40 object-contain rounded-lg p-2">
            <p class="mt-2 text-xs font-medium text-gray-600">Scan QR Pay</p>
          </div>
          <?php if ($mtBank): ?>
          <div class="flex items-center gap-3 py-0.5">
            <span class="flex-1 h-px bg-gray-200"></span>
            <span class="text-xs font-medium text-gray-400">atau manual transfer</span>
            <span class="flex-1 h-px bg-gray-200"></span>
          </div>
          <?php endif; ?>
          <?php endif; ?>

          <?php if ($mtBank): ?>
          <div class="bg-white rounded-lg border border-gray-200 p-3">
            <p class="text-xs text-gray-500 mb-1">Transfer ke <?= ($mtBank['channel_type'] ?? 'bank') === 'ewallet' ? 'akun' : 'rekening' ?>:</p>
            <p class="text-sm font-semibold text-gray-900"><?= e($mtBank['bank_name']) ?></p>
            <p class="text-sm font-mono text-gray-700"><?= e($mtBank['account_number']) ?></p>
            <p class="text-sm text-gray-500"><?= e($mtBank['account_name']) ?></p>
            <?php if (!empty($mtBank['instructions'])): ?>
            <p class="text-xs text-amber-600 mt-1"><?= e($mtBank['instructions']) ?></p>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <div class="text-xs text-gray-600 bg-white/70 rounded-lg p-2.5">
            Setelah transfer, <strong>login</strong> lalu upload bukti transfer di halaman order kamu — akses produk aktif setelah dikonfirmasi admin.
          </div>
        </div>

        <?php else: ?>
        <!-- ── FALLBACK / MANUAL: menunggu konfirmasi admin ── -->
        <div class="rounded-xl bg-blue-50 border border-blue-200 p-4 text-sm text-blue-700">
          <p><strong>Menunggu konfirmasi pembayaran.</strong></p>
          <p class="mt-1">Instruksi pembayaran sudah dikirim ke email <strong><?= e($order['buyer_email']) ?></strong>. Akses produk aktif otomatis setelah pembayaran dikonfirmasi.</p>
        </div>
        <?php endif; ?>

      </div>
      <?php endif; ?>

      <!-- 5. TOMBOL AKSI -->
      <div class="pt-5 space-y-2.5">
        <?php if (!$isPaid && !$isFailed): ?>
        <button type="button" id="check_status_btn"
                class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl text-sm font-semibold border transition hover:bg-gray-50"
                style="border-color: var(--brand-color); color: var(--brand-color);">
          <span id="check_status_label">Sudah Bayar? Cek Status</span>
        </button>
        <?php endif; ?>

        <a href="mailto:<?= e($order['buyer_email']) ?>"
           class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl text-sm font-medium border border-gray-200 text-gray-600 hover:bg-gray-50 transition">
          Cek Email Saya
        </a>

        <?php if (!($isPaid && $isNewAccount)): // akun baru paid sudah punya tombol "Akses ... Sekarang" di atas ?>
        <a href="<?= e(APP_URL) ?>/login"
           class="flex items-center justify-center gap-2 w-full py-3 rounded-xl text-sm font-bold text-white transition hover:opacity-90"
           style="background-color: var(--brand-color);">
          Login ke Dashboard
        </a>
        <p class="text-center text-xs text-gray-400"><?= $isPaid ? 'Login untuk mengakses produk kamu.' : 'Login untuk akses produk setelah pembayaran dikonfirmasi.' ?></p>
        <?php endif; ?>
      </div>

    </div>

    <!-- 6. FOOTER -->
    <div class="text-center mt-5 space-y-1">
      <?php if ($supportWa !== '' || $supportEm !== ''): ?>
      <p class="text-xs text-gray-400">
        Butuh bantuan?
        <?php if ($supportWa !== ''): $waDigits = preg_replace('/[^0-9]/', '', $supportWa); ?>
        <a href="https://wa.me/<?= e($waDigits) ?>" target="_blank" rel="noopener" class="font-medium hover:underline" style="color: var(--brand-color);">Chat WhatsApp</a>
        <?php elseif ($supportEm !== ''): ?>
        <a href="mailto:<?= e($supportEm) ?>" class="font-medium hover:underline" style="color: var(--brand-color);">Email kami</a>
        <?php endif; ?>
      </p>
      <?php else: ?>
      <p class="text-xs text-gray-400">Butuh bantuan? Balas email konfirmasi yang kami kirim.</p>
      <?php endif; ?>
      <p class="text-[11px] text-gray-400">© <?= date('Y') ?> <?= e($appName) ?></p>
    </div>

  </div>
</div>

<!-- Toast copy -->
<div id="copy_toast" class="hidden fixed bottom-5 left-1/2 -translate-x-1/2 bg-gray-900 text-white text-xs px-4 py-2 rounded-lg shadow-lg">Disalin!</div>

<script>
(function () {
  var APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
  var ORDER_NUMBER = <?= json_encode($order['order_number']) ?>;
  var toast = document.getElementById('copy_toast');

  function showToast(msg) {
    if (!toast) return;
    toast.textContent = msg || 'Disalin!';
    toast.classList.remove('hidden');
    setTimeout(function () { toast.classList.add('hidden'); }, 1600);
  }

  // ── Copy buttons ──
  document.querySelectorAll('.copy-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var val = String(btn.getAttribute('data-copy') || '');
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(val).then(function () { showToast('Disalin: ' + val); });
      } else {
        var ta = document.createElement('textarea');
        ta.value = val; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); showToast('Disalin: ' + val); } catch (e) {}
        document.body.removeChild(ta);
      }
    });
  });

  // ── Cek status (AJAX) ──
  var btn   = document.getElementById('check_status_btn');
  var label = document.getElementById('check_status_label');

  function renderPaid() {
    var card = document.getElementById('status_card');
    if (card) {
      card.innerHTML =
        '<div class="w-16 h-16 mx-auto rounded-full bg-green-100 flex items-center justify-center mb-3">' +
        '<svg class="w-8 h-8 text-green-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg></div>' +
        '<h1 class="text-xl font-bold">Pembayaran Berhasil! 🎉</h1>' +
        '<p class="text-sm text-gray-500 mt-1">Order #' + ORDER_NUMBER + ' sudah dikonfirmasi.</p>';
    }
    var instr = document.getElementById('payment_instructions');
    if (instr) instr.remove();
    if (btn) btn.remove();
  }

  // ── Status live: auto-poll tiap 5 detik (tanpa perlu klik) ──
  // Buyer membayar di tab gateway → begitu webhook konfirmasi masuk,
  // halaman ini berubah "Pembayaran Berhasil" sendiri. Berhenti saat
  // paid / sesi token habis; skip saat tab tersembunyi (hemat).
  if (btn) {
    var autoPoll = setInterval(async function () {
      if (document.hidden) return;
      try {
        var res = await fetch(APP_URL + '/actions/public/check-order-status', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'order_number=' + encodeURIComponent(ORDER_NUMBER)
        });
        var data = await res.json();
        if (data.paid) {
          clearInterval(autoPoll);
          // Reload penuh: server merender state lunas lengkap + penawaran upsell.
          showToast('Pembayaran dikonfirmasi!');
          setTimeout(function () { window.location.reload(); }, 600);
        } else if (data.status === 'invalid') {
          clearInterval(autoPoll); // sesi habis — biarkan tombol manual yang bicara
        } else if (data.status === 'expired' || data.status === 'failed' || data.status === 'cancelled') {
          // Order jadi terminal (mis. kedaluwarsa) saat ditonton → refresh ke tampilannya.
          clearInterval(autoPoll);
          window.location.reload();
        }
      } catch (e) { /* transient — tick berikutnya */ }
    }, 5000);
  }

  if (btn) {
    btn.addEventListener('click', async function () {
      btn.disabled = true;
      if (label) label.textContent = 'Mengecek...';
      try {
        var res = await fetch(APP_URL + '/actions/public/check-order-status', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'order_number=' + encodeURIComponent(ORDER_NUMBER)
        });
        var data = await res.json();
        if (data.paid) {
          showToast('Pembayaran dikonfirmasi!');
          setTimeout(function () { window.location.reload(); }, 600);
        } else if (data.status === 'invalid') {
          showToast('Sesi berakhir');
          setTimeout(function () { window.location.href = APP_URL + '/login'; }, 1200);
        } else {
          if (label) label.textContent = 'Belum terbayar — Cek Lagi';
          showToast(data.message || 'Masih menunggu pembayaran');
        }
      } catch (e) {
        if (label) label.textContent = 'Gagal — Coba Lagi';
      } finally {
        btn.disabled = false;
      }
    });
  }
})();
</script>
<?php if ($paidRedirectUrl): ?>
<script>
  // Redirect Setelah Bayar — tunda ~2,5 dtk agar event pixel Purchase (dirender
  // di <head> saat state lunas) sempat terkirim sebelum meninggalkan halaman.
  setTimeout(function () {
    window.location.replace(<?= json_encode($paidRedirectUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
  }, 2500);
</script>
<?php endif; ?>
</body>
</html>

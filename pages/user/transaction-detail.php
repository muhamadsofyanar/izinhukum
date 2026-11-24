<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

// PERTAHANKAN '-' dan '_': order hasil import/migrasi berformat 'INV-20-...-hex'
// atau 'SEJOLI-123'. Membuang '-' (versi lama) membuat lookup gagal → 404.
// Dipakai di prepared statement, jadi aman. Tak di-uppercase agar cocok apa adanya.
$orderNumber = preg_replace('/[^A-Za-z0-9_-]/', '', trim($_GET['order_number'] ?? ''));
if ($orderNumber === '') {
    flash('error', 'Order tidak ditemukan.', 'error');
    redirect('/transactions');
}

$userId = (int) $_SESSION['user_id'];
$order  = null;
$items  = [];
$payment = null;

try {
    $pdo = getDB();

    $stmt = $pdo->prepare(
        'SELECT o.*, u.name AS user_name, u.email AS user_email
         FROM orders o
         JOIN users u ON o.user_id = u.id
         WHERE o.order_number = ? AND o.user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$orderNumber, $userId]);
    $order = $stmt->fetch();

    if (!$order) {
        flash('error', 'Order tidak ditemukan.', 'error');
        redirect('/transactions');
    }

    $stmt = $pdo->prepare(
        'SELECT oi.*, p.slug AS product_slug, p.type AS product_type, p.thumbnail
         FROM order_items oi
         LEFT JOIN products p ON oi.product_id = p.id
         WHERE oi.order_id = ?
         ORDER BY oi.id'
    );
    $stmt->execute([$order['id']]);
    $items = $stmt->fetchAll();

    // Nama bundle bila ini order paket → header "Paket" di atas daftar produk.
    $orderBundleName = '';
    foreach ($items as $it) {
        if ((int) ($it['is_bundle'] ?? 0) === 1 && (int) ($it['bundle_id'] ?? 0) > 0) {
            $bs = $pdo->prepare('SELECT name FROM product_bundles WHERE id = ? LIMIT 1');
            $bs->execute([(int) $it['bundle_id']]);
            $orderBundleName = (string) ($bs->fetchColumn() ?: '');
            break;
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM payments WHERE order_id = ? LIMIT 1');
    $stmt->execute([$order['id']]);
    $payment = $stmt->fetch() ?: null;

} catch (Exception $e) {
    error_log('Transaction detail error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat memuat detail transaksi.', 'error');
    redirect('/transactions');
}

generateCSRF();
$pageTitle   = 'Order ' . $orderNumber;
$topbarTitle = 'Detail Transaksi';

// Redirect Setelah Bayar (per produk): hanya auto-arahkan untuk pembelian yang
// BARU lunas (≤60 mnt) — halaman ini juga dibuka member untuk melihat invoice
// lama, jangan sampai mereka ikut terlempar. Order booking (akses utama belum
// terbuka) dikecualikan. Upsell dilewati bila redirect aktif.
$paidRedirectUrl = null;
if ($order['status'] === 'paid' && ($order['order_stage'] ?? 'full') !== 'booking') {
    $paidAtTs = !empty($order['paid_at']) ? strtotime((string) $order['paid_at']) : 0;
    if ($paidAtTs && (time() - $paidAtTs) <= 3600) {
        $paidRedirectUrl = buildPaidRedirectUrl((int) $order['id']);
    }
}

// ─── Gateway hosted-redirect (Duitku/Xendit/Midtrans/Tripay/PayPal): URL bayar tersimpan ──
// Disimpan create-payment/submit-public-checkout di payments.gateway_response
// → tombol "Bayar Sekarang" persisten (tahan refresh, buka di tab baru).
// SEMUA gateway hosted WAJIB ikut: alur checkout me-redirect ke halaman ini setelah
// order dibuat; kalau gateway-nya tak dikenali di sini, panel "Menunggu Pembayaran" +
// tombol bayar tak tampil → pembeli terjebak tanpa cara melanjutkan pembayaran
// (payment_url-nya sudah tersimpan). Pernah menggigit paypal, midtrans, & tripay.
$orderGateway  = strtolower((string) ($order['payment_gateway'] ?? ''));
$isHostedGw    = in_array($orderGateway, ['duitku', 'xendit', 'midtrans', 'tripay', 'paypal'], true);
$gatewayPayUrl = '';
if ($isHostedGw && !empty($payment['gateway_response'])) {
    $gr = json_decode($payment['gateway_response'], true);
    if (is_array($gr) && !empty($gr['payment_url'])) {
        $gatewayPayUrl = (string) $gr['payment_url'];
    }
}

$statusConfig = match ($order['status']) {
    'paid'      => ['label' => 'Lunas',       'bg' => 'bg-green-100 dark:bg-green-900/20',  'text' => 'text-green-700 dark:text-green-400',  'icon_color' => 'text-green-500'],
    'pending'   => ['label' => 'Menunggu',    'bg' => 'bg-yellow-100 dark:bg-yellow-900/20','text' => 'text-yellow-700 dark:text-yellow-400','icon_color' => 'text-yellow-500'],
    'failed'    => ['label' => 'Gagal',       'bg' => 'bg-red-100 dark:bg-red-900/20',      'text' => 'text-red-700 dark:text-red-400',      'icon_color' => 'text-red-500'],
    'cancelled' => ['label' => 'Dibatalkan',  'bg' => 'bg-gray-100 dark:bg-gray-800',       'text' => 'text-gray-600 dark:text-gray-400',    'icon_color' => 'text-gray-400'],
    'expired'   => ['label' => 'Kedaluwarsa', 'bg' => 'bg-orange-100 dark:bg-orange-900/20', 'text' => 'text-orange-700 dark:text-orange-400','icon_color' => 'text-orange-500'],
    default     => ['label' => ucfirst($order['status']), 'bg' => 'bg-gray-100', 'text' => 'text-gray-600', 'icon_color' => 'text-gray-400'],
};
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="max-w-2xl mx-auto">

        <!-- Back -->
        <a href="<?= e(APP_URL) ?>/transactions" class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400 hover:text-[var(--brand-color)] transition mb-5">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
          Kembali ke Transaksi
        </a>

        <!-- Status Banner -->
        <div class="<?= $statusConfig['bg'] ?> rounded-xl p-5 mb-4 flex items-center gap-4">
          <?php if ($order['status'] === 'paid'): ?>
          <div class="w-12 h-12 rounded-full bg-green-500/10 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
              <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
          </div>
          <?php elseif ($order['status'] === 'pending'): ?>
          <div class="w-12 h-12 rounded-full bg-yellow-500/10 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6 text-yellow-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
            </svg>
          </div>
          <?php else: ?>
          <div class="w-12 h-12 rounded-full bg-red-500/10 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6 text-red-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
            </svg>
          </div>
          <?php endif; ?>
          <div>
            <p class="<?= $statusConfig['text'] ?> font-bold text-base"><?= $statusConfig['label'] ?></p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
              Order #<?= e($order['order_number']) ?>
              &middot; <?= e(date('d M Y, H:i', strtotime($order['created_at']))) ?>
            </p>
            <?php if ($paidRedirectUrl): ?>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 inline-flex items-center gap-1.5">
              <svg class="w-3.5 h-3.5 animate-spin text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
              Mengalihkan ke halaman berikutnya…
            </p>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($paidRedirectUrl): ?>
        <script>
          // Redirect Setelah Bayar — jeda singkat agar halaman sempat tampil
          // (Purchase CAPI sudah dikirim server-side saat order lunas).
          setTimeout(function () {
            window.location.replace(<?= json_encode($paidRedirectUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
          }, 2500);
        </script>
        <?php endif; ?>

      <?php require __DIR__ . '/../../partials/order-confirmation-wa.php'; ?>
        <?php
        // Booking Fee: info tahap order (booking / pelunasan) + deadline pelunasan.
        $orderStage = $order['order_stage'] ?? 'full';
        ?>
        <?php if ($orderStage === 'settlement' && $order['status'] === 'pending' && !empty($order['due_at'])): ?>
        <div class="bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800/40 rounded-xl p-4 mb-4">
          <p class="text-sm text-amber-800 dark:text-amber-300">
            <strong>Order pelunasan booking.</strong> Selesaikan pembayaran sebelum
            <strong><?= e(date('d M Y H:i', strtotime($order['due_at']))) ?></strong> — lewat batas waktu, booking hangus dan akses tidak diberikan.
          </p>
        </div>
        <?php elseif ($orderStage === 'booking' && $order['status'] === 'paid'): ?>
        <div class="bg-indigo-50 dark:bg-indigo-900/10 border border-indigo-200 dark:border-indigo-800/40 rounded-xl p-4 mb-4">
          <p class="text-sm text-indigo-800 dark:text-indigo-300">
            <strong>Booking fee dibayar — slot &amp; harga kamu terkunci.</strong> Link pembayaran pelunasan dikirim ke email/WhatsApp kamu (juga muncul di daftar transaksi). Akses konten terbuka setelah lunas.
          </p>
        </div>
        <?php endif; ?>

        <?php
        // ─── Upsell post-purchase: penawaran sekali-lihat setelah lunas ───
        // (bukan untuk order booking fee — akses utama saja belum terbuka)
        $upsellOffer = ($order['status'] === 'paid' && $orderStage !== 'booking' && !$paidRedirectUrl)
            ? getUpsellOffer((int) $order['id'], $userId)
            : null;
        include __DIR__ . '/../../partials/_upsell-offer.php';
        ?>

        <?php if ($order['status'] === 'pending'): ?>
          <?php if (($order['payment_gateway'] ?? '') === 'moota'):
              require_once __DIR__ . '/../../payment_gateways/MootaGateway.php';
              $mootaBank = (new MootaGateway())->bankInfo();
              // Nominal mentah utk disalin (tanpa "Rp"/titik) → tinggal tempel di m-banking.
              $mootaAmountRaw = (string) (int) round((float) $order['total']);
              // Ekstrak nomor rekening (rangkaian angka terpanjang ≥6 digit) dari teks bebas admin.
              $mootaAcctNo = '';
              if (preg_match_all('/\d{6,}/', $mootaBank, $mAll)) {
                  foreach ($mAll[0] as $cand) { if (strlen($cand) > strlen($mootaAcctNo)) $mootaAcctNo = $cand; }
              }
          ?>
          <div class="bg-emerald-50 dark:bg-emerald-900/10 border border-emerald-200 dark:border-emerald-800/40 rounded-xl p-4 mb-4 space-y-3">
            <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">Selesaikan pembayaran via transfer bank</p>
            <div>
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Transfer <strong>TEPAT</strong> sebesar:</p>
              <div class="flex items-center gap-2 flex-wrap">
                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= e(formatRupiah((float) $order['total'])) ?></p>
                <button type="button" class="tx-copy inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-emerald-300 dark:border-emerald-700 text-emerald-700 dark:text-emerald-300 bg-white/70 dark:bg-gray-800/40 hover:bg-emerald-100 dark:hover:bg-emerald-900/30 transition"
                        data-copy="<?= e($mootaAmountRaw) ?>" data-label="Nominal">
                  <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                  <span>Salin nominal</span>
                </button>
              </div>
              <p class="text-xs text-amber-600 dark:text-amber-400 mt-0.5">Nominal harus persis (termasuk 3 digit terakhir) agar terkonfirmasi otomatis.</p>
            </div>
            <?php if ($mootaBank !== ''): ?>
            <div>
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Ke rekening:</p>
              <div class="flex items-center gap-2 flex-wrap">
                <p class="text-sm font-medium text-gray-900 dark:text-white whitespace-pre-line"><?= e($mootaBank) ?></p>
                <?php if ($mootaAcctNo !== ''): ?>
                <button type="button" class="tx-copy inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-emerald-300 dark:border-emerald-700 text-emerald-700 dark:text-emerald-300 bg-white/70 dark:bg-gray-800/40 hover:bg-emerald-100 dark:hover:bg-emerald-900/30 transition"
                        data-copy="<?= e($mootaAcctNo) ?>" data-label="Nomor rekening">
                  <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                  <span>Salin no. rek</span>
                </button>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>
            <div class="text-xs text-gray-600 dark:text-gray-400 bg-white/70 dark:bg-gray-800/40 rounded-lg p-2.5">
              Cukup transfer dengan nominal <strong>persis</strong> di atas — pembayaran terkonfirmasi <strong>otomatis</strong> beberapa menit setelah transfer masuk. Tanpa perlu isi berita transfer atau upload bukti.
            </div>
          </div>
          <script>
          (function () {
            function fallbackCopy(text) {
              var ta = document.createElement('textarea');
              ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
              document.body.appendChild(ta); ta.focus(); ta.select();
              try { document.execCommand('copy'); } catch (e) {}
              document.body.removeChild(ta);
            }
            document.querySelectorAll('.tx-copy').forEach(function (btn) {
              btn.addEventListener('click', function () {
                var text  = btn.getAttribute('data-copy') || '';
                var label = btn.getAttribute('data-label') || 'Teks';
                var done  = function () {
                  var span = btn.querySelector('span');
                  var orig = span ? span.textContent : '';
                  if (span) span.textContent = 'Tersalin!';
                  setTimeout(function () { if (span) span.textContent = orig; }, 1500);
                  if (window.showToast) showToast(label + ' disalin', 'success');
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                  navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text); done(); });
                } else {
                  fallbackCopy(text); done();
                }
              });
            });
          })();
          </script>
          <?php elseif (($payment['method'] ?? '') === 'manual_transfer'):
              $mtBank = null;
              if (!empty($payment['bank_account_id'])) {
                  $mtb = getDB()->prepare("SELECT * FROM bank_accounts WHERE id = ? LIMIT 1");
                  $mtb->execute([(int) $payment['bank_account_id']]);
                  $mtBank = $mtb->fetch() ?: null;
              }
              $mtInstructions = getSetting('manual_transfer_instructions', '');
              $mtQrImage      = getSetting('manual_qr_image');
              $hasProof       = !empty($payment['proof_file']);
              $proofIsPdf     = $hasProof && preg_match('/\.pdf$/i', $payment['proof_file']);
          ?>
          <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-5 mb-4 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Transfer & Upload Bukti</h3>

            <?php if (!empty($mtQrImage)): ?>
            <div class="flex flex-col items-center rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-4">
              <img src="<?= e(APP_URL) ?>/<?= e($mtQrImage) ?>" alt="QR Pay" class="w-40 h-40 object-contain rounded-lg bg-white p-2 shadow-sm">
              <p class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-gray-600 dark:text-gray-300">
                <svg class="w-3.5 h-3.5" style="color:var(--brand-color)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M20 20h.01M17 20h.01M20 17h.01"/></svg>
                Scan QR Pay
              </p>
            </div>
            <?php if ($mtBank): ?>
            <div class="flex items-center gap-3 py-0.5">
              <span class="flex-1 h-px bg-gray-200 dark:bg-gray-700"></span>
              <span class="text-xs font-medium text-gray-400">atau manual transfer</span>
              <span class="flex-1 h-px bg-gray-200 dark:bg-gray-700"></span>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($mtBank): ?>
            <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4">
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Transfer ke <?= ($mtBank['channel_type'] ?? 'bank') === 'ewallet' ? 'akun' : 'rekening' ?>:</p>
              <p class="text-sm font-semibold text-gray-900 dark:text-white"><?= e($mtBank['bank_name']) ?></p>
              <p class="text-sm font-mono text-gray-700 dark:text-gray-300"><?= e($mtBank['account_number']) ?></p>
              <p class="text-sm text-gray-500 dark:text-gray-400"><?= e($mtBank['account_name']) ?></p>
              <?php if (!empty($mtBank['instructions'])): ?>
              <p class="text-xs text-amber-600 dark:text-amber-400 mt-1"><?= e($mtBank['instructions']) ?></p>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <div>
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Nominal transfer:</p>
              <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= e(formatRupiah((float) $order['total'])) ?></p>
              <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">Transfer <strong>tepat</strong> sesuai nominal di atas (hingga 3 digit terakhir) agar pembayaran cepat diverifikasi.</p>
            </div>

            <?php if (trim((string) $mtInstructions) !== ''): ?>
            <p class="text-xs text-gray-600 dark:text-gray-400 whitespace-pre-line bg-gray-50 dark:bg-gray-800/50 rounded-lg p-2.5"><?= e($mtInstructions) ?></p>
            <?php endif; ?>

            <?php if (!empty($payment['rejection_reason'])): ?>
            <div class="bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800/40 rounded-lg p-3 text-sm text-red-700 dark:text-red-400">
              Bukti sebelumnya ditolak: <strong><?= e($payment['rejection_reason']) ?></strong>. Silakan upload ulang.
            </div>
            <?php endif; ?>

            <?php if ($hasProof): ?>
            <div class="bg-emerald-50 dark:bg-emerald-900/10 border border-emerald-200 dark:border-emerald-800/40 rounded-lg p-4">
              <p class="text-sm text-emerald-700 dark:text-emerald-400 font-medium">Bukti transfer sudah diupload, menunggu konfirmasi admin.</p>
              <?php if ($proofIsPdf): ?>
              <a href="<?= e($payment['proof_file']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 mt-2 text-sm font-semibold text-[var(--brand-color)] hover:underline">Lihat Bukti Transfer (PDF)</a>
              <?php else: ?>
              <a href="<?= e($payment['proof_file']) ?>" target="_blank" rel="noopener" class="block mt-2"><img src="<?= e($payment['proof_file']) ?>" alt="Bukti transfer" class="max-w-[220px] rounded-lg border border-gray-200 dark:border-gray-700"></a>
              <?php endif; ?>
              <details class="mt-3">
                <summary class="text-xs font-medium text-gray-500 dark:text-gray-400 cursor-pointer hover:text-gray-700 dark:hover:text-gray-200">Ganti Bukti</summary>
                <?php include __DIR__ . '/../../partials/_proof-upload-form.php'; ?>
              </details>
            </div>
            <?php else: ?>
            <?php include __DIR__ . '/../../partials/_proof-upload-form.php'; ?>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <?php
          // Order pelunasan via gateway: tampilkan tombol bayar (buat invoice on-demand).
          $payGateways = [];
          if ($orderStage === 'settlement') {
              try {
                  $gwStmt = getDB()->query("SELECT provider FROM payment_gateways WHERE provider IN ('duitku','xendit') AND is_active = 1");
                  $payGateways = $gwStmt ? $gwStmt->fetchAll(PDO::FETCH_COLUMN) : [];
              } catch (Throwable $e) { $payGateways = []; }
          }
          ?>
          <?php if ($orderStage === 'settlement' && !empty($payGateways)): ?>
          <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-5 mb-4 space-y-3">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Bayar Pelunasan Sekarang</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">Nominal: <strong class="text-gray-900 dark:text-white"><?= e(formatRupiah((float) $order['total'])) ?></strong></p>
            <?php foreach ($payGateways as $gw): ?>
            <button type="button" data-gw="<?= e($gw) ?>" onclick="payToSettle(this)"
                    class="w-full py-3 rounded-xl text-sm font-bold text-white transition hover:opacity-90"
                    style="background-color: var(--brand-color);">
              Bayar via <?= e(ucfirst($gw)) ?> (transfer bank, QRIS, e-wallet)
            </button>
            <?php endforeach; ?>
            <p id="settle-pay-result" class="text-xs text-red-500 hidden"></p>
          </div>
          <script>
          function payToSettle(btn) {
            var res = document.getElementById('settle-pay-result');
            btn.disabled = true; btn.style.opacity = '0.6';
            var body = new URLSearchParams();
            body.append('order_id', <?= (int) $order['id'] ?>);
            body.append('gateway', btn.getAttribute('data-gw'));
            fetch(<?= json_encode(APP_URL . '/actions/checkout/create-payment') ?>, {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: body.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              if (d.success && d.payment_url) {
                // Tetap di Averion: gateway dibuka di tab baru, halaman ini
                // memantau status live (auto-poll) sampai pelunasan terkonfirmasi.
                var a = document.createElement('a');
                a.href = d.payment_url; a.target = '_blank'; a.rel = 'noopener';
                a.className = btn.className;
                a.style.backgroundColor = 'var(--brand-color)';
                a.textContent = 'Buka Halaman Pembayaran →';
                btn.replaceWith(a);
                var w = window.open(d.payment_url, '_blank'); // best-effort auto-buka
                if (w) w.opener = null;
                return;
              }
              res.textContent = d.message || 'Gagal membuat pembayaran. Coba lagi.';
              res.classList.remove('hidden');
              btn.disabled = false; btn.style.opacity = '';
            })
            .catch(function () {
              res.textContent = 'Gagal menghubungi server. Coba lagi.';
              res.classList.remove('hidden');
              btn.disabled = false; btn.style.opacity = '';
            });
          }
          </script>
          <?php elseif ($isHostedGw): ?>
          <!-- Order pending Duitku/Xendit: buyer tetap di Averion — bayar via tab
               baru, halaman ini memantau status live & berubah sendiri saat lunas. -->
          <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-5 mb-4">
            <div class="flex items-center gap-2.5 mb-1.5">
              <span class="relative flex w-2.5 h-2.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-400 opacity-60"></span>
                <span class="relative inline-flex rounded-full w-2.5 h-2.5 bg-yellow-400"></span>
              </span>
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Menunggu Pembayaran</h3>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
              Selesaikan pembayaran melalui halaman aman <?= e(ucfirst($orderGateway)) ?> —
              terbuka di tab baru, dan halaman ini akan <strong>otomatis diperbarui</strong> begitu pembayaranmu terkonfirmasi.
            </p>
            <?php if ($gatewayPayUrl !== ''): ?>
            <a href="<?= e($gatewayPayUrl) ?>" target="_blank" rel="noopener"
               class="flex items-center justify-center gap-2 w-full py-3 rounded-xl text-sm font-bold text-white transition hover:opacity-90"
               style="background-color: var(--brand-color);">
              Bayar Sekarang →
            </a>
            <?php else: ?>
            <!-- Order lama tanpa URL tersimpan → buat invoice on-demand lalu buka tab -->
            <button type="button" id="pay-now-btn" data-gw="<?= e($orderGateway) ?>"
                    class="w-full py-3 rounded-xl text-sm font-bold text-white transition hover:opacity-90"
                    style="background-color: var(--brand-color);">
              Bayar Sekarang →
            </button>
            <p id="pay-now-result" class="text-xs text-red-500 hidden mt-2"></p>
            <?php endif; ?>
            <p class="text-xs text-gray-400 dark:text-gray-600 text-center mt-2">Sudah bayar tapi status belum berubah? Tunggu beberapa detik — konfirmasi berjalan otomatis.</p>
          </div>
          <?php if ($gatewayPayUrl === ''): ?>
          <script>
          (function () {
            var btn = document.getElementById('pay-now-btn');
            if (!btn) return;
            btn.addEventListener('click', function () {
              var res = document.getElementById('pay-now-result');
              btn.disabled = true; btn.textContent = 'Menyiapkan pembayaran…';
              var body = new URLSearchParams();
              body.append('order_id', <?= (int) $order['id'] ?>);
              body.append('gateway', btn.getAttribute('data-gw'));
              fetch(<?= json_encode(APP_URL . '/actions/checkout/create-payment') ?>, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
              })
              .then(function (r) { return r.json(); })
              .then(function (d) {
                if (d.success && d.payment_url) {
                  // Ganti tombol jadi link tab-baru (klik user → tak diblokir popup blocker).
                  var a = document.createElement('a');
                  a.href = d.payment_url; a.target = '_blank'; a.rel = 'noopener';
                  a.className = 'flex items-center justify-center gap-2 w-full py-3 rounded-xl text-sm font-bold text-white transition hover:opacity-90';
                  a.style.backgroundColor = 'var(--brand-color)';
                  a.textContent = 'Buka Halaman Pembayaran →';
                  btn.replaceWith(a);
                  var w = window.open(d.payment_url, '_blank'); // best-effort auto-buka
                  if (w) w.opener = null;
                  return;
                }
                res.textContent = d.message || 'Gagal membuat pembayaran. Coba lagi.';
                res.classList.remove('hidden');
                btn.disabled = false; btn.textContent = 'Bayar Sekarang →';
              })
              .catch(function () {
                res.textContent = 'Gagal menghubungi server. Coba lagi.';
                res.classList.remove('hidden');
                btn.disabled = false; btn.textContent = 'Bayar Sekarang →';
              });
            });
          })();
          </script>
          <?php endif; ?>
          <?php else: ?>
          <div class="bg-blue-50 dark:bg-blue-900/10 border border-blue-200 dark:border-blue-800/40 rounded-xl p-4 mb-4">
            <p class="text-sm text-blue-700 dark:text-blue-400">
              <strong>Menunggu konfirmasi pembayaran.</strong> Admin akan memverifikasi pembayaran kamu segera. Akses produk akan aktif setelah pembayaran dikonfirmasi.
            </p>
          </div>
          <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>

        <!-- Order Items -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-4">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Produk Dipesan</h3>
            <?php if ($orderBundleName !== ''): ?>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold" style="background:color-mix(in srgb, var(--brand-color) 12%, transparent);color:var(--brand-color)">
              <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12v10H4V12M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/></svg>
              Paket: <?= e($orderBundleName) ?>
            </span>
            <?php endif; ?>
          </div>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($items as $item): ?>
            <div class="flex items-center gap-4 p-4">
              <?php if (!empty($item['thumbnail'])): ?>
              <img src="<?= e($item['thumbnail']) ?>" alt="" class="w-12 h-12 rounded-lg object-cover shrink-0">
              <?php else: ?>
              <div class="w-12 h-12 rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                </svg>
              </div>
              <?php endif; ?>
              <div class="flex-1 min-w-0">
                <p class="font-semibold text-gray-900 dark:text-white text-sm"><?= e($item['product_name']) ?></p>
                <?php if (!empty($item['product_type'])): ?>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5"><?= e(ucfirst($item['product_type'])) ?></p>
                <?php endif; ?>
              </div>
              <div class="text-right shrink-0">
                <p class="font-semibold text-gray-900 dark:text-white text-sm">
                  <?= (float)$item['price'] > 0 ? e(formatRupiah((float)$item['price'])) : '<span class="text-green-600 dark:text-green-400">Gratis</span>' ?>
                </p>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Ringkasan harga & info order -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-4">
          <h3 class="font-semibold text-gray-900 dark:text-white text-sm mb-4">Ringkasan Pembayaran</h3>
          <div class="space-y-2.5 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-500 dark:text-gray-400">Subtotal</span>
              <span class="text-gray-800 dark:text-gray-200"><?= e(formatRupiah((float)$order['subtotal'])) ?></span>
            </div>
            <?php if ((float)$order['discount'] > 0): ?>
            <div class="flex justify-between">
              <span class="text-gray-500 dark:text-gray-400">Diskon</span>
              <span class="text-green-600 dark:text-green-400">-<?= e(formatRupiah((float)$order['discount'])) ?></span>
            </div>
            <?php endif; ?>
            <?php if ((float)($order['setup_fee'] ?? 0) > 0): ?>
            <div class="flex justify-between">
              <span class="text-gray-500 dark:text-gray-400">Biaya setup awal</span>
              <span class="text-gray-800 dark:text-gray-200">+<?= e(formatRupiah((float)$order['setup_fee'])) ?></span>
            </div>
            <?php endif; ?>
            <div class="flex justify-between font-bold text-base pt-2.5 border-t border-gray-100 dark:border-gray-800">
              <span class="text-gray-900 dark:text-white">Total</span>
              <span class="text-[var(--brand-color)]">
                <?= (float)$order['total'] > 0 ? e(formatRupiah((float)$order['total'])) : 'GRATIS' ?>
              </span>
            </div>
          </div>

          <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 grid grid-cols-2 gap-3 text-xs">
            <div>
              <p class="text-gray-400 dark:text-gray-600 mb-0.5">Metode Pembayaran</p>
              <p class="font-medium text-gray-700 dark:text-gray-300"><?= e(ucfirst($payment['method'] ?? 'Manual')) ?></p>
            </div>
            <div>
              <p class="text-gray-400 dark:text-gray-600 mb-0.5">Status Bayar</p>
              <p class="font-medium text-gray-700 dark:text-gray-300"><?= e(ucfirst($payment['status'] ?? '-')) ?></p>
            </div>
            <?php if ($order['paid_at']): ?>
            <div>
              <p class="text-gray-400 dark:text-gray-600 mb-0.5">Tanggal Bayar</p>
              <p class="font-medium text-gray-700 dark:text-gray-300"><?= e(date('d M Y, H:i', strtotime($order['paid_at']))) ?></p>
            </div>
            <?php endif; ?>
            <?php if ($order['referral_code']): ?>
            <div>
              <p class="text-gray-400 dark:text-gray-600 mb-0.5">Kode Referral</p>
              <p class="font-mono font-bold text-[var(--brand-color)]"><?= e($order['referral_code']) ?></p>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($order['notes']): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-4">
          <h3 class="font-semibold text-gray-900 dark:text-white text-sm mb-2">Catatan</h3>
          <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed"><?= e($order['notes']) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($order['status'] === 'paid'): ?>
        <a href="<?= e(APP_URL) ?>/member"
           class="flex items-center justify-center gap-2 w-full py-3 rounded-xl text-sm font-bold text-white transition hover:opacity-90"
           style="background-color: var(--brand-color);">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
          Buka Area Member
        </a>
        <?php endif; ?>

        <a href="<?= e(APP_URL) ?>/invoice/<?= e($order['order_number']) ?>" target="_blank"
           class="flex items-center justify-center gap-2 w-full py-3 mt-3 rounded-xl text-sm font-semibold border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
          Download Invoice PDF
        </a>

      </div>

    </main>
  </div>
</div>

<?php if (($order['status'] ?? '') === 'pending'): ?>
<script>
// ─── Status live: poll ringan tiap 5 detik selama order pending ───
// Buyer membayar di tab gateway → webhook menandai paid → halaman ini
// otomatis reload menampilkan status lunas + akses produk. Poll berhenti
// saat tab tersembunyi (hemat) dan saat status berubah dari pending.
(function () {
  var ORDER = <?= json_encode($order['order_number']) ?>;
  var URL   = <?= json_encode(APP_URL . '/actions/user/order-status') ?>;
  var iv = setInterval(function () {
    if (document.hidden) return;
    fetch(URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'order_number=' + encodeURIComponent(ORDER)
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.paid || (d.status && d.status !== 'pending' && d.status !== 'error')) {
        clearInterval(iv);
        window.location.reload();
      }
    })
    .catch(function () { /* transient — coba lagi di tick berikutnya */ });
  }, 5000);
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

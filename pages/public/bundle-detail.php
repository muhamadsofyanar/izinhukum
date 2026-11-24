<?php
require_once __DIR__ . '/../../bootstrap.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['slug'] ?? ''));
if ($slug === '') { http_response_code(404); exit('Bundle tidak ditemukan.'); }

try {
    $bStmt = getDB()->prepare("SELECT * FROM product_bundles WHERE slug = ? LIMIT 1");
    $bStmt->execute([$slug]);
    $bundle = $bStmt->fetch();
} catch (Exception $e) { http_response_code(500); exit('Terjadi kesalahan.'); }
if (!$bundle) { http_response_code(404); exit('Bundle tidak ditemukan.'); }
// Bundle ditemukan tapi dinonaktifkan → redirect ke homepage.
if (($bundle['status'] ?? '') !== 'active') {
    header('Location: ' . APP_URL . '/', true, 302);
    exit;
}

// Funnel tracking: Visitor Sales Page (lp_view) untuk bundle. Dedup per sesi/hari,
// exclude admin/staff. Fail-soft. → tampil di Funnel Analytics mode Bundle.
trackBundleFunnelEvent((int) $bundle['id'], 'lp_view');

$iStmt = getDB()->prepare(
    "SELECT p.id AS product_id, p.name, p.thumbnail, p.type, p.price, p.short_description
     FROM product_bundle_items pbi JOIN products p ON p.id = pbi.product_id
     WHERE pbi.bundle_id = ? AND p.status = 'active' ORDER BY pbi.sort_order ASC"
);
$iStmt->execute([(int) $bundle['id']]);
$items = $iStmt->fetchAll();

$ownedCount = 0;
if (isLoggedIn()) {
    foreach ($items as $it) if (hasAccess((int) $_SESSION['user_id'], (int) $it['product_id'])) $ownedCount++;
}
$allOwned = $items && $ownedCount === count($items);
$savings    = (float) $bundle['original_price'] - (float) $bundle['bundle_price'];
$savingsPct = (float) $bundle['original_price'] > 0 ? round($savings / (float) $bundle['original_price'] * 100) : 0;
$isFreeBundle = (float) $bundle['bundle_price'] <= 0;

// Gateway aktif (sama seperti checkout produk)
$duitkuActive = $xenditActive = $mootaActive = false; $mootaBankInfo = '';
try {
    foreach (getDB()->query("SELECT provider, is_active, config_json FROM payment_gateways WHERE provider IN ('duitku','xendit','moota')")->fetchAll() as $gw) {
        if ($gw['provider'] === 'duitku') $duitkuActive = (bool) $gw['is_active'];
        if ($gw['provider'] === 'xendit') $xenditActive = (bool) $gw['is_active'];
        if ($gw['provider'] === 'moota') { $cfg = json_decode($gw['config_json'] ?? '', true) ?: []; $mootaBankInfo = trim($cfg['bank_info'] ?? ''); $mootaActive = ((bool) $gw['is_active']) && $mootaBankInfo !== ''; }
    }
} catch (Exception $e) {}

// Transfer Manual: HANYA tawarkan bila diaktifkan admin DAN ada rekening aktif / QR
// (pola SAMA dgn checkout.php). Tanpa guard ini, tombol "Transfer Manual" bocor
// tampil walau manual dinonaktifkan.
$manualTransferEnabled = getSetting('manual_transfer_enabled', '1') === '1';
$manualBankCount = 0; $manualQrImage = null;
if ($manualTransferEnabled) {
    try { $manualBankCount = (int) getDB()->query("SELECT COUNT(*) FROM bank_accounts WHERE is_active = 1")->fetchColumn(); } catch (Exception $e) {}
    $manualQrImage = getSetting('manual_qr_image');
}
$manualTransferAvailable = $manualTransferEnabled && ($manualBankCount > 0 || !empty($manualQrImage));
$anyPaidMethod = $duitkuActive || $xenditActive || $mootaActive || $manualTransferAvailable;

$pageTitle = $bundle['name'] . ' — ' . getSetting('app_name', 'STIFIn Mulia');
$appName   = getSetting('app_name', 'STIFIn Mulia');
generateCSRF();
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="min-h-screen">
  <nav class="border-b border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between">
      <a href="<?= e(APP_URL) ?>/products" class="font-bold text-gray-900 dark:text-white"><?= e($appName) ?></a>
      <?php if (isLoggedIn()): ?><a href="<?= e(APP_URL) ?>/member" class="text-sm text-gray-500 hover:text-[var(--brand-color)]">Area Member</a><?php else: ?><a href="<?= e(APP_URL) ?>/login" class="text-sm text-gray-500 hover:text-[var(--brand-color)]">Masuk</a><?php endif; ?>
    </div>
  </nav>

  <main class="max-w-3xl mx-auto px-4 sm:px-6 py-8">
    <?php include __DIR__ . '/../../partials/flash-message.php'; ?>
    <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 overflow-hidden shadow-sm">
      <?php if ($bundle['thumbnail']): ?>
      <img src="<?= e($bundle['thumbnail']) ?>" class="w-full h-56 object-cover" alt="<?= e($bundle['name']) ?>">
      <?php endif; ?>
      <div class="p-5 sm:p-8">
        <?php if ($savingsPct > 0): ?><span class="inline-flex items-center gap-1 mb-3 text-xs font-bold px-2.5 py-1 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-full"><svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>Hemat <?= $savingsPct ?>%</span><?php endif; ?>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white"><?= e($bundle['name']) ?></h1>
        <?php if ($bundle['tagline']): ?><p class="text-gray-500 dark:text-gray-400 mt-1"><?= e($bundle['tagline']) ?></p><?php endif; ?>

        <div class="mt-4">
          <span class="block text-3xl sm:text-4xl font-extrabold text-gray-900 dark:text-white tracking-tight leading-none"><?= e(formatRupiah((float) $bundle['bundle_price'])) ?></span>
          <?php if ($savings > 0): ?>
          <div class="flex items-center flex-wrap gap-x-3 gap-y-1 mt-2">
            <span class="text-base text-gray-400 line-through"><?= e(formatRupiah((float) $bundle['original_price'])) ?></span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-bold bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400">Hemat <?= e(formatRupiah($savings)) ?></span>
          </div>
          <?php endif; ?>
        </div>

        <div class="mt-5">
          <?php if ($allOwned): ?>
            <button disabled class="w-full py-3 rounded-lg text-sm font-bold text-gray-400 bg-gray-100 dark:bg-gray-800 cursor-not-allowed">Kamu sudah memiliki semua produk ini</button>
            <p class="text-center text-xs text-gray-400 mt-2">Semua akses aktif di Area Member</p>
          <?php elseif (isLoggedIn()): ?>
            <?php if ($ownedCount > 0): ?><div class="mb-3 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg text-xs text-blue-700 dark:text-blue-300">Kamu sudah memiliki <?= $ownedCount ?> dari <?= count($items) ?> produk. Bundle ini memberi akses ke produk yang belum kamu miliki.</div><?php endif; ?>
            <?php
              $buyerPhone = '';
              try {
                  $phStmt = getDB()->prepare("SELECT phone FROM users WHERE id = ? LIMIT 1");
                  $phStmt->execute([(int) $_SESSION['user_id']]);
                  $buyerPhone = (string) ($phStmt->fetchColumn() ?: '');
              } catch (Exception $e) {}

              // Kelas tombol pembayaran — premium & compact, ikon SVG di kiri.
              $payPrimary = 'bundle-pay w-full inline-flex items-center justify-center gap-2 py-3 rounded-lg text-sm font-bold text-white transition hover:opacity-90 shadow-sm';
              $payOutline = 'bundle-pay w-full inline-flex items-center justify-center gap-2 py-3 rounded-lg text-sm font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 transition hover:bg-gray-50 dark:hover:bg-gray-800';
              $icZap    = '<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>';
              $icBank   = '<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M3 10h18"/><path d="M5 6l7-3 7 3"/><path d="M4 10v11"/><path d="M20 10v11"/><path d="M8 14v3"/><path d="M12 14v3"/><path d="M16 14v3"/></svg>';
              $icReceipt= '<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1z"/><path d="M8 7h8"/><path d="M8 11h8"/><path d="M8 15h5"/></svg>';
              $icGift   = '<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>';
            ?>
            <div class="mb-4">
              <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Nomor WhatsApp <span class="text-red-500">*</span></label>
              <input type="tel" id="bundle-wa" inputmode="numeric" value="<?= e($buyerPhone) ?>" maxlength="20"
                     class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                     placeholder="08xxxxxxxxxx">
              <p id="bundle-wa-error" class="hidden text-xs text-red-500 mt-1">Nomor WhatsApp wajib diisi (10–15 digit).</p>
            </div>
            <div class="space-y-2.5" id="pay-methods">
              <?php if ($isFreeBundle): ?>
                <button type="button" data-method="manual" class="<?= $payPrimary ?>" style="background-color:var(--brand-color)"><?= $icGift ?> Ambil Gratis Sekarang</button>
              <?php else: ?>
                <?php if ($duitkuActive): ?><button type="button" data-method="duitku" class="<?= $payPrimary ?>" style="background-color:var(--brand-color)"><?= $icZap ?> Bayar Otomatis (VA / QRIS / e-Wallet)</button><?php endif; ?>
                <?php if ($xenditActive): ?><button type="button" data-method="xendit" class="<?= $payPrimary ?>" style="background-color:var(--brand-color)"><?= $icZap ?> Bayar Otomatis (Xendit)</button><?php endif; ?>
                <?php if ($mootaActive): ?><button type="button" data-method="moota" class="<?= $payOutline ?>"><?= $icBank ?> Transfer Bank (auto-konfirmasi)</button><?php endif; ?>
                <?php if ($manualTransferAvailable): ?><button type="button" data-method="manual" class="<?= $payOutline ?>"><?= $icReceipt ?> Transfer Manual</button><?php endif; ?>
                <?php if (!$anyPaidMethod): ?>
                <div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/40 text-xs text-amber-700 dark:text-amber-400 text-center">Metode pembayaran belum tersedia. Silakan hubungi admin.</div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <p id="pay-error" class="text-xs text-red-500 mt-2 hidden"></p>
            <script>
            (function(){
              var CSRF = <?= json_encode($_SESSION['csrf_token']) ?>, BID = <?= (int) $bundle['id'] ?>;
              var err = document.getElementById('pay-error');
              var waInput = document.getElementById('bundle-wa');
              var waError = document.getElementById('bundle-wa-error');
              function showErr(m){ err.textContent = m; err.classList.remove('hidden'); }
              document.querySelectorAll('.bundle-pay').forEach(function(btn){
                btn.addEventListener('click', async function(){
                  var method = btn.dataset.method;
                  err.classList.add('hidden');
                  if (waError) waError.classList.add('hidden');

                  var rawWa = waInput ? waInput.value : '';
                  var digits = rawWa.replace(/[^0-9]/g, '');
                  if (digits.length < 10 || digits.length > 15) {
                    if (waError) waError.classList.remove('hidden');
                    if (waInput) waInput.focus();
                    return;
                  }

                  document.querySelectorAll('.bundle-pay').forEach(function(b){ b.disabled = true; });
                  if (method === 'duitku' || method === 'xendit') {
                    try {
                      var pr = await fetch('<?= e(APP_URL) ?>/actions/checkout/process', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'csrf_token='+encodeURIComponent(CSRF)+'&bundle_id='+BID+'&payment_gateway_method='+method+'&phone='+encodeURIComponent(digits) });
                      var pd = await pr.json();
                      if (pd.error || !pd.order_id) { showErr(pd.error || 'Gagal membuat order.'); document.querySelectorAll('.bundle-pay').forEach(function(b){ b.disabled=false; }); return; }
                      var cr = await fetch('<?= e(APP_URL) ?>/actions/checkout/create-payment', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'order_id='+encodeURIComponent(pd.order_id)+'&gateway='+encodeURIComponent(method) });
                      var cd = await cr.json();
                      // Tetap di Averion: mendarat di halaman transaksi (tombol bayar tab baru + status live).
                      if (cd.success && cd.payment_url) { window.location.href = '<?= e(APP_URL) ?>/transactions/' + encodeURIComponent(cd.order_number || pd.order_number); }
                      else { showErr(cd.message || 'Gagal membuat pembayaran.'); document.querySelectorAll('.bundle-pay').forEach(function(b){ b.disabled=false; }); }
                    } catch(e){ showErr('Terjadi kesalahan.'); document.querySelectorAll('.bundle-pay').forEach(function(b){ b.disabled=false; }); }
                  } else {
                    var f = document.createElement('form'); f.method='POST'; f.action='<?= e(APP_URL) ?>/actions/checkout/process';
                    function add(n,v){ var i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; f.appendChild(i); }
                    add('csrf_token', CSRF); add('bundle_id', BID); add('phone', digits); if (method==='moota') add('payment_gateway_method','moota');
                    document.body.appendChild(f); f.submit();
                  }
                });
              });
            })();
            </script>
          <?php else: ?>
            <a href="<?= e(APP_URL) ?>/login" class="w-full inline-flex items-center justify-center gap-2 py-3 rounded-lg text-sm font-bold text-white transition hover:opacity-90 shadow-sm" style="background-color:var(--brand-color)">
              <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
              Login untuk Beli Bundle
            </a>
          <?php endif; ?>
        </div>

        <div class="mt-7 border-t border-gray-100 dark:border-gray-800 pt-5">
          <p class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Produk dalam bundle ini:</p>
          <div class="space-y-3">
            <?php foreach ($items as $it): $owned = isLoggedIn() && hasAccess((int) $_SESSION['user_id'], (int) $it['product_id']); ?>
            <div class="flex items-center gap-3 p-3 rounded-lg border border-gray-100 dark:border-gray-800">
              <?php if ($it['thumbnail']): ?><img src="<?= e($it['thumbnail']) ?>" class="w-12 h-12 rounded-lg object-cover shrink-0"><?php else: ?><div class="w-12 h-12 rounded-lg bg-gray-100 dark:bg-gray-800 shrink-0"></div><?php endif; ?>
              <div class="flex-1 min-w-0">
                <p class="font-medium text-sm text-gray-900 dark:text-white truncate"><?= e($it['name']) ?> <?php if ($owned): ?><span class="text-xs text-green-600 dark:text-green-400">✓ Sudah Dimiliki</span><?php endif; ?></p>
                <?php if ($it['short_description']): ?><p class="text-xs text-gray-400 truncate"><?= e($it['short_description']) ?></p><?php endif; ?>
              </div>
              <span class="text-xs text-gray-400 line-through shrink-0"><?= e(formatRupiah((float) $it['price'])) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($bundle['description']): ?>
        <div class="mt-6 border-t border-gray-100 dark:border-gray-800 pt-5 text-sm text-gray-600 dark:text-gray-400 leading-relaxed whitespace-pre-line"><?= e($bundle['description']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <p class="text-center mt-6"><a href="<?= e(APP_URL) ?>/products" class="text-xs text-gray-400 hover:text-[var(--brand-color)]">← Kembali ke Produk</a></p>
  </main>
</div>
<?php echo funnelHeartbeatScript('bundle', (int) $bundle['id'], 'salespage'); ?>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

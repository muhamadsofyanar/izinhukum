<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

$pageTitle   = 'Reseller';
$topbarTitle = 'Reseller';

$userId = (int) $_SESSION['user_id'];

$affCode   = '';
$userPhone = '';
$products  = [];
try {
    $pdo = getDB();

    $affCode = (string) ($pdo->query("SELECT code FROM affiliates WHERE user_id = " . (int) $userId . " LIMIT 1")->fetchColumn() ?: '');
    if ($affCode === '') {
        $st = $pdo->prepare("SELECT affiliate_code FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        $affCode = (string) ($st->fetchColumn() ?: '');
    }

    $ph = $pdo->prepare("SELECT phone FROM users WHERE id = ? LIMIT 1");
    $ph->execute([$userId]);
    $userPhone = trim((string) ($ph->fetchColumn() ?: ''));

    // Produk yang salespage-nya aktif DAN reseller memiliki akses aktif (pemilik resell rights).
    $stmt = $pdo->prepare(
        // Produk PERSONAL (P) yang salespage-nya aktif & ditautkan ke produk Resell (R),
        // di mana reseller MEMILIKI produk Resell (R) tersebut (beli lisensi resell).
        "SELECT p.id, p.name, p.slug, p.thumbnail, p.price, p.access_page_enabled
           FROM products p
          WHERE p.salespage_enabled = 1 AND p.status = 'active' AND p.resell_product_id IS NOT NULL
            AND EXISTS (
              SELECT 1 FROM member_access ma
               WHERE ma.user_id = ? AND ma.product_id = p.resell_product_id
                 AND ma.status = 'active' AND (ma.expires_at IS NULL OR ma.expires_at > NOW())
            )
          ORDER BY p.name ASC"
    );
    $stmt->execute([$userId]);
    $products = $stmt->fetchAll();

    // Setting salespage per-reseller (teks WA + toggle resell) untuk user ini.
    ensureResellerSalespageTable($pdo);
    $rsMap = [];
    $rq = $pdo->prepare("SELECT product_id, wa_template, resell_enabled, access_password FROM reseller_salespage WHERE user_id = ?");
    $rq->execute([$userId]);
    foreach ($rq->fetchAll() as $r) $rsMap[(int) $r['product_id']] = $r;
} catch (Exception $e) {
    error_log('reseller page error: ' . $e->getMessage());
    $rsMap = $rsMap ?? [];
}

$base = rtrim(APP_URL, '/');

include __DIR__ . '/../../partials/head.php';
?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <div class="max-w-5xl">

        <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

        <div class="mb-5">
          <h1 class="text-xl font-bold text-gray-900 dark:text-white">Link Salespage Siap Pakai</h1>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Belum punya website sendiri? Gunakan link salespage siap pakai di bawah untuk promosi produk resell kamu. Salin dan sebarkan.</p>
        </div>

        <?php if ($userPhone === ''): ?>
        <div class="mb-5 bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800/40 rounded-xl p-4 flex gap-3">
          <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          <div class="text-sm text-amber-800 dark:text-amber-300">
            Nomor WhatsApp kamu belum diisi. Tombol <strong>Order Personal Use</strong> di salespage mengarah ke WA kamu — isi dulu di
            <a href="<?= e($base) ?>/profile" class="font-semibold underline">Profil</a>.
          </div>
        </div>
        <?php endif; ?>

        <?php if (empty($products)): ?>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm p-8 text-center">
          <div class="w-12 h-12 rounded-2xl bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center mx-auto mb-4">
            <svg class="w-6 h-6 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13.828 10.172a4 4 0 0 0-5.656 0l-4 4a4 4 0 1 0 5.656 5.656l1.102-1.101"/><path d="M10.172 13.828a4 4 0 0 0 5.656 0l4-4a4 4 0 0 0-5.656-5.656l-1.1 1.1"/></svg>
          </div>
          <h2 class="text-base font-bold text-gray-900 dark:text-white">Belum ada produk untuk dipromosikan</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Link salespage muncul di sini setelah kamu memiliki produk dengan hak jual ulang (resell) yang salespage-nya diaktifkan admin.</p>
          <a href="<?= e($base) ?>/member" class="inline-flex items-center gap-2 mt-5 px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Lihat Produk Saya</a>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
          <?php foreach ($products as $p):
              $link = $base . '/on/' . rawurlencode($p['slug']) . ($affCode !== '' ? '?ref=' . rawurlencode($affCode) : '');
              $rsRow     = $rsMap[(int) $p['id']] ?? null;
              $curWa     = ($rsRow && trim((string) $rsRow['wa_template']) !== '') ? (string) $rsRow['wa_template'] : defaultSalespageWaTemplate();
              $curResell = $rsRow ? ((int) $rsRow['resell_enabled'] === 1) : true;
          ?>
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <div class="p-4 flex items-center gap-3 border-b border-gray-100 dark:border-gray-800">
              <?php if (!empty($p['thumbnail'])): ?>
              <img src="<?= e($p['thumbnail']) ?>" alt="" class="w-11 h-11 rounded-lg object-cover shrink-0">
              <?php else: ?>
              <div class="w-11 h-11 rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)] flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
              </div>
              <?php endif; ?>
              <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900 dark:text-white truncate"><?= e($p['name']) ?></p>
                <?php if ((float) $p['price'] > 0): ?>
                <p class="text-xs text-gray-400 dark:text-gray-500">Harga personal: <?= e(formatRupiah((float) $p['price'])) ?></p>
                <?php endif; ?>
              </div>
            </div>
            <div class="p-4 space-y-2.5">
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Link salespage siap pakai</label>
              <div class="flex items-center gap-2">
                <input type="text" readonly value="<?= e($link) ?>"
                       class="flex-1 min-w-0 px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-mono outline-none"
                       onclick="this.select()">
                <button type="button" onclick="copyToClipboard('<?= e($link) ?>', this, 'Tersalin!')"
                        class="shrink-0 px-3.5 h-9 rounded-lg text-xs font-semibold text-white bg-brand hover:opacity-90 transition whitespace-nowrap">Salin</button>
                <a href="<?= e($link) ?>" target="_blank" rel="noopener"
                   class="shrink-0 px-3.5 h-9 inline-flex items-center rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition whitespace-nowrap">Buka</a>
              </div>
              <p class="text-[11px] text-gray-400 dark:text-gray-500">
                Tombol <strong>Order Personal Use</strong> di link ini mengarah ke WhatsApp kamu.
                <?php if ($curResell): ?>Tombol <strong>Order Resell Rights</strong> sudah berisi link afiliasi kamu (komisi otomatis).<?php endif; ?>
              </p>

              <!-- Atur salespage (per reseller): teks WA + toggle tombol resell -->
              <details class="mt-1 group">
                <summary class="inline-flex items-center gap-1 text-xs font-semibold text-[var(--brand-color)] cursor-pointer select-none">
                  <svg class="w-3.5 h-3.5 transition-transform group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                  Atur salespage (teks WA &amp; tombol resell)
                </summary>
                <form method="POST" action="<?= e($base) ?>/actions/user/save-reseller-salespage" class="mt-2.5 space-y-2.5 pl-1">
                  <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
                  <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                  <div>
                    <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-1">Teks WhatsApp — tombol Order Personal Use</label>
                    <textarea name="wa_template" rows="2" maxlength="500"
                              class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition resize-none"><?= e($curWa) ?></textarea>
                    <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Gunakan <strong>{produk}</strong> untuk nama produk. Kosong = teks default.</p>
                  </div>
                  <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="hidden" name="resell_enabled" value="0">
                    <input type="checkbox" name="resell_enabled" value="1" <?= $curResell ? 'checked' : '' ?> class="w-4 h-4 rounded accent-[var(--brand-color)]">
                    <span class="text-sm text-gray-700 dark:text-gray-300">Tampilkan tombol "Order Resell Rights" (dapat komisi)</span>
                  </label>
                  <button type="submit" class="px-4 h-9 rounded-lg text-xs font-semibold text-white bg-brand hover:opacity-90 transition">Simpan Pengaturan</button>
                </form>
              </details>

              <?php if ((int) $p['access_page_enabled'] === 1):
                  $accessUrl = $base . '/akses/' . rawurlencode($p['slug']);
                  // Password akses = milik reseller (kosong bila belum diatur → reseller set dulu).
                  $effPass = ($rsRow && trim((string) ($rsRow['access_password'] ?? '')) !== '')
                      ? (string) $rsRow['access_password'] : '';
              ?>
              <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 space-y-2">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Kirim akses ke pembeli (setelah bayar)</label>
                <div class="flex items-center gap-2">
                  <input type="text" readonly value="<?= e($accessUrl) ?>"
                         class="flex-1 min-w-0 px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-mono outline-none" onclick="this.select()">
                  <button type="button" onclick="copyToClipboard('<?= e($accessUrl) ?>', this, 'Tersalin!')"
                          class="shrink-0 px-3.5 h-9 rounded-lg text-xs font-semibold text-white bg-brand hover:opacity-90 transition whitespace-nowrap">Salin</button>
                </div>
                <form method="POST" action="<?= e($base) ?>/actions/user/save-reseller-access" class="flex items-center gap-2 flex-wrap">
                  <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
                  <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                  <span class="text-xs text-gray-500 dark:text-gray-400 shrink-0">Password:</span>
                  <input type="text" name="access_password" value="<?= e($effPass) ?>" maxlength="100" placeholder="mis. promosi123"
                         class="w-40 px-2.5 h-8 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
                  <button type="submit" class="shrink-0 px-3 h-8 rounded-lg text-xs font-semibold text-white bg-brand hover:opacity-90 transition">Simpan</button>
                  <button type="button" onclick="copyToClipboard(this.closest('form').querySelector('[name=access_password]').value, this, 'Disalin!')"
                          class="shrink-0 px-2.5 h-8 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Salin</button>
                </form>
                <p class="text-[11px] text-gray-400 dark:text-gray-500">Password ini <strong>milik kamu sendiri</strong> — isi &amp; Simpan dulu (link akses aktif setelah diisi), lalu kirim link + password via WhatsApp ke pembeli personal-use.</p>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      </div>
    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

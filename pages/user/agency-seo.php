<?php
// ─── Portal Agency SEO Engine (VENDOR-ONLY) ───────────────────────────────
// Pembeli paket agency membagikan kuota 50 lisensi SEO lifetime ke kliennya.
// Lisensi diterbitkan on-demand per klien (email klien) — lihat helpers/agency-seo.php.
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

// Gerbang: hanya tampil di instalasi vendor. Pembeli Averion biasa → tak relevan.
if (!agencySeoAvailable()) {
    redirect('/member');
}

$userId = (int) $_SESSION['user_id'];
$quotas = agencySeoQuotasForUser($userId);

$pageTitle   = 'Agency SEO Engine';
$topbarTitle = 'Agency SEO Engine';
$csrf        = generateCSRF();

include __DIR__ . '/../../partials/head.php';
?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="max-w-4xl mx-auto">
        <div class="mb-5">
          <h1 class="text-xl font-bold text-gray-900 dark:text-white">Agency SEO Engine</h1>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            Bagikan lisensi SEO Engine <strong>lifetime</strong> ke klienmu. Terbitkan per klien dengan email masing-masing &mdash; kredit AI klien terpisah dan diisi sendiri oleh klien.
          </p>
        </div>

        <?php if (empty($quotas)): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-10 text-center">
          <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mx-auto mb-3">
            <svg class="w-6 h-6 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12 2 4 6v6c0 5 3.4 7.7 8 10 4.6-2.3 8-5 8-10V6l-8-4z"/></svg>
          </div>
          <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Belum ada paket agency</p>
          <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Kuota lisensi akan muncul di sini setelah pembelian paket Agency SEO Engine lunas.</p>
        </div>
        <?php else: ?>

        <?php if (!agencySeoCanIssue()): ?>
        <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-xl p-4 mb-5 text-sm text-yellow-800 dark:text-yellow-300">
          Penerbitan lisensi belum aktif. Hubungi admin untuk mengaktifkan endpoint server lisensi.
        </div>
        <?php endif; ?>

        <?php foreach ($quotas as $quota):
          $slots      = agencySeoSlots((int) $quota['id']);
          $slotTotal  = (int) $quota['slot_total'];
          $byNumber   = [];
          $issuedCount = 0; $pendingCount = 0;
          foreach ($slots as $s) {
              $byNumber[(int) $s['slot_number']] = $s;
              if ($s['status'] === 'issued') $issuedCount++; else $pendingCount++;
          }
          $remaining = max(0, $slotTotal - $issuedCount);
          $canAddNew = $issuedCount < $slotTotal && (empty($byNumber) ? true : (max(array_keys($byNumber)) < $slotTotal));
        ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-5">
          <!-- Ringkasan kuota -->
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex flex-wrap items-center justify-between gap-3">
            <div>
              <p class="text-sm font-semibold text-gray-900 dark:text-white">Paket #<?= (int) $quota['id'] ?>
                <span class="font-normal text-gray-400 dark:text-gray-500 text-xs">&middot; <span class="font-mono"><?= e($quota['order_ref']) ?></span></span>
              </p>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Dibeli <?= e(date('d M Y', strtotime((string) $quota['created_at']))) ?></p>
            </div>
            <div class="flex items-center gap-4 text-center">
              <div><p class="text-lg font-bold text-emerald-600 dark:text-emerald-400 leading-none"><?= $issuedCount ?></p><p class="text-[11px] text-gray-400 mt-1">Terbit</p></div>
              <div><p class="text-lg font-bold text-amber-600 dark:text-amber-400 leading-none"><?= $pendingCount ?></p><p class="text-[11px] text-gray-400 mt-1">Tertunda</p></div>
              <div><p class="text-lg font-bold text-gray-900 dark:text-white leading-none"><?= $remaining ?></p><p class="text-[11px] text-gray-400 mt-1">Sisa</p></div>
            </div>
          </div>

          <!-- Form terbitkan lisensi -->
          <?php if ($canAddNew && agencySeoCanIssue()): ?>
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50/60 dark:bg-gray-800/20">
            <form method="POST" action="<?= e(APP_URL) ?>/actions/user/issue-agency-seo" class="grid grid-cols-1 sm:grid-cols-4 gap-2.5" data-loading-form>
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="do" value="issue">
              <input type="hidden" name="quota_id" value="<?= (int) $quota['id'] ?>">
              <input type="text" name="client_name" required maxlength="150" placeholder="Nama klien"
                     class="px-3 h-10 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
              <input type="email" name="client_email" required maxlength="191" placeholder="Email klien"
                     class="px-3 h-10 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
              <input type="text" name="client_phone" maxlength="40" placeholder="No. HP klien (opsional)"
                     class="px-3 h-10 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
              <button type="submit" data-loading data-loading-text="Menerbitkan..."
                      class="h-10 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">Terbitkan Lisensi</button>
            </form>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">Klien memasang lisensi di domainnya &mdash; domain terikat otomatis saat validasi pertama. Kamu tak perlu mengisi domain.</p>
          </div>
          <?php elseif ($issuedCount >= $slotTotal): ?>
          <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800 text-sm text-emerald-700 dark:text-emerald-400">Semua <?= $slotTotal ?> lisensi sudah diterbitkan.</div>
          <?php endif; ?>

          <!-- Daftar 50 slot -->
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs text-gray-400 dark:text-gray-500 border-b border-gray-100 dark:border-gray-800">
                  <th class="px-5 py-2.5 font-medium">Slot</th>
                  <th class="px-3 py-2.5 font-medium">Status</th>
                  <th class="px-3 py-2.5 font-medium">Klien</th>
                  <th class="px-3 py-2.5 font-medium">License Key</th>
                  <th class="px-3 py-2.5 font-medium">Terbit</th>
                  <th class="px-5 py-2.5 font-medium text-right">Aksi</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                <?php for ($n = 1; $n <= $slotTotal; $n++):
                  $s = $byNumber[$n] ?? null; ?>
                <tr class="align-top">
                  <td class="px-5 py-3 text-gray-500 dark:text-gray-400 font-mono"><?= sprintf('%02d', $n) ?></td>
                  <?php if ($s === null): ?>
                  <td class="px-3 py-3"><span class="inline-flex px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">Kosong</span></td>
                  <td class="px-3 py-3 text-gray-300 dark:text-gray-600">&mdash;</td>
                  <td class="px-3 py-3 text-gray-300 dark:text-gray-600">&mdash;</td>
                  <td class="px-3 py-3 text-gray-300 dark:text-gray-600">&mdash;</td>
                  <td class="px-5 py-3"></td>
                  <?php elseif ($s['status'] === 'issued'): ?>
                  <td class="px-3 py-3"><span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">Terbit</span></td>
                  <td class="px-3 py-3 text-gray-700 dark:text-gray-300"><?= e($s['client_name']) ?><br><span class="text-xs text-gray-400"><?= e($s['client_email']) ?></span></td>
                  <td class="px-3 py-3">
                    <code class="text-xs font-mono text-gray-800 dark:text-gray-200 break-all" id="key-<?= (int) $s['id'] ?>"><?= e($s['license_key']) ?></code>
                  </td>
                  <td class="px-3 py-3 text-xs text-gray-400 whitespace-nowrap"><?= $s['issued_at'] ? e(date('d M Y', strtotime((string) $s['issued_at']))) : '' ?></td>
                  <td class="px-5 py-3 text-right">
                    <button type="button" onclick="agencyCopyKey('key-<?= (int) $s['id'] ?>', this)"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                      Salin
                    </button>
                  </td>
                  <?php else: /* pending */ ?>
                  <td class="px-3 py-3"><span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">Tertunda</span></td>
                  <td class="px-3 py-3 text-gray-700 dark:text-gray-300"><?= e($s['client_name']) ?><br><span class="text-xs text-gray-400"><?= e($s['client_email']) ?></span></td>
                  <td class="px-3 py-3 text-xs text-red-500 dark:text-red-400 max-w-[220px]"><?= $s['last_error'] ? e(mb_substr((string) $s['last_error'], 0, 120)) : 'Belum terbit' ?></td>
                  <td class="px-3 py-3 text-gray-300 dark:text-gray-600">&mdash;</td>
                  <td class="px-5 py-3 text-right">
                    <?php if (agencySeoCanIssue()): ?>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/user/issue-agency-seo" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                      <input type="hidden" name="do" value="retry">
                      <input type="hidden" name="license_id" value="<?= (int) $s['id'] ?>">
                      <button type="submit" data-loading data-loading-text="..."
                              class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold text-white bg-brand hover:opacity-90 transition">Coba Lagi</button>
                    </form>
                    <?php endif; ?>
                  </td>
                  <?php endif; ?>
                </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<script>
function agencyCopyKey(id, btn) {
  var el = document.getElementById(id);
  if (!el) return;
  var text = el.textContent.trim();
  var done = function () {
    if (window.showToast) { showToast('License key disalin', 'success'); }
    else { var o = btn.textContent; btn.textContent = 'Tersalin'; setTimeout(function(){ btn.textContent = o; }, 1500); }
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(done).catch(function () { done(); });
  } else {
    var r = document.createRange(); r.selectNode(el);
    var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
    try { document.execCommand('copy'); } catch (e) {}
    sel.removeAllRanges(); done();
  }
}
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

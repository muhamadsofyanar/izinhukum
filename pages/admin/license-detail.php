<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$id = (int) ($_GET['id'] ?? 0);

$license     = null;
$activations = [];
$logs        = [];

try {
    $pdo = getDB();

    $stmt = $pdo->prepare(
        "SELECT pl.*,
                u.name AS buyer_name, u.email AS buyer_email,
                p.name AS product_name,
                o.order_number
         FROM product_licenses pl
         JOIN users u    ON pl.user_id    = u.id
         JOIN products p ON pl.product_id = p.id
         JOIN orders o   ON pl.order_id   = o.id
         WHERE pl.id = ? LIMIT 1"
    );
    $stmt->execute([$id]);
    $license = $stmt->fetch();

    if ($license) {
        $aStmt = $pdo->prepare(
            "SELECT * FROM license_activations WHERE license_id = ? ORDER BY activated_at DESC"
        );
        $aStmt->execute([$id]);
        $activations = $aStmt->fetchAll();

        $lStmt = $pdo->prepare(
            "SELECT * FROM license_api_logs WHERE license_id = ? ORDER BY requested_at DESC LIMIT 50"
        );
        $lStmt->execute([$id]);
        $logs = $lStmt->fetchAll();
    }
} catch (Exception $e) {
    error_log('License detail error: ' . $e->getMessage());
}

if (!$license) {
    flash('error', 'License tidak ditemukan.', 'error');
    redirect('/admin/licenses');
}

$pageTitle   = 'Detail License';
$topbarTitle = 'Detail License';

$licenseBadge = function (string $status): string {
    $map = [
        'active'    => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'Aktif'],
        'suspended' => ['bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400', 'Disuspend'],
        'revoked'   => ['bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400', 'Direvoke'],
        'expired'   => ['bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', 'Expired'],
    ];
    $c = $map[$status] ?? ['bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300', $status];
    return '<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold ' . $c[0] . '">' . e($c[1]) . '</span>';
};

$resultBadge = function (string $r): string {
    $green = 'text-green-600 dark:text-green-400';
    $red   = 'text-red-500 dark:text-red-400';
    $color = $r === 'success' ? $green : $red;
    return '<span class="font-medium ' . $color . '">' . e($r) . '</span>';
};

$csrf = generateCSRF();
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <!-- Back -->
      <a href="<?= e(APP_URL) ?>/admin/licenses" class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 mb-4 transition">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Kembali ke daftar license
      </a>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        <!-- ═══ KOLOM KIRI: Detail + Aktivasi + Log ═══ -->
        <div class="lg:col-span-2 space-y-5">

          <!-- Detail License -->
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
            <div class="flex items-start justify-between mb-4">
              <div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-white"><?= e($license['product_name']) ?></h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">Order: <?= e($license['order_number']) ?></p>
              </div>
              <?= $licenseBadge($license['status']) ?>
            </div>

            <!-- License key copyable -->
            <div class="bg-gray-50 dark:bg-gray-800/60 rounded-lg p-3 flex items-center justify-between gap-3 mb-4">
              <code id="fullKey" class="font-mono text-sm text-gray-800 dark:text-gray-100 break-all"><?= e($license['license_key']) ?></code>
              <button type="button" onclick="copyLicenseKey()"
                      class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-medium bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600 transition">
                <span id="copyLabel">Salin</span>
              </button>
            </div>

            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
              <div><dt class="text-xs text-gray-400 dark:text-gray-500">Buyer</dt><dd class="text-gray-800 dark:text-gray-200 font-medium"><?= e($license['buyer_name']) ?></dd></div>
              <div><dt class="text-xs text-gray-400 dark:text-gray-500">Email</dt><dd class="text-gray-800 dark:text-gray-200"><?= e($license['buyer_email']) ?></dd></div>
              <div><dt class="text-xs text-gray-400 dark:text-gray-500">Seats</dt><dd class="text-gray-800 dark:text-gray-200"><?= (int) $license['used_seats'] ?> / <?= (int) $license['max_seats'] ?> terpakai</dd></div>
              <div><dt class="text-xs text-gray-400 dark:text-gray-500">Diterbitkan</dt><dd class="text-gray-800 dark:text-gray-200"><?= e(date('d M Y H:i', strtotime($license['issued_at']))) ?></dd></div>
              <div><dt class="text-xs text-gray-400 dark:text-gray-500">Masa Berlaku</dt><dd class="text-gray-800 dark:text-gray-200"><?= $license['expires_at'] ? e(date('d M Y H:i', strtotime($license['expires_at']))) : 'Selamanya' ?></dd></div>
              <div><dt class="text-xs text-gray-400 dark:text-gray-500">Cek Terakhir</dt><dd class="text-gray-800 dark:text-gray-200"><?= $license['last_checked_at'] ? e(date('d M Y H:i', strtotime($license['last_checked_at']))) : '—' ?></dd></div>
            </dl>

            <?php if (!empty($license['notes'])): ?>
            <div class="mt-4 p-3 rounded-lg bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800">
              <p class="text-xs font-semibold text-yellow-700 dark:text-yellow-400 mb-1">Catatan Admin</p>
              <p class="text-sm text-yellow-800 dark:text-yellow-300 whitespace-pre-line"><?= e($license['notes']) ?></p>
            </div>
            <?php endif; ?>
          </div>

          <!-- Aktivasi Aktif -->
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
              <h3 class="font-semibold text-sm text-gray-900 dark:text-white">Aktivasi</h3>
            </div>
            <?php if (empty($activations)): ?>
            <p class="px-5 py-8 text-center text-sm text-gray-400 dark:text-gray-600">Belum ada aktivasi.</p>
            <?php else: ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
                    <th class="text-left px-5 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Identifier</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">IP</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Versi</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Aktivasi</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Cek Terakhir</th>
                    <th class="text-right px-5 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Aksi</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                  <?php foreach ($activations as $act): ?>
                  <tr class="<?= $act['status'] === 'deactivated' ? 'opacity-50' : '' ?>">
                    <td class="px-5 py-3 font-mono text-xs text-gray-700 dark:text-gray-300 break-all"><?= e($act['string_identifier']) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400"><?= e($act['ip_address'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400"><?= e($act['app_version'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400"><?= e(date('d M Y H:i', strtotime($act['activated_at']))) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400"><?= $act['last_validated_at'] ? e(date('d M Y H:i', strtotime($act['last_validated_at']))) : '—' ?></td>
                    <td class="px-5 py-3 text-right">
                      <?php if ($act['status'] === 'active'): ?>
                      <form id="deact-<?= (int) $act['id'] ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/manage-license" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="deactivate_seat">
                        <input type="hidden" name="license_id" value="<?= (int) $license['id'] ?>">
                        <input type="hidden" name="activation_id" value="<?= (int) $act['id'] ?>">
                        <button type="button"
                                onclick="confirmAndSubmit('deact-<?= (int) $act['id'] ?>',{title:'Cabut Aktivasi?',message:'Slot ini akan dilepas dan bisa dipakai di domain/device lain.',confirmText:'Ya, Cabut',variant:'warning'})"
                                class="px-2.5 py-1 rounded-lg text-xs font-medium text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 transition">
                          Cabut
                        </button>
                      </form>
                      <?php else: ?>
                      <span class="text-xs text-gray-400 dark:text-gray-600">Dinonaktifkan</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>

          <!-- Log API -->
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
              <h3 class="font-semibold text-sm text-gray-900 dark:text-white">Log API <span class="text-gray-400 font-normal">(50 terakhir)</span></h3>
            </div>
            <?php if (empty($logs)): ?>
            <p class="px-5 py-8 text-center text-sm text-gray-400 dark:text-gray-600">Belum ada request API.</p>
            <?php else: ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
                    <th class="text-left px-5 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Endpoint</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Hasil</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Identifier</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">IP</th>
                    <th class="text-left px-5 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Waktu</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                  <?php foreach ($logs as $log): ?>
                  <tr>
                    <td class="px-5 py-2.5 text-xs font-medium text-gray-700 dark:text-gray-300"><?= e($log['endpoint']) ?></td>
                    <td class="px-4 py-2.5 text-xs"><?= $resultBadge($log['result']) ?></td>
                    <td class="px-4 py-2.5 text-xs font-mono text-gray-500 dark:text-gray-400 break-all"><?= e($log['string_identifier'] ?? '—') ?></td>
                    <td class="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400"><?= e($log['ip_address'] ?? '—') ?></td>
                    <td class="px-5 py-2.5 text-xs text-gray-500 dark:text-gray-400"><?= e(date('d M H:i', strtotime($log['requested_at']))) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>

        </div>

        <!-- ═══ KOLOM KANAN: Aksi Admin ═══ -->
        <div class="space-y-5">

          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 space-y-4">
            <h3 class="font-semibold text-sm text-gray-900 dark:text-white">Aksi Admin</h3>

            <!-- Suspend / Unsuspend -->
            <?php if ($license['status'] === 'active'): ?>
            <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/manage-license">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="suspend">
              <input type="hidden" name="license_id" value="<?= (int) $license['id'] ?>">
              <button type="submit" class="w-full px-4 py-2 rounded-lg text-sm font-medium text-orange-700 dark:text-orange-400 bg-orange-50 dark:bg-orange-900/20 hover:bg-orange-100 dark:hover:bg-orange-900/40 transition">
                Suspend License
              </button>
            </form>
            <?php elseif ($license['status'] === 'suspended'): ?>
            <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/manage-license">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="unsuspend">
              <input type="hidden" name="license_id" value="<?= (int) $license['id'] ?>">
              <button type="submit" class="w-full px-4 py-2 rounded-lg text-sm font-medium text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/20 hover:bg-green-100 dark:hover:bg-green-900/40 transition">
                Aktifkan Kembali
              </button>
            </form>
            <?php endif; ?>

            <!-- Revoke -->
            <?php if ($license['status'] !== 'revoked'): ?>
            <form id="revokeForm" method="POST" action="<?= e(APP_URL) ?>/actions/admin/manage-license">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="revoke">
              <input type="hidden" name="license_id" value="<?= (int) $license['id'] ?>">
              <button type="button"
                      onclick="confirmAndSubmit('revokeForm',{title:'Revoke License?',message:'License akan dinonaktifkan permanen. Buyer tidak bisa validasi lagi.',confirmText:'Ya, Revoke',variant:'danger'})"
                      class="w-full px-4 py-2 rounded-lg text-sm font-medium text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 transition">
                Revoke License
              </button>
            </form>
            <?php endif; ?>

            <!-- Extend -->
            <?php if ($license['expires_at']): ?>
            <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/manage-license" class="pt-2 border-t border-gray-100 dark:border-gray-800">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="extend">
              <input type="hidden" name="license_id" value="<?= (int) $license['id'] ?>">
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1 mt-2">Perpanjang Masa Berlaku</label>
              <div class="flex gap-2">
                <input type="number" name="extend_days" min="1" value="30"
                       class="flex-1 px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                <button type="submit" class="shrink-0 px-4 h-9 rounded-lg text-sm font-medium text-white bg-brand hover:opacity-90 transition">Tambah</button>
              </div>
              <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Hari ditambahkan ke tanggal kedaluwarsa saat ini.</p>
            </form>
            <?php else: ?>
            <p class="pt-2 border-t border-gray-100 dark:border-gray-800 text-xs text-gray-400 dark:text-gray-600 mt-2">License ini berlaku selamanya (tanpa kedaluwarsa).</p>
            <?php endif; ?>

            <!-- Notes -->
            <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/manage-license" class="pt-2 border-t border-gray-100 dark:border-gray-800">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="add_note">
              <input type="hidden" name="license_id" value="<?= (int) $license['id'] ?>">
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1 mt-2">Catatan Admin</label>
              <textarea name="notes" rows="3"
                        class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition resize-none"
                        placeholder="Tulis catatan internal..."><?= e($license['notes'] ?? '') ?></textarea>
              <button type="submit" class="mt-2 w-full px-4 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Simpan Catatan</button>
            </form>

          </div>
        </div>

      </div>

    </main>
  </div>
</div>

<script>
function copyLicenseKey() {
  var key = document.getElementById('fullKey').textContent.trim();
  navigator.clipboard.writeText(key).then(function () {
    var lbl = document.getElementById('copyLabel');
    lbl.textContent = 'Tersalin!';
    setTimeout(function () { lbl.textContent = 'Salin'; }, 1500);
  });
}
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

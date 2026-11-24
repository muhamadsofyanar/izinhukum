<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

$pageTitle   = 'Lisensi Saya';
$topbarTitle = 'Lisensi Saya';

$userId   = (int) $_SESSION['user_id'];
$licenses = [];

try {
    $pdo = getDB();

    $stmt = $pdo->prepare(
        "SELECT pl.*,
                p.name AS product_name,
                p.thumbnail AS product_thumbnail,
                o.order_number,
                COUNT(la.id) AS activation_count
         FROM product_licenses pl
         JOIN products p ON pl.product_id = p.id
         JOIN orders o   ON pl.order_id   = o.id
         LEFT JOIN license_activations la ON la.license_id = pl.id AND la.status = 'active'
         WHERE pl.user_id = ?
         GROUP BY pl.id
         ORDER BY pl.created_at DESC"
    );
    $stmt->execute([$userId]);
    $licenses = $stmt->fetchAll();

    // Auto-update status jika expires_at sudah lewat
    foreach ($licenses as &$lic) {
        if ($lic['expires_at'] && strtotime($lic['expires_at']) < time() && $lic['status'] === 'active') {
            $pdo->prepare("UPDATE product_licenses SET status = 'expired' WHERE id = ?")->execute([(int) $lic['id']]);
            $lic['status'] = 'expired';
        }
    }
    unset($lic);

} catch (Exception $e) {
    error_log('My licenses error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data lisensi.', 'error');
}

// Ambil aktivasi aktif per license (untuk panel detail)
$activationsByLicense = [];
try {
    if (!empty($licenses)) {
        $ids   = array_map(fn($l) => (int) $l['id'], $licenses);
        $place = implode(',', array_fill(0, count($ids), '?'));
        $aStmt = $pdo->prepare(
            "SELECT * FROM license_activations
             WHERE license_id IN ($place) AND status = 'active'
             ORDER BY activated_at DESC"
        );
        $aStmt->execute($ids);
        foreach ($aStmt->fetchAll() as $a) {
            $activationsByLicense[(int) $a['license_id']][] = $a;
        }
    }
} catch (Exception $e) {
    error_log('My licenses activations error: ' . $e->getMessage());
}

$statusBadge = function (string $status): string {
    $map = [
        'active'    => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'Aktif'],
        'expired'   => ['bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', 'Expired'],
        'suspended' => ['bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400', 'Disuspend'],
        'revoked'   => ['bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400 line-through', 'Dicabut'],
    ];
    $c = $map[$status] ?? ['bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300', $status];
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' . $c[0] . '">' . e($c[1]) . '</span>';
};

$csrf = generateCSRF();
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Lisensi Saya</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5"><?= count($licenses) ?> license key yang kamu miliki</p>
      </div>

      <?php
      // Lisensi vendor yang masih dalam antrean penerbitan — tanpa ini pembeli yang
      // sudah bayar melihat halaman KOSONG & mengira lisensinya tak terbit.
      $vfPending = function_exists('vfPendingForUser') ? vfPendingForUser($userId) : [];
      ?>
      <?php foreach ($vfPending as $vp): ?>
      <?php
      // Class amber varian dark: (dark:bg-amber-900/xx) TIDAK ada di tailwind.css —
      // belum pernah ter-compile, jadi jangan dipakai: di dark mode latarnya hilang.
      // Amber hanya untuk light; dark memakai netral gray yang pasti ter-compile.
      $vfFail = $vp['status'] === 'failed';
      ?>
      <div class="mb-4 flex items-start gap-3 p-4 rounded-xl border <?= $vfFail ? 'border-amber-200 dark:border-gray-800 bg-amber-50 dark:bg-gray-900' : 'border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900' ?> shadow-sm">
        <div class="w-9 h-9 shrink-0 rounded-lg flex items-center justify-center <?= $vfFail ? 'bg-amber-100 dark:bg-gray-800' : 'bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)]' ?>">
          <svg class="w-4 h-4 <?= $vfFail ? 'text-amber-600 dark:text-amber-400' : 'text-[var(--brand-color)]' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
          </svg>
        </div>
        <div class="min-w-0">
          <p class="text-sm font-semibold text-gray-900 dark:text-white">
            License key <?= e($vp['product_name']) ?> sedang disiapkan
          </p>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            <?php if ($vfFail): ?>
            Penerbitan tertunda lebih lama dari biasanya. Pembayaranmu sudah kami terima &amp; tercatat
            (order <span class="font-mono"><?= e($vp['order_number']) ?></span>) — hubungi admin bila belum muncul juga.
            <?php else: ?>
            Kunci biasanya muncul dalam beberapa menit — halaman ini otomatis menampilkannya setelah terbit.
            Order <span class="font-mono"><?= e($vp['order_number']) ?></span>.
            <?php endif; ?>
          </p>
        </div>
      </div>
      <?php endforeach; ?>

      <?php if (empty($licenses) && empty($vfPending)): ?>

      <!-- Empty state -->
      <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm py-16 text-center">
        <div class="w-16 h-16 mx-auto rounded-2xl bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center mb-4">
          <svg class="w-8 h-8 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
          </svg>
        </div>
        <p class="text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Belum ada license key</p>
        <p class="text-sm text-gray-400 dark:text-gray-600">Beli produk dengan fitur lisensi untuk mendapatkannya.</p>
      </div>

      <?php else: ?>

      <div class="space-y-4">
        <?php foreach ($licenses as $lic):
            $licId      = (int) $lic['id'];
            $isLifetime = empty($lic['expires_at']);
            $daysLeft   = null;
            $expired    = false;
            if (!$isLifetime) {
                $diff     = strtotime($lic['expires_at']) - time();
                $daysLeft = (int) ceil($diff / 86400);
                $expired  = $diff <= 0;
            }
            $acts = $activationsByLicense[$licId] ?? [];
            // Lisensi yang diterbitkan SERVER LISENSI VENDOR: aktivasinya tercatat di
            // vendor, bukan di sini — averion.id cuma mencerminkan kuncinya. Tanpa
            // penanda ini panel menampilkan "0/1 seat" & "belum ada aktivasi" untuk
            // lisensi yang sebenarnya AKTIF, dan pembeli mengira aktivasinya gagal.
            // Penanda = produk dipetakan ke fulfillment vendor (guard yang sama dipakai
            // helpers.php saat memutuskan TIDAK menerbitkan kunci lokal).
            $isVendorLicense = function_exists('vfPlanForProduct')
                && vfPlanForProduct((int) $lic['product_id']) !== '';
        ?>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">

          <!-- Header card -->
          <div class="flex items-center gap-4 p-5">
            <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0 overflow-hidden">
              <?php if (!empty($lic['product_thumbnail'])): ?>
                <img src="<?= e($lic['product_thumbnail']) ?>" alt="" class="w-full h-full object-cover">
              <?php else: ?>
                <svg class="w-6 h-6 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
                </svg>
              <?php endif; ?>
            </div>
            <div class="flex-1 min-w-0">
              <p class="font-semibold text-gray-900 dark:text-white truncate"><?= e($lic['product_name']) ?></p>
              <p class="text-xs text-gray-400 dark:text-gray-600">Order: <?= e($lic['order_number']) ?></p>
            </div>
            <?= $statusBadge($lic['status']) ?>
          </div>

          <!-- License key -->
          <div class="px-5 pb-4">
            <p class="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-1.5">License Key</p>
            <div class="flex items-center gap-2 bg-gray-50 dark:bg-gray-800/60 rounded-lg p-3">
              <code id="key-<?= $licId ?>" data-full="<?= e($lic['license_key']) ?>"
                    data-masked="AVRN-••••-••••-••••-<?= e(substr($lic['license_key'], -4)) ?>"
                    data-shown="0"
                    class="flex-1 font-mono text-sm text-gray-800 dark:text-gray-100 break-all">AVRN-••••-••••-••••-<?= e(substr($lic['license_key'], -4)) ?></code>
              <button type="button" onclick="toggleKey(<?= $licId ?>)"
                      class="shrink-0 px-2.5 py-1.5 rounded-lg text-xs font-medium bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600 transition">
                <span id="toggle-label-<?= $licId ?>">Tampilkan</span>
              </button>
              <button type="button" onclick="copyKey(<?= $licId ?>, this)"
                      class="shrink-0 px-2.5 py-1.5 rounded-lg text-xs font-medium bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600 transition">
                Salin
              </button>
            </div>
          </div>

          <!-- Meta + toggle detail -->
          <div class="px-5 pb-4 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
            <?php if ($isVendorLicense): ?>
            <span class="text-gray-600 dark:text-gray-400">
              Aktivasi dikelola <span class="font-medium text-gray-900 dark:text-white">server lisensi</span>
            </span>
            <?php else: ?>
            <span class="text-gray-600 dark:text-gray-400">
              <span class="font-medium text-gray-900 dark:text-white"><?= (int) $lic['used_seats'] ?>/<?= (int) $lic['max_seats'] ?></span> seat aktif
            </span>
            <?php endif; ?>
            <span class="text-gray-600 dark:text-gray-400">
              <?php if ($isLifetime): ?>
                Masa berlaku: <span class="font-medium text-gray-900 dark:text-white">Seumur Hidup</span>
              <?php else: ?>
                Masa berlaku:
                <span class="font-medium <?= ($expired ? 'text-red-500' : ($daysLeft <= 7 ? 'text-orange-500' : 'text-gray-900 dark:text-white')) ?>">
                  <?= e(date('d M Y', strtotime($lic['expires_at']))) ?>
                  <?php if ($expired): ?>(Sudah berakhir)<?php else: ?>(<?= $daysLeft ?> hari tersisa)<?php endif; ?>
                </span>
              <?php endif; ?>
            </span>
            <button type="button" onclick="toggleDetail(<?= $licId ?>)"
                    class="ml-auto inline-flex items-center gap-1 text-sm font-medium text-[var(--brand-color)] hover:underline">
              Detail
              <svg id="chev-<?= $licId ?>" class="w-4 h-4 transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
          </div>

          <!-- Detail aktivasi (collapsed) -->
          <div id="detail-<?= $licId ?>" class="hidden border-t border-gray-100 dark:border-gray-800">
            <?php if ($isVendorLicense): ?>
            <p class="px-5 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
              Aktivasi lisensi ini dikelola <span class="font-medium text-gray-700 dark:text-gray-200">server lisensi</span>, bukan halaman ini.
              Status pemasangannya — domain, masa berlaku, dan kapan terakhir dicek — bisa kamu lihat langsung di panel aplikasinya, menu <span class="font-medium text-gray-700 dark:text-gray-200">Lisensi</span>.
            </p>
            <?php elseif (empty($acts)): ?>
            <p class="px-5 py-6 text-center text-sm text-gray-400 dark:text-gray-600">Belum ada aktivasi aktif untuk license ini.</p>
            <?php else: ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
                    <th class="text-left px-5 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Domain / Identifier</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">IP</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Aktivasi</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Cek Terakhir</th>
                    <th class="text-right px-5 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Aksi</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                  <?php foreach ($acts as $act): ?>
                  <tr>
                    <td class="px-5 py-3 font-mono text-xs text-gray-700 dark:text-gray-300 break-all"><?= e($act['string_identifier']) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400"><?= e($act['ip_address'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400"><?= e(date('d M Y', strtotime($act['activated_at']))) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400"><?= $act['last_validated_at'] ? e(date('d M Y', strtotime($act['last_validated_at']))) : '—' ?></td>
                    <td class="px-5 py-3 text-right">
                      <form id="reset-<?= (int) $act['id'] ?>" method="POST" action="<?= e(APP_URL) ?>/actions/user/deactivate-license" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="license_id" value="<?= $licId ?>">
                        <input type="hidden" name="activation_id" value="<?= (int) $act['id'] ?>">
                        <button type="button"
                                onclick="confirmAndSubmit('reset-<?= (int) $act['id'] ?>',{title:'Reset Aktivasi?',message:'Slot ini akan dilepas dan bisa kamu pakai di domain/device lain.',confirmText:'Ya, Reset',variant:'warning'})"
                                class="px-2.5 py-1 rounded-lg text-xs font-medium text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 transition">
                          Reset Aktivasi
                        </button>
                      </form>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>

        </div>
        <?php endforeach; ?>
      </div>

      <?php endif; ?>

    </main>
  </div>
</div>

<script>
function toggleKey(id) {
  var el = document.getElementById('key-' + id);
  var lbl = document.getElementById('toggle-label-' + id);
  var shown = el.getAttribute('data-shown') === '1';
  if (shown) {
    el.textContent = el.getAttribute('data-masked');
    el.setAttribute('data-shown', '0');
    lbl.textContent = 'Tampilkan';
  } else {
    el.textContent = el.getAttribute('data-full');
    el.setAttribute('data-shown', '1');
    lbl.textContent = 'Sembunyikan';
  }
}

function copyKey(id, btn) {
  var full = document.getElementById('key-' + id).getAttribute('data-full');
  navigator.clipboard.writeText(full).then(function () {
    var orig = btn.textContent;
    btn.textContent = 'Tersalin!';
    setTimeout(function () { btn.textContent = orig; }, 1500);
  });
}

function toggleDetail(id) {
  var box = document.getElementById('detail-' + id);
  var chev = document.getElementById('chev-' + id);
  var hidden = box.classList.toggle('hidden');
  chev.style.transform = hidden ? '' : 'rotate(180deg)';
}
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

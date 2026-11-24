<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Hasil Split Test';
$topbarTitle = 'Hasil Split Test';

$rotatorId = (int) ($_GET['id'] ?? 0);
$rotator   = null;
$variants  = [];
$totImpr   = 0;
$totClick  = 0;

try {
    $pdo = getDB();
    $rs = $pdo->prepare("SELECT * FROM canvas_pages WHERE id = ? AND page_type = 'rotator' LIMIT 1");
    $rs->execute([$rotatorId]);
    $rotator = $rs->fetch();

    if ($rotator) {
        $vs = $pdo->prepare(
            "SELECT rv.id, rv.label, rv.weight, rv.is_active, cp.title, cp.slug,
                    (SELECT COUNT(*) FROM canvas_rotator_events e
                     WHERE e.variant_id = rv.id AND e.event_type = 'impression')     AS impressions,
                    (SELECT COUNT(*) FROM canvas_rotator_events e
                     WHERE e.variant_id = rv.id AND e.event_type = 'checkout_click') AS clicks
             FROM canvas_rotator_variants rv
             LEFT JOIN canvas_pages cp ON cp.id = rv.variant_canvas_id
             WHERE rv.rotator_id = ?
             ORDER BY rv.sort_order ASC, rv.id ASC"
        );
        $vs->execute([$rotatorId]);
        $variants = $vs->fetchAll();
        foreach ($variants as $v) { $totImpr += (int) $v['impressions']; $totClick += (int) $v['clicks']; }
    }
} catch (Throwable $e) {
    error_log('Rotator stats error: ' . $e->getMessage());
}

// Varian unggulan = CR tertinggi dengan minimal ambang impresi (hindari noise).
$MIN_IMPR = 20;
$leadId = 0; $leadCr = -1.0;
foreach ($variants as $v) {
    $imp = (int) $v['impressions'];
    if ($imp < $MIN_IMPR) continue;
    $cr = $imp > 0 ? ((int) $v['clicks'] / $imp) : 0;
    if ($cr > $leadCr) { $leadCr = $cr; $leadId = (int) $v['id']; }
}

$appBase = rtrim(APP_URL, '/');
$cr = fn(int $c, int $i): string => $i > 0 ? number_format($c / $i * 100, 1) . '%' : '—';
generateCSRF();
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="mb-5">
        <a href="<?= e($appBase) ?>/admin/canvas" class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400 hover:text-[var(--brand-color)] transition mb-2">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M15 18l-6-6 6-6"/></svg>
          Kembali ke Canvas
        </a>
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Hasil Split Test</h2>
        <?php if ($rotator): ?>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
          <?= e($rotator['title']) ?> — <span class="font-mono"><?= e($appBase) ?>/<?= e($rotator['slug']) ?></span>
        </p>
        <?php endif; ?>
      </div>

      <?php if (!$rotator): ?>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-8 text-center">
        <p class="text-sm text-gray-500 dark:text-gray-400">Rotator tidak ditemukan atau bukan tipe Split Test.</p>
      </div>
      <?php else: ?>

      <!-- KPI ringkas -->
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-5">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4">
          <p class="text-xs text-gray-500 dark:text-gray-400">Total Impresi</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1 tabular-nums"><?= number_format($totImpr) ?></p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4">
          <p class="text-xs text-gray-500 dark:text-gray-400">Total Klik Checkout</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1 tabular-nums"><?= number_format($totClick) ?></p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4">
          <p class="text-xs text-gray-500 dark:text-gray-400">Conversion Rate</p>
          <p class="text-2xl font-bold text-[var(--brand-color)] mt-1 tabular-nums"><?= $cr($totClick, $totImpr) ?></p>
        </div>
      </div>

      <!-- Tabel varian -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-800">
                <th class="px-4 py-3 font-medium">Varian (Angle)</th>
                <th class="px-4 py-3 font-medium">Canvas</th>
                <th class="px-4 py-3 font-medium text-center">Bobot</th>
                <th class="px-4 py-3 font-medium text-right">Impresi</th>
                <th class="px-4 py-3 font-medium text-right">Klik Checkout</th>
                <th class="px-4 py-3 font-medium text-right">CR</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php if (empty($variants)): ?>
              <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400 dark:text-gray-600">Belum ada varian.</td></tr>
              <?php else: foreach ($variants as $v): $vid = (int) $v['id']; $isLead = $vid === $leadId; ?>
              <tr<?= $isLead ? ' style="background:color-mix(in srgb,var(--brand-color) 5%,transparent)"' : '' ?>>
                <td class="px-4 py-3">
                  <div class="flex items-center gap-2">
                    <span class="font-medium text-gray-900 dark:text-white"><?= e($v['label'] !== '' ? $v['label'] : 'Varian #' . $vid) ?></span>
                    <?php if ($isLead): ?>
                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold text-[var(--brand-color)]" style="background:color-mix(in srgb,var(--brand-color) 15%,transparent)">
                      <svg class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>
                      Unggulan
                    </span>
                    <?php endif; ?>
                    <?php if ((int) $v['is_active'] !== 1): ?>
                    <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">Nonaktif</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                  <?php if ($v['slug']): ?>
                  <a href="<?= e($appBase) ?>/<?= e($v['slug']) ?>" target="_blank" rel="noopener" class="hover:text-[var(--brand-color)] hover:underline"><?= e($v['title'] ?: $v['slug']) ?></a>
                  <?php else: ?>
                  <span class="text-red-500">canvas terhapus</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-center text-gray-500 dark:text-gray-400 tabular-nums"><?= (int) $v['weight'] ?></td>
                <td class="px-4 py-3 text-right tabular-nums text-gray-900 dark:text-white"><?= number_format((int) $v['impressions']) ?></td>
                <td class="px-4 py-3 text-right tabular-nums text-gray-900 dark:text-white"><?= number_format((int) $v['clicks']) ?></td>
                <td class="px-4 py-3 text-right tabular-nums font-semibold <?= $isLead ? 'text-[var(--brand-color)]' : 'text-gray-700 dark:text-gray-300' ?>"><?= $cr((int) $v['clicks'], (int) $v['impressions']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <p class="text-xs text-gray-400 dark:text-gray-600 mt-3 leading-relaxed max-w-2xl">
        <strong>CR</strong> = Klik Checkout ÷ Impresi (unik per pengunjung/hari). Tanda <strong>Unggulan</strong> muncul pada varian dengan CR tertinggi setelah minimal <?= $MIN_IMPR ?> impresi (agar tak salah simpul dari data kecil). Metrik ini proksi konversi; atribusi order lunas per-varian menyusul di fase berikutnya.
      </p>

      <?php endif; ?>
    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

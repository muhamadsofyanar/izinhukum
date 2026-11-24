<?php
// ─── Poin Reward member: saldo + riwayat mutasi ──────────────────────────
// Read-only. Semua perhitungan poin dilakukan di helpers/points.php.
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

if (!pointsEnabled()) {
    flash('error', 'Program poin belum aktif.', 'error');
    redirect('/dashboard');
}

$userId  = (int) $_SESSION['user_id'];
$label   = pointsLabel();
$balance = pointsBalance($userId);
$rows    = [];
try {
    $st = getDB()->prepare(
        "SELECT pt.type, pt.points, pt.note, pt.created_at, o.order_number
         FROM point_transactions pt
         LEFT JOIN orders o ON o.id = pt.order_id
         WHERE pt.user_id = ?
         ORDER BY pt.id DESC
         LIMIT 100"
    );
    $st->execute([$userId]);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    error_log('rewards page: ' . $e->getMessage());
}

$pageTitle   = pointsMenuLabel();
$topbarTitle = pointsMenuLabel();

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
          <h1 class="text-xl font-bold text-gray-900 dark:text-white"><?= e(pointsMenuLabel()) ?></h1>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Kumpulkan <?= e(strtolower($label)) ?> dari setiap transaksi, lalu pakai jadi potongan harga.</p>
        </div>

        <!-- Kartu saldo -->
        <div class="p-6 mb-5 text-white relative overflow-hidden" style="border-radius:var(--card-radius);background:linear-gradient(135deg,var(--brand-color),color-mix(in srgb,var(--brand-color) 55%,#000))">
          <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
              <p class="text-[11px] font-semibold uppercase tracking-widest" style="opacity:.85">Saldo <?= e($label) ?> Saya</p>
              <p class="text-3xl font-bold mt-1 leading-none"><?= number_format($balance, 0, ',', '.') ?> <span class="text-lg font-semibold"><?= e($label) ?></span></p>
              <p class="text-sm mt-3" style="opacity:.9">
                Setara <strong><?= e(formatRupiah(pointsToRupiah($balance))) ?></strong>
                &middot; 1 <?= e($label) ?> = <?= e(formatRupiah(pointsValue())) ?>
              </p>
            </div>
            <div class="shrink-0 w-14 h-14 flex items-center justify-center" style="border-radius:var(--control-radius);background:rgba(255,255,255,.18)">
              <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.66 3.58 3 8 3s8-1.34 8-3V6"/><path d="M4 12v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/>
              </svg>
            </div>
          </div>
          <div class="mt-4 inline-flex items-center gap-2 text-xs px-3 py-1.5" style="border-radius:var(--control-radius);background:rgba(255,255,255,.18)">
            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            Pakai <?= e(strtolower($label)) ?> saat checkout &mdash; maksimal <?= (int) pointsMaxDiscountPercent() ?>% dari harga produk.
          </div>
        </div>

        <a href="<?= e(APP_URL) ?>/katalog" class="inline-flex items-center gap-2 mb-5 px-4 h-10 text-sm font-semibold text-white bg-brand hover:opacity-90 transition" style="border-radius:var(--control-radius)">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9h18M3 9l1.5-4.5A2 2 0 0 1 6.4 3h11.2a2 2 0 0 1 1.9 1.5L21 9M3 9v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9"/></svg>
          Belanja pakai <?= e($label) ?>
        </a>

        <!-- Riwayat -->
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden" style="border-radius:var(--card-radius)">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Riwayat <?= e($label) ?></h2>
          </div>
          <?php if (empty($rows)): ?>
          <div class="p-10 text-center">
            <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mx-auto mb-3">
              <svg class="w-6 h-6 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Belum ada riwayat</p>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1"><?= e($label) ?> otomatis masuk setiap transaksimu lunas.</p>
          </div>
          <?php else: ?>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($rows as $r):
              $pts   = (int) $r['points'];
              $plus  = $pts > 0;
              $tLabel = match ($r['type']) {
                  'earn'          => 'Poin dari pembelian',
                  'redeem'        => 'Dipakai jadi potongan',
                  'reverse_earn'  => 'Ditarik (order dibatalkan)',
                  'refund_redeem' => 'Dikembalikan (order dibatalkan)',
                  default         => 'Penyesuaian admin',
              };
            ?>
            <div class="flex items-center justify-between gap-3 px-5 py-3.5">
              <div class="min-w-0">
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate"><?= e($tLabel) ?></p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                  <?= e(date('d M Y, H:i', strtotime((string) $r['created_at']))) ?>
                  <?php if (!empty($r['order_number'])): ?>
                    &middot; <span class="font-mono"><?= e($r['order_number']) ?></span>
                  <?php endif; ?>
                </p>
              </div>
              <span class="shrink-0 text-sm font-bold <?= $plus ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500 dark:text-gray-400' ?>">
                <?= $plus ? '+' : '' ?><?= number_format($pts, 0, ',', '.') ?>
              </span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

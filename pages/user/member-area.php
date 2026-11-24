<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

$pageTitle   = 'Area Member';
$topbarTitle = 'Area Member';

$userId  = (int) $_SESSION['user_id'];
$accesses = [];

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.slug, p.type, p.catalog_category, p.thumbnail, p.status AS product_status,
                p.short_description, p.access_duration_days, p.reviews_enabled,
                ma.started_at, ma.expires_at, ma.status AS access_status,
                (SELECT status FROM product_reviews pr WHERE pr.user_id = ma.user_id AND pr.product_id = p.id LIMIT 1) AS my_review_status,
                (SELECT COUNT(*) FROM classes c WHERE (c.product_id = p.id OR EXISTS (SELECT 1 FROM class_products cp WHERE cp.class_id = c.id AND cp.product_id = p.id)) AND c.status = 'active') AS class_count
         FROM member_access ma
         JOIN products p ON ma.product_id = p.id
         WHERE ma.user_id = ?
           AND ma.status = 'active'
           AND (ma.expires_at IS NULL OR ma.expires_at > NOW())
         ORDER BY ma.started_at DESC"
    );
    $stmt->execute([$userId]);
    $accesses = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Member area error: ' . $e->getMessage());
}

// Konten lead magnet yang bisa diakses (user = promoted lead + magnet punya modul aktif).
$leadMagnetAccess = [];
try {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT lm.id, lm.title, lm.thumbnail, lm.subheadline,
                (SELECT COUNT(*) FROM classes c WHERE c.lead_magnet_id = lm.id AND c.status='active') AS class_count
         FROM leads l
         JOIN lead_magnets lm ON lm.id = l.lead_magnet_id
         WHERE l.promoted_user_id = ?
           AND EXISTS (SELECT 1 FROM classes c WHERE c.lead_magnet_id = lm.id AND c.status='active')
         ORDER BY lm.title ASC"
    );
    $stmt->execute([$userId]);
    $leadMagnetAccess = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Member area lead magnet error: ' . $e->getMessage());
}

// Lanjutkan Belajar: materi terakhir dibuka per produk / lead magnet.
// Fail-safe bila tabel material_resume belum ada (belum migrasi 1.2.26).
$resumeMap   = [];
$resumeLmMap = [];
try {
    $rs = $pdo->prepare('SELECT product_id, lead_magnet_id, material_id FROM material_resume WHERE user_id = ?');
    $rs->execute([$userId]);
    foreach ($rs->fetchAll() as $r) {
        if ((int) $r['product_id'] > 0)          $resumeMap[(int) $r['product_id']]      = (int) $r['material_id'];
        elseif ((int) $r['lead_magnet_id'] > 0)  $resumeLmMap[(int) $r['lead_magnet_id']] = (int) $r['material_id'];
    }
} catch (Exception $e) { /* tabel belum ada — tombol lanjutkan dilewati */ }

// Progres belajar agregat per produk: materi non-link di modul aktif
// (termasuk modul yang DIBAGIKAN ke produk ini) vs yang sudah selesai.
$progressMap = [];
if ($accesses) {
    try {
        $pids = array_map(fn($a) => (int) $a['id'], $accesses);
        $ph   = implode(',', array_fill(0, count($pids), '?'));
        $pq   = $pdo->prepare(
            "SELECT p.id AS pid, COUNT(m.id) AS total,
                    SUM(EXISTS(SELECT 1 FROM material_completions mc WHERE mc.user_id = ? AND mc.material_id = m.id)) AS done
             FROM products p
             JOIN classes c ON c.status = 'active'
                  AND (c.product_id = p.id OR c.id IN (SELECT class_id FROM class_products WHERE product_id = p.id))
             JOIN materials m ON m.class_id = c.id AND m.content_type <> 'link'
             WHERE p.id IN ($ph)
             GROUP BY p.id"
        );
        $pq->execute([$userId, ...$pids]);
        foreach ($pq->fetchAll() as $r) {
            $progressMap[(int) $r['pid']] = ['done' => (int) $r['done'], 'total' => (int) $r['total']];
        }
    } catch (Exception $e) { /* progres opsional — jangan ganggu halaman */ }
}

// Review aktif global (per-produk dicek per kartu). Tombol "Beri Ulasan" hanya
// tampil bila produk membuka review — form-nya ada di halaman produk (#ulasan).
$reviewsGlobal = getSetting('reviews_enabled', '1') === '1';

$typeBadge = function(string $t): string {
    return match ($t) {
        'class'      => 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400',
        'membership' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
        'ebook'      => 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
        'software'   => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        'jasa'       => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
        'coaching'   => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400',
        'webinar'    => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
        'template'   => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
        default      => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
    };
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

      <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Area Member</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
          <?= count($accesses) ?> produk aktif<?php if (!empty($leadMagnetAccess)): ?> &middot; <?= count($leadMagnetAccess) ?> konten<?php endif; ?> yang bisa kamu akses
        </p>
      </div>

      <?php if (empty($accesses) && empty($leadMagnetAccess)): ?>

      <!-- Empty state -->
      <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm py-16 text-center">
        <div class="w-16 h-16 mx-auto rounded-2xl bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center mb-4">
          <svg class="w-8 h-8 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
        </div>
        <h3 class="font-bold text-gray-900 dark:text-white text-lg mb-2">Belum ada akses</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 max-w-sm mx-auto mb-6">
          Kamu belum memiliki produk aktif. Beli produk untuk mendapatkan akses ke konten eksklusif.
        </p>
        <a href="<?= e(APP_URL) ?>/products"
           class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90"
           style="background-color: var(--brand-color);">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
          </svg>
          Jelajahi Produk
        </a>
      </div>

      <?php else: ?>

      <?php if (!empty($accesses)): ?>
      <!-- Product grid -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($accesses as $acc): ?>
        <?php
        $isExpired   = !empty($acc['expires_at']) && strtotime($acc['expires_at']) < time();
        $expiresText = '';
        if (!empty($acc['expires_at'])) {
            $daysLeft = (int) ceil((strtotime($acc['expires_at']) - time()) / 86400);
            $expiresText = $daysLeft > 0 ? 'Berakhir ' . $daysLeft . ' hari lagi' : 'Berakhir hari ini';
        } else {
            $expiresText = 'Akses Seumur Hidup';
        }
        ?>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden flex flex-col">

          <!-- Thumbnail -->
          <?php if ($acc['thumbnail']): ?>
          <img src="<?= e($acc['thumbnail']) ?>" alt="<?= e($acc['name']) ?>" class="w-full h-40 object-cover">
          <?php else: ?>
          <div class="w-full h-40 bg-gradient-to-br from-[color-mix(in_srgb,var(--brand-color)_80%,black)] to-[var(--brand-color)] flex items-center justify-center">
            <svg class="w-12 h-12 text-white/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
            </svg>
          </div>
          <?php endif; ?>

          <div class="p-4 flex-1 flex flex-col">
            <!-- Badge + title -->
            <div class="flex items-center gap-2 mb-2">
              <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= $typeBadge($acc['type']) ?>">
                <?= e(productCatalogLabel($acc)) ?>
              </span>
              <?php if ((int)$acc['class_count'] > 0): ?>
              <span class="text-xs text-gray-400 dark:text-gray-600">
                <?= (int)$acc['class_count'] ?> modul
              </span>
              <?php endif; ?>
            </div>
            <h3 class="font-bold text-gray-900 dark:text-white text-sm leading-snug mb-1">
              <?= e($acc['name']) ?>
            </h3>
            <?php if ($acc['short_description']): ?>
            <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed mb-3 line-clamp-2 flex-1">
              <?= e($acc['short_description']) ?>
            </p>
            <?php else: ?>
            <div class="flex-1"></div>
            <?php endif; ?>

            <!-- Expiry info -->
            <div class="flex items-center gap-1.5 text-xs mb-4 <?= !empty($acc['expires_at']) && $isExpired ? 'text-red-500' : 'text-gray-400 dark:text-gray-600' ?>">
              <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
              </svg>
              <?= e($expiresText) ?>
            </div>

            <?php
            // Progres belajar (hanya bila produk punya materi yang bisa diselesaikan).
            $prog = $progressMap[(int) $acc['id']] ?? null;
            if ($prog && $prog['total'] > 0):
                $pct = (int) round($prog['done'] / $prog['total'] * 100);
            ?>
            <div class="mb-4 -mt-1">
              <div class="flex items-center justify-between text-xs mb-1">
                <span class="text-gray-400 dark:text-gray-600"><?= (int) $prog['done'] ?>/<?= (int) $prog['total'] ?> materi selesai</span>
                <span class="font-semibold text-[var(--brand-color)]"><?= $pct ?>%</span>
              </div>
              <div class="h-1.5 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
                <div class="h-full rounded-full transition-all" style="width: <?= $pct ?>%; background-color: var(--brand-color);"></div>
              </div>
            </div>
            <?php endif; ?>

            <!-- CTA -->
            <?php $resumeMat = $isExpired ? 0 : ($resumeMap[(int) $acc['id']] ?? 0); ?>
            <?php if ($isExpired): ?>
            <span class="block text-center py-2.5 rounded-xl text-xs font-semibold bg-gray-100 dark:bg-gray-800 text-gray-400 cursor-not-allowed">
              Akses Berakhir
            </span>
            <?php elseif ($resumeMat > 0): ?>
            <a href="<?= e(APP_URL) ?>/member/<?= (int)$acc['id'] ?>/material/<?= $resumeMat ?>"
               class="flex items-center justify-center gap-1.5 py-2.5 rounded-xl text-xs font-bold text-white transition hover:opacity-90"
               style="background-color: var(--brand-color);">
              Lanjutkan Belajar
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <a href="<?= e(APP_URL) ?>/member/<?= (int)$acc['id'] ?>"
               class="block text-center mt-1.5 text-xs text-gray-400 dark:text-gray-600 hover:text-[var(--brand-color)] transition">
              Lihat semua materi
            </a>
            <?php else: ?>
            <a href="<?= e(APP_URL) ?>/member/<?= (int)$acc['id'] ?>"
               class="block text-center py-2.5 rounded-xl text-xs font-bold text-white transition hover:opacity-90"
               style="background-color: var(--brand-color);">
              Buka Konten
            </a>
            <?php endif; ?>

            <?php
            // Tombol Ulasan — hanya bila review aktif (global + produk) & produk masih tampil.
            $canReview = $reviewsGlobal
                       && (int) ($acc['reviews_enabled'] ?? 0) === 1
                       && in_array($acc['product_status'] ?? '', ['active', 'coming_soon'], true);
            if ($canReview):
                $rvStatus = (string) ($acc['my_review_status'] ?? '');
                [$rvLabel, $rvIconFill] = match ($rvStatus) {
                    'approved' => ['Lihat Ulasanmu', 'currentColor'],
                    'pending'  => ['Ulasan Menunggu', 'currentColor'],
                    default    => ['Beri Ulasan', 'none'],
                };
            ?>
            <a href="<?= e(APP_URL) ?>/products/<?= e($acc['slug']) ?>#ulasan"
               class="mt-2 flex items-center justify-center gap-1.5 py-2 rounded-xl text-xs font-semibold text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-[var(--brand-color)] hover:text-[var(--brand-color)] transition">
              <svg class="w-3.5 h-3.5 text-yellow-400" viewBox="0 0 24 24" fill="<?= $rvIconFill ?>" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <?= e($rvLabel) ?>
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($leadMagnetAccess)): ?>
      <!-- Konten akses (dari lead magnet) — disajikan sebagai akses konten biasa -->
      <div class="<?= !empty($accesses) ? 'mt-8' : '' ?>">
        <div class="flex items-center gap-2 mb-4">
          <h3 class="text-base font-bold text-gray-900 dark:text-white">Akses Konten</h3>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
          <?php foreach ($leadMagnetAccess as $lm): ?>
          <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden flex flex-col">
            <?php if ($lm['thumbnail']): ?>
            <img src="<?= e($lm['thumbnail']) ?>" alt="<?= e($lm['title']) ?>" class="w-full h-40 object-cover">
            <?php else: ?>
            <div class="w-full h-40 flex items-center justify-center" style="background:linear-gradient(to bottom right, color-mix(in srgb, var(--brand-color) 80%, black), var(--brand-color));">
              <svg class="w-12 h-12 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8"/><rect x="3" y="5" width="18" height="14" rx="2"/></svg>
            </div>
            <?php endif; ?>
            <div class="p-4 flex-1 flex flex-col">
              <div class="flex items-center gap-2 mb-2">
                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium" style="background:color-mix(in srgb, var(--brand-color) 12%, transparent); color:var(--brand-color);">Akses</span>
                <?php if ((int)$lm['class_count'] > 0): ?>
                <span class="text-xs text-gray-400 dark:text-gray-600"><?= (int)$lm['class_count'] ?> modul</span>
                <?php endif; ?>
              </div>
              <h3 class="font-bold text-gray-900 dark:text-white text-sm leading-snug mb-1"><?= e($lm['title']) ?></h3>
              <?php if (!empty($lm['subheadline'])): ?>
              <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed mb-3 line-clamp-2 flex-1"><?= e($lm['subheadline']) ?></p>
              <?php else: ?>
              <div class="flex-1"></div>
              <?php endif; ?>
              <?php $lmResume = $resumeLmMap[(int) $lm['id']] ?? 0; ?>
              <?php if ($lmResume > 0): ?>
              <a href="<?= e(APP_URL) ?>/member/lm/<?= (int)$lm['id'] ?>/material/<?= $lmResume ?>"
                 class="flex items-center justify-center gap-1.5 py-2.5 rounded-xl text-xs font-bold text-white transition hover:opacity-90"
                 style="background-color: var(--brand-color);">
                Lanjutkan Belajar
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
              </a>
              <?php else: ?>
              <a href="<?= e(APP_URL) ?>/member/lm/<?= (int)$lm['id'] ?>"
                 class="block text-center py-2.5 rounded-xl text-xs font-bold text-white transition hover:opacity-90"
                 style="background-color: var(--brand-color);">
                Buka Konten
              </a>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php endif; ?>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

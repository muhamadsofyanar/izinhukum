<?php
require_once __DIR__ . '/../../bootstrap.php';

// Mode preview: admin/staff melihat modul persis seperti pembeli (read-only).
$previewMode = !empty($_GET['preview']);
if ($previewMode) {
    requireAdminOrStaff();
} else {
    requireLogin();
}

$productId    = (int) ($_GET['product_id'] ?? 0);
$leadMagnetId = (int) ($_GET['lead_magnet_id'] ?? 0);
// Owner = produk ATAU lead magnet. Default produk (perilaku lama tak berubah).
$ownerType = $leadMagnetId > 0 ? 'lead_magnet' : 'product';
$ownerId   = $ownerType === 'lead_magnet' ? $leadMagnetId : $productId;
$ownerCol  = $ownerType === 'lead_magnet' ? 'lead_magnet_id' : 'product_id';
$baseRoot  = $previewMode ? '/admin/content/preview' : '/member';
$ownerBase = $ownerType === 'lead_magnet' ? $baseRoot . '/lm/' . $ownerId : $baseRoot . '/' . $ownerId;
$exitUrl   = '/admin/content?' . ($ownerType === 'lead_magnet' ? 'lead_magnet_id=' : 'product_id=') . $ownerId;

if ($ownerId <= 0) {
    flash('error', 'Konten tidak ditemukan.', 'error');
    redirect($previewMode ? '/admin/content' : '/member');
}

$userId = (int) $_SESSION['user_id'];

if (!$previewMode) {
    // Cek akses (produk: hasAccess; lead magnet: hasLeadMagnetAccess)
    $canAccess = $ownerType === 'lead_magnet'
        ? hasLeadMagnetAccess($userId, $leadMagnetId)
        : hasAccess($userId, $productId);
    if (!$canAccess) {
        flash('error', 'Kamu tidak memiliki akses ke konten ini.', 'error');
        redirect($previewMode ? '/admin/content' : '/member');
    }
}

$product  = null;
$classes  = [];
$completedIds     = [];
$doneTotal        = 0;
$completableTotal = 0;
$dripStates       = [];

try {
    $pdo = getDB();
    if (function_exists('ensureEmbedAccessColumns')) ensureEmbedAccessColumns($pdo);

    if ($ownerType === 'lead_magnet') {
        $stmt = $pdo->prepare("SELECT id, title AS name, subheadline AS short_description, thumbnail, access_type, embed_html, embed_url FROM lead_magnets WHERE id = ? LIMIT 1");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
    }
    $stmt->execute([$ownerId]);
    $product = $stmt->fetch();

    if (!$product) {
        flash('error', 'Konten tidak ditemukan.', 'error');
        redirect($previewMode ? '/admin/content' : '/member');
    }

    // ─── Mode Akses "Embed langsung" → render embed di layout member, skip kelas ──
    if (($product['access_type'] ?? 'course') === 'embed') {
        include __DIR__ . '/embed-access.php';   // memakai $product, $previewMode; berakhir exit
        exit;
    }

    // Load semua class + materials-nya sekaligus (owner: produk / lead magnet).
    // Untuk owner produk, sertakan juga modul yang DIBAGIKAN ke produk ini (class_products).
    $sharedClause = ($ownerCol === 'product_id')
        ? ' OR c.id IN (SELECT class_id FROM class_products WHERE product_id = ?)'
        : '';
    $stmt = $pdo->prepare(
        "SELECT c.*,
                (SELECT COUNT(*) FROM materials m WHERE m.class_id = c.id) AS material_count,
                cat.name AS category_name,
                cat.id   AS cat_id
         FROM classes c
         LEFT JOIN class_categories cat ON cat.id = c.category_id
         WHERE (c.{$ownerCol} = ?{$sharedClause}) AND c.status = 'active'
         ORDER BY c.sort_order ASC, c.id ASC"
    );
    $stmt->execute($sharedClause !== '' ? [$ownerId, $ownerId] : [$ownerId]);
    $rawClasses = $stmt->fetchAll();

    // Load materials untuk semua class sekaligus
    if ($rawClasses) {
        $classIds    = array_column($rawClasses, 'id');
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $mStmt = $pdo->prepare(
            "SELECT * FROM materials WHERE class_id IN ($placeholders) ORDER BY sort_order ASC, id ASC"
        );
        $mStmt->execute($classIds);
        $allMaterials = $mStmt->fetchAll();

        // Group materials by class_id
        $matsByClass = [];
        foreach ($allMaterials as $mat) {
            $matsByClass[$mat['class_id']][] = $mat;
        }

        // Materi yang sudah diselesaikan user (indikator progres).
        $allMatIds = array_map('intval', array_column($allMaterials, 'id'));
        if ($allMatIds) {
            $cph   = implode(',', array_fill(0, count($allMatIds), '?'));
            $cStmt = $pdo->prepare("SELECT material_id FROM material_completions WHERE user_id = ? AND material_id IN ($cph)");
            $cStmt->execute(array_merge([$userId], $allMatIds));
            foreach ($cStmt->fetchAll(PDO::FETCH_COLUMN) as $mid) $completedIds[(int) $mid] = true;
        }

        foreach ($rawClasses as &$cls) {
            $cls['materials'] = $matsByClass[$cls['id']] ?? [];
            // Hitung progres per modul — hanya materi non-link (bisa ditandai selesai).
            $cd = 0; $ct = 0;
            foreach ($cls['materials'] as $m) {
                if ($m['content_type'] === 'link') continue;
                $ct++;
                if (isset($completedIds[(int) $m['id']])) $cd++;
            }
            $cls['done_count']        = $cd;
            $cls['completable_count'] = $ct;
            $doneTotal        += $cd;
            $completableTotal += $ct;
        }
        unset($cls);
        $classes = $rawClasses;
    }

    // Drip Content: status kunci per modul — hanya owner produk (lead magnet
    // tanpa member_access) dan bukan mode preview (admin bebas melihat semua).
    if (!$previewMode && $ownerType === 'product') {
        $dripStates = dripLockStates($classes, $userId, $productId);
    }

} catch (Exception $e) {
    error_log('Class detail error: ' . $e->getMessage());
    flash('error', 'Gagal memuat konten.', 'error');
    redirect($previewMode ? '/admin/content' : '/member');
}

$pageTitle   = $product['name'];
$topbarTitle = $product['name'];

$upcomingKajian = [];
$kajianTimezone = new DateTimeZone('Asia/Jakarta');
if (!$previewMode && $ownerType === 'product') {
    try {
        $stmt = $pdo->prepare("SELECT id, title, starts_at FROM darmutiara_kajian
            WHERE product_id = ? AND published = 1 AND starts_at >= ?
              AND LOWER(title) NOT LIKE '[demo]%'
            ORDER BY starts_at ASC, id ASC LIMIT 5");
        $stmt->execute([$productId, (new DateTimeImmutable('now', $kajianTimezone))->format('Y-m-d H:i:s')]);
        $upcomingKajian = $stmt->fetchAll();
    } catch (PDOException $error) {
        // Instalasi lama bisa membuka materi sebelum migrasi Kajian dipasang.
        if ($error->getCode() !== '42S02') {
            error_log('Class detail Kajian: ' . $error->getMessage());
        }
    }
}

// Thumbnail bisa tersimpan sebagai path relatif (mis. assets/uploads/...). Jadikan
// absolut agar tetap tampil di route dalam seperti /member/lm/7 (path relatif akan
// keliru resolve ke /member/lm/assets/...). URL http(s) penuh dibiarkan apa adanya.
$thumbRaw = trim((string) ($product['thumbnail'] ?? ''));
$thumbUrl = $thumbRaw === '' ? ''
    : (preg_match('#^https?://#i', $thumbRaw) ? $thumbRaw : rtrim(APP_URL, '/') . '/' . ltrim($thumbRaw, '/'));

$totalMaterials = array_sum(array_column($classes, 'material_count'));
$progressPct    = $completableTotal > 0 ? (int) round($doneTotal / $completableTotal * 100) : 0;

$iconForType = function(string $type): string {
    return match ($type) {
        'video' => '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>',
        'file'  => '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        'link'  => '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        default => '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
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

      <div class="max-w-3xl mx-auto">

        <!-- Breadcrumb -->
        <nav class="flex items-center gap-2 text-sm text-gray-400 dark:text-gray-600 mb-5">
          <a href="<?= e(APP_URL) ?><?= $previewMode ? e($exitUrl) : '/member' ?>" class="hover:text-[var(--brand-color)] transition"><?= $previewMode ? 'Editor Konten' : 'Area Member' ?></a>
          <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
          <span class="text-gray-600 dark:text-gray-400 truncate"><?= e($product['name']) ?></span>
        </nav>

        <!-- Product Header -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-5">
          <div class="flex items-start gap-4">
            <?php if ($thumbUrl !== ''): ?>
            <img src="<?= e($thumbUrl) ?>" alt="" class="w-14 h-14 rounded-xl object-cover shrink-0">
            <?php else: ?>
            <div class="w-14 h-14 rounded-xl bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)] flex items-center justify-center shrink-0">
              <svg class="w-6 h-6 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
              </svg>
            </div>
            <?php endif; ?>
            <div class="flex-1 min-w-0">
              <h2 class="font-bold text-gray-900 dark:text-white text-lg leading-tight"><?= e($product['name']) ?></h2>
              <?php if ($product['short_description']): ?>
              <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 leading-relaxed"><?= e($product['short_description']) ?></p>
              <?php endif; ?>
              <div class="flex items-center gap-4 mt-3 text-xs text-gray-400 dark:text-gray-600">
                <span class="flex items-center gap-1">
                  <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
                  </svg>
                  <?= count($classes) ?> modul
                </span>
                <span class="flex items-center gap-1">
                  <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                  </svg>
                  <?= $totalMaterials ?> materi
                </span>
              </div>
            </div>
          </div>
          <?php if ($completableTotal > 0): ?>
          <div class="mt-5">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-1.5">
              <span class="text-xs font-medium text-gray-500 dark:text-gray-400"><?= $doneTotal ?>/<?= $completableTotal ?> materi selesai</span>
              <span class="text-xs font-bold" style="color:var(--brand-color)"><?= $progressPct ?>%</span>
            </div>
            <div class="h-1.5 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
              <div class="h-full rounded-full transition-all" style="width:<?= $progressPct ?>%;background:var(--brand-color)"></div>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($upcomingKajian): ?>
        <section class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-5 mb-5">
          <h2 class="font-bold text-gray-900 dark:text-white">Kajian Mendatang</h2>
          <ul class="mt-2 divide-y divide-gray-200 dark:divide-gray-800">
            <?php foreach ($upcomingKajian as $session): ?>
            <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2">
              <a class="font-semibold text-[var(--brand-color)] underline"
                 href="<?= e(rtrim(APP_URL, '/') . '/kajian?id=' . (int) $session['id']) ?>"><?= e($session['title']) ?></a>
              <span class="text-sm text-gray-600 dark:text-gray-400"><?= e((new DateTimeImmutable($session['starts_at'], $kajianTimezone))->format('d/m/Y H:i')) ?> WIB</span>
            </li>
            <?php endforeach; ?>
          </ul>
        </section>
        <?php endif; ?>

        <!-- Category Filter (shown only when multiple categories exist) -->
        <?php
        $uniqueCategories = [];
        foreach ($classes as $cls) {
            if (!empty($cls['cat_id']) && !isset($uniqueCategories[$cls['cat_id']])) {
                $uniqueCategories[$cls['cat_id']] = $cls['category_name'];
            }
        }
        ?>
        <?php if (count($uniqueCategories) > 1): ?>
        <div class="flex items-center gap-2 mb-4 flex-wrap">
          <button onclick="filterByCategory('')"
                  class="cat-filter-btn px-3 py-1.5 rounded-lg text-xs font-medium transition-colors bg-[var(--brand-color)] text-white"
                  data-cat-filter="">
            Semua
          </button>
          <?php foreach ($uniqueCategories as $catId => $catName): ?>
          <button onclick="filterByCategory('<?= (int)$catId ?>')"
                  class="cat-filter-btn px-3 py-1.5 rounded-lg text-xs font-medium transition-colors bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-[var(--brand-color)] hover:text-[var(--brand-color)]"
                  data-cat-filter="<?= (int)$catId ?>">
            <?= e($catName) ?>
          </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Modules & Materials -->
        <?php if (empty($classes)): ?>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm py-14 text-center">
          <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
          </svg>
          <p class="text-sm font-medium text-gray-400 dark:text-gray-600">Konten sedang disiapkan</p>
          <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Admin akan segera menambahkan materi.</p>
        </div>
        <?php else: ?>
        <div class="space-y-3" id="modulesAccordion">
          <?php foreach ($classes as $i => $cls): ?>
          <?php $drip = $dripStates[(int) $cls['id']] ?? ['locked' => false, 'unlock_at' => null, 'days_left' => 0]; ?>
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden"
               data-cat-id="<?= !empty($cls['cat_id']) ? (int)$cls['cat_id'] : '' ?>">

            <!-- Module Header (toggle) -->
            <button type="button"
                    onclick="toggleModule(this)"
                    class="w-full flex items-center gap-4 px-5 py-4 text-left hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors focus:outline-none">
              <div class="w-8 h-8 rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)] flex items-center justify-center text-xs font-bold text-[var(--brand-color)] shrink-0">
                <?= $i + 1 ?>
              </div>
              <div class="flex-1 min-w-0 text-left">
                <p class="font-semibold text-gray-900 dark:text-white text-sm"><?= e($cls['title']) ?></p>
                <?php if ($cls['description']): ?>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5 truncate"><?= e($cls['description']) ?></p>
                <?php endif; ?>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <?php if (!empty($cls['category_name'])): ?>
                <span class="hidden sm:inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] text-[var(--brand-color)]">
                  <?= e($cls['category_name']) ?>
                </span>
                <?php endif; ?>
                <?php if ($drip['locked']): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400">
                  <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                  <?= (int) $drip['days_left'] ?> hari lagi
                </span>
                <?php elseif ($previewMode && (int) ($cls['unlock_after_days'] ?? 0) > 0): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400" title="Drip content — member baru bisa membuka modul ini setelah <?= (int) $cls['unlock_after_days'] ?> hari sejak akses aktif">
                  <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  Drip hari ke-<?= (int) $cls['unlock_after_days'] ?>
                </span>
                <?php endif; ?>
                <?php if ((int) ($cls['completable_count'] ?? 0) > 0 && (int) $cls['done_count'] === (int) $cls['completable_count']): ?>
                <span class="inline-flex items-center gap-1 text-xs font-semibold text-green-600 dark:text-green-400">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  Selesai
                </span>
                <?php elseif ((int) ($cls['completable_count'] ?? 0) > 0): ?>
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400"><?= (int) $cls['done_count'] ?>/<?= (int) $cls['completable_count'] ?></span>
                <?php else: ?>
                <span class="text-xs text-gray-400 dark:text-gray-600"><?= count($cls['materials']) ?> materi</span>
                <?php endif; ?>
                <svg class="module-chevron w-4 h-4 text-gray-400 transition-transform duration-200 <?= $i === 0 ? 'rotate-180' : '' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="6 9 12 15 18 9"/>
                </svg>
              </div>
            </button>

            <!-- Materials List -->
            <div class="module-content <?= $i === 0 ? '' : 'hidden' ?>">
              <?php if ($drip['locked']): ?>
              <div class="px-5 py-8 border-t border-gray-100 dark:border-gray-800 text-center">
                <div class="w-10 h-10 mx-auto rounded-xl bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center mb-2.5">
                  <svg class="w-5 h-5 text-amber-500 dark:text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Modul ini masih terkunci</p>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">
                  Terbuka pada <?= e(date('d M Y', strtotime($drip['unlock_at']))) ?> — <?= (int) $drip['days_left'] ?> hari lagi
                </p>
              </div>
              <?php elseif (empty($cls['materials'])): ?>
              <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800">
                <p class="text-xs text-gray-400 dark:text-gray-600 italic">Belum ada materi di modul ini.</p>
              </div>
              <?php else: ?>
              <div class="divide-y divide-gray-100 dark:divide-gray-800 border-t border-gray-100 dark:border-gray-800">
                <?php foreach ($cls['materials'] as $mat): ?>
                <?php $matDone = isset($completedIds[(int) $mat['id']]); ?>
                <a href="<?= e(APP_URL) ?><?= $ownerBase ?>/material/<?= (int)$mat['id'] ?>"
                   class="flex items-center gap-3 px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors group">
                  <?php if ($matDone): ?>
                  <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 text-white" style="background:#16a34a">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  </div>
                  <?php else: ?>
                  <div class="w-7 h-7 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0 text-gray-500 dark:text-gray-400 group-hover:bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] group-hover:text-[var(--brand-color)] transition-colors">
                    <?= $iconForType($mat['content_type']) ?>
                  </div>
                  <?php endif; ?>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200 group-hover:text-[var(--brand-color)] transition-colors"><?= e($mat['title']) ?></p>
                    <p class="text-xs text-gray-400 dark:text-gray-600"><?= e(materialTypeLabel($mat['content_type'])) ?></p>
                  </div>
                  <svg class="w-4 h-4 text-gray-300 dark:text-gray-700 group-hover:text-[var(--brand-color)] shrink-0 transition-colors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="9 18 15 12 9 6"/>
                  </svg>
                </a>
                <?php endforeach; ?>
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

<script>
function toggleModule(btn) {
  const content  = btn.nextElementSibling;
  const chevron  = btn.querySelector('.module-chevron');
  const isHidden = content.classList.contains('hidden');
  content.classList.toggle('hidden', !isHidden);
  chevron.classList.toggle('rotate-180', isHidden);
}

function filterByCategory(catId) {
  document.querySelectorAll('#modulesAccordion [data-cat-id]').forEach(el => {
    const match = !catId || el.dataset.catId === catId;
    el.style.display = match ? '' : 'none';
  });
  document.querySelectorAll('.cat-filter-btn').forEach(btn => {
    const active = btn.dataset.catFilter === catId;
    btn.classList.toggle('bg-[var(--brand-color)]', active);
    btn.classList.toggle('text-white', active);
    btn.classList.toggle('bg-white', !active);
    btn.classList.toggle('dark:bg-gray-900', !active);
    btn.classList.toggle('border', !active);
    btn.classList.toggle('border-gray-200', !active);
    btn.classList.toggle('dark:border-gray-700', !active);
    btn.classList.toggle('text-gray-600', !active);
    btn.classList.toggle('dark:text-gray-400', !active);
  });
}
</script>

<?php if ($previewMode): ?>
<!-- Toolbar Mode Preview (admin/staff) -->
<div class="fixed left-1/2 -translate-x-1/2 bottom-5 z-[9999] flex items-center gap-3 pl-4 pr-2 py-2 rounded-full bg-gray-900 text-white shadow-2xl border border-white/10">
  <span class="inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap">
    <svg class="w-4 h-4 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
    Mode Preview — tampilan pembeli
  </span>
  <a href="<?= e(APP_URL) ?><?= e($exitUrl) ?>" class="inline-flex items-center gap-1 text-xs font-semibold px-3 py-1.5 rounded-full bg-white/15 hover:bg-white/25 transition whitespace-nowrap">
    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    Keluar
  </a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

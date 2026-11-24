<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pdo = getDB();

try {
    // Ambil semua level + jumlah user per level
    $stmt = $pdo->query(
        'SELECT ml.*,
                (SELECT COUNT(*) FROM users u WHERE u.membership_level_id = ml.id) AS user_count
         FROM membership_levels ml
         ORDER BY ml.sort_order ASC'
    );
    $levels = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Membership levels load error: ' . $e->getMessage());
    $levels = [];
}

// Ringkasan distribusi (selaras kartu "Distribusi Membership Level" di dashboard).
$totalLevelUsers = 0;
foreach ($levels as $l) { $totalLevelUsers += (int) $l['user_count']; }
$nLv = count($levels);
// Brand monochrome ramp: index rendah = teal muda, index tinggi = teal tua.
$brandRamp = function (int $i, int $n): string {
    $f = $n > 1 ? $i / ($n - 1) : 0.5;
    if ($f < 0.5) {
        $p = (int) round(35 + ($f / 0.5) * 65);
        return 'color-mix(in srgb, var(--brand-color) ' . $p . '%, #ffffff)';
    }
    $d = (int) round((($f - 0.5) / 0.5) * 45);
    return 'color-mix(in srgb, var(--brand-color) ' . (100 - $d) . '%, #000000)';
};
$domIdx = 0; $domCount = -1;
foreach ($levels as $di => $dl) { if ((int)$dl['user_count'] > $domCount) { $domCount = (int)$dl['user_count']; $domIdx = $di; } }

generateCSRF();
$pageTitle   = 'Membership Levels';
$topbarTitle = 'Membership Levels';
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
          <h1 class="text-xl font-bold text-gray-900 dark:text-white">Membership Levels</h1>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Level otomatis berdasarkan total belanja user</p>
        </div>
        <button onclick="openLevelModal()"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90"
                style="background-color:var(--brand-color)">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          Tambah Level
        </button>
      </div>

      <!-- Overview distribusi (selaras kartu Distribusi di dashboard) -->
      <?php if (!empty($levels)): ?>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
          <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Distribusi Level</h3>
          <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5"><?= number_format($totalLevelUsers) ?> user aktif di <?= $nLv ?> level</p>
        </div>
        <div class="p-5">
          <?php if ($totalLevelUsers > 0): ?>
          <!-- Bar tersegmen 100% -->
          <div class="flex h-6 rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-800 mb-3.5">
            <?php $seg = 0; foreach ($levels as $i => $lv): ?>
              <?php $c = (int) $lv['user_count']; $wp = $totalLevelUsers > 0 ? $c / $totalLevelUsers * 100 : 0; if ($wp <= 0) continue; ?>
              <div class="h-full <?= $seg > 0 ? 'border-l-2 border-white dark:border-gray-900' : '' ?>"
                   style="width:<?= number_format($wp, 3, '.', '') ?>%;background:<?= $brandRamp($i, $nLv) ?>"
                   title="<?= e($lv['name']) ?> · <?= number_format($c) ?> (<?= round($wp) ?>%)"></div>
              <?php $seg++; endforeach; ?>
          </div>
          <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Dominan: <span class="font-semibold text-gray-800 dark:text-gray-200"><?= e($levels[$domIdx]['name']) ?></span> · <?= round($domCount / $totalLevelUsers * 100) ?>% dari total user</p>
          <div class="space-y-2.5">
            <?php foreach ($levels as $i => $lv): $c = (int) $lv['user_count']; $p = $totalLevelUsers > 0 ? round($c / $totalLevelUsers * 100) : 0; ?>
            <div class="flex items-center gap-3">
              <span class="w-3 h-3 rounded shrink-0" style="background:<?= $brandRamp($i, $nLv) ?>"></span>
              <span class="text-sm font-medium text-gray-800 dark:text-gray-200 flex-1 min-w-0 truncate"><?= e($lv['name']) ?></span>
              <span class="text-xs text-gray-400 dark:text-gray-600 w-9 text-right"><?= $p ?>%</span>
              <span class="text-sm font-bold text-gray-900 dark:text-white w-6 text-right"><?= number_format($c) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <p class="text-sm text-gray-400 dark:text-gray-600 text-center py-4">Belum ada user di level mana pun.</p>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Kelola Level -->
      <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Kelola Level</h2>

      <!-- Cards Level -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <?php foreach ($levels as $lvl): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 flex flex-col gap-3">
          <div class="flex items-start justify-between">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl flex items-center justify-center"
                   style="background-color: <?= e($lvl['color']) ?>20;">
                <?php if ($lvl['icon'] === 'crown'): ?>
                <svg class="w-5 h-5" style="color:<?= e($lvl['color']) ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="m2 4 3 12h14l3-12-6 5-4-5-4 5-6-5z"/>
                  <path d="M3 20h18"/>
                </svg>
                <?php elseif ($lvl['icon'] === 'star'): ?>
                <svg class="w-5 h-5" style="color:<?= e($lvl['color']) ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                </svg>
                <?php elseif ($lvl['icon'] === 'trophy'): ?>
                <svg class="w-5 h-5" style="color:<?= e($lvl['color']) ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/>
                  <path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/>
                  <path d="M4 22h16"/>
                  <path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/>
                  <path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/>
                  <path d="M18 2H6v7a6 6 0 0 0 12 0V2z"/>
                </svg>
                <?php else: /* medal */?>
                <svg class="w-5 h-5" style="color:<?= e($lvl['color']) ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="12" cy="8" r="6"/>
                  <path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
                </svg>
                <?php endif; ?>
              </div>
              <div>
                <p class="font-bold text-gray-900 dark:text-white"><?= e($lvl['name']) ?></p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                  <?= $lvl['min_spend'] > 0 ? 'Min. ' . e(formatRupiah((float)$lvl['min_spend'])) : 'Semua user' ?>
                </p>
              </div>
            </div>
            <div class="flex gap-1">
              <button onclick='openLevelModal(<?= htmlspecialchars(json_encode($lvl), ENT_QUOTES) ?>)'
                      class="p-1.5 rounded-lg text-gray-400 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                  <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
              </button>
            </div>
          </div>
          <!-- Color swatch -->
          <div class="flex items-center gap-2">
            <div class="w-4 h-4 rounded-full border border-white dark:border-gray-700 shadow-sm"
                 style="background-color:<?= e($lvl['color']) ?>"></div>
            <span class="text-xs font-mono text-gray-500 dark:text-gray-400"><?= e($lvl['color']) ?></span>
          </div>
          <div class="pt-3 border-t border-gray-100 dark:border-gray-800">
            <div class="flex items-end justify-between mb-1.5">
              <div>
                <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none"><?= number_format((int)$lvl['user_count']) ?></p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">User di level ini</p>
              </div>
              <span class="text-xs font-semibold text-gray-400 dark:text-gray-500"><?= $totalLevelUsers > 0 ? round((int)$lvl['user_count'] / $totalLevelUsers * 100) : 0 ?>%</span>
            </div>
            <div class="w-full h-1.5 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
              <div class="h-full rounded-full" style="width:<?= $totalLevelUsers > 0 ? round((int)$lvl['user_count'] / $totalLevelUsers * 100) : 0 ?>%;background-color:<?= e($lvl['color']) ?>"></div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($levels)): ?>
        <div class="col-span-4 py-12 text-center text-sm text-gray-400 dark:text-gray-600">
          Belum ada level. Klik "Tambah Level" untuk memulai.
        </div>
        <?php endif; ?>
      </div>

      <!-- Info -->
      <div class="bg-blue-50 dark:bg-blue-900/10 border border-blue-200 dark:border-blue-800/40 rounded-xl p-4 text-sm text-blue-800 dark:text-blue-300">
        <strong>Cara kerja:</strong> Level user diperbarui otomatis setiap kali ada order yang berhasil dibayar.
        User mendapat level tertinggi yang memenuhi syarat min. belanja kumulatif mereka.
      </div>
    </main>
  </div>
</div>

<!-- Modal Form Level -->
<div id="levelModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
  <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" onclick="closeLevelModal()"></div>
  <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-100 dark:border-gray-800 w-full max-w-md">
    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
      <h3 id="levelModalTitle" class="font-bold text-gray-900 dark:text-white text-base">Tambah Level</h3>
      <button onclick="closeLevelModal()" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <form id="levelForm" method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-membership-level" class="p-5 space-y-4">
      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="level_id" id="levelId" value="">

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            Nama Level <span class="text-red-500">*</span>
          </label>
          <input type="text" name="name" id="levelName" required maxlength="50"
                 placeholder="Contoh: Gold"
                 oninput="document.getElementById('levelSlug').value=this.value.toLowerCase().replace(/[^a-z0-9]/g,'-').replace(/-+/g,'-')"
                 class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Slug</label>
          <input type="text" name="slug" id="levelSlug" required maxlength="50"
                 class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition"
                 placeholder="auto-generated">
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
          Min. Total Belanja (<?= e(currencySymbol()) ?>) <span class="text-red-500">*</span>
        </label>
        <input type="number" name="min_spend" id="levelMinSpend" required min="0" step="1" value="0"
               class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
        <p class="text-xs text-gray-400 mt-1">Set ke 0 agar berlaku untuk semua user baru</p>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            Warna Badge <span class="text-red-500">*</span>
          </label>
          <div class="flex items-center gap-2">
            <input type="color" name="color" id="levelColor" value="#94a3b8"
                   class="w-10 h-10 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer p-0.5">
            <input type="text" id="levelColorText" maxlength="7" value="#94a3b8"
                   oninput="document.getElementById('levelColor').value=this.value"
                   class="flex-1 px-3 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white font-mono focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Ikon</label>
          <select name="icon" id="levelIcon"
                  class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
            <option value="medal">Medal</option>
            <option value="star">Star</option>
            <option value="crown">Crown</option>
            <option value="trophy">Trophy</option>
          </select>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Urutan</label>
        <input type="number" name="sort_order" id="levelSortOrder" min="0" step="1" value="0"
               class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
      </div>

      <div class="flex gap-2.5 pt-1">
        <button type="button" onclick="closeLevelModal()"
                class="flex-1 px-4 py-2.5 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
          Batal
        </button>
        <button type="submit"
                class="flex-1 px-4 py-2.5 rounded-xl text-sm font-bold text-white transition hover:opacity-90"
                style="background-color:var(--brand-color)">
          Simpan Level
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openLevelModal(level) {
    document.getElementById('levelForm').reset();
    document.getElementById('levelId').value = '';
    document.getElementById('levelModalTitle').textContent = level ? 'Edit Level' : 'Tambah Level';

    if (level) {
        document.getElementById('levelId').value         = level.id;
        document.getElementById('levelName').value       = level.name;
        document.getElementById('levelSlug').value       = level.slug;
        document.getElementById('levelMinSpend').value   = level.min_spend;
        document.getElementById('levelColor').value      = level.color;
        document.getElementById('levelColorText').value  = level.color;
        document.getElementById('levelIcon').value       = level.icon || 'medal';
        document.getElementById('levelSortOrder').value  = level.sort_order;
    } else {
        document.getElementById('levelColor').value     = '#94a3b8';
        document.getElementById('levelColorText').value = '#94a3b8';
    }

    // Sync color picker ↔ text input
    document.getElementById('levelColor').addEventListener('input', function () {
        document.getElementById('levelColorText').value = this.value;
    });

    const modal = document.getElementById('levelModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeLevelModal() {
    const modal = document.getElementById('levelModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

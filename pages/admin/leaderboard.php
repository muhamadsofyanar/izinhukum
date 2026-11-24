<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('affiliates')) { redirect('/admin/no-access'); }

$period = in_array($_GET['period'] ?? '', ['month', 'week']) ? $_GET['period'] : 'all';

$periodJoinSql = match ($period) {
    'month' => "AND r.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
    'week'  => 'AND r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
    default => '',
};

$leaderboard = [];

try {
    $pdo  = getDB();
    $stmt = $pdo->query(
        "SELECT u.name, u.email, u.avatar, a.code, a.custom_slug, a.total_earned, a.commission_rate,
                ml.name AS level_name, ml.color AS level_color,
                COUNT(DISTINCT r.id) AS total_referrals,
                COUNT(DISTINCT CASE WHEN r.status = 'purchased' THEN r.id END) AS total_conversions
         FROM affiliates a
         JOIN users u ON a.user_id = u.id
         LEFT JOIN membership_levels ml ON ml.id = u.membership_level_id
         LEFT JOIN referrals r ON r.affiliate_id = a.id {$periodJoinSql}
         WHERE a.status = 'active'
         GROUP BY a.id, u.name, u.email, u.avatar, a.code, a.custom_slug, a.total_earned, a.commission_rate, ml.name, ml.color
         ORDER BY a.total_earned DESC
         LIMIT 20"
    );
    $leaderboard = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Leaderboard error: ' . $e->getMessage());
}

// ─── Ringkasan KPI (kartu atas) ───────────────────────────────
$kpi = ['affiliates' => 0, 'komisi' => 0.0, 'referrals' => 0, 'conv_rate' => 0.0];
try {
    $pdo = getDB();
    $r1 = $pdo->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(total_earned),0) AS komisi FROM affiliates WHERE status='active'")->fetch();
    $kpi['affiliates'] = (int) ($r1['cnt'] ?? 0);
    $kpi['komisi']     = (float) ($r1['komisi'] ?? 0);
    $r2 = $pdo->query(
        "SELECT COUNT(*) AS refs,
                COALESCE(SUM(CASE WHEN r.status = 'purchased' THEN 1 ELSE 0 END),0) AS convs
         FROM referrals r JOIN affiliates a ON a.id = r.affiliate_id AND a.status = 'active'
         WHERE 1=1 {$periodJoinSql}"
    )->fetch();
    $totRef  = (int) ($r2['refs'] ?? 0);
    $totConv = (int) ($r2['convs'] ?? 0);
    $kpi['referrals'] = $totRef;
    $kpi['conv_rate'] = $totRef > 0 ? round($totConv / $totRef * 100, 1) : 0.0;
} catch (Exception $e) {
    error_log('Leaderboard KPI error: ' . $e->getMessage());
}

// Skala bar komisi relatif terhadap juara teratas (urut DESC → baris pertama = max).
$maxEarned = !empty($leaderboard) ? max(1.0, (float) $leaderboard[0]['total_earned']) : 1.0;

$pageTitle   = 'Leaderboard Affiliate';
$topbarTitle = 'Leaderboard';

$periodLabels = ['all' => 'All Time', 'month' => 'Bulan Ini', 'week' => 'Minggu Ini'];

$rankMeta = [
    1 => ['bg' => 'bg-yellow-50 dark:bg-yellow-900/10', 'border' => 'border-yellow-200 dark:border-yellow-800/40', 'badge' => 'bg-yellow-400 text-yellow-900', 'color' => '#F59E0B'],
    2 => ['bg' => 'bg-slate-50 dark:bg-slate-800/30',   'border' => 'border-slate-200 dark:border-slate-700',        'badge' => 'bg-slate-400 text-white',      'color' => '#94A3B8'],
    3 => ['bg' => 'bg-orange-50 dark:bg-orange-900/10', 'border' => 'border-orange-200 dark:border-orange-800/40',   'badge' => 'bg-orange-500 text-white',     'color' => '#B45309'],
];

// Inisial dari nama (maks 2 huruf).
$initials = function (string $name): string {
    $p = preg_split('/\s+/', trim($name));
    $s = mb_substr($p[0] ?? '', 0, 1) . mb_substr($p[1] ?? '', 0, 1);
    return mb_strtoupper($s !== '' ? $s : mb_substr($name, 0, 1));
};
// Avatar: foto bila ada, kalau tidak inisial dengan warna brand solid.
$avatar = function (array $row, int $size) use ($initials): string {
    $av = trim((string) ($row['avatar'] ?? ''));
    if ($av !== '') {
        return '<img src="' . e($av) . '" alt="" class="rounded-full object-cover shrink-0" style="width:' . $size . 'px;height:' . $size . 'px">';
    }
    $fs = max(11, (int) round($size * 0.4));
    return '<div class="rounded-full flex items-center justify-center text-white font-semibold shrink-0" '
         . 'style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . $fs . 'px;background:var(--brand-color)">'
         . e($initials((string) $row['name'])) . '</div>';
};
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
          <h1 class="text-xl font-bold text-gray-900 dark:text-white">Leaderboard Affiliate</h1>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Ranking affiliate berdasarkan total komisi earned</p>
        </div>
        <!-- Period Filter -->
        <div class="flex items-center gap-1 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-1 shadow-sm">
          <?php foreach ($periodLabels as $key => $label): ?>
          <a href="?period=<?= $key ?>"
             class="px-3.5 py-2 rounded-lg text-xs font-semibold transition-colors <?= $period === $key ? 'text-white shadow-sm' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' ?>"
             <?= $period === $key ? 'style="background-color:var(--brand-color)"' : '' ?>>
            <?= e($label) ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- KPI ringkasan -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4 flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background:color-mix(in srgb,var(--brand-color) 12%,transparent);color:var(--brand-color)">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <div class="min-w-0"><p class="text-xs text-gray-500 dark:text-gray-400">Affiliate Aktif</p><p class="text-xl font-bold text-gray-900 dark:text-white truncate"><?= number_format($kpi['affiliates']) ?></p></div>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4 flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background:color-mix(in srgb,var(--brand-color) 12%,transparent);color:var(--brand-color)">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          </div>
          <div class="min-w-0"><p class="text-xs text-gray-500 dark:text-gray-400">Total Komisi</p><p class="text-xl font-bold text-gray-900 dark:text-white truncate"><?= e(formatRupiah($kpi['komisi'])) ?></p></div>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4 flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background:color-mix(in srgb,var(--brand-color) 12%,transparent);color:var(--brand-color)">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
          </div>
          <div class="min-w-0"><p class="text-xs text-gray-500 dark:text-gray-400">Referral · <?= e($periodLabels[$period]) ?></p><p class="text-xl font-bold text-gray-900 dark:text-white truncate"><?= number_format($kpi['referrals']) ?></p></div>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4 flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background:color-mix(in srgb,var(--brand-color) 12%,transparent);color:var(--brand-color)">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
          </div>
          <div class="min-w-0"><p class="text-xs text-gray-500 dark:text-gray-400">Avg Konversi</p><p class="text-xl font-bold text-gray-900 dark:text-white truncate"><?= number_format($kpi['conv_rate'], 1) ?>%</p></div>
        </div>
      </div>

      <!-- Top 3 Podium (jika ada cukup data) -->
      <?php if (count($leaderboard) >= 1): ?>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 sm:items-end">
        <?php
        $podium = array_slice($leaderboard, 0, min(3, count($leaderboard)));
        // Reorder for podium display: silver(2), gold(1), bronze(3)
        $podiumOrder = count($podium) === 1 ? [0] : (count($podium) === 2 ? [1, 0] : [1, 0, 2]);
        ?>
        <?php foreach ($podiumOrder as $idx): ?>
        <?php
          $p    = $podium[$idx];
          $rank = $idx + 1;
          $meta = $rankMeta[$rank];
          $convRate = (int)$p['total_referrals'] > 0
            ? round((int)$p['total_conversions'] / (int)$p['total_referrals'] * 100, 1)
            : 0;
        ?>
        <?php $av = $rank === 1 ? 64 : 54; $mb = $rank === 1 ? 26 : 22; ?>
        <div class="<?= $meta['bg'] ?> border <?= $meta['border'] ?> rounded-2xl p-5 flex flex-col items-center text-center shadow-sm
                    <?= $rank === 1 ? 'sm:order-2 ring-2 sm:pb-7' : ($rank === 2 ? 'sm:order-1 sm:mt-6' : 'sm:order-3 sm:mt-6') ?>"
             <?= $rank === 1 ? 'style="--tw-ring-color:' . $meta['color'] . '"' : '' ?>>
          <!-- Avatar + medali -->
          <div class="relative mb-3" style="width:<?= $av ?>px;height:<?= $av ?>px">
            <?= $avatar($p, $av) ?>
            <span class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 rounded-full flex items-center justify-center text-white border-2 border-white dark:border-gray-900 shadow-sm"
                  style="width:<?= $mb ?>px;height:<?= $mb ?>px;background:<?= $meta['color'] ?>">
              <?php if ($rank === 1): ?>
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M5 16L3 5l5.5 5L12 4l3.5 6L21 5l-2 11H5zm0 3h14v2H5v-2z"/></svg>
              <?php else: ?>
              <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.9 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L8 14.14l-5-4.87 7.1-1.01L12 2z"/></svg>
              <?php endif; ?>
            </span>
          </div>
          <span class="text-xs font-bold uppercase tracking-wider mb-0.5" style="color:<?= $meta['color'] ?>">#<?= $rank ?></span>
          <p class="font-bold text-gray-900 dark:text-white <?= $rank === 1 ? 'text-lg' : 'text-base' ?> leading-tight"><?= e($p['name']) ?></p>
          <p class="text-xs text-gray-500 dark:text-gray-400 mb-2 truncate w-full"><?= e($p['email']) ?></p>
          <?php if (!empty($p['level_name'])): ?>
          <span class="inline-flex items-center gap-1 text-[11px] font-medium px-2 py-0.5 rounded-full mb-2" style="background:<?= e($p['level_color']) ?>1a;color:<?= e($p['level_color']) ?>">
            <span class="w-1.5 h-1.5 rounded-full" style="background:<?= e($p['level_color']) ?>"></span><?= e($p['level_name']) ?>
          </span>
          <?php endif; ?>
          <p class="<?= $rank === 1 ? 'text-2xl' : 'text-xl' ?> font-bold text-gray-900 dark:text-white mt-1"><?= e(formatRupiah((float)$p['total_earned'])) ?></p>
          <p class="text-xs text-gray-400 dark:text-gray-500">Total Komisi</p>
          <div class="mt-3 flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
            <span><strong class="text-gray-900 dark:text-white"><?= (int)$p['total_referrals'] ?></strong> Referral</span>
            <span class="text-gray-300 dark:text-gray-700">·</span>
            <span><strong class="text-gray-900 dark:text-white"><?= $convRate ?>%</strong> Konversi</span>
          </div>
          <?php if (!empty($p['custom_slug'])): ?>
          <div class="mt-2 font-mono text-xs text-gray-400 dark:text-gray-600 bg-gray-100 dark:bg-gray-800 px-2 py-0.5 rounded-full">/r/<?= e($p['custom_slug']) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Full Ranking Table -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
          <h3 class="font-semibold text-gray-900 dark:text-white text-sm">
            Ranking Lengkap — <?= e($periodLabels[$period]) ?>
          </h3>
          <span class="text-xs text-gray-400 dark:text-gray-600"><?= count($leaderboard) ?> affiliate aktif</span>
        </div>

        <?php if (empty($leaderboard)): ?>
        <div class="py-14 text-center">
          <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
          </svg>
          <p class="text-sm text-gray-400 dark:text-gray-600">Belum ada data affiliate aktif.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
                <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider w-12">#</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Affiliate</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Referral</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Konversi</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Conv. Rate</th>
                <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Komisi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($leaderboard as $i => $row): ?>
              <?php
                $rank     = $i + 1;
                $rowMeta  = $rankMeta[$rank] ?? null;
                $convRate = (int)$row['total_referrals'] > 0
                  ? round((int)$row['total_conversions'] / (int)$row['total_referrals'] * 100, 1)
                  : 0;
                $rowClass = $rowMeta
                  ? $rowMeta['bg'] . ' ' . $rowMeta['border']
                  : 'hover:bg-gray-50 dark:hover:bg-gray-800/40';
              ?>
              <tr class="<?= $rowClass ?> transition-colors">
                <td class="px-5 py-3.5">
                  <?php if ($rank <= 3): ?>
                  <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold <?= $rowMeta['badge'] ?>">
                    <?= $rank ?>
                  </span>
                  <?php else: ?>
                  <span class="text-sm font-semibold text-gray-400 dark:text-gray-600"><?= $rank ?></span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3.5">
                  <div class="flex items-center gap-3">
                    <?= $avatar($row, 36) ?>
                    <div class="min-w-0">
                      <p class="font-semibold text-gray-900 dark:text-white truncate"><?= e($row['name']) ?></p>
                      <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5 truncate"><?= e($row['email']) ?></p>
                      <div class="flex items-center gap-2 mt-1">
                        <span class="font-mono text-xs text-gray-400 dark:text-gray-600 bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded"><?= e($row['code']) ?></span>
                        <?php if (!empty($row['custom_slug'])): ?>
                        <span class="font-mono text-xs text-[var(--brand-color)] bg-[color-mix(in_srgb,var(--brand-color)_8%,transparent)] px-1.5 py-0.5 rounded">/r/<?= e($row['custom_slug']) ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-3.5 text-center">
                  <span class="text-sm font-semibold text-gray-900 dark:text-white"><?= (int)$row['total_referrals'] ?></span>
                </td>
                <td class="px-4 py-3.5 text-center">
                  <span class="text-sm font-semibold text-gray-900 dark:text-white"><?= (int)$row['total_conversions'] ?></span>
                </td>
                <td class="px-4 py-3.5 text-center">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $convRate >= 50 ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : ($convRate >= 20 ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400') ?>">
                    <?= $convRate ?>%
                  </span>
                </td>
                <td class="px-5 py-3.5 text-right">
                  <span class="font-bold text-gray-900 dark:text-white"><?= e(formatRupiah((float)$row['total_earned'])) ?></span>
                  <?php $barPct = $maxEarned > 0 ? max(2, (int) round((float)$row['total_earned'] / $maxEarned * 100)) : 0; ?>
                  <div class="mt-1.5 ml-auto w-28 h-1.5 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
                    <div class="h-full rounded-full" style="width:<?= $barPct ?>%;background:var(--brand-color)"></div>
                  </div>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1"><?= number_format((float)$row['commission_rate'], 1) ?>% rate</p>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

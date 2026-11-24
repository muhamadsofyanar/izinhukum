<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pdo = getDB();

$tab = strtolower(trim($_GET['tab'] ?? 'magnets'));
if (!in_array($tab, ['magnets', 'leads'], true)) $tab = 'magnets';

// ─── Data: membership levels (dropdown bonus) ────────────────
$levels = [];
try {
    $levels = $pdo->query('SELECT id, name FROM membership_levels ORDER BY sort_order ASC')->fetchAll();
} catch (Exception $e) {
    error_log('LM load levels error: ' . $e->getMessage());
}
$levelName = [];
foreach ($levels as $lv) $levelName[(int) $lv['id']] = $lv['name'];

// ─── Data: produk (dropdown "Produk Tujuan" utk Funnel Analytics) ───
$lmProducts = [];
try {
    $lmProducts = $pdo->query("SELECT id, name FROM products WHERE status = 'active' ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    error_log('LM load products error: ' . $e->getMessage());
}

// ─── Data: magnets ───────────────────────────────────────────
$magnets = [];
try {
    if (function_exists('ensureEmbedAccessColumns')) ensureEmbedAccessColumns($pdo);
    $magnets = $pdo->query(
        'SELECT lm.*, (SELECT COUNT(*) FROM leads l WHERE l.lead_magnet_id = lm.id) AS leads_count
         FROM lead_magnets lm ORDER BY lm.created_at DESC'
    )->fetchAll();
} catch (Exception $e) {
    error_log('LM load magnets error: ' . $e->getMessage());
}

// ─── Data: leads (tab leads, dengan filter) ──────────────────
$leads = [];
$filterMagnet = (int) ($_GET['magnet'] ?? 0);
$q = trim($_GET['q'] ?? '');
if ($tab === 'leads') {
    try {
        $where = '1=1';
        $params = [];
        if ($filterMagnet > 0) { $where .= ' AND l.lead_magnet_id = ?'; $params[] = $filterMagnet; }
        if ($q !== '') {
            $where .= ' AND (l.name LIKE ? OR l.whatsapp LIKE ? OR l.email LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        $st = $pdo->prepare(
            "SELECT l.*, lm.title AS magnet_title, au.name AS affiliate_name
             FROM leads l
             INNER JOIN lead_magnets lm ON lm.id = l.lead_magnet_id
             LEFT JOIN affiliates a ON a.id = l.affiliate_id
             LEFT JOIN users au ON au.id = a.user_id
             WHERE {$where} ORDER BY l.created_at DESC LIMIT 500"
        );
        $st->execute($params);
        $leads = $st->fetchAll();
    } catch (Exception $e) {
        error_log('LM load leads error: ' . $e->getMessage());
    }
}

// ─── Funnel stats (tab leads, hormati filter magnet) ─────────
$funnel = ['total' => 0, 'engaged' => 0, 'customers' => 0];
if ($tab === 'leads') {
    try {
        $fw = '1=1'; $fp = [];
        if ($filterMagnet > 0) { $fw .= ' AND lead_magnet_id = ?'; $fp[] = $filterMagnet; }
        $fst = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(promoted_user_id IS NOT NULL) AS engaged,
                    SUM(promoted_user_id IS NOT NULL AND EXISTS(
                        SELECT 1 FROM orders o WHERE o.user_id = leads.promoted_user_id AND o.status = 'paid'
                    )) AS customers
             FROM leads WHERE {$fw}"
        );
        $fst->execute($fp);
        $fr = $fst->fetch() ?: [];
        $funnel = [
            'total'     => (int) ($fr['total'] ?? 0),
            'engaged'   => (int) ($fr['engaged'] ?? 0),
            'customers' => (int) ($fr['customers'] ?? 0),
        ];
    } catch (Exception $e) {
        error_log('LM funnel stats error: ' . $e->getMessage());
    }
}
$pct = function (int $n, int $d): string {
    return $d > 0 ? number_format($n / $d * 100, $n / $d * 100 == (int)($n / $d * 100) ? 0 : 1) . '%' : '0%';
};

$csrf  = generateCSRF();
$base  = rtrim(APP_URL, '/');
$pageTitle   = 'Lead Magnet';
$topbarTitle = 'Lead Magnet';

function lm_badge(string $s): array {
    return match ($s) {
        'published' => ['Published', 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'],
        'paused'    => ['Paused', 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
        default     => ['Draft', 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'],
    };
}
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
      <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
        <div>
          <h1 class="text-xl font-bold text-gray-900 dark:text-white">Lead Magnet</h1>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Tangkap prospek lewat halaman opt-in gratis</p>
        </div>
        <?php if ($tab === 'magnets'): ?>
        <button onclick="openMagnetModal()"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90"
                style="background-color:var(--brand-color)">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambah Lead Magnet
        </button>
        <?php endif; ?>
      </div>

      <!-- Tabs -->
      <div class="flex items-center gap-1 mb-6 border-b border-gray-200 dark:border-gray-800">
        <a href="<?= e($base) ?>/admin/lead-magnets?tab=magnets"
           class="px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition <?= $tab === 'magnets' ? 'border-brand text-gray-900 dark:text-white' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' ?>"
           style="<?= $tab === 'magnets' ? 'border-color:var(--brand-color)' : '' ?>">Magnets</a>
        <a href="<?= e($base) ?>/admin/lead-magnets?tab=leads"
           class="px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition <?= $tab === 'leads' ? 'text-gray-900 dark:text-white' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' ?>"
           style="<?= $tab === 'leads' ? 'border-color:var(--brand-color)' : '' ?>">Leads</a>
      </div>

      <?php if ($tab === 'magnets'): ?>
      <!-- ═══ TAB: MAGNETS ═══ -->
      <?php if (empty($magnets)): ?>
        <div class="text-center py-16 bg-white dark:bg-gray-900 rounded-2xl border border-dashed border-gray-200 dark:border-gray-800">
          <p class="text-gray-500 dark:text-gray-400 text-sm">Belum ada lead magnet. Klik <span class="font-semibold">Tambah Lead Magnet</span> untuk membuat yang pertama.</p>
        </div>
      <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <?php foreach ($magnets as $m): [$bLabel, $bClass] = lm_badge($m['status']); ?>
          <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden flex flex-col">
            <?php if (trim($m['thumbnail'] ?? '') !== ''): ?>
            <div class="h-36 bg-gray-100 dark:bg-gray-800 overflow-hidden">
              <img src="<?= e($base . '/' . ltrim($m['thumbnail'], '/')) ?>" alt="" class="w-full h-full object-cover">
            </div>
            <?php endif; ?>
            <div class="p-4 flex flex-col gap-3 flex-1">
              <div class="flex items-start justify-between gap-2">
                <h3 class="font-bold text-gray-900 dark:text-white text-sm leading-snug"><?= e($m['title']) ?></h3>
                <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold shrink-0 <?= $bClass ?>"><?= $bLabel ?></span>
              </div>
              <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <button type="button" onclick="copyToClipboard('<?= e($base) ?>/lp/<?= e($m['slug']) ?>', this.querySelector('.copy-label'), 'Tersalin')" class="inline-flex items-center gap-1 hover:text-brand transition" title="Salin URL publik">
                  <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                  <span class="copy-label">/lp/<?= e($m['slug']) ?></span>
                </button>
              </div>
              <div class="flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                <span class="inline-flex items-center gap-1.5">
                  <svg class="w-4 h-4 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>
                  <strong class="text-gray-900 dark:text-white"><?= (int) $m['leads_count'] ?></strong> leads
                </span>
                <?php if (!empty($m['bonus_membership_level_id']) && isset($levelName[(int) $m['bonus_membership_level_id']])): ?>
                <span class="inline-flex items-center gap-1.5" title="Bonus level">
                  <svg class="w-4 h-4 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                  <?= e($levelName[(int) $m['bonus_membership_level_id']]) ?>
                </span>
                <?php endif; ?>
              </div>
              <div class="mt-auto pt-2 space-y-2">
                <div class="flex items-center gap-2">
                  <button type="button" onclick="copyToClipboard('<?= e($base) ?>/lp/<?= e($m['slug']) ?>', this.querySelector('.copy-label'), 'Tersalin')" class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition" title="Salin link form lead magnet">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    <span class="copy-label">Salin Link</span>
                  </button>
                  <form id="dup-magnet-<?= (int) $m['id'] ?>" method="POST" action="<?= e($base) ?>/actions/admin/duplicate-lead-magnet" class="flex-1">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                    <button type="button" onclick="confirmAndSubmit('dup-magnet-<?= (int) $m['id'] ?>',{title:'Duplikat Lead Magnet?',message:'Salinan akan dibuat sebagai draft baru dengan slug terpisah.',confirmText:'Ya, Duplikat',variant:'info'})" class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition" title="Duplikat lead magnet">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>
                      Duplikat
                    </button>
                  </form>
                </div>
                <div class="flex items-center gap-2">
                  <a href="<?= e($base) ?>/lp/<?= e($m['slug']) ?>" target="_blank" class="flex-1 text-center px-3 py-2 rounded-lg text-xs font-semibold text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Lihat</a>
                  <button type="button" onclick='editMagnet(<?= json_encode($m, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)' class="flex-1 px-3 py-2 rounded-lg text-xs font-semibold text-white transition hover:opacity-90" style="background-color:var(--brand-color)">Edit</button>
                  <form id="del-magnet-<?= (int) $m['id'] ?>" method="POST" action="<?= e($base) ?>/actions/admin/delete-lead-magnet">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                    <button type="button" onclick="confirmAndSubmit('del-magnet-<?= (int) $m['id'] ?>',{title:'Hapus Lead Magnet?',message:'Lead magnet ini beserta semua leads-nya akan dihapus permanen.',confirmText:'Ya, Hapus',variant:'danger'})" class="px-3 py-2 rounded-lg text-xs font-semibold text-red-600 border border-red-200 dark:border-red-900/40 hover:bg-red-50 dark:hover:bg-red-900/20 transition" title="Hapus">
                      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    </button>
                  </form>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php else: ?>
      <!-- ═══ TAB: LEADS ═══ -->

      <!-- Funnel stats -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4 flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center shrink-0">
            <svg class="text-blue-600" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>
          </div>
          <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">Leads Masuk</p>
            <p class="text-xl font-bold text-gray-900 dark:text-white"><?= number_format($funnel['total']) ?></p>
          </div>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4 flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-indigo-50 dark:bg-indigo-900/20 flex items-center justify-center shrink-0">
            <svg class="text-indigo-600" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11l-3 3-1.5-1.5"/></svg>
          </div>
          <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">Jadi User <span class="text-gray-400">· <?= $pct($funnel['engaged'], $funnel['total']) ?></span></p>
            <p class="text-xl font-bold text-gray-900 dark:text-white"><?= number_format($funnel['engaged']) ?></p>
          </div>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4 flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-green-50 dark:bg-green-900/20 flex items-center justify-center shrink-0">
            <svg class="text-green-600" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
          </div>
          <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">Jadi Customer <span class="text-gray-400">· <?= $pct($funnel['customers'], $funnel['total']) ?></span></p>
            <p class="text-xl font-bold text-gray-900 dark:text-white"><?= number_format($funnel['customers']) ?></p>
          </div>
        </div>
      </div>

      <form method="GET" class="flex flex-wrap items-center gap-2 mb-4">
        <input type="hidden" name="tab" value="leads">
        <select name="magnet" class="px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-700 dark:text-gray-200">
          <option value="0">Semua Magnet</option>
          <?php foreach ($magnets as $m): ?>
          <option value="<?= (int) $m['id'] ?>" <?= $filterMagnet === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari nama / WA / email"
               class="px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-700 dark:text-gray-200 flex-1 min-w-[180px]">
        <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background-color:var(--brand-color)">Filter</button>
        <a href="<?= e($base) ?>/actions/admin/export-leads?magnet=<?= (int) $filterMagnet ?>&q=<?= urlencode($q) ?>"
           class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-semibold text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Export CSV
        </a>
      </form>

      <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500 border-b border-gray-100 dark:border-gray-800">
                <th class="px-4 py-3 font-semibold">Nama</th>
                <th class="px-4 py-3 font-semibold">WhatsApp</th>
                <th class="px-4 py-3 font-semibold">Email</th>
                <th class="px-4 py-3 font-semibold">Magnet</th>
                <th class="px-4 py-3 font-semibold">Affiliate</th>
                <th class="px-4 py-3 font-semibold">Tanggal</th>
                <th class="px-4 py-3 font-semibold text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php if (empty($leads)): ?>
              <tr><td colspan="7" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">Belum ada lead.</td></tr>
              <?php else: foreach ($leads as $l): ?>
              <tr class="text-gray-700 dark:text-gray-200">
                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white"><?= e($l['name']) ?></td>
                <td class="px-4 py-3"><a href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', $l['whatsapp'])) ?>" target="_blank" class="hover:text-brand"><?= e($l['whatsapp']) ?></a></td>
                <td class="px-4 py-3"><?= e($l['email'] ?? '') ?: '<span class="text-gray-300 dark:text-gray-600">—</span>' ?></td>
                <td class="px-4 py-3 text-gray-500 dark:text-gray-400"><?= e($l['magnet_title']) ?></td>
                <td class="px-4 py-3"><?= !empty($l['affiliate_name']) ? e($l['affiliate_name']) : '<span class="text-gray-300 dark:text-gray-600">— Langsung</span>' ?></td>
                <td class="px-4 py-3 text-gray-500 dark:text-gray-400 whitespace-nowrap"><?= e(date('d M Y H:i', strtotime($l['created_at']))) ?></td>
                <td class="px-4 py-3 text-right">
                  <form id="del-lead-<?= (int) $l['id'] ?>" method="POST" action="<?= e($base) ?>/actions/admin/delete-lead" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="lead_id" value="<?= (int) $l['id'] ?>">
                    <button type="button" onclick="confirmAndSubmit('del-lead-<?= (int) $l['id'] ?>',{title:'Hapus Lead?',message:'Lead <?= e(addslashes($l['name'])) ?> akan dihapus permanen.',confirmText:'Ya, Hapus',variant:'danger'})" class="text-red-500 hover:text-red-700" title="Hapus">
                      <svg class="w-4 h-4 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>

<!-- ═══ MODAL: Magnet Form ═══ -->
<div id="magnetModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeMagnetModal()"></div>
  <div class="absolute inset-0 flex items-start sm:items-center justify-center p-4 overflow-y-auto">
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg my-8">
      <form id="magnetForm" method="POST" action="<?= e($base) ?>/actions/admin/save-lead-magnet" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="id" id="lm_id" value="0">

        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
          <h3 id="lm_modal_title" class="font-bold text-gray-900 dark:text-white">Tambah Lead Magnet</h3>
          <div class="flex items-center gap-1 shrink-0">
            <a href="<?= e(APP_URL) ?>/admin/docs#lead-magnet" target="_blank" rel="noopener"
               title="Buka panduan Lead Magnet"
               class="inline-flex items-center gap-1.5 px-2.5 h-8 rounded-lg text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-[var(--brand-color)] hover:bg-gray-100 dark:hover:bg-gray-800 transition">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
              <span class="hidden sm:inline">Panduan</span>
            </a>
            <button type="button" onclick="closeMagnetModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
        </div>

        <div class="px-5 py-4 space-y-4 max-h-[70vh] overflow-y-auto">
          <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Judul *</label>
            <input type="text" name="title" id="lm_title" required oninput="autoSlug()" class="lm-inp" placeholder="Webinar Gratis - AI Jadi Babu">
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Slug *</label>
            <div class="flex items-center gap-2">
              <span class="text-xs text-gray-400 shrink-0">/lp/</span>
              <input type="text" name="slug" id="lm_slug" required class="lm-inp" placeholder="webinar-ai-jadi-babu">
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Badge</label>
              <input type="text" name="badge_text" id="lm_badge" maxlength="60" class="lm-inp" placeholder="FREE ACCESS">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Teks Tombol</label>
              <input type="text" name="cta_text" id="lm_cta" maxlength="60" class="lm-inp" placeholder="Free Access">
            </div>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Headline</label>
            <input type="text" name="headline" id="lm_headline" maxlength="255" class="lm-inp" placeholder="Buktikan sendiri seberapa jauh AI bisa bantu kamu">
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Subheadline</label>
            <textarea name="subheadline" id="lm_subheadline" rows="2" class="lm-inp" placeholder="Deskripsi singkat penawaran"></textarea>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Thumbnail <span class="text-gray-400 font-normal">(JPG/PNG/WEBP, maks 2MB)</span></label>
            <input type="file" name="thumbnail" accept="image/jpeg,image/png,image/webp" class="block w-full text-xs text-gray-500 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-gray-100 dark:file:bg-gray-800 file:text-gray-700 dark:file:text-gray-200">
            <p id="lm_thumb_cur" class="text-xs text-gray-400 mt-1 hidden"></p>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Produk Tujuan <span class="text-gray-400 font-normal">(opsional)</span></label>
            <select name="linked_product_id" id="lm_product" class="lm-inp">
              <option value="0">— Tidak tertaut —</option>
              <?php foreach ($lmProducts as $pr): ?>
              <option value="<?= (int) $pr['id'] ?>"><?= e($pr['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-gray-400 mt-1">Produk yang dituju lead magnet ini. Dipakai <strong>Funnel Analytics</strong> untuk menyambung opt-in → sales page → pembayaran. Kosongkan bila lead magnet berdiri sendiri.</p>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Produk Tripwire <span class="text-gray-400 font-normal">(opsional — penawaran berbayar di thank-you)</span></label>
            <select name="tripwire_product_id" id="lm_tripwire" class="lm-inp">
              <option value="0">— Tidak ada —</option>
              <?php foreach ($lmProducts as $pr): ?>
              <option value="<?= (int) $pr['id'] ?>"><?= e($pr['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-gray-400 mt-1">Setelah opt-in, produk ini ditawarkan di halaman terima kasih. <strong>Product Bumps</strong> produk itu tampil otomatis di checkout-nya. Kosongkan bila tak ada penawaran.</p>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Headline Penawaran <span class="text-gray-400 font-normal">(opsional)</span></label>
            <input type="text" name="tripwire_headline" id="lm_tripwire_headline" maxlength="200" class="lm-inp" placeholder="Penawaran khusus untukmu — hanya sekarang">
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Bonus Membership Level</label>
              <select name="bonus_membership_level_id" id="lm_level" class="lm-inp">
                <option value="0">— Tidak ada —</option>
                <?php foreach ($levels as $lv): ?>
                <option value="<?= (int) $lv['id'] ?>"><?= e($lv['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Status</label>
              <select name="status" id="lm_status" class="lm-inp">
                <option value="draft">Draft</option>
                <option value="published">Published</option>
                <option value="paused">Paused</option>
              </select>
            </div>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Meta Pixel ID <span class="text-gray-400 font-normal">(opsional, pisah koma)</span></label>
            <input type="text" name="fb_pixel_ids" id="lm_pixel" class="lm-inp" placeholder="123456789012345">
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Pesan Thank You</label>
            <textarea name="thankyou_message" id="lm_thankyou" rows="2" class="lm-inp" placeholder="Pesan setelah lead mendaftar"></textarea>
          </div>
          <div class="pt-1">
            <label class="flex items-center gap-2.5 cursor-pointer">
              <input type="checkbox" name="show_on_homepage" id="lm_show_home" value="1" class="w-4 h-4 rounded border-gray-300 dark:border-gray-600">
              <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Tampilkan di homepage (section &quot;Gratis&quot;)</span>
            </label>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 ml-6">Hanya berlaku bila status <strong>Published</strong>. Kartu di homepage menaut ke halaman opt-in <code class="px-1 rounded bg-gray-100 dark:bg-gray-800">/lp/{slug}</code>.</p>
          </div>
          <div class="pt-1">
            <label class="flex items-center gap-2.5 cursor-pointer">
              <input type="checkbox" name="affiliate_enabled" id="lm_affiliate" value="1" class="w-4 h-4 rounded border-gray-300 dark:border-gray-600">
              <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Boleh diafiliasikan</span>
            </label>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 ml-6">Muncul di dashboard afiliasi dengan link <code class="px-1 rounded bg-gray-100 dark:bg-gray-800">/lp/{slug}?ref=KODE</code>. Afiliasi dapat komisi dari <strong>pembelian berbayar</strong> (penawaran lanjutan/tripwire) oleh prospek yang dibawa — bukan dari lead magnet gratisnya. Pastikan produk lanjutannya punya komisi afiliasi.</p>
          </div>
          <div class="pt-1">
            <label class="flex items-center gap-2.5 cursor-pointer">
              <input type="checkbox" name="require_otp" id="lm_require_otp" value="1" checked class="w-4 h-4 rounded border-gray-300 dark:border-gray-600">
              <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Wajibkan verifikasi WhatsApp (OTP) sebelum masuk dashboard</span>
            </label>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 ml-6">Bila <strong>dimatikan</strong>, pendaftar baru langsung masuk dashboard tanpa OTP (akun dibuat otomatis). Nomor/email yang sudah terdaftar tetap diarahkan login demi keamanan (cegah ambil-alih akun).</p>
          </div>
        </div>

        <div class="px-5 pb-5">
          <div class="pt-1 border-t border-gray-100 dark:border-gray-800 mt-1">
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5 mt-3">Mode Akses Konten</label>
            <select name="access_type" id="lm_access_type" class="lm-inp" onchange="syncLmAccessType()">
              <option value="course">Kelas / Modul (default)</option>
              <option value="embed">Embed langsung — satu konten HTML/URL, tanpa kelas</option>
            </select>
            <div id="lm_embed_fields" class="hidden space-y-2 mt-2">
              <textarea name="embed_html" id="lm_embed_html" rows="5" class="lm-inp" style="font-family:monospace" placeholder="&lt;iframe ...&gt;&lt;/iframe&gt; atau HTML aplikasi lengkap"></textarea>
              <input type="url" name="embed_url" id="lm_embed_url" class="lm-inp" placeholder="atau URL tool: https://...">
              <p class="text-xs text-gray-400 dark:text-gray-500">Dirender di iframe aman (sandbox). <strong>HTML diutamakan</strong> bila diisi; URL dipakai bila HTML kosong. Prospek yang punya akses langsung mendarat di sini.</p>
            </div>
          </div>
        </div>

        <div class="flex items-center justify-end gap-2 px-5 py-4 border-t border-gray-100 dark:border-gray-800">
          <button type="button" onclick="closeMagnetModal()" class="px-4 py-2.5 rounded-xl text-sm font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition">Batal</button>
          <button type="submit" class="px-5 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90" style="background-color:var(--brand-color)">Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
  .lm-inp { width:100%; padding:0.55rem 0.75rem; border-radius:0.625rem; font-size:0.875rem;
            border:1px solid rgb(229 231 235); background:#fff; color:rgb(17 24 39); }
  .dark .lm-inp { border-color:rgb(55 65 81); background:rgb(17 24 39); color:#fff; }
  .lm-inp:focus { outline:none; border-color:var(--brand-color); }
</style>

<script>
// Tampilkan modal apa adanya — dipakai openMagnetModal() & editMagnet() setelah
// form disiapkan masing-masing.
function showMagnetModal() { document.getElementById('magnetModal').classList.remove('hidden'); }

// "Tambah Lead Magnet": form WAJIB direset dulu. Tanpa ini, lm_id sisa dari Edit
// sebelumnya masih terisi → Simpan menimpa magnet yang tadi diedit, bukan bikin baru.
function openMagnetModal() {
  resetMagnetForm();
  showMagnetModal();
}

function resetMagnetForm() {
  document.getElementById('magnetForm').reset();   // termasuk input file thumbnail
  document.getElementById('lm_modal_title').textContent = 'Tambah Lead Magnet';
  document.getElementById('lm_id').value = '0';
  var slug = document.getElementById('lm_slug');
  slug.value = '';
  delete slug.dataset.touched;                     // biar autoSlug hidup lagi
  document.getElementById('lm_thumb_cur').classList.add('hidden');
  syncLmAccessType();
}

function syncLmAccessType() {
  var v = document.getElementById('lm_access_type').value;
  document.getElementById('lm_embed_fields').classList.toggle('hidden', v !== 'embed');
}

function closeMagnetModal() { document.getElementById('magnetModal').classList.add('hidden'); }

function autoSlug() {
  var slug = document.getElementById('lm_slug');
  if (slug.dataset.touched === '1') return;
  var t = document.getElementById('lm_title').value.toLowerCase()
            .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  slug.value = t;
}
document.getElementById('lm_slug').addEventListener('input', function(){ this.dataset.touched = '1'; });

function editMagnet(m) {
  resetMagnetForm();   // buang sisa isian/file thumbnail dari pembukaan sebelumnya
  document.getElementById('lm_modal_title').textContent = 'Edit Lead Magnet';
  document.getElementById('lm_id').value = m.id || 0;
  document.getElementById('lm_title').value = m.title || '';
  var slug = document.getElementById('lm_slug');
  slug.value = m.slug || ''; slug.dataset.touched = '1';
  document.getElementById('lm_badge').value = m.badge_text || '';
  document.getElementById('lm_cta').value = m.cta_text || '';
  document.getElementById('lm_headline').value = m.headline || '';
  document.getElementById('lm_subheadline').value = m.subheadline || '';
  document.getElementById('lm_level').value = m.bonus_membership_level_id || 0;
  document.getElementById('lm_product').value = m.linked_product_id || 0;
  document.getElementById('lm_tripwire').value = m.tripwire_product_id || 0;
  document.getElementById('lm_tripwire_headline').value = m.tripwire_headline || '';
  document.getElementById('lm_require_otp').checked = (m.require_otp === undefined || parseInt(m.require_otp) === 1);
  document.getElementById('lm_status').value = m.status || 'draft';
  document.getElementById('lm_pixel').value = m.fb_pixel_ids || '';
  document.getElementById('lm_thankyou').value = m.thankyou_message || '';
  document.getElementById('lm_access_type').value = m.access_type || 'course';
  document.getElementById('lm_embed_html').value = m.embed_html || '';
  document.getElementById('lm_embed_url').value = m.embed_url || '';
  syncLmAccessType();
  document.getElementById('lm_show_home').checked = !!parseInt(m.show_on_homepage || 0);
  document.getElementById('lm_affiliate').checked = !!parseInt(m.affiliate_enabled || 0);
  var cur = document.getElementById('lm_thumb_cur');
  if (m.thumbnail) { cur.textContent = 'Thumbnail saat ini tersimpan. Upload baru untuk mengganti.'; cur.classList.remove('hidden'); }
  else { cur.classList.add('hidden'); }
  showMagnetModal();   // BUKAN openMagnetModal() — itu akan mereset isian yang baru diisi
}
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

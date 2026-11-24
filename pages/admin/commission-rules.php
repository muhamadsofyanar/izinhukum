<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Commission Rules';
$topbarTitle = 'Aturan Komisi';

$pdo = getDB();

// ─── Load data ────────────────────────────────────────────────
$rules   = [];
$products = [];
$levels   = [];

try {
    $rules = $pdo->query(
        "SELECT cr.*,
                p.name  AS product_name,
                rp.name AS required_product_name,
                ml.name AS level_name, ml.color AS level_color
         FROM commission_rules cr
         LEFT JOIN products p  ON cr.product_id = p.id
         LEFT JOIN products rp ON cr.required_product_id = rp.id
         LEFT JOIN membership_levels ml ON cr.membership_level_id = ml.id
         ORDER BY cr.priority DESC, cr.created_at DESC"
    )->fetchAll();

    $products = $pdo->query(
        "SELECT id, name FROM products WHERE status = 'active' ORDER BY name ASC"
    )->fetchAll();

    $levels = $pdo->query(
        "SELECT id, name, color FROM membership_levels ORDER BY sort_order ASC"
    )->fetchAll();
} catch (Exception $e) {
    error_log('Commission rules load error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data.', 'error');
}

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

      <div class="mb-5 flex items-center justify-between gap-4">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Aturan Komisi</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Rule spesifik per produk / per level membership — menggantikan rate flat</p>
        </div>
        <button type="button" onclick="openRuleModal()"
          class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold text-white"
          style="background:var(--brand-color)">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambah Rule
        </button>
      </div>

      <!-- Info box -->
      <div class="mb-5 p-4 rounded-xl border border-blue-100 dark:border-blue-900/40 bg-blue-50/60 dark:bg-blue-900/10 text-sm text-blue-700 dark:text-blue-400">
        <strong>Prioritas resolusi:</strong> rule ber-<em>syarat produk</em> → rule yang <em>menunjuk produk tertentu</em> (produk+level, lalu produk) → <strong>komisi affiliate di form produk</strong> → rule <em>umum</em> (level, lalu global) → rate default affiliate → setting global.
        Nilai <em>prioritas</em> hanya memutus seri antar-rule dengan tingkat kespesifikan yang sama.
        <br>Catatan: nilai komisi yang diisi <strong>eksplisit di form produk</strong> mengalahkan rule <em>umum</em> per-level (mis. Gold) — yang dijanjikan produk = yang dibayar. Rule yang <em>khusus menunjuk produk itu</em> atau <em>bersyarat kepemilikan</em> tetap menang (mis. pemilik lisensi resell dapat 100% saat menjual lisensi personalnya; akses kedaluwarsa = kembali ke rate normal).
      </div>

      <!-- Table -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($rules)): ?>
        <div class="px-5 py-14 text-center">
          <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
          </svg>
          <p class="text-sm text-gray-400 dark:text-gray-500">Belum ada rule komisi. Tanpa rule, sistem menggunakan rate default affiliate.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800/50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Produk</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Syarat Kepemilikan</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Level Membership</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Rate</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Prioritas</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($rules as $rule): ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">

                <!-- Produk -->
                <td class="px-4 py-3">
                  <?php if ($rule['product_name']): ?>
                    <span class="text-sm font-medium text-gray-900 dark:text-white"><?= e($rule['product_name']) ?></span>
                  <?php else: ?>
                    <span class="text-sm italic text-gray-400 dark:text-gray-500">Semua Produk</span>
                  <?php endif; ?>
                </td>

                <!-- Syarat kepemilikan -->
                <td class="px-4 py-3">
                  <?php if (!empty($rule['required_product_name'])): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400"
                          title="Berlaku hanya bila affiliate memiliki akses aktif ke produk ini">
                      <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                      Punya: <?= e($rule['required_product_name']) ?>
                    </span>
                  <?php else: ?>
                    <span class="text-sm italic text-gray-400 dark:text-gray-500">Tanpa syarat</span>
                  <?php endif; ?>
                </td>

                <!-- Level -->
                <td class="px-4 py-3">
                  <?php if ($rule['level_name']): ?>
                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium text-white"
                          style="background:<?= e($rule['level_color'] ?? '#94a3b8') ?>">
                      <?= e($rule['level_name']) ?>
                    </span>
                  <?php else: ?>
                    <span class="text-sm italic text-gray-400 dark:text-gray-500">Semua Level</span>
                  <?php endif; ?>
                </td>

                <!-- Rate -->
                <td class="px-4 py-3">
                  <span class="text-lg font-bold text-gray-900 dark:text-white"><?= number_format((float)$rule['commission_rate'], 1) ?></span>
                  <span class="text-sm text-gray-500">%</span>
                </td>

                <!-- Prioritas -->
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                  <?= (int) $rule['priority'] ?>
                </td>

                <!-- Status -->
                <td class="px-4 py-3">
                  <?php if ($rule['status'] === 'active'): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400">Aktif</span>
                  <?php else: ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">Nonaktif</span>
                  <?php endif; ?>
                </td>

                <!-- Aksi -->
                <td class="px-4 py-3 text-right">
                  <button type="button"
                    onclick="openRuleModal(<?= htmlspecialchars(json_encode($rule), ENT_QUOTES) ?>)"
                    class="text-xs font-medium text-[var(--brand-color)] hover:underline">Edit</button>

                  <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-commission-rule"
                    class="inline" id="del-rule-<?= (int)$rule['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="rule_id"    value="<?= (int)$rule['id'] ?>">
                    <button type="button"
                      onclick="confirmAndSubmit('del-rule-<?= (int)$rule['id'] ?>', {title:'Hapus Rule?',message:'Rule komisi ini akan dihapus permanen.',variant:'danger'})"
                      class="ml-3 text-xs font-medium text-red-500 hover:underline">Hapus</button>
                  </form>
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

<!-- ─── Modal Rule ─────────────────────────────────────────────── -->
<div id="ruleModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeRuleModal()"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-800 w-full max-w-md flex flex-col" style="max-height:90vh">

      <!-- Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 shrink-0">
        <h3 id="ruleModalTitle" class="text-base font-bold text-gray-900 dark:text-white">Tambah Rule Komisi</h3>
        <button type="button" onclick="closeRuleModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <!-- Form -->
      <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-commission-rule" class="flex flex-col min-h-0">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="rule_id"    id="ruleId" value="0">

        <div class="overflow-y-auto p-6 space-y-4 flex-1">

          <!-- Produk -->
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Produk</label>
            <select name="product_id" id="ruleProductId"
              class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
              <option value="">Semua Produk (default global)</option>
              <?php foreach ($products as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-gray-400 mt-1">Kosongkan untuk rule yang berlaku ke semua produk</p>
          </div>

          <!-- Syarat kepemilikan produk -->
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Syarat: Affiliate Memiliki Produk</label>
            <select name="required_product_id" id="ruleRequiredProductId"
              class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
              <option value="">Tanpa syarat kepemilikan</option>
              <?php foreach ($products as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-gray-400 mt-1">Rule hanya berlaku bila affiliate punya akses aktif ke produk ini — mis. pemilik lisensi resell dapat rate khusus untuk produk personalnya. Akses kedaluwarsa = kembali ke rate normal.</p>
          </div>

          <!-- Level Membership -->
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Level Membership Affiliate</label>
            <select name="membership_level_id" id="ruleLevelId"
              class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
              <option value="">Semua Level (berlaku semua affiliate)</option>
              <?php foreach ($levels as $lv): ?>
              <option value="<?= (int)$lv['id'] ?>"><?= e($lv['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-gray-400 mt-1">Kosongkan untuk rule yang berlaku ke semua level</p>
          </div>

          <!-- Rate + Priority baris horizontal -->
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Rate Komisi (%)</label>
              <div class="relative">
                <input type="number" name="commission_rate" id="ruleRate" required
                  min="0" max="100" step="0.01" placeholder="10.00"
                  class="w-full h-9 pl-3 pr-8 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">%</span>
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Prioritas</label>
              <input type="number" name="priority" id="rulePriority" value="0" min="0"
                class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
              <p class="text-xs text-gray-400 mt-1">Lebih tinggi = lebih diprioritaskan</p>
            </div>
          </div>

          <!-- Status -->
          <div class="flex items-center justify-between py-2 border-t border-gray-100 dark:border-gray-800">
            <div>
              <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Rule Aktif</p>
              <p class="text-xs text-gray-400 dark:text-gray-500">Rule nonaktif tidak dipakai saat resolusi</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" name="status" id="ruleStatus" value="active" class="sr-only peer" checked>
              <div class="w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer peer-checked:bg-[var(--brand-color)] after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5"></div>
            </label>
          </div>

        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-3 shrink-0">
          <button type="button" onclick="closeRuleModal()"
            class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">
            Batal
          </button>
          <button type="submit" data-loading="Menyimpan..."
            class="px-5 py-2 rounded-lg text-sm font-semibold text-white"
            style="background:var(--brand-color)">Simpan Rule</button>
        </div>
      </form>

    </div>
  </div>
</div>

<script>
function openRuleModal(rule) {
  document.getElementById('ruleModalTitle').textContent = rule ? 'Edit Rule Komisi' : 'Tambah Rule Komisi';
  document.getElementById('ruleId').value          = rule ? rule.id                : 0;
  document.getElementById('ruleProductId').value   = rule ? (rule.product_id           || '') : '';
  document.getElementById('ruleRequiredProductId').value = rule ? (rule.required_product_id || '') : '';
  document.getElementById('ruleLevelId').value     = rule ? (rule.membership_level_id  || '') : '';
  document.getElementById('ruleRate').value        = rule ? rule.commission_rate        : '';
  document.getElementById('rulePriority').value    = rule ? rule.priority               : 0;
  document.getElementById('ruleStatus').checked    = rule ? rule.status === 'active'    : true;

  document.getElementById('ruleModal').classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function closeRuleModal() {
  document.getElementById('ruleModal').classList.add('hidden');
  document.body.style.overflow = '';
}

document.querySelector('#ruleModal form').addEventListener('submit', function() {
  var btn = this.querySelector('button[data-loading]');
  btn.disabled = true;
  btn.textContent = btn.dataset.loading;
});
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

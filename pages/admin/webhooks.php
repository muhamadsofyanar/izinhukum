<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('integrations')) { redirect('/admin/no-access'); }

$pageTitle   = 'Integrasi Webhook';
$topbarTitle = 'Integrasi Webhook';

// Pseudo-cron: proses antrean yang due saat halaman dibuka.
$pumpResult = processWebhookQueue(10);

$webhooks = []; $deliveries = []; $mappedCount = 0;
try {
    $pdo = getDB();
    $webhooks = $pdo->query("SELECT * FROM outgoing_webhooks ORDER BY id DESC")->fetchAll();
    $deliveries = $pdo->query(
        "SELECT d.*, w.name AS webhook_name FROM webhook_deliveries d
         LEFT JOIN outgoing_webhooks w ON w.id = d.webhook_id
         ORDER BY d.id DESC LIMIT 30"
    )->fetchAll();
    $mappedCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE external_program_code IS NOT NULL AND external_program_code <> ''")->fetchColumn();
} catch (Exception $e) { error_log('webhooks page load: ' . $e->getMessage()); }

$statusBadge = function (string $s): string {
    $map = [
        'pending' => ['bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', 'Antre'],
        'sent'    => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'Terkirim'],
        'failed'  => ['bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', 'Gagal'],
    ];
    $c = $map[$s] ?? ['bg-gray-100 text-gray-600', $s];
    return '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium ' . $c[0] . '">' . e($c[1]) . '</span>';
};

generateCSRF();
$csrf = $_SESSION['csrf_token'];
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Integrasi Webhook</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Kirim event <span class="font-mono text-xs">order.paid</span> ke aplikasi eksternal (grant akses otomatis) — bertanda-tangan &amp; dengan antrean/retry.</p>
        </div>
        <button onclick="newWebhook()" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">+ Tambah Webhook</button>
      </div>

      <!-- Info pemetaan produk -->
      <div class="mb-5 rounded-xl border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 px-4 py-3 text-sm text-blue-800 dark:text-blue-300">
        Webhook hanya terkirim untuk produk yang punya <strong>Kode Program Eksternal</strong>. Saat ini <strong><?= (int) $mappedCount ?> produk</strong> sudah dipetakan.
        Atur di <a href="<?= e(APP_URL) ?>/admin/products" class="underline font-semibold">Produk → Edit → Kode Program Eksternal</a>.
      </div>

      <!-- Pointer ke panduan integrasi lengkap di Dokumentasi -->
      <a href="<?= e(APP_URL) ?>/admin/docs#integrasi-eksternal" class="group mb-6 flex items-center justify-between gap-3 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm px-5 py-4 hover:border-[var(--brand-color)] transition-colors">
        <div class="flex items-center gap-3 min-w-0">
          <span class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0" style="background:color-mix(in srgb,var(--brand-color) 12%,transparent);color:var(--brand-color)">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
          </span>
          <div class="min-w-0">
            <span class="block text-sm font-semibold text-gray-900 dark:text-white">Panduan Integrasi untuk Developer App Tujuan</span>
            <span class="block text-xs text-gray-400 dark:text-gray-500 mt-0.5">Prompt AI siap-pakai + nilai <span class="font-mono">.env</span> untuk menyambungkan app lain — ada di Dokumentasi.</span>
          </div>
        </div>
        <svg class="w-5 h-5 text-gray-400 shrink-0 group-hover:text-[var(--brand-color)] transition-colors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
      </a>

      <!-- Daftar webhook -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-6">
        <?php if (empty($webhooks)): ?>
        <div class="p-10 text-center text-sm text-gray-400">Belum ada webhook. Tambahkan endpoint tujuan (URL aplikasi eksternal).</div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead>
          <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40">
            <th class="text-left px-5 py-3">Nama</th><th class="text-left px-4 py-3">URL Tujuan</th><th class="text-left px-4 py-3">Secret</th><th class="text-center px-4 py-3">Status</th><th class="text-right px-5 py-3">Aksi</th>
          </tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($webhooks as $w): ?>
          <tr>
            <td class="px-5 py-3 font-medium text-gray-900 dark:text-white"><?= e($w['name']) ?><div class="text-xs text-gray-400 font-mono"><?= e($w['event']) ?></div></td>
            <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300 max-w-[260px] truncate font-mono" title="<?= e($w['url']) ?>"><?= e($w['url']) ?></td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <code class="text-xs font-mono text-gray-500" id="sec-<?= (int)$w['id'] ?>">••••••••<?= e(substr($w['secret'], -4)) ?></code>
                <button type="button" onclick="copySecret('<?= e($w['secret']) ?>', this)" class="text-xs text-[var(--brand-color)] hover:underline">Salin</button>
              </div>
            </td>
            <td class="px-4 py-3 text-center"><span class="text-xs px-2 py-0.5 rounded-full <?= $w['is_active'] ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800' ?>"><?= $w['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
            <td class="px-5 py-3 text-right whitespace-nowrap">
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/test-webhook" class="inline"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">Tes Kirim</button></form>
              <button type="button" onclick='editWebhook(<?= json_encode(["id"=>(int)$w["id"],"name"=>$w["name"],"url"=>$w["url"],"event"=>$w["event"],"is_active"=>(int)$w["is_active"]], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">Edit</button>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-webhook" class="inline"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                <button type="button" onclick="confirmAndRun({title:'Hapus Webhook?',message:'Endpoint &amp; semua log kirimannya akan dihapus.',confirmText:'Ya, Hapus',variant:'danger'}, () => this.closest('form').submit())" class="inline-flex items-center justify-center w-8 h-8 align-middle rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                </button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>

      <!-- Log kiriman -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
          <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Log Kiriman Terakhir</h3>
          <span class="text-xs text-gray-400">Auto-proses: <?= (int)($pumpResult['sent'] ?? 0) ?> terkirim<?= ($pumpResult['retry']??0) ? ', '.(int)$pumpResult['retry'].' antre-ulang' : '' ?></span>
        </div>
        <?php if (empty($deliveries)): ?>
        <div class="p-8 text-center text-sm text-gray-400">Belum ada kiriman. Log muncul setelah ada transaksi lunas pada produk yang dipetakan (atau klik "Tes Kirim").</div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead>
          <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40">
            <th class="text-left px-5 py-2.5">Waktu</th><th class="text-left px-4 py-2.5">Webhook</th><th class="text-left px-4 py-2.5">Order</th><th class="text-center px-4 py-2.5">Status</th><th class="text-center px-4 py-2.5">HTTP</th><th class="text-left px-4 py-2.5">Respon</th><th class="text-right px-5 py-2.5">Aksi</th>
          </tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($deliveries as $d): ?>
          <tr>
            <td class="px-5 py-2.5 text-xs text-gray-500 whitespace-nowrap"><?= e(date('d M H:i', strtotime($d['created_at']))) ?></td>
            <td class="px-4 py-2.5 text-xs text-gray-700 dark:text-gray-300"><?= e($d['webhook_name'] ?? '—') ?></td>
            <td class="px-4 py-2.5 text-xs font-mono text-gray-500"><?= e($d['order_number'] ?? '—') ?></td>
            <td class="px-4 py-2.5 text-center"><?= $statusBadge($d['status']) ?><?= (int)$d['attempts'] > 1 ? '<div class="text-[10px] text-gray-400 mt-0.5">'.(int)$d['attempts'].'x</div>' : '' ?></td>
            <td class="px-4 py-2.5 text-center text-xs text-gray-500"><?= $d['http_code'] !== null ? (int)$d['http_code'] : '—' ?></td>
            <td class="px-4 py-2.5 text-xs text-gray-500 max-w-[220px] truncate" title="<?= e((string)$d['response']) ?>"><?= e(mb_substr((string)$d['response'], 0, 100)) ?></td>
            <td class="px-5 py-2.5 text-right">
              <?php if ($d['status'] === 'failed'): ?>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/retry-webhook" class="inline"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button type="submit" class="px-2.5 py-1 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">Kirim Ulang</button></form>
              <?php else: ?><span class="text-xs text-gray-300 dark:text-gray-700">—</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<!-- Modal webhook -->
<div id="wh-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
  <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-md">
    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-webhook">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" id="wh-id">
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
        <h3 class="font-semibold text-gray-900 dark:text-white" id="wh-title">Tambah Webhook</h3>
        <button type="button" onclick="document.getElementById('wh-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="p-5 space-y-3">
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Nama</label><input type="text" name="name" id="wh-name" required placeholder="Contoh: BeautyCode Grant Akses" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">URL Tujuan</label><input type="url" name="url" id="wh-url" required placeholder="https://app-tujuan.com/api/averion/grant-access" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono"></div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Event</label>
          <select name="event" id="wh-event" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"><option value="order.paid">order.paid (pembelian lunas)</option></select></div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Status</label>
          <select name="is_active" id="wh-active" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"><option value="1">Aktif</option><option value="0">Nonaktif</option></select></div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Secret <span class="normal-case text-gray-400 font-normal">(opsional — isi manual)</span></label>
          <input type="text" name="secret" id="wh-secret" placeholder="Kosongkan = dibuat otomatis" autocomplete="off" spellcheck="false" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono">
          <p class="text-[11px] text-gray-400 mt-1">Isi bila mau menyamakan dengan secret yang sudah dipegang aplikasi tujuan (mis. diberi dev). Kosong = Averion buat otomatis. Min. 16 karakter, tanpa spasi.</p></div>
        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300" id="wh-regen-wrap"><input type="checkbox" name="regenerate_secret" value="1" style="accent-color:var(--brand-color)"> Generate ulang secret acak (abaikan bila field Secret di atas diisi)</label>
        <p class="text-[11px] text-gray-400">Salin secret dari daftar untuk dipasang di aplikasi tujuan (dipakai memverifikasi tanda tangan HMAC). Kedua sisi WAJIB memakai secret yang sama.</p>
      </div>
      <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2"><button type="button" onclick="document.getElementById('wh-modal').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700">Batal</button><button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Simpan</button></div>
    </form>
  </div>
</div>

<script>
function newWebhook(){
  document.getElementById('wh-title').textContent='Tambah Webhook';
  document.getElementById('wh-id').value='';
  document.getElementById('wh-name').value=''; document.getElementById('wh-url').value='';
  document.getElementById('wh-event').value='order.paid'; document.getElementById('wh-active').value='1';
  document.getElementById('wh-secret').value='';
  document.getElementById('wh-regen-wrap').classList.add('hidden');
  document.getElementById('wh-modal').classList.remove('hidden');
}
function editWebhook(w){
  document.getElementById('wh-title').textContent='Edit Webhook';
  document.getElementById('wh-id').value=w.id;
  document.getElementById('wh-name').value=w.name; document.getElementById('wh-url').value=w.url;
  document.getElementById('wh-event').value=w.event; document.getElementById('wh-active').value=String(w.is_active);
  document.getElementById('wh-secret').value=''; // kosong = biarkan secret lama; isi = ganti manual
  document.getElementById('wh-regen-wrap').classList.remove('hidden');
  document.getElementById('wh-modal').classList.remove('hidden');
}
function copySecret(s, btn){
  var done=function(){ var o=btn.textContent; btn.textContent='Tersalin!'; setTimeout(function(){btn.textContent=o;},1500); };
  if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(s).then(done).catch(done); } else { done(); }
}
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

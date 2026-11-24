<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('email_sequences')) { redirect('/admin/no-access'); }

$pageTitle   = 'Email Sequence';
$topbarTitle = 'Email Sequence';

// Pseudo-cron: proses yang sudah due saat halaman dibuka
$processResult = processDueSequenceSends(20);

$sequences = []; $products = []; $pendingCount = 0; $seqProducts = [];
try {
    $pdo = getDB();
    $sequences = $pdo->query(
        "SELECT es.*,
                (SELECT COUNT(*) FROM email_sequence_steps WHERE sequence_id = es.id AND status='active') AS step_count,
                (SELECT COUNT(*) FROM email_sequence_enrollments WHERE sequence_id = es.id AND status='active') AS active_enrollments
         FROM email_sequences es
         ORDER BY es.created_at DESC"
    )->fetchAll();
    $products = $pdo->query("SELECT id, name FROM products WHERE status='active' ORDER BY name ASC")->fetchAll();
    $pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM email_sequence_sends WHERE status='pending' AND scheduled_at <= NOW()")->fetchColumn();
    // Target (include) & kecualikan (exclude) produk per sequence.
    foreach ($pdo->query("SELECT sequence_id, product_id, kind FROM email_sequence_products")->fetchAll() as $r) {
        $seqProducts[(int) $r['sequence_id']][$r['kind']][] = (int) $r['product_id'];
    }
    // Statistik kiriman per sequence (sent/failed/pending/skipped) — dari data existing.
    $seqStats = [];
    foreach ($pdo->query(
        "SELECT e.sequence_id AS sid, s.status AS st, COUNT(*) AS c
         FROM email_sequence_sends s
         JOIN email_sequence_enrollments e ON e.id = s.enrollment_id
         GROUP BY e.sequence_id, s.status"
    )->fetchAll() as $r) {
        $seqStats[(int) $r['sid']][$r['st']] = (int) $r['c'];
    }
    // Log kiriman terbaru (semua sequence) untuk panel bawah.
    $recentSends = $pdo->query(
        "SELECT s.id, s.status, s.scheduled_at, s.sent_at, s.response,
                u.email, u.name AS user_name, esq.name AS sequence_name,
                estp.step_number, estp.subject
         FROM email_sequence_sends s
         JOIN email_sequence_enrollments e ON e.id = s.enrollment_id
         JOIN email_sequences esq ON esq.id = e.sequence_id
         JOIN email_sequence_steps estp ON estp.id = s.step_id
         JOIN users u ON u.id = e.user_id
         ORDER BY COALESCE(s.sent_at, s.scheduled_at) DESC, s.id DESC
         LIMIT 30"
    )->fetchAll();
} catch (Exception $e) { error_log('email-sequences load error: ' . $e->getMessage()); }
$seqStats = $seqStats ?? [];
$recentSends = $recentSends ?? [];

// Nama produk (id→nama) + ringkasan target utk kolom tabel.
$productNames = [];
foreach ($products as $p) { $productNames[(int) $p['id']] = $p['name']; }
$targetSummary = function (int $seqId) use ($seqProducts, $productNames): string {
    $inc = $seqProducts[$seqId]['include'] ?? [];
    $exc = $seqProducts[$seqId]['exclude'] ?? [];
    if (!$inc)                 $t = 'Semua Produk';
    elseif (count($inc) === 1) $t = $productNames[$inc[0]] ?? ('#' . $inc[0]);
    else                       $t = count($inc) . ' produk';
    if ($exc) $t .= ' · kecuali ' . count($exc);
    return $t;
};

$triggerLabels = [
    'order_paid'              => 'Setelah Pembelian Berhasil',
    'member_access_activated' => 'Setelah Akses Diaktifkan',
    'user_register'           => 'Setelah User Registrasi',
    'waitlist_joined'         => 'Setelah Daftar Waitlist',
    'booking_paid'            => 'Setelah Booking Fee (DP) Dibayar',
    'lead_captured'           => 'Setelah Lead Masuk (Lead Magnet)',
];

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

      <?php if ($processResult['processed'] > 0): ?>
      <div class="mb-4 rounded-xl border border-green-200 dark:border-green-800/40 bg-green-50 dark:bg-green-900/10 px-4 py-3 text-sm text-green-700 dark:text-green-400">
        Auto-processed: <?= (int)$processResult['sent'] ?> email terkirim, <?= (int)$processResult['failed'] ?> gagal.
      </div>
      <?php endif; ?>

      <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
        <div><h2 class="text-xl font-bold text-gray-900 dark:text-white">Email Sequence</h2><p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Email terjadwal otomatis (drip) berdasarkan trigger</p></div>
        <div class="flex items-center gap-2">
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/process-sequences" class="inline">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <button type="submit" class="px-3 py-2 rounded-lg text-sm font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">Proses Antrian<?= $pendingCount > 0 ? ' ('.$pendingCount.')' : '' ?></button>
          </form>
          <button onclick="newSeq()" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">+ Buat Sequence</button>
        </div>
      </div>

      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($sequences)): ?>
        <div class="p-10 text-center text-sm text-gray-400">Belum ada sequence. Buat sequence pertama lalu tambahkan step (email D+0, D+1, dst).</div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40">
          <th class="text-left px-5 py-3">Nama</th><th class="text-left px-4 py-3">Trigger</th><th class="text-left px-4 py-3">Produk</th><th class="text-center px-4 py-3">Step</th><th class="text-center px-4 py-3">Enroll Aktif</th><th class="text-left px-4 py-3">Email (terkirim / gagal / antre)</th><th class="text-center px-4 py-3">Status</th><th class="text-right px-5 py-3">Aksi</th>
        </tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($sequences as $s): ?>
          <tr>
            <td class="px-5 py-3 font-medium text-gray-900 dark:text-white"><?= e($s['name']) ?></td>
            <td class="px-4 py-3 text-gray-600 dark:text-gray-300 text-xs"><?= e($triggerLabels[$s['trigger_event']] ?? $s['trigger_event']) ?></td>
            <td class="px-4 py-3 text-gray-500 text-xs"><?= e($targetSummary((int)$s['id'])) ?></td>
            <td class="px-4 py-3 text-center"><?= (int)$s['step_count'] ?></td>
            <td class="px-4 py-3 text-center"><?= (int)$s['active_enrollments'] ?></td>
            <?php $st = $seqStats[(int)$s['id']] ?? []; $cSent=(int)($st['sent']??0); $cFail=(int)($st['failed']??0); $cPend=(int)($st['pending']??0); $cSkip=(int)($st['skipped']??0); ?>
            <td class="px-4 py-3 text-xs whitespace-nowrap">
              <?php if ($cSent+$cFail+$cPend+$cSkip === 0): ?>
              <span class="text-gray-400 dark:text-gray-600">—</span>
              <?php else: ?>
              <span class="inline-flex items-center gap-1.5">
                <span class="inline-flex items-center gap-1 text-green-600 dark:text-green-400 font-semibold" title="Terkirim"><span class="w-1.5 h-1.5 rounded-full bg-green-500"></span><?= $cSent ?></span>
                <span class="inline-flex items-center gap-1 <?= $cFail>0 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-400 dark:text-gray-600' ?>" title="Gagal"><span class="w-1.5 h-1.5 rounded-full <?= $cFail>0?'bg-red-500':'bg-gray-300 dark:bg-gray-700' ?>"></span><?= $cFail ?></span>
                <span class="inline-flex items-center gap-1 <?= $cPend>0 ? 'text-gray-600 dark:text-gray-300' : 'text-gray-400 dark:text-gray-600' ?>" title="Antre (terjadwal)"><span class="w-1.5 h-1.5 rounded-full <?= $cPend>0?'bg-gray-400':'bg-gray-300 dark:bg-gray-700' ?>"></span><?= $cPend ?></span>
                <?php if ($cSkip>0): ?><span class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400" title="Dilewati"><span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span><?= $cSkip ?></span><?php endif; ?>
              </span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-center"><span class="text-xs px-2 py-0.5 rounded-full <?= $s['status']==='active' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800' ?>"><?= $s['status']==='active'?'Aktif':'Nonaktif' ?></span></td>
            <td class="px-5 py-3 text-right">
              <a href="<?= e(APP_URL) ?>/admin/email-sequence-steps?id=<?= (int)$s['id'] ?>" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">Steps</a>
              <button type="button" onclick="editSeq(<?= (int)$s['id'] ?>)" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">Edit</button>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/toggle-email-sequence" class="inline"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200"><?= $s['status']==='active'?'Nonaktifkan':'Aktifkan' ?></button></form>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-email-sequence" class="inline">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button type="button" title="Hapus sequence"
                        onclick="confirmAndRun({title:'Hapus Sequence?',message:'Sequence &quot;<?= e(addslashes($s['name'])) ?>&quot; beserta semua step, enrollment, dan antrian emailnya akan dihapus permanen.',confirmText:'Ya, Hapus',variant:'danger'}, () => this.closest('form').submit())"
                        class="inline-flex items-center justify-center w-8 h-8 align-middle rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path stroke-linecap="round" stroke-linejoin="round" d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path stroke-linecap="round" stroke-linejoin="round" d="M10 11v6M14 11v6"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 6V4h6v2"/></svg>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>

      <!-- Log Kiriman Terakhir -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mt-6">
        <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
          <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Log Kiriman Terakhir</h3>
          <span class="text-xs text-gray-400">30 kiriman terbaru</span>
        </div>
        <?php if (empty($recentSends)): ?>
        <div class="p-8 text-center text-sm text-gray-400">Belum ada kiriman. Log muncul setelah ada peserta yang masuk sequence &amp; email terjadwal diproses.</div>
        <?php else:
          $sendBadge = function (string $s): string {
            $map = [
              'sent'    => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'Terkirim'],
              'failed'  => ['bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', 'Gagal'],
              'pending' => ['bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', 'Antre'],
              'skipped' => ['bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400', 'Dilewati'],
            ];
            $c = $map[$s] ?? ['bg-gray-100 text-gray-600', $s];
            return '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium ' . $c[0] . '">' . e($c[1]) . '</span>';
          };
        ?>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead>
          <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40">
            <th class="text-left px-5 py-2.5">Waktu</th><th class="text-left px-4 py-2.5">Penerima</th><th class="text-left px-4 py-2.5">Sequence</th><th class="text-left px-4 py-2.5">Step</th><th class="text-center px-4 py-2.5">Status</th><th class="text-left px-4 py-2.5">Keterangan</th><th class="text-right px-5 py-2.5">Aksi</th>
          </tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($recentSends as $d):
            $when = $d['sent_at'] ?: $d['scheduled_at'];
          ?>
          <tr>
            <td class="px-5 py-2.5 text-xs text-gray-500 whitespace-nowrap"><?= $when ? e(date('d M H:i', strtotime($when))) : '—' ?></td>
            <td class="px-4 py-2.5 text-xs text-gray-700 dark:text-gray-300 max-w-[200px] truncate" title="<?= e($d['email']) ?>"><?= e($d['email']) ?></td>
            <td class="px-4 py-2.5 text-xs text-gray-500 max-w-[160px] truncate" title="<?= e($d['sequence_name']) ?>"><?= e($d['sequence_name']) ?></td>
            <td class="px-4 py-2.5 text-xs text-gray-500 max-w-[200px] truncate" title="<?= e($d['subject']) ?>">#<?= (int)$d['step_number'] ?> · <?= e($d['subject']) ?></td>
            <td class="px-4 py-2.5 text-center"><?= $sendBadge($d['status']) ?></td>
            <td class="px-4 py-2.5 text-xs text-gray-500 max-w-[220px] truncate" title="<?= e((string)$d['response']) ?>"><?= e(mb_substr((string)$d['response'], 0, 100)) ?: '<span class="text-gray-300 dark:text-gray-700">—</span>' ?></td>
            <td class="px-5 py-2.5 text-right">
              <?php if (in_array($d['status'], ['failed', 'skipped'], true)): ?>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/retry-sequence-send" class="inline">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button type="submit" class="px-2.5 py-1 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:border-[var(--brand-color)]">Kirim Ulang</button>
              </form>
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

<div id="modal-seq" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
  <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-lg overflow-y-auto" style="max-height:92vh">
    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-email-sequence">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" id="seq-id">
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
        <h3 class="font-semibold text-gray-900 dark:text-white" id="seq-modal-title">Buat Sequence</h3>
        <button type="button" onclick="document.getElementById('modal-seq').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="p-5 space-y-3">
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Nama Sequence</label><input type="text" name="name" id="seq-name" required class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Trigger</label>
          <select name="trigger_event" id="seq-trigger" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
            <?php foreach ($triggerLabels as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
          </select></div>
        <div>
          <label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Produk Target</label>
          <div class="flex gap-4 mb-2 text-sm text-gray-700 dark:text-gray-300">
            <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="target_mode" value="all" id="seq-tm-all" checked onchange="seqToggleTargetMode()" style="accent-color:var(--brand-color)"> Semua Produk</label>
            <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="target_mode" value="specific" id="seq-tm-spec" onchange="seqToggleTargetMode()" style="accent-color:var(--brand-color)"> Produk Tertentu</label>
          </div>
          <div id="seq-target-list" class="hidden max-h-36 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg p-2 space-y-1 bg-gray-50 dark:bg-gray-800/40">
            <?php if (empty($products)): ?><p class="text-xs text-gray-400 px-1">Belum ada produk aktif.</p><?php endif; ?>
            <?php foreach ($products as $p): ?>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200 cursor-pointer px-1 py-0.5"><input type="checkbox" name="target_products[]" value="<?= (int)$p['id'] ?>" class="seq-target-cb" style="accent-color:var(--brand-color)"> <?= e($p['name']) ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Kecualikan Produk (opsional)</label>
          <div class="max-h-36 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg p-2 space-y-1 bg-gray-50 dark:bg-gray-800/40">
            <?php if (empty($products)): ?><p class="text-xs text-gray-400 px-1">Belum ada produk aktif.</p><?php endif; ?>
            <?php foreach ($products as $p): ?>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200 cursor-pointer px-1 py-0.5"><input type="checkbox" name="exclude_products[]" value="<?= (int)$p['id'] ?>" class="seq-exclude-cb" style="accent-color:var(--brand-color)"> <?= e($p['name']) ?></label>
            <?php endforeach; ?>
          </div>
          <p class="text-[11px] text-gray-400 mt-1">Produk yang dilewati meski cocok trigger — berguna saat target "Semua Produk".</p>
        </div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Status</label>
          <select name="status" id="seq-status" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"><option value="inactive">Nonaktif</option><option value="active">Aktif</option></select></div>
      </div>
      <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2"><button type="button" onclick="document.getElementById('modal-seq').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700">Batal</button><button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Simpan</button></div>
    </form>
  </div>
</div>
<script>
window.__SEQ_DATA = <?= json_encode($sequences, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.__SEQ_PRODUCTS = <?= json_encode($seqProducts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function seqToggleTargetMode(){
  var spec=document.getElementById('seq-tm-spec').checked;
  document.getElementById('seq-target-list').classList.toggle('hidden', !spec);
}
function seqClearChecks(cls){ document.querySelectorAll('.'+cls).forEach(function(c){c.checked=false;}); }
function seqSetChecks(cls, ids){ seqClearChecks(cls); (ids||[]).forEach(function(id){ var el=document.querySelector('.'+cls+'[value="'+id+'"]'); if(el) el.checked=true; }); }

function newSeq(){
  document.getElementById('seq-modal-title').textContent='Buat Sequence';
  document.getElementById('seq-id').value='';
  document.getElementById('seq-name').value='';
  document.getElementById('seq-trigger').selectedIndex=0;
  document.getElementById('seq-status').value='inactive';
  document.getElementById('seq-tm-all').checked=true;
  seqClearChecks('seq-target-cb'); seqClearChecks('seq-exclude-cb');
  seqToggleTargetMode();
  document.getElementById('modal-seq').classList.remove('hidden');
}
function editSeq(id){
  var s=(window.__SEQ_DATA||[]).find(function(x){return String(x.id)===String(id);});
  if(!s) return;
  document.getElementById('seq-modal-title').textContent='Edit Sequence';
  document.getElementById('seq-id').value=s.id;
  document.getElementById('seq-name').value=s.name;
  document.getElementById('seq-trigger').value=s.trigger_event;
  document.getElementById('seq-status').value=s.status;
  var pj=(window.__SEQ_PRODUCTS||{})[s.id]||{};
  var inc=pj.include||[], exc=pj.exclude||[];
  document.getElementById(inc.length ? 'seq-tm-spec' : 'seq-tm-all').checked=true;
  seqSetChecks('seq-target-cb', inc);
  seqSetChecks('seq-exclude-cb', exc);
  seqToggleTargetMode();
  document.getElementById('modal-seq').classList.remove('hidden');
}
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

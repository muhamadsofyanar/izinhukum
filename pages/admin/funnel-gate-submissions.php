<?php
// ════════════════════════════════════════════════════════════════
// Funnel Gate — Submissions per form. Filter status/skor + detail + ubah status.
// Route: /admin/funnel-gate/submissions?form=<id>
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pdo    = getDB();
$formId = (int) ($_GET['form'] ?? 0);
if ($formId <= 0) { redirect('/admin/funnel-gate'); }

$form = null;
try {
    $st = $pdo->prepare('SELECT * FROM funnel_gate_forms WHERE id = ? LIMIT 1');
    $st->execute([$formId]);
    $form = $st->fetch();
} catch (Exception $e) { error_log('FG subs load form: ' . $e->getMessage()); }
if (!$form) { flash('error', 'Form tidak ditemukan.', 'error'); redirect('/admin/funnel-gate'); }

// Peta field pertanyaan (untuk render jawaban).
$fieldMap = [];
try {
    $fs = $pdo->prepare("SELECT * FROM funnel_gate_fields WHERE form_id = ? AND field_role = 'none' ORDER BY sort_order");
    $fs->execute([$formId]);
    foreach ($fs as $f) $fieldMap[$f['field_key']] = $f;
} catch (Exception $e) { error_log('FG subs fields: ' . $e->getMessage()); }

// Filter.
$statusFilter = $_GET['status'] ?? 'all';
$validStatus  = ['all', 'submitted', 'approved', 'rejected', 'pending', 'draft'];
if (!in_array($statusFilter, $validStatus, true)) $statusFilter = 'all';
$q = trim($_GET['q'] ?? '');

$where  = 'form_id = ?';
$params = [$formId];
if ($statusFilter === 'all') { $where .= " AND status <> 'draft'"; }
else { $where .= ' AND status = ?'; $params[] = $statusFilter; }
if ($q !== '') {
    $where .= ' AND (name LIKE ? OR whatsapp LIKE ? OR email LIKE ?)';
    $like = '%' . $q . '%'; array_push($params, $like, $like, $like);
}

$subs = [];
try {
    $sq = $pdo->prepare("SELECT * FROM funnel_gate_submissions WHERE {$where} ORDER BY COALESCE(submitted_at, created_at) DESC LIMIT 300");
    $sq->execute($params);
    $subs = $sq->fetchAll();
} catch (Exception $e) { error_log('FG subs list: ' . $e->getMessage()); }

// Statistik (non-draft).
$stats = ['submitted' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0, 'draft' => 0, 'avg' => 0, 'total' => 0];
try {
    $sr = $pdo->prepare("SELECT status, COUNT(*) c, AVG(score) a FROM funnel_gate_submissions WHERE form_id = ? GROUP BY status");
    $sr->execute([$formId]);
    $sumScore = 0; $sumCnt = 0;
    foreach ($sr as $r) {
        $stats[$r['status']] = (int) $r['c'];
        if ($r['status'] !== 'draft') { $sumScore += (float) $r['a'] * (int) $r['c']; $sumCnt += (int) $r['c']; }
    }
    $stats['total'] = $sumCnt;
    $stats['avg']   = $sumCnt > 0 ? round($sumScore / $sumCnt) : 0;
} catch (Exception $e) { error_log('FG subs stats: ' . $e->getMessage()); }

$csrf = generateCSRF();
$base = rtrim(APP_URL, '/');
$pageTitle   = 'Submissions — ' . $form['title'];
$topbarTitle = 'Funnel Gate';

// Provider aktif (untuk modal Broadcast) — channel tanpa provider dinonaktifkan.
$bcProviders = ['email' => [], 'whatsapp' => []];
if (function_exists('broadcastActiveProviders')) { $bcProviders = broadcastActiveProviders($pdo); }
$bcHasEmail = !empty($bcProviders['email']);
$bcHasWa    = !empty($bcProviders['whatsapp']);

// Ketersediaan WhatsApp untuk kirim 1-on-1 — SUMBER SAMA dengan action pengirim
// (resolveActiveWaProvider), bukan broadcastActiveProviders. Penting: Averion Relay
// (averionrelay) dihitung di sini tapi TIDAK di broadcastActiveProviders, jadi tanpa
// ini tombol WA salah bilang "tak ada provider" padahal Relay aktif.
require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
$waProvider  = NotificationDispatcher::resolveActiveWaProvider();
$waAvailable = ($waProvider !== '');

function fgs_badge(string $s): array {
    return match ($s) {
        'approved'  => ['Approved', 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'],
        'rejected'  => ['Rejected', 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300'],
        'pending'   => ['Pending', 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
        'draft'     => ['Draft', 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'],
        default     => ['Submitted', 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'],
    };
}

// Render nilai jawaban jadi teks manusiawi (resolve label opsi).
function fgs_answer_text(array $field, $val): string {
    $type = $field['field_type'];
    if ($type === 'select' || $type === 'radio') {
        $sv = is_array($val) ? (string) reset($val) : (string) $val;
        $opts = json_decode((string) $field['options'], true) ?: [];
        foreach ($opts as $o) if ((string) ($o['value'] ?? '') === $sv) return (string) $o['label'];
        return $sv; // opsi mungkin sudah dihapus admin → tampilkan nilai mentah tersimpan
    }
    if ($type === 'checkbox') {
        $opts = json_decode((string) $field['options'], true) ?: [];
        $chosen = is_array($val) ? array_map('strval', $val) : [(string) $val];
        $labels = [];
        foreach ($opts as $o) if (in_array((string) ($o['value'] ?? ''), $chosen, true)) $labels[] = (string) $o['label'];
        return $labels ? implode(', ', $labels) : '';
    }
    if ($type === 'rating') {
        $cfg = json_decode((string) $field['options'], true) ?: [];
        $max = (int) ($cfg['max'] ?? 5);
        return ((string) $val) . ' / ' . $max;
    }
    return is_array($val) ? implode(', ', array_map('strval', $val)) : (string) $val;
}

// Bangun peta detail untuk modal (JS).
$subDetail = [];
foreach ($subs as $s) {
    $ans = $s['answers'] ? json_decode($s['answers'], true) : [];
    $rows = [];
    foreach ($fieldMap as $key => $field) {
        if (!array_key_exists($key, (array) $ans)) continue;
        $rows[] = ['label' => $field['label'], 'value' => fgs_answer_text($field, $ans[$key])];
    }
    $subDetail[(int) $s['id']] = [
        'name'    => (string) $s['name'],
        'wa'      => (string) ($s['whatsapp'] ?? ''),
        'email'   => (string) ($s['email'] ?? ''),
        'score'   => (int) $s['score'],
        'status'  => (string) $s['status'],
        'notes'   => (string) ($s['internal_notes'] ?? ''),
        'date'    => (string) ($s['submitted_at'] ?: $s['created_at']),
        'rows'    => $rows,
    ];
}
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<style>
  @media (min-width: 768px) { .fgb-grid5 { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
</style>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <a href="<?= e($base) ?>/admin/funnel-gate" class="inline-flex items-center gap-1 text-sm text-gray-400 hover:text-brand">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Funnel Gate
      </a>
      <div class="flex flex-wrap items-center justify-between gap-3 mt-2 mb-5">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Submissions — <?= e($form['title']) ?></h2>
        <div class="flex items-center gap-3">
          <button type="button" onclick="fgbOpen()"
                  class="inline-flex items-center gap-1 px-4 h-9 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
            Broadcast ke peserta
          </button>
          <a href="<?= e($base) ?>/admin/funnel-gate/edit?id=<?= $formId ?>" class="text-sm font-medium text-brand hover:underline">Edit Form</a>
        </div>
      </div>

      <!-- Stat cards -->
      <div class="grid grid-cols-2 gap-3 mb-5 fgb-grid5">
        <?php
        $cards = [
          ['Total', $stats['total'], 'text-gray-900 dark:text-white'],
          ['Approved', $stats['approved'], 'text-green-600 dark:text-green-400'],
          ['Pending', $stats['pending'], 'text-amber-600 dark:text-amber-400'],
          ['Rejected', $stats['rejected'], 'text-red-600 dark:text-red-400'],
          ['Rata Skor', $stats['avg'], 'text-brand'],
        ];
        foreach ($cards as [$lbl, $val, $cls]): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4">
          <p class="text-xs font-medium text-gray-500 uppercase tracking-wide"><?= $lbl ?></p>
          <p class="text-2xl font-bold mt-1 <?= $cls ?>"><?= (int) $val ?></p>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Filter -->
      <form method="GET" action="<?= e($base) ?>/admin/funnel-gate/submissions" class="flex flex-wrap items-center gap-2 mb-4">
        <input type="hidden" name="form" value="<?= $formId ?>">
        <div class="flex items-center gap-1.5 bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 p-1">
          <?php foreach (['all' => 'Semua', 'submitted' => 'Submitted', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'draft' => 'Draft'] as $k => $v): ?>
          <a href="<?= e($base) ?>/admin/funnel-gate/submissions?form=<?= $formId ?>&status=<?= $k ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
             class="px-3 py-1.5 rounded-md text-xs font-medium transition <?= $statusFilter === $k ? 'bg-brand text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800' ?>"><?= $v ?></a>
          <?php endforeach; ?>
        </div>
        <div class="flex items-center gap-2 flex-1 min-w-[200px]">
          <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari nama / WA / email…"
                 class="flex-1 h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
          <button type="submit" class="px-4 h-9 rounded-lg text-sm font-medium text-white bg-brand hover:opacity-90">Cari</button>
        </div>
      </form>

      <!-- Tabel -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs font-semibold text-gray-500 uppercase tracking-wide border-b border-gray-100 dark:border-gray-800">
                <th class="px-4 py-3">Nama</th>
                <th class="px-4 py-3">Kontak</th>
                <th class="px-4 py-3 text-center">Skor</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Catatan</th>
                <th class="px-4 py-3">Tanggal</th>
                <th class="px-4 py-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
              <?php if (empty($subs)): ?>
              <tr><td colspan="7" class="px-4 py-12 text-center text-sm text-gray-400">Belum ada submission untuk filter ini.</td></tr>
              <?php else: foreach ($subs as $s): [$bl, $bc] = fgs_badge($s['status']); ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white"><?= e((string) $s['name']) ?: '<span class="text-gray-400">—</span>' ?></td>
                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                  <div class="text-xs font-mono"><?= e((string) ($s['whatsapp'] ?? '')) ?></div>
                  <?php if (trim((string) ($s['email'] ?? '')) !== ''): ?><div class="text-xs text-gray-400"><?= e((string) $s['email']) ?></div><?php endif; ?>
                </td>
                <td class="px-4 py-3 text-center"><span class="inline-flex items-center justify-center px-2 py-0.5 rounded-md text-sm font-semibold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200" style="min-width:2rem"><?= (int) $s['score'] ?></span></td>
                <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full text-[11px] font-medium <?= $bc ?>"><?= $bl ?></span></td>
                <td class="px-4 py-3">
                  <?php $nt = trim((string) ($s['internal_notes'] ?? '')); ?>
                  <?php if ($nt !== ''): ?>
                    <span class="block truncate text-xs text-gray-600 dark:text-gray-300" style="max-width:220px" title="<?= e($nt) ?>"><?= e($nt) ?></span>
                  <?php else: ?>
                    <span class="text-xs text-gray-300 dark:text-gray-600">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400"><?= e(date('d M Y H:i', strtotime((string) ($s['submitted_at'] ?: $s['created_at'])))) ?></td>
                <td class="px-4 py-3 text-right">
                  <div class="inline-flex items-center justify-end gap-1.5">
                    <?php if ($waAvailable && trim((string) ($s['whatsapp'] ?? '')) !== ''): ?>
                    <button type="button" onclick="fgOpenWa(<?= (int) $s['id'] ?>)" title="Kirim WhatsApp"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold text-white transition hover:opacity-90" style="background:#16a34a">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.3-1.38a9.86 9.86 0 0 0 4.73 1.2h.01c5.46 0 9.9-4.44 9.9-9.9S17.5 2 12.04 2zm5.8 14.06c-.24.69-1.4 1.32-1.95 1.4-.5.08-1.13.11-1.82-.11-.42-.14-.96-.31-1.65-.61-2.9-1.25-4.79-4.17-4.94-4.37-.14-.19-1.18-1.57-1.18-3s.75-2.13 1.02-2.42c.26-.29.57-.36.76-.36l.55.01c.18.01.42-.07.65.5.24.58.82 2 .89 2.15.07.14.12.31.02.5-.09.19-.14.31-.28.48-.14.17-.3.37-.42.5-.14.14-.29.29-.12.57.17.29.74 1.22 1.59 1.98 1.09.97 2.01 1.28 2.3 1.42.29.14.46.12.63-.07.17-.19.72-.84.91-1.13.19-.29.39-.24.65-.14.26.09 1.67.79 1.96.93.29.14.48.22.55.34.07.12.07.69-.17 1.38z"/></svg>
                      WA
                    </button>
                    <?php endif; ?>
                    <button type="button" onclick="fgOpenDetail(<?= (int) $s['id'] ?>)"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                      Detail
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>
</div>

<!-- Modal detail -->
<div id="fgDetailModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/40" onclick="fgCloseDetail()"></div>
  <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-lg overflow-y-auto" style="max-height:88vh">
    <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 dark:border-gray-800 sticky top-0 bg-white dark:bg-gray-900">
      <h3 class="text-base font-semibold text-gray-900 dark:text-white">Detail Submission</h3>
      <button type="button" onclick="fgCloseDetail()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="p-5 space-y-4">
      <div class="flex items-center justify-between gap-3">
        <div>
          <p id="fgd-name" class="font-semibold text-gray-900 dark:text-white"></p>
          <p id="fgd-contact" class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"></p>
        </div>
        <div class="text-right">
          <p class="text-xs text-gray-400 uppercase">Skor</p>
          <p id="fgd-score" class="text-2xl font-bold text-brand"></p>
        </div>
      </div>
      <p id="fgd-date" class="text-xs text-gray-400"></p>

      <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
        <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Jawaban</h4>
        <div id="fgd-answers" class="space-y-2.5"></div>
      </div>

      <form method="POST" action="<?= e($base) ?>/actions/admin/save-funnel-gate-submission" class="border-t border-gray-100 dark:border-gray-800 pt-4 space-y-3">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="form_id" value="<?= $formId ?>">
        <input type="hidden" name="id" id="fgd-id" value="">
        <div>
          <label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Status</label>
          <select name="status" id="fgd-status" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
            <option value="submitted">Submitted</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Catatan Internal</label>
          <textarea name="internal_notes" id="fgd-notes" rows="3" placeholder="Catatan tim (tidak terlihat prospek)…"
                    class="w-full px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></textarea>
        </div>
        <button type="submit" class="w-full py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">Simpan Status</button>
      </form>
    </div>
  </div>
</div>

<!-- Modal kirim WhatsApp 1-on-1 -->
<div id="fgWaModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/40" onclick="fgCloseWa()"></div>
  <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-md overflow-y-auto" style="max-height:88vh">
    <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 dark:border-gray-800">
      <h3 class="text-base font-semibold text-gray-900 dark:text-white flex items-center gap-2">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" style="color:#16a34a"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.3-1.38a9.86 9.86 0 0 0 4.73 1.2h.01c5.46 0 9.9-4.44 9.9-9.9S17.5 2 12.04 2zm5.8 14.06c-.24.69-1.4 1.32-1.95 1.4-.5.08-1.13.11-1.82-.11-.42-.14-.96-.31-1.65-.61-2.9-1.25-4.79-4.17-4.94-4.37-.14-.19-1.18-1.57-1.18-3s.75-2.13 1.02-2.42c.26-.29.57-.36.76-.36l.55.01c.18.01.42-.07.65.5.24.58.82 2 .89 2.15.07.14.12.31.02.5-.09.19-.14.31-.28.48-.14.17-.3.37-.42.5-.14.14-.29.29-.12.57.17.29.74 1.22 1.59 1.98 1.09.97 2.01 1.28 2.3 1.42.29.14.46.12.63-.07.17-.19.72-.84.91-1.13.19-.29.39-.24.65-.14.26.09 1.67.79 1.96.93.29.14.48.22.55.34.07.12.07.69-.17 1.38z"/></svg>
        Kirim WhatsApp
      </h3>
      <button type="button" onclick="fgCloseWa()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="p-5 space-y-3">
      <div>
        <p id="fgw-name" class="font-semibold text-gray-900 dark:text-white"></p>
        <p id="fgw-num" class="text-xs font-mono text-gray-500 dark:text-gray-400 mt-0.5"></p>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Isi pesan</label>
        <textarea id="fgw-msg" rows="5" placeholder="Tulis pesan untuk peserta…"
                  class="w-full px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></textarea>
        <p class="mt-1 text-xs text-gray-400">Gunakan <code class="px-1 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">{{name}}</code> untuk menyisipkan nama peserta.</p>
      </div>
      <p id="fgw-status" class="hidden text-xs"></p>
      <div class="flex items-center justify-end gap-2 pt-1">
        <button type="button" onclick="fgCloseWa()" class="px-4 h-9 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">Batal</button>
        <button type="button" id="fgw-send" onclick="fgSendWa()" class="px-5 h-9 rounded-lg text-sm font-semibold text-white transition hover:opacity-90" style="background:#16a34a">Kirim WhatsApp</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal broadcast ke peserta -->
<div id="fgbModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/40" onclick="fgbClose()"></div>
  <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-lg overflow-y-auto" style="max-height:90vh">
    <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 dark:border-gray-800 sticky top-0 bg-white dark:bg-gray-900 z-10">
      <h3 class="text-base font-semibold text-gray-900 dark:text-white">Broadcast ke peserta funnel</h3>
      <button type="button" onclick="fgbClose()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="p-5 space-y-4">
      <!-- Channel -->
      <div>
        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Channel pengiriman</label>
        <div class="grid grid-cols-2 gap-2">
          <label class="fgb-ch flex items-center gap-2 px-3 py-2.5 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer <?= $bcHasEmail ? '' : 'opacity-50 cursor-not-allowed' ?>">
            <input type="checkbox" id="fgbChEmail" class="fgb-chk w-4 h-4" <?= $bcHasEmail ? '' : 'disabled' ?> onchange="fgbSync()">
            <span class="text-sm font-medium text-gray-800 dark:text-gray-200">Email<?= $bcHasEmail ? '' : ' <span class="text-[10px] text-gray-400">(tak aktif)</span>' ?></span>
          </label>
          <label class="fgb-ch flex items-center gap-2 px-3 py-2.5 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer <?= $bcHasWa ? '' : 'opacity-50 cursor-not-allowed' ?>">
            <input type="checkbox" id="fgbChWa" class="fgb-chk w-4 h-4" <?= $bcHasWa ? '' : 'disabled' ?> onchange="fgbSync()">
            <span class="text-sm font-medium text-gray-800 dark:text-gray-200">WhatsApp<?= $bcHasWa ? '' : ' <span class="text-[10px] text-gray-400">(tak aktif)</span>' ?></span>
          </label>
        </div>
        <?php if (!$bcHasEmail && !$bcHasWa): ?>
        <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">Belum ada provider aktif. Aktifkan Email (Mailketing) atau WhatsApp di menu Integrasi dulu.</p>
        <?php endif; ?>
      </div>

      <!-- Target -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Target status</label>
          <select id="fgbStatus" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
            <option value="">Semua (non-draft)</option>
            <option value="submitted">Submitted</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Skor minimum</label>
          <input type="number" id="fgbMinScore" min="0" step="1" placeholder="Opsional"
                 class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
        </div>
      </div>

      <!-- Subjek (email) -->
      <div id="fgbSubjectWrap" class="hidden">
        <label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Subjek email</label>
        <input type="text" id="fgbSubject" maxlength="200" placeholder="Judul email…"
               class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
      </div>

      <!-- Pesan -->
      <div>
        <label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Isi pesan</label>
        <textarea id="fgbBody" rows="6" placeholder="Tulis informasi tindak lanjut untuk peserta…"
                  class="w-full px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></textarea>
        <p class="mt-1.5 text-xs text-gray-400">Gunakan <code class="px-1 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">{{name}}</code> untuk menyisipkan nama peserta (terisi untuk peserta yang punya akun member).</p>
        <label id="fgbHtmlWrap" class="hidden mt-2 items-center gap-2 text-xs text-gray-600 dark:text-gray-300 cursor-pointer">
          <input type="checkbox" id="fgbHtml" class="w-3.5 h-3.5"> Kirim email sebagai HTML (jika pesan berisi tag HTML)
        </label>
        <label id="fgbSkipFailWrap" class="hidden mt-2 items-center gap-2 text-xs text-gray-600 dark:text-gray-300 cursor-pointer">
          <input type="checkbox" id="fgbSkipFail" class="w-3.5 h-3.5" checked> Lewati email yang pernah gagal terkirim (disarankan)
        </label>
      </div>

      <!-- Waktu kirim -->
      <div>
        <label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Waktu kirim</label>
        <div class="flex items-center gap-2 bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 p-1" style="width:max-content">
          <button type="button" id="fgbModeNow" onclick="fgbMode('now')" class="px-3 py-1.5 rounded-md text-xs font-medium bg-brand text-white">Kirim sekarang</button>
          <button type="button" id="fgbModeSched" onclick="fgbMode('scheduled')" class="px-3 py-1.5 rounded-md text-xs font-medium text-gray-600 dark:text-gray-300">Jadwalkan</button>
        </div>
        <input type="datetime-local" id="fgbSchedAt" class="hidden mt-2 w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
      </div>

      <!-- Progress -->
      <div id="fgbProgress" class="hidden space-y-2 pt-1"></div>

      <div class="flex items-center justify-end gap-2 pt-1">
        <button type="button" onclick="fgbClose()" class="px-4 h-9 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">Batal</button>
        <button type="button" id="fgbSend" onclick="fgbSubmit()" class="px-5 h-9 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Kirim broadcast</button>
      </div>
    </div>
  </div>
</div>

<script>
window.FGB = {
  csrf: <?= json_encode($csrf) ?>,
  base: <?= json_encode($base) ?>,
  formId: <?= (int) $formId ?>,
  curStatus: <?= json_encode(in_array($statusFilter, ['submitted','pending','approved','rejected'], true) ? $statusFilter : '') ?>,
  mode: 'now',
  busy: false
};
function fgbOpen(){
  var m = document.getElementById('fgbModal');
  document.getElementById('fgbStatus').value = window.FGB.curStatus || '';
  document.getElementById('fgbProgress').classList.add('hidden');
  document.getElementById('fgbProgress').innerHTML = '';
  fgbSync();
  m.classList.remove('hidden'); m.classList.add('flex');
}
function fgbClose(){ if(window.FGB.busy) return; var m=document.getElementById('fgbModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
function fgbSync(){
  var email = document.getElementById('fgbChEmail').checked;
  document.getElementById('fgbSubjectWrap').classList.toggle('hidden', !email);
  var hw = document.getElementById('fgbHtmlWrap');
  hw.classList.toggle('hidden', !email); hw.classList.toggle('flex', email);
  var sf = document.getElementById('fgbSkipFailWrap');
  sf.classList.toggle('hidden', !email); sf.classList.toggle('flex', email);
}
function fgbMode(mode){
  window.FGB.mode = mode;
  var now = mode === 'now';
  document.getElementById('fgbModeNow').className = 'px-3 py-1.5 rounded-md text-xs font-medium ' + (now ? 'bg-brand text-white' : 'text-gray-600 dark:text-gray-300');
  document.getElementById('fgbModeSched').className = 'px-3 py-1.5 rounded-md text-xs font-medium ' + (!now ? 'bg-brand text-white' : 'text-gray-600 dark:text-gray-300');
  document.getElementById('fgbSchedAt').classList.toggle('hidden', now);
}
function fgbToast(msg, type){ if (typeof showToast === 'function') showToast(msg, type || 'info'); else alert(msg); }
async function fgbSubmit(){
  if (window.FGB.busy) return;
  var channels = [];
  if (document.getElementById('fgbChEmail').checked) channels.push('email');
  if (document.getElementById('fgbChWa').checked) channels.push('whatsapp');
  if (!channels.length) { fgbToast('Pilih minimal satu channel.', 'error'); return; }
  var body = document.getElementById('fgbBody').value.trim();
  if (!body) { fgbToast('Isi pesan belum diisi.', 'error'); return; }
  if (channels.indexOf('email') >= 0 && !document.getElementById('fgbSubject').value.trim()) { fgbToast('Subjek email wajib diisi.', 'error'); return; }
  var sched = '';
  if (window.FGB.mode === 'scheduled') {
    sched = document.getElementById('fgbSchedAt').value;
    if (!sched) { fgbToast('Pilih waktu jadwal.', 'error'); return; }
  }

  var fd = new FormData();
  fd.append('csrf_token', window.FGB.csrf);
  fd.append('form_id', window.FGB.formId);
  channels.forEach(function(c){ fd.append('channels[]', c); });
  fd.append('status', document.getElementById('fgbStatus').value);
  fd.append('min_score', document.getElementById('fgbMinScore').value || '0');
  fd.append('subject', document.getElementById('fgbSubject').value.trim());
  fd.append('body', body);
  fd.append('body_format', document.getElementById('fgbHtml').checked ? 'html' : 'text');
  fd.append('skip_failed_emails', document.getElementById('fgbSkipFail').checked ? '1' : '0');
  fd.append('send_mode', window.FGB.mode);
  fd.append('scheduled_at', sched);

  var btn = document.getElementById('fgbSend');
  var prog = document.getElementById('fgbProgress');
  window.FGB.busy = true; btn.disabled = true; btn.style.opacity = '.6'; btn.textContent = 'Memproses…';
  prog.classList.remove('hidden'); prog.innerHTML = '<p class="text-xs text-gray-500">Menyiapkan antrian…</p>';

  try {
    var r = await fetch(window.FGB.base + '/actions/admin/broadcast-funnel', { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} });
    var data = await r.json();
    if (!data.success) {
      prog.innerHTML = '<p class="text-xs text-red-500">' + fgEsc(data.message || 'Gagal.') + '</p>';
      fgbToast(data.message || 'Gagal membuat broadcast.', 'error');
      window.FGB.busy = false; btn.disabled = false; btn.style.opacity = ""; btn.textContent = 'Kirim broadcast';
      return;
    }
    if (data.errors && data.errors.length) {
      prog.innerHTML = data.errors.map(function(x){ return '<p class="text-xs text-amber-600 dark:text-amber-400">' + fgEsc(x) + '</p>'; }).join('');
    } else { prog.innerHTML = ''; }

    if (data.scheduled) {
      prog.innerHTML += '<p class="text-xs text-green-600 dark:text-green-400">Broadcast dijadwalkan. Akan terkirim otomatis pada waktunya.</p>';
      fgbToast('Broadcast berhasil dijadwalkan.', 'success');
      window.FGB.busy = false; btn.disabled = false; btn.style.opacity = ""; btn.textContent = 'Selesai';
      setTimeout(function(){ btn.textContent = 'Kirim broadcast'; }, 1500);
      return;
    }

    var startable = data.startable || [];
    if (!startable.length) {
      prog.innerHTML += '<p class="text-xs text-amber-600 dark:text-amber-400">Tidak ada penerima valid untuk dikirim.</p>';
      fgbToast('Tidak ada penerima valid.', 'error');
      window.FGB.busy = false; btn.disabled = false; btn.style.opacity = ""; btn.textContent = 'Kirim broadcast';
      return;
    }

    // Pump tiap campaign sampai selesai.
    for (var i = 0; i < startable.length; i++) {
      await fgbPump(startable[i], prog);
    }
    fgbToast('Broadcast selesai dikirim.', 'success');
    btn.textContent = 'Selesai';
    window.FGB.busy = false;
    setTimeout(function(){ btn.disabled = false; btn.style.opacity = ""; btn.textContent = 'Kirim broadcast'; }, 1500);
  } catch (e) {
    prog.innerHTML = '<p class="text-xs text-red-500">Terjadi kesalahan jaringan.</p>';
    fgbToast('Gangguan jaringan. Coba lagi.', 'error');
    window.FGB.busy = false; btn.disabled = false; btn.style.opacity = ""; btn.textContent = 'Kirim broadcast';
  }
}
async function fgbPump(item, prog){
  var chLabel = item.channel === 'email' ? 'Email' : 'WhatsApp';
  var line = document.createElement('p');
  line.className = 'text-xs text-gray-600 dark:text-gray-300';
  line.textContent = chLabel + ': memulai…';
  prog.appendChild(line);
  var guard = 0;
  while (guard++ < 2000) {
    var fd = new FormData();
    fd.append('csrf_token', window.FGB.csrf);
    fd.append('campaign_id', item.id);
    fd.append('limit', '15');
    var r = await fetch(window.FGB.base + '/actions/admin/broadcast-pump', { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} });
    var d = await r.json();
    var sent = d.sent || 0, failed = d.failed || 0, total = d.total || item.valid || 0, pct = d.percent || 0;
    line.textContent = chLabel + ': ' + sent + ' terkirim' + (failed ? ', ' + failed + ' gagal' : '') + ' dari ' + total + ' (' + pct + '%)';
    if ((d.status && d.status !== 'sending') || (d.pending !== undefined && d.pending <= 0 && d.processed === 0)) {
      line.className = 'text-xs ' + (failed > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-green-600 dark:text-green-400');
      break;
    }
  }
}
</script>

<script>
window.FG_SUBS = <?= json_encode($subDetail, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function fgEsc(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
function fgOpenDetail(id){
  var s = window.FG_SUBS[id]; if(!s) return;
  document.getElementById('fgd-name').textContent = s.name || '—';
  var contact = [s.wa, s.email].filter(Boolean).join(' · ');
  document.getElementById('fgd-contact').textContent = contact;
  document.getElementById('fgd-score').textContent = s.score;
  document.getElementById('fgd-date').textContent = s.date;
  document.getElementById('fgd-id').value = id;
  document.getElementById('fgd-status').value = (s.status === 'draft' ? 'submitted' : s.status);
  document.getElementById('fgd-notes').value = s.notes || '';
  var ans = document.getElementById('fgd-answers');
  if (!s.rows || !s.rows.length) { ans.innerHTML = '<p class="text-sm text-gray-400">Tidak ada jawaban pertanyaan.</p>'; }
  else {
    ans.innerHTML = s.rows.map(function(r){
      return '<div><p class="text-xs text-gray-400">'+fgEsc(r.label)+'</p><p class="text-sm text-gray-800 dark:text-gray-200">'+(fgEsc(r.value)||'<span class=\"text-gray-400\">—</span>')+'</p></div>';
    }).join('');
  }
  var m = document.getElementById('fgDetailModal');
  m.classList.remove('hidden'); m.classList.add('flex');
}
function fgCloseDetail(){ var m=document.getElementById('fgDetailModal'); m.classList.add('hidden'); m.classList.remove('flex'); }

// ── Modal kirim WhatsApp 1-on-1 ──
window.FG_WA_ID = 0;
function fgOpenWa(id){
  var s = window.FG_SUBS[id]; if(!s) return;
  window.FG_WA_ID = id;
  document.getElementById('fgw-name').textContent = s.name || '—';
  document.getElementById('fgw-num').textContent  = s.wa || '';
  document.getElementById('fgw-msg').value = '';
  var st = document.getElementById('fgw-status'); st.textContent=''; st.className='hidden text-xs';
  var btn = document.getElementById('fgw-send'); btn.disabled=false; btn.style.opacity=''; btn.textContent='Kirim WhatsApp';
  var m = document.getElementById('fgWaModal'); m.classList.remove('hidden'); m.classList.add('flex');
}
function fgCloseWa(){ var m=document.getElementById('fgWaModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ fgCloseDetail(); fgCloseWa(); } });

async function fgSendWa(){
  var msgEl = document.getElementById('fgw-msg');
  var stEl  = document.getElementById('fgw-status');
  var btn   = document.getElementById('fgw-send');
  var id    = window.FG_WA_ID;
  var msg   = (msgEl.value || '').trim();
  function setStatus(text, cls){ stEl.textContent = text; stEl.className = 'text-xs ' + cls; }
  if (!msg) { setStatus('Isi pesan belum diisi.', 'text-red-500'); return; }
  btn.disabled = true; btn.style.opacity = '.6'; btn.textContent = 'Mengirim…';
  setStatus('Mengirim ke WhatsApp…', 'text-gray-500');
  try {
    var fd = new FormData();
    fd.append('csrf_token', window.FGB.csrf);
    fd.append('id', id);
    fd.append('message', msg);
    var r = await fetch(window.FGB.base + '/actions/admin/send-funnel-wa', { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} });
    var d = await r.json();
    if (d.success) {
      setStatus(d.message || 'Pesan WhatsApp terkirim.', 'text-green-600 dark:text-green-400');
      msgEl.value = '';
      if (typeof showToast === 'function') showToast(d.message || 'Pesan WhatsApp terkirim.', 'success');
      setTimeout(fgCloseWa, 1200);
    } else {
      setStatus(d.message || 'Gagal mengirim.', 'text-red-500');
    }
  } catch (e) {
    setStatus('Gangguan jaringan. Coba lagi.', 'text-red-500');
  } finally {
    btn.disabled = false; btn.style.opacity = ''; btn.textContent = 'Kirim WhatsApp';
  }
}
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

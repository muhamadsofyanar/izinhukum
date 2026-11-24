<?php
// Konten tab "Pencairan" — dipakai oleh pages/admin/affiliate-finance.php.
// Guard, layout & generateCSRF ditangani parent. Endpoint aksi TIDAK berubah.
$csrf = $_SESSION['csrf_token'];
$stats = ['pending' => 0, 'approved' => 0, 'paid' => 0];
$pendingComms = []; $payouts = [];
try {
    $pdo = getDB();
    $stats['pending']  = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status='pending'")->fetchColumn();
    $stats['approved'] = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status='approved'")->fetchColumn();
    $stats['paid']     = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status='paid'")->fetchColumn();
    $pendingComms = $pdo->query(
        "SELECT c.id, c.amount, c.rate, c.created_at, o.order_number, u.name AS aff_name, u.email AS aff_email
         FROM commissions c JOIN affiliates a ON a.id=c.affiliate_id JOIN users u ON u.id=a.user_id
         LEFT JOIN orders o ON o.id=c.order_id WHERE c.status='pending' ORDER BY c.created_at DESC LIMIT 100"
    )->fetchAll();
    $payouts = $pdo->query(
        "SELECT pr.*, u.name AS aff_name, u.email AS aff_email FROM payout_requests pr
         JOIN affiliates a ON a.id=pr.affiliate_id JOIN users u ON u.id=a.user_id
         ORDER BY FIELD(pr.status,'pending','processing','paid','rejected'), pr.created_at DESC LIMIT 50"
    )->fetchAll();
} catch (Exception $e) { error_log('payouts page error: ' . $e->getMessage()); }
?>
<div class="grid grid-cols-3 gap-3 mb-5">
  <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4"><p class="text-lg font-bold text-gray-900 dark:text-white"><?= e(formatRupiah($stats['pending'])) ?></p><p class="text-xs text-gray-400">Komisi Pending</p></div>
  <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4"><p class="text-lg font-bold text-gray-900 dark:text-white"><?= e(formatRupiah($stats['approved'])) ?></p><p class="text-xs text-gray-400">Approved (siap cair)</p></div>
  <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4"><p class="text-lg font-bold text-green-600 dark:text-green-400"><?= e(formatRupiah($stats['paid'])) ?></p><p class="text-xs text-gray-400">Total Paid</p></div>
</div>

<!-- Komisi Pending: batch approve -->
<div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-6">
  <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Komisi Pending</h3></div>
  <?php if (empty($pendingComms)): ?>
  <div class="p-8 text-center text-sm text-gray-400">Tidak ada komisi pending.</div>
  <?php else: ?>
  <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/batch-approve-commissions" id="batch-approve-form">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-800">
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" id="comm-all" class="rounded" onchange="document.querySelectorAll('.comm-cb').forEach(c=>c.checked=this.checked);commCount()"> Pilih Semua</label>
      <button type="button" id="batch-btn" disabled onclick="confirmAndSubmit('batch-approve-form', {title:'Approve Komisi', message:'Approve komisi yang dipilih?', confirmText:'Approve', variant:'success'})" class="px-3 py-1.5 text-sm font-semibold bg-green-600 text-white rounded-lg disabled:opacity-40">✓ Approve Terpilih</button>
      <span id="comm-count" class="text-xs text-gray-400"></span>
    </div>
    <div class="overflow-x-auto"><table class="w-full text-sm"><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
      <?php foreach ($pendingComms as $c): ?>
      <tr>
        <td class="px-5 py-2.5 w-8"><input type="checkbox" name="commission_ids[]" value="<?= (int)$c['id'] ?>" class="comm-cb rounded" onchange="commCount()"></td>
        <td class="px-4 py-2.5"><p class="text-gray-900 dark:text-white"><?= e($c['aff_name']) ?></p><p class="text-xs text-gray-400"><?= e($c['aff_email']) ?></p></td>
        <td class="px-4 py-2.5 font-mono text-xs text-gray-500"><?= e($c['order_number'] ?? '-') ?></td>
        <td class="px-4 py-2.5 text-gray-400 text-xs"><?= (float)$c['rate'] ?>%</td>
        <td class="px-5 py-2.5 text-right font-semibold text-gray-900 dark:text-white"><?= e(formatRupiah((float)$c['amount'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </form>
  <?php endif; ?>
</div>

<!-- Payout Requests -->
<div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
  <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Payout Requests</h3></div>
  <?php if (empty($payouts)): ?>
  <div class="p-8 text-center text-sm text-gray-400">Belum ada permintaan pencairan.</div>
  <?php else: ?>
  <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40">
    <th class="text-left px-5 py-3">Affiliate</th><th class="text-right px-4 py-3">Jumlah</th><th class="text-left px-4 py-3">Rekening</th><th class="text-center px-4 py-3">Status</th><th class="text-right px-5 py-3">Aksi</th>
  </tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
    <?php foreach ($payouts as $pr):
      $sc = ['pending'=>'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400','processing'=>'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400','paid'=>'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400','rejected'=>'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400'][$pr['status']] ?? 'bg-gray-100 text-gray-500';
    ?>
    <tr>
      <td class="px-5 py-3"><p class="text-gray-900 dark:text-white"><?= e($pr['aff_name']) ?></p><p class="text-xs text-gray-400"><?= e($pr['aff_email']) ?></p></td>
      <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white"><?= e(formatRupiah((float)$pr['amount'])) ?></td>
      <td class="px-4 py-3 text-xs text-gray-500"><?= e($pr['bank_name']) ?><br><span class="font-mono"><?= e($pr['bank_account_no']) ?></span><br><?= e($pr['bank_account_name']) ?></td>
      <td class="px-4 py-3 text-center"><span class="text-xs px-2 py-0.5 rounded-full <?= $sc ?>"><?= e($pr['status']) ?></span></td>
      <td class="px-5 py-3 text-right">
        <?php if ($pr['status'] === 'pending'): ?>
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/update-payout-status" class="inline"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="payout_id" value="<?= (int)$pr['id'] ?>"><button class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-blue-200 dark:border-blue-900/40 text-blue-600">Proses</button></form>
          <button onclick="rejectPayout(<?= (int)$pr['id'] ?>)" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-red-200 dark:border-red-900/40 text-red-600">Tolak</button>
        <?php elseif ($pr['status'] === 'processing'): ?>
          <button onclick='markPaid(<?= (int)$pr['id'] ?>)' class="px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-green-600 text-white">Bukti &amp; Mark Paid</button>
          <button onclick="rejectPayout(<?= (int)$pr['id'] ?>)" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-red-200 dark:border-red-900/40 text-red-600">Tolak</button>
        <?php elseif ($pr['status'] === 'paid' && !empty($pr['proof_path'])): ?>
          <a href="<?= e(UPLOAD_URL . $pr['proof_path']) ?>" target="_blank" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">Lihat Bukti</a>
        <?php else: ?><span class="text-xs text-gray-300">—</span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<form method="POST" action="<?= e(APP_URL) ?>/actions/admin/reject-payout" id="reject-form" class="hidden"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="payout_id" id="reject-id"><input type="hidden" name="reason" id="reject-reason"></form>

<div id="modal-paid" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
  <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-md">
    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/mark-payout-paid" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="payout_id" id="paid-id">
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">Upload Bukti &amp; Mark Paid</h3><button type="button" onclick="document.getElementById('modal-paid').classList.add('hidden')" class="text-gray-400">✕</button></div>
      <div class="p-5 space-y-3">
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Bukti Transfer (JPG/PNG/PDF, opsional)</label><input type="file" name="proof" class="w-full text-sm"></div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Catatan (opsional)</label><input type="text" name="admin_note" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
      </div>
      <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2"><button type="button" onclick="document.getElementById('modal-paid').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700">Batal</button><button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-green-600">Mark Paid</button></div>
    </form>
  </div>
</div>
<script>
function commCount(){ var n=document.querySelectorAll('.comm-cb:checked').length; document.getElementById('batch-btn').disabled=n===0; document.getElementById('comm-count').textContent=n>0?n+' dipilih':''; }
function rejectPayout(id){ var r=prompt('Alasan penolakan:'); if(!r) return; document.getElementById('reject-id').value=id; document.getElementById('reject-reason').value=r; document.getElementById('reject-form').submit(); }
function markPaid(id){ document.getElementById('paid-id').value=id; document.getElementById('modal-paid').classList.remove('hidden'); }
</script>

<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { redirect('/admin/no-access'); }

$pageTitle   = 'Review Manager';
$topbarTitle = 'Review Manager';

$filterStatus = in_array($_GET['status'] ?? '', ['pending','approved','rejected'], true) ? $_GET['status'] : 'pending';
$stats = ['pending'=>0,'approved'=>0,'rejected'=>0];
$reviews = [];
try {
    $pdo = getDB();
    foreach (['pending','approved','rejected'] as $st) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM product_reviews WHERE status=?"); $c->execute([$st]); $stats[$st] = (int) $c->fetchColumn();
    }
    $q = $pdo->prepare(
        "SELECT r.*, u.name AS reviewer_name, u.email AS reviewer_email, p.name AS product_name
         FROM product_reviews r JOIN users u ON u.id=r.user_id JOIN products p ON p.id=r.product_id
         WHERE r.status = ? ORDER BY r.created_at DESC LIMIT 50"
    );
    $q->execute([$filterStatus]);
    $reviews = $q->fetchAll();
} catch (Exception $e) { error_log('admin reviews error: ' . $e->getMessage()); }

generateCSRF();
$csrf = $_SESSION['csrf_token'];
$stars = function(int $n) { $o=''; for($i=1;$i<=5;$i++){ $o .= $i<=$n ? '★' : '☆'; } return $o; };
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>
      <div class="mb-5"><h2 class="text-xl font-bold text-gray-900 dark:text-white">Review Manager</h2></div>

      <div class="flex gap-2 mb-5">
        <?php foreach (['pending'=>'Menunggu','approved'=>'Disetujui','rejected'=>'Ditolak'] as $st=>$lbl): ?>
        <a href="?status=<?= $st ?>" class="px-3 py-2 rounded-lg text-sm font-medium <?= $filterStatus===$st ? 'bg-brand text-white' : 'bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-gray-600 dark:text-gray-300' ?>"><?= $lbl ?> (<?= $stats[$st] ?>)</a>
        <?php endforeach; ?>
      </div>

      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($reviews)): ?>
        <div class="p-10 text-center text-sm text-gray-400">Tidak ada review berstatus "<?= e($filterStatus) ?>".</div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40">
          <th class="text-left px-5 py-3">Reviewer</th><th class="text-left px-4 py-3">Produk</th><th class="text-left px-4 py-3">Rating</th><th class="text-left px-4 py-3">Ulasan</th><th class="text-right px-5 py-3">Aksi</th>
        </tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($reviews as $r): ?>
          <tr>
            <td class="px-5 py-3"><p class="text-gray-900 dark:text-white"><?= e($r['reviewer_name']) ?></p><p class="text-xs text-gray-400"><?= e($r['reviewer_email']) ?></p></td>
            <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?= e($r['product_name']) ?></td>
            <td class="px-4 py-3 text-yellow-400 text-base"><?= $stars((int)$r['rating']) ?></td>
            <td class="px-4 py-3 text-gray-500 text-xs max-w-xs"><?= e(mb_substr($r['review_text'] ?? '', 0, 80)) ?: '<span class="text-gray-300">—</span>' ?></td>
            <td class="px-5 py-3 text-right whitespace-nowrap">
              <?php if ($r['status'] !== 'approved'): ?>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/approve-review" class="inline"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="approve"><button class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-green-200 dark:border-green-900/40 text-green-600">Setujui</button></form>
              <?php endif; ?>
              <?php if ($r['status'] !== 'rejected'): ?>
              <button onclick="rejectReview(<?= (int)$r['id'] ?>)" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-amber-200 dark:border-amber-900/40 text-amber-600">Tolak</button>
              <?php endif; ?>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-review" class="inline" id="del-rv-<?= (int)$r['id'] ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>"><button type="button" onclick="confirmAndSubmit('del-rv-<?= (int)$r['id'] ?>', {title:'Hapus Review', message:'Hapus review ini?', confirmText:'Hapus', variant:'danger'})" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-red-200 dark:border-red-900/40 text-red-600">Hapus</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>
<form method="POST" action="<?= e(APP_URL) ?>/actions/admin/approve-review" id="reject-rv-form" class="hidden"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="review_id" id="reject-rv-id"><input type="hidden" name="action" value="reject"><input type="hidden" name="admin_note" id="reject-rv-note"></form>
<script>
function rejectReview(id){ var n=prompt('Alasan penolakan (opsional):'); if(n===null) return; document.getElementById('reject-rv-id').value=id; document.getElementById('reject-rv-note').value=n||''; document.getElementById('reject-rv-form').submit(); }
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

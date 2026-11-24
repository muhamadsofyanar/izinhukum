<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Leads AI Chat';
$topbarTitle = 'Leads AI Chat';

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$leads   = [];
$total   = 0;

try {
    $pdo   = getDB();
    $total = (int) $pdo->query('SELECT COUNT(*) FROM chat_leads')->fetchColumn();
    $stmt  = $pdo->prepare('SELECT * FROM chat_leads ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $leads = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('chat-leads page: ' . $e->getMessage());
    flash('error', 'Gagal memuat data leads.', 'error');
}
$totalPages = max(1, (int) ceil($total / $perPage));
$csrf = generateCSRF();
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="mb-5">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Leads dari AI Chat</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Pengunjung yang meninggalkan kontak lewat widget chat — lengkap dengan isi percakapannya untuk bekal follow-up.</p>
      </div>

      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (!$leads): ?>
        <div class="px-6 py-14 text-center">
          <div class="w-12 h-12 rounded-2xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mx-auto mb-3">
            <svg class="w-6 h-6 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
          </div>
          <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Belum ada lead dari chat</p>
          <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Form nama + WhatsApp muncul otomatis setelah pengunjung mengirim 2 pesan di widget AI Chat.</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($leads as $l): ?>
          <details class="group">
            <summary class="flex items-center gap-4 px-5 py-3.5 cursor-pointer list-none hover:bg-gray-50 dark:hover:bg-gray-800/40 transition">
              <div class="w-9 h-9 rounded-full flex items-center justify-center text-white text-sm font-bold shrink-0" style="background:var(--brand-color)"><?= e(strtoupper(mb_substr($l['name'], 0, 1))) ?></div>
              <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-gray-900 dark:text-white truncate"><?= e($l['name']) ?></p>
                <p class="text-xs text-gray-400 dark:text-gray-500"><?= e(date('d M Y H:i', strtotime($l['created_at']))) ?><?= $l['updated_at'] ? ' · masih aktif ' . e(date('d M H:i', strtotime($l['updated_at']))) : '' ?></p>
              </div>
              <a href="https://wa.me/<?= e($l['whatsapp']) ?>?text=<?= rawurlencode('Halo kak ' . $l['name'] . ', terima kasih sudah chat di website kami!') ?>" target="_blank" rel="noopener"
                 onclick="event.stopPropagation()"
                 class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-green-700 bg-green-50 dark:bg-green-900/20 dark:text-green-400 hover:bg-green-100 dark:hover:bg-green-900/40 transition">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                <?= e($l['whatsapp']) ?>
              </a>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-chat-lead" id="del-cl-<?= (int) $l['id'] ?>" class="inline" onclick="event.stopPropagation()">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                <button type="button" onclick="confirmAndSubmit('del-cl-<?= (int) $l['id'] ?>', {title: 'Hapus Lead?', message: 'Lead beserta transcript percakapannya akan dihapus permanen.'})"
                        class="shrink-0 p-1.5 rounded-lg text-gray-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition" title="Hapus">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
              </form>
              <svg class="w-4 h-4 text-gray-300 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </summary>
            <div class="px-5 pb-4">
              <div style="margin-left:3.25rem" class="rounded-lg bg-gray-50 dark:bg-gray-800/50 border border-gray-100 dark:border-gray-800 p-4 max-h-80 overflow-y-auto">
                <?php if (trim((string) $l['transcript']) === ''): ?>
                <p class="text-xs text-gray-400">Tidak ada transcript.</p>
                <?php else: ?>
                <pre class="text-xs text-gray-600 dark:text-gray-300 whitespace-pre-wrap font-sans leading-relaxed"><?= e($l['transcript']) ?></pre>
                <?php endif; ?>
              </div>
            </div>
          </details>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($totalPages > 1): ?>
      <div class="flex items-center justify-between mt-4">
        <p class="text-xs text-gray-400"><?= $total ?> lead total</p>
        <div class="flex gap-1.5">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <a href="?page=<?= $i ?>" class="px-3 py-1.5 rounded-lg text-xs font-medium <?= $i === $page ? 'text-white' : 'text-gray-500 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800' ?>" <?= $i === $page ? 'style="background:var(--brand-color)"' : '' ?>><?= $i ?></a>
          <?php endfor; ?>
        </div>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

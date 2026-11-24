<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

// Menu/halaman ini bisa dimatikan admin (Pengaturan → Menu Dashboard Member).
if (getSetting('member_menu_certificates', '1') === '0') {
    redirect('/dashboard');
}

$pageTitle   = 'Sertifikat Saya';
$topbarTitle = 'Sertifikat Saya';

$certs = [];
try {
    $stmt = getDB()->prepare(
        "SELECT c.*, p.thumbnail AS product_thumbnail FROM certificates c
         JOIN products p ON p.id = c.product_id
         WHERE c.user_id = ? AND c.status = 'active' ORDER BY c.issued_at DESC"
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    $certs = $stmt->fetchAll();
} catch (Exception $e) { error_log('user certs error: ' . $e->getMessage()); }
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>
      <div class="mb-5"><h2 class="text-xl font-bold text-gray-900 dark:text-white">Sertifikat Saya</h2></div>
      <?php if (empty($certs)): ?>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-10 text-center text-sm text-gray-500 dark:text-gray-400">Belum ada sertifikat. Selesaikan kelas untuk mendapatkan sertifikat.</div>
      <?php else: ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($certs as $c): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 overflow-hidden">
          <?php if (!empty($c['thumbnail_path'])): ?>
          <img src="<?= e(UPLOAD_URL . $c['thumbnail_path']) ?>" class="w-full h-40 object-cover">
          <?php else: ?>
          <div class="w-full h-40 bg-gradient-to-br from-indigo-900 to-indigo-600 flex items-center justify-center text-white/40"><svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="6"/><path stroke-linecap="round" stroke-linejoin="round" d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg></div>
          <?php endif; ?>
          <div class="p-4">
            <p class="font-semibold text-gray-900 dark:text-white text-sm"><?= e($c['product_name']) ?></p>
            <p class="text-xs text-gray-400 font-mono mt-0.5"><?= e($c['certificate_no']) ?></p>
            <p class="text-xs text-gray-400 mt-0.5"><?= date('d M Y', strtotime($c['issued_at'])) ?></p>
            <div class="flex gap-2 mt-3">
              <a href="<?= e(APP_URL) ?>/certificate/<?= e($c['uuid']) ?>" target="_blank" class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-brand text-white hover:opacity-90">Lihat</a>
              <button onclick="copyCert('<?= e($c['uuid']) ?>', this)" class="text-xs font-semibold px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">Salin Link</button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </main>
  </div>
</div>
<script>
function copyCert(uuid, btn){ navigator.clipboard.writeText('<?= e(rtrim(APP_URL,"/")) ?>/certificate/'+uuid).then(function(){ var t=btn.textContent; btn.textContent='Tersalin!'; setTimeout(function(){btn.textContent=t;},1500); }); }
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

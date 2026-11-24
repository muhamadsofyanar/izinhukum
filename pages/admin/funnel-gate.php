<?php
// ════════════════════════════════════════════════════════════════
// Funnel Gate — daftar form kualifikasi prospek (Decision Engine).
// Route: /admin/funnel-gate. Editor: /admin/funnel-gate/edit?id=
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pdo = getDB();

$forms = [];
try {
    $forms = $pdo->query(
        "SELECT f.*,
                (SELECT COUNT(*) FROM funnel_gate_submissions s
                  WHERE s.form_id = f.id AND s.status <> 'draft') AS submitted_count,
                (SELECT COUNT(*) FROM funnel_gate_fields fl
                  WHERE fl.form_id = f.id AND fl.field_role = 'none') AS question_count
         FROM funnel_gate_forms f
         ORDER BY f.created_at DESC"
    )->fetchAll();
} catch (Exception $e) {
    error_log('Funnel Gate list error: ' . $e->getMessage());
}

$csrf = generateCSRF();
$base = rtrim(APP_URL, '/');
$pageTitle   = 'Funnel Gate';
$topbarTitle = 'Funnel Gate';

function fg_badge(string $s): array {
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
      <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Funnel Gate</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-2xl">
            Saring prospek sebelum masuk funnel utama. Buat form kualifikasi ber-skor —
            arahkan yang layak ke checkout, yang lain ke jalur nurture.
          </p>
        </div>
        <a href="<?= e($base) ?>/admin/funnel-gate/edit"
           class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition shrink-0">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          Buat Form
        </a>
      </div>

      <?php if (empty($forms)): ?>
      <!-- Empty state -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-10 text-center">
        <div class="w-14 h-14 mx-auto rounded-2xl flex items-center justify-center mb-4" style="background:color-mix(in srgb, var(--brand-color) 12%, transparent)">
          <svg class="w-7 h-7" style="color:var(--brand-color)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V20l-4-2v-5.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
          </svg>
        </div>
        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Belum ada Funnel Gate</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 mb-5 max-w-md mx-auto">
          Buat form kualifikasi pertama Anda — cocok untuk aplikasi mentoring/coaching,
          high-ticket, atau seleksi membership.
        </p>
        <a href="<?= e($base) ?>/admin/funnel-gate/edit"
           class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
          Buat Form Pertama
        </a>
      </div>
      <?php else: ?>
      <!-- Grid form -->
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php foreach ($forms as $f):
          [$badgeLabel, $badgeClass] = fg_badge($f['status']);
          $publicUrl = $base . '/apply/' . $f['slug'];
        ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 flex flex-col">
          <div class="flex items-start justify-between gap-3 mb-3">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white leading-snug"><?= e($f['title']) ?></h3>
            <span class="shrink-0 px-2 py-0.5 rounded-full text-[11px] font-medium <?= $badgeClass ?>"><?= $badgeLabel ?></span>
          </div>

          <div class="flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400 mb-4">
            <a href="<?= e($base) ?>/admin/funnel-gate/submissions?form=<?= (int) $f['id'] ?>" class="inline-flex items-center gap-1.5 hover:text-brand transition">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
              <?= (int) $f['submitted_count'] ?> submission
            </a>
            <span class="inline-flex items-center gap-1.5">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
              <?= (int) $f['question_count'] ?> pertanyaan
            </span>
          </div>

          <!-- URL publik -->
          <div class="flex items-center gap-2 mb-4">
            <input type="text" readonly value="<?= e($publicUrl) ?>"
                   class="flex-1 min-w-0 h-8 px-2.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-xs font-mono text-gray-600 dark:text-gray-300 truncate"
                   onclick="this.select()">
            <button type="button" title="Salin URL"
                    onclick="navigator.clipboard.writeText('<?= e($publicUrl) ?>').then(()=>showToast('URL disalin','success'))"
                    class="shrink-0 w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-brand transition">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
            </button>
          </div>

          <!-- Aksi -->
          <div class="mt-auto flex items-center gap-2 pt-3 border-t border-gray-100 dark:border-gray-800">
            <a href="<?= e($base) ?>/admin/funnel-gate/submissions?form=<?= (int) $f['id'] ?>"
               class="flex-1 inline-flex items-center justify-center gap-1.5 h-9 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
              Submissions
            </a>
            <a href="<?= e($base) ?>/admin/funnel-gate/edit?id=<?= (int) $f['id'] ?>" title="Edit"
               class="inline-flex items-center justify-center h-9 px-3 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </a>
            <?php if ($f['status'] === 'published'): ?>
            <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener"
               class="inline-flex items-center justify-center h-9 px-3 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition" title="Buka form publik">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/></svg>
            </a>
            <?php endif; ?>
            <form id="fg-del-<?= (int) $f['id'] ?>" method="POST" action="<?= e($base) ?>/actions/admin/delete-funnel-gate" class="shrink-0">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <button type="button" title="Hapus"
                      onclick="confirmAndSubmit('fg-del-<?= (int) $f['id'] ?>', {title:'Hapus Funnel Gate?',message:'Form, pertanyaan, dan semua submission akan dihapus permanen.',confirmText:'Ya, Hapus',variant:'danger'})"
                      class="inline-flex items-center justify-center h-9 px-3 rounded-lg text-sm font-medium text-red-600 border border-gray-200 dark:border-gray-700 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
              </button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

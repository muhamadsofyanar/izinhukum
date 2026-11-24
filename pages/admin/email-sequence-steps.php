<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('email_sequences')) { redirect('/admin/no-access'); }

$sequenceId = (int) ($_GET['id'] ?? 0);
if (!$sequenceId) { redirect('/admin/email-sequences'); }

$pdo = getDB();
ensureEmailStepBodyFormatColumn($pdo);
$sStmt = $pdo->prepare("SELECT * FROM email_sequences WHERE id = ? LIMIT 1");
$sStmt->execute([$sequenceId]);
$sequence = $sStmt->fetch();
if (!$sequence) { flash('error', 'Sequence tidak ditemukan.', 'error'); redirect('/admin/email-sequences'); }

$stStmt = $pdo->prepare("SELECT * FROM email_sequence_steps WHERE sequence_id = ? ORDER BY delay_days ASC, step_number ASC");
$stStmt->execute([$sequenceId]);
$steps = $stStmt->fetchAll();

$pageTitle   = $sequence['name'] . ' — Steps';
$topbarTitle = 'Email Sequence Steps';
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
      <a href="<?= e(APP_URL) ?>/admin/email-sequences" class="text-sm text-gray-400 hover:text-brand">← Email Sequences</a>
      <div class="flex flex-wrap items-center justify-between gap-4 mb-5 mt-2">
        <div><h2 class="text-xl font-bold text-gray-900 dark:text-white"><?= e($sequence['name']) ?> — Steps</h2></div>
        <button onclick="openStep()" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">+ Tambah Step</button>
      </div>

      <?php if (empty($steps)): ?>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-10 text-center text-sm text-gray-400">Belum ada step. Tambah step pertama (mis. D+0 email sambutan).</div>
      <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($steps as $st):
          $d = (int) $st['delay_days'];
          $badge = $d === 0 ? 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400' : ($d <= 3 ? 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400');
        ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 flex items-start gap-4">
          <div class="shrink-0 w-16 text-center"><span class="inline-block px-2 py-1 rounded-lg text-sm font-bold <?= $badge ?>">D+<?= $d ?></span></div>
          <div class="flex-1 min-w-0">
            <p class="font-semibold text-gray-900 dark:text-white text-sm flex items-center gap-2 flex-wrap">
              <?= e($st['subject']) ?>
              <?php if (($st['body_format'] ?? 'text') === 'html'): ?><span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">HTML</span><?php endif; ?>
              <?php if ($st['status']!=='active'): ?><span class="text-xs font-normal text-gray-400">(nonaktif)</span><?php endif; ?>
            </p>
            <?php
              // Snippet bersih: buang isi <style>/<script>/<head> dulu (agar CSS tak bocor), strip tag, rapikan spasi.
              $snippet = preg_replace('/<(style|script|head)\b[^>]*>.*?<\/\1>/is', ' ', $st['body']);
              $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($snippet)));
            ?>
            <p class="text-xs text-gray-400 mt-0.5 truncate"><?= e(mb_substr($snippet, 0, 120)) ?: '—' ?></p>
          </div>
          <div class="shrink-0 flex items-center gap-1.5">
            <button type="button" onclick="editStep(<?= (int)$st['id'] ?>)" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">Edit</button>
            <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-email-sequence-step" class="inline">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="sequence_id" value="<?= (int)$sequenceId ?>">
              <input type="hidden" name="id" value="<?= (int)$st['id'] ?>">
              <button type="button" title="Hapus step"
                      onclick="confirmAndRun({title:'Hapus Step?',message:'Step D+<?= $d ?> (<?= e(addslashes($st['subject'])) ?>) akan dihapus permanen.',confirmText:'Ya, Hapus',variant:'danger'}, () => this.closest('form').submit())"
                      class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path stroke-linecap="round" stroke-linejoin="round" d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path stroke-linecap="round" stroke-linejoin="round" d="M10 11v6M14 11v6"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 6V4h6v2"/></svg>
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

<div id="modal-step" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
  <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-email-sequence-step">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="sequence_id" value="<?= (int)$sequenceId ?>"><input type="hidden" name="id" id="step-id">
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
        <h3 class="font-semibold text-gray-900 dark:text-white">Step Email</h3>
        <button type="button" onclick="document.getElementById('modal-step').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="p-5 space-y-3">
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Kirim setelah (hari)</label><input type="number" name="delay_days" id="step-delay" min="0" value="0" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"><p class="text-xs text-gray-400 mt-1">0 = langsung saat trigger, 1 = besok, 7 = seminggu</p></div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Subject</label><input type="text" name="subject" id="step-subject" required class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
        <div>
          <div class="flex flex-wrap items-center justify-between gap-3 mb-1.5">
            <label class="block text-xs font-medium text-gray-500 uppercase">Body</label>
            <div class="flex items-center gap-3">
              <button type="button" id="step-preview-btn" class="hidden text-xs font-semibold text-brand hover:underline">Preview</button>
              <label class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 cursor-pointer select-none">
                <input type="checkbox" name="body_format" id="step-html-toggle" value="html" class="w-3.5 h-3.5 rounded border-gray-300 text-brand focus:ring-brand">
                Konten HTML (embed template)
              </label>
            </div>
          </div>
          <textarea name="body" id="step-body" rows="6" required class="w-full px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white resize-y"></textarea>
          <p id="step-body-hint" class="text-xs text-gray-400 mt-1">Placeholder: {{name}}, {{sequence_name}}, {{trigger_ref}}</p>
          <p id="step-html-hint" class="hidden text-xs text-gray-400 mt-1">Tempel <strong>HTML template email lengkap</strong> — dikirim apa adanya (tanpa pembungkus). Gunakan {{name}} dll untuk personalisasi. Klik <strong>Preview</strong> untuk melihat hasil.</p>
        </div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Status</label><select name="status" id="step-status" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select></div>
      </div>
      <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2"><button type="button" onclick="document.getElementById('modal-step').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700">Batal</button><button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Simpan</button></div>
    </form>
  </div>
</div>
<!-- Preview HTML Email -->
<div id="step-preview-modal" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4">
  <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl flex flex-col" style="max-height:90vh">
    <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 dark:border-gray-800 shrink-0">
      <h3 class="text-sm font-bold text-gray-900 dark:text-white">Preview Email HTML</h3>
      <button type="button" onclick="document.getElementById('step-preview-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="p-4 overflow-auto bg-gray-100 dark:bg-gray-950">
      <iframe id="step-preview-frame" sandbox="allow-same-origin" class="w-full bg-white rounded-lg border border-gray-200 dark:border-gray-700" style="height:65vh"></iframe>
    </div>
  </div>
</div>

<script>window.__STEP_DATA = <?= json_encode($steps) ?>;</script>
<script>
(function () {
    var modal      = document.getElementById('modal-step');
    var htmlToggle = document.getElementById('step-html-toggle');
    var body       = document.getElementById('step-body');
    var bodyHint   = document.getElementById('step-body-hint');
    var htmlHint   = document.getElementById('step-html-hint');
    var previewBtn = document.getElementById('step-preview-btn');

    function applyHtmlMode() {
        var on = htmlToggle.checked;
        previewBtn.classList.toggle('hidden', !on);
        htmlHint.classList.toggle('hidden', !on);
        bodyHint.classList.toggle('hidden', on);
        body.classList.toggle('font-mono', on);
        body.classList.toggle('text-xs', on);
        body.rows = on ? 14 : 6;
        body.placeholder = on
            ? '<!DOCTYPE html><html><body> ... template email kamu, pakai {{name}} untuk personalisasi ... </body></html>'
            : '';
    }

    htmlToggle.addEventListener('change', applyHtmlMode);

    previewBtn.addEventListener('click', function () {
        var html = (body.value || '')
            .replace(/\{\{name\}\}/g, 'Nama Pelanggan')
            .replace(/\{\{sequence_name\}\}/g, 'Nama Sequence')
            .replace(/\{\{trigger_ref\}\}/g, '');
        if (html.trim() === '') html = '<p style="font-family:sans-serif;color:#94a3b8;padding:24px">Body masih kosong.</p>';
        // Tampilkan modal dulu, baru tulis ke iframe (render lebih andal daripada srcdoc saat iframe sempat display:none).
        document.getElementById('step-preview-modal').classList.remove('hidden');
        var frame = document.getElementById('step-preview-frame');
        try {
            var doc = frame.contentDocument || frame.contentWindow.document;
            doc.open(); doc.write(html); doc.close();
        } catch (e) {
            frame.srcdoc = html;
        }
    });

    window.openStep = function () {
        document.getElementById('step-id').value = '';
        document.getElementById('step-delay').value = 0;
        document.getElementById('step-subject').value = '';
        body.value = '';
        document.getElementById('step-status').value = 'active';
        htmlToggle.checked = false;
        applyHtmlMode();
        modal.classList.remove('hidden');
    };

    window.editStep = function (id) {
        var s = (window.__STEP_DATA || []).find(function (x) { return String(x.id) === String(id); });
        if (!s) return;
        document.getElementById('step-id').value = s.id;
        document.getElementById('step-delay').value = s.delay_days;
        document.getElementById('step-subject').value = s.subject;
        body.value = s.body;
        document.getElementById('step-status').value = s.status;
        htmlToggle.checked = (s.body_format === 'html');
        applyHtmlMode();
        modal.classList.remove('hidden');
    };
})();
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

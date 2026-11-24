<?php
// ════════════════════════════════════════════════════════════════
// Funnel Gate — Editor: konfigurasi form + builder pertanyaan (skor).
// Route: /admin/funnel-gate/edit?id=. Satu POST → save-funnel-gate.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pdo = getDB();
$id  = (int) ($_GET['id'] ?? 0);

$form   = null;
$fields = [];
if ($id > 0) {
    try {
        $st = $pdo->prepare('SELECT * FROM funnel_gate_forms WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $form = $st->fetch();
        if (!$form) { flash('error', 'Form tidak ditemukan.', 'error'); redirect('/admin/funnel-gate'); }
        $fs = $pdo->prepare('SELECT * FROM funnel_gate_fields WHERE form_id = ? ORDER BY sort_order ASC');
        $fs->execute([$id]);
        $fields = $fs->fetchAll();
    } catch (Exception $e) {
        error_log('Funnel Gate edit load error: ' . $e->getMessage());
        flash('error', 'Gagal memuat form.', 'error');
        redirect('/admin/funnel-gate');
    }
}

// ─── Pecah field jadi: config kontak + daftar pertanyaan (JS) ─
$nameLabel = 'Nama Lengkap';
$waLabel   = 'Nomor WhatsApp';
$emailOn   = false; $emailReq = false; $emailLabel = 'Email';
$questions = [];
foreach ($fields as $fl) {
    switch ($fl['field_role']) {
        case 'name':     $nameLabel = $fl['label']; break;
        case 'whatsapp': $waLabel   = $fl['label']; break;
        case 'email':    $emailOn = true; $emailReq = (bool) $fl['is_required']; $emailLabel = $fl['label']; break;
        default:
            $opts = [];
            $ratingMax = 5;
            $decoded = $fl['options'] ? json_decode($fl['options'], true) : null;
            if ($fl['field_type'] === 'rating') {
                if (is_array($decoded) && isset($decoded['max'])) $ratingMax = (int) $decoded['max'];
            } elseif (is_array($decoded)) {
                foreach ($decoded as $o) {
                    if (is_array($o)) $opts[] = ['label' => (string) ($o['label'] ?? ''), 'score' => (float) ($o['score'] ?? 0)];
                }
            }
            $questions[] = [
                'type'        => $fl['field_type'],
                'label'       => $fl['label'],
                'placeholder' => (string) ($fl['placeholder'] ?? ''),
                'help'        => (string) ($fl['help_text'] ?? ''),
                'required'    => (bool) $fl['is_required'],
                'weight'      => (float) $fl['weight'],
                'ratingMax'   => $ratingMax,
                'options'     => $opts,
                'stepCid'     => !empty($fl['step_id']) ? ('s' . (int) $fl['step_id']) : '',
            ];
    }
}

// ─── Steps (mode multi) — untuk init builder ─────────────────
$stepList = [];
if ($id > 0) {
    try {
        $ss = $pdo->prepare('SELECT * FROM funnel_gate_steps WHERE form_id = ? ORDER BY sort_order ASC');
        $ss->execute([$id]);
        foreach ($ss->fetchAll() as $s) {
            $stepList[] = [
                'cid'         => 's' . (int) $s['id'],
                'title'       => (string) $s['title'],
                'description' => (string) ($s['description'] ?? ''),
            ];
        }
    } catch (Exception $e) { error_log('FG edit steps load: ' . $e->getMessage()); }
}

// Setelan mode + wizard (default aman untuk form lama).
$formMode      = (string) ($form['form_mode'] ?? 'single');
$progressType  = (string) ($form['progress_type'] ?? 'bar');
$stepAnimation = (string) ($form['step_animation'] ?? 'slide');
$nextText      = (string) ($form['next_text'] ?? '');
$backText      = (string) ($form['back_text'] ?? '');
$allowBack     = (int) ($form['allow_back'] ?? 1);
$autosaveEnabled = (int) ($form['autosave_enabled'] ?? 1);

// Aturan redirect existing (untuk init builder).
$redirectRules = [];
if ($form && !empty($form['redirect_rules'])) {
    $dec = json_decode((string) $form['redirect_rules'], true);
    if (is_array($dec)) $redirectRules = $dec;
}

$csrf = generateCSRF();
$base = rtrim(APP_URL, '/');
$pageTitle   = $form ? 'Edit Funnel Gate' : 'Buat Funnel Gate';
$topbarTitle = $pageTitle;

$fv = fn(string $k, string $d = '') => e((string) ($form[$k] ?? $d));
$inputCls = 'w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white';
$labelCls = 'block text-xs font-medium text-gray-500 uppercase mb-1.5';
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<style>
  /* Grid responsif lokal (class utility per-breakpoint tak selalu ter-compile). */
  @media (min-width: 640px) { .fgb-grid4 { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
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
      <h2 class="text-xl font-bold text-gray-900 dark:text-white mt-2 mb-5"><?= $form ? 'Edit Funnel Gate' : 'Buat Funnel Gate Baru' ?></h2>

      <form method="POST" action="<?= e($base) ?>/actions/admin/save-funnel-gate" id="fgForm" class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <input type="hidden" name="fields_json" id="fields_json" value="">
        <input type="hidden" name="redirect_rules_json" id="redirect_rules_json" value="">
        <input type="hidden" name="form_mode" id="form_mode" value="<?= e($formMode === 'multi' ? 'multi' : 'single') ?>">
        <input type="hidden" name="steps_json" id="steps_json" value="">

        <!-- ═══ Kolom kiri: Pengaturan Form ═══ -->
        <div class="lg:col-span-1 space-y-4">

          <!-- Toggle Mode Form -->
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4">
            <label class="<?= $labelCls ?>">Tipe Form</label>
            <div class="grid grid-cols-2 gap-2" id="fg-mode-toggle">
              <button type="button" data-mode="single"
                      class="fg-mode-btn flex items-center gap-2 px-3 py-2.5 rounded-lg border text-sm font-medium transition text-left">
                <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="4" y="4" width="16" height="16" rx="2"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="16" y2="13"/></svg>
                <span>Single Page</span>
              </button>
              <button type="button" data-mode="multi"
                      class="fg-mode-btn flex items-center gap-2 px-3 py-2.5 rounded-lg border text-sm font-medium transition text-left">
                <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="5" width="7" height="14" rx="1.5"/><rect x="14" y="5" width="7" height="14" rx="1.5"/></svg>
                <span>Multi Step</span>
              </button>
            </div>
            <p class="text-xs text-gray-400 mt-2" id="fg-mode-hint"></p>
          </div>

          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 space-y-3">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Pengaturan Form</h3>

            <div>
              <label class="<?= $labelCls ?>">Judul Form</label>
              <input type="text" name="title" id="fg-title" value="<?= $fv('title') ?>" required
                     placeholder="Aplikasi Mentoring High-Ticket" class="<?= $inputCls ?>">
            </div>
            <div>
              <label class="<?= $labelCls ?>">Slug (URL publik)</label>
              <div class="flex items-center gap-1.5">
                <span class="text-xs text-gray-400 font-mono shrink-0">/apply/</span>
                <input type="text" name="slug" id="fg-slug" value="<?= $fv('slug') ?>"
                       placeholder="mentoring" class="<?= $inputCls ?> font-mono">
              </div>
            </div>
            <div>
              <label class="<?= $labelCls ?>">Pengantar (opsional)</label>
              <textarea name="intro" rows="3" placeholder="Jelaskan singkat untuk siapa program ini & apa yang dinilai."
                        class="w-full px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"><?= e((string) ($form['intro'] ?? '')) ?></textarea>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="<?= $labelCls ?>">Teks Tombol</label>
                <input type="text" name="submit_text" value="<?= $fv('submit_text') ?>" placeholder="Kirim Aplikasi" class="<?= $inputCls ?>">
              </div>
              <div>
                <label class="<?= $labelCls ?>">Status</label>
                <select name="status" class="<?= $inputCls ?>">
                  <?php foreach (['draft' => 'Draft', 'published' => 'Published', 'paused' => 'Paused'] as $k => $v): ?>
                  <option value="<?= $k ?>" <?= ($form['status'] ?? 'draft') === $k ? 'selected' : '' ?>><?= $v ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <label class="flex items-center gap-2.5 cursor-pointer pt-1">
              <input type="checkbox" name="score_enabled" value="1" <?= ((int) ($form['score_enabled'] ?? 1)) === 1 ? 'checked' : '' ?>
                     class="w-4 h-4 rounded border-gray-300 text-brand focus:ring-brand">
              <span class="text-sm text-gray-700 dark:text-gray-200">Aktifkan skoring otomatis</span>
            </label>
            <label class="flex items-center gap-2.5 cursor-pointer">
              <input type="checkbox" name="notify_admin" value="1" <?= ((int) ($form['notify_admin'] ?? 1)) === 1 ? 'checked' : '' ?>
                     class="w-4 h-4 rounded border-gray-300 text-brand focus:ring-brand">
              <span class="text-sm text-gray-700 dark:text-gray-200">Notifikasi admin saat ada submission</span>
            </label>

            <div>
              <label class="<?= $labelCls ?>">Pesan Terima Kasih (bila tanpa redirect)</label>
              <textarea name="success_message" rows="2" placeholder="Terima kasih! Aplikasi Anda sedang kami tinjau."
                        class="w-full px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"><?= e((string) ($form['success_message'] ?? '')) ?></textarea>
            </div>
          </div>

          <!-- Kontak (selalu di awal form) -->
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 space-y-3">
            <div class="flex items-center gap-2">
              <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Data Kontak</h3>
              <span class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-500">DI AWAL</span>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 -mt-1">
              Selalu ditampilkan pertama. Nama &amp; WhatsApp wajib — supaya draft yang belum
              terkirim tetap bisa Anda follow-up.
            </p>
            <div>
              <label class="<?= $labelCls ?>">Label Nama</label>
              <input type="text" name="name_label" value="<?= e($nameLabel) ?>" class="<?= $inputCls ?>">
            </div>
            <div>
              <label class="<?= $labelCls ?>">Label WhatsApp</label>
              <input type="text" name="whatsapp_label" value="<?= e($waLabel) ?>" class="<?= $inputCls ?>">
            </div>
            <div class="pt-1 border-t border-gray-100 dark:border-gray-800">
              <label class="flex items-center gap-2.5 cursor-pointer pt-2">
                <input type="checkbox" name="email_enabled" id="fg-email-on" value="1" <?= $emailOn ? 'checked' : '' ?>
                       class="w-4 h-4 rounded border-gray-300 text-brand focus:ring-brand">
                <span class="text-sm text-gray-700 dark:text-gray-200">Minta Email juga</span>
              </label>
              <div id="fg-email-cfg" class="mt-2.5 space-y-2.5 <?= $emailOn ? '' : 'hidden' ?>">
                <input type="text" name="email_label" value="<?= e($emailLabel) ?>" placeholder="Email" class="<?= $inputCls ?>">
                <label class="flex items-center gap-2.5 cursor-pointer">
                  <input type="checkbox" name="email_required" value="1" <?= $emailReq ? 'checked' : '' ?>
                         class="w-4 h-4 rounded border-gray-300 text-brand focus:ring-brand">
                  <span class="text-sm text-gray-700 dark:text-gray-200">Email wajib diisi</span>
                </label>
              </div>
            </div>
          </div>
        </div>

        <!-- ═══ Kolom kanan: Builder ═══ -->
        <div class="lg:col-span-2 space-y-4">

          <!-- Panel Steps (mode multi) -->
          <div id="fg-steps-panel" class="hidden bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
            <div class="flex items-center justify-between gap-3 mb-4">
              <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Langkah Wizard (Steps)</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Kontak (Nama/WA/Email) selalu di Step pertama. Seret untuk mengurutkan.</p>
              </div>
              <button type="button" id="fg-add-step"
                      class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition shrink-0">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Tambah Step
              </button>
            </div>
            <div id="fg-steps" class="space-y-2"></div>
          </div>

          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
            <div class="flex items-center justify-between gap-3 mb-4">
              <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Pertanyaan Kualifikasi</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Tiap jawaban bisa diberi bobot skor untuk menilai prospek.</p>
              </div>
              <button type="button" id="fg-add-q"
                      class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition shrink-0">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Tambah Pertanyaan
              </button>
            </div>

            <div id="fg-questions" class="space-y-3"></div>

            <div id="fg-empty-q" class="hidden text-center py-10 text-sm text-gray-400 dark:text-gray-500">
              Belum ada pertanyaan. Klik <strong>Tambah Pertanyaan</strong> untuk mulai.
            </div>
          </div>

          <!-- Multi Step Settings (mode multi) -->
          <div id="fg-multi-settings" class="hidden bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 space-y-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Multi Step Settings</h3>

            <div>
              <label class="<?= $labelCls ?>">Indikator Progress</label>
              <div class="grid grid-cols-2 gap-2 fgb-grid4">
                <?php foreach (['bar' => 'Progress Bar', 'number' => 'Step Number', 'circle' => 'Circle Step', 'none' => 'None'] as $k => $v): ?>
                <label class="flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer text-sm text-gray-700 dark:text-gray-200 hover:border-gray-300 transition">
                  <input type="radio" name="progress_type" value="<?= $k ?>" <?= $progressType === $k ? 'checked' : '' ?> class="text-brand focus:ring-brand">
                  <span><?= $v ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="<?= $labelCls ?>">Animasi Perpindahan</label>
                <select name="step_animation" class="<?= $inputCls ?>">
                  <?php foreach (['slide' => 'Slide (halus)', 'fade' => 'Fade', 'none' => 'Tanpa animasi'] as $k => $v): ?>
                  <option value="<?= $k ?>" <?= $stepAnimation === $k ? 'selected' : '' ?>><?= $v ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="flex items-end">
                <div class="space-y-2 w-full">
                  <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="checkbox" name="allow_back" value="1" <?= $allowBack === 1 ? 'checked' : '' ?> class="w-4 h-4 rounded border-gray-300 text-brand focus:ring-brand">
                    <span class="text-sm text-gray-700 dark:text-gray-200">Izinkan kembali ke step sebelumnya</span>
                  </label>
                  <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="checkbox" name="autosave_enabled" value="1" <?= $autosaveEnabled === 1 ? 'checked' : '' ?> class="w-4 h-4 rounded border-gray-300 text-brand focus:ring-brand">
                    <span class="text-sm text-gray-700 dark:text-gray-200">Simpan progress otomatis</span>
                  </label>
                </div>
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label class="<?= $labelCls ?>">Teks Tombol Next</label>
                <input type="text" name="next_text" value="<?= e($nextText) ?>" placeholder="Selanjutnya" class="<?= $inputCls ?>">
              </div>
              <div>
                <label class="<?= $labelCls ?>">Teks Tombol Back</label>
                <input type="text" name="back_text" value="<?= e($backText) ?>" placeholder="Kembali" class="<?= $inputCls ?>">
              </div>
              <div>
                <label class="<?= $labelCls ?>">Teks Tombol Submit</label>
                <input type="text" value="<?= $fv('submit_text') ?>" placeholder="Kirim" class="<?= $inputCls ?> bg-gray-50 dark:bg-gray-800/50" readonly title="Diatur di 'Teks Tombol' pengaturan form">
              </div>
            </div>
            <p class="text-xs text-gray-400">Teks Submit mengikuti field <strong>Teks Tombol</strong> di panel Pengaturan Form.</p>
          </div>

          <!-- Alur setelah submit: redirect by skor + fallback -->
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Alur Setelah Submit</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 mb-4">
              Arahkan prospek sesuai skornya. Aturan dicek dari atas — yang pertama cocok dipakai.
              Bila tak ada yang cocok, pakai fallback di bawah. Kosongkan semua untuk menampilkan
              pesan terima kasih saja.
            </p>

            <div id="fg-rules" class="space-y-2"></div>
            <button type="button" id="fg-add-rule"
                    class="mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-brand hover:underline">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Tambah aturan skor
            </button>

            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
              <label class="<?= $labelCls ?>">Fallback / Default URL (opsional)</label>
              <input type="text" name="default_redirect_url" value="<?= $fv('default_redirect_url') ?>"
                     placeholder="/checkout  atau  https://…" class="<?= $inputCls ?> font-mono">
              <p class="text-xs text-gray-400 mt-1.5">Boleh path relatif (mis. <code>/products/kelas-vip</code>) atau URL penuh.</p>
            </div>

            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
              <label class="<?= $labelCls ?>">Mailketing List ID saat di-approve (opsional)</label>
              <input type="text" name="mailketing_list_id" value="<?= $fv('mailketing_list_id') ?>"
                     placeholder="mis. 12345" class="<?= $inputCls ?> font-mono">
              <p class="text-xs text-gray-400 mt-1.5">Submission yang Anda tandai <strong>Approved</strong> otomatis masuk list Mailketing ini (butuh email &amp; token Mailketing aktif di Integrations).</p>
            </div>
          </div>

          <div class="flex items-center justify-end gap-3">
            <a href="<?= e($base) ?>/admin/funnel-gate" class="px-4 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition">Batal</a>
            <button type="submit" class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">Simpan Funnel Gate</button>
          </div>
        </div>
      </form>
    </main>
  </div>
</div>

<!-- Template ikon (dipakai builder JS) -->
<script>
window.FG_FIELDS = <?= json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.FG_RULES  = <?= json_encode($redirectRules, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.FG_STEPS  = <?= json_encode($stepList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.FG_MODE   = <?= json_encode($formMode === 'multi' ? 'multi' : 'single') ?>;
</script>
<script>
(function () {
  'use strict';
  var wrap    = document.getElementById('fg-questions');
  var emptyEl = document.getElementById('fg-empty-q');
  var addBtn  = document.getElementById('fg-add-q');
  var hidden  = document.getElementById('fields_json');
  var formEl  = document.getElementById('fgForm');

  var TYPES = [
    ['text', 'Isian singkat'],
    ['textarea', 'Isian panjang'],
    ['select', 'Dropdown pilihan'],
    ['radio', 'Pilihan tunggal'],
    ['checkbox', 'Pilihan ganda'],
    ['number', 'Angka'],
    ['rating', 'Rating (bintang)']
  ];
  var CHOICE = ['select', 'radio', 'checkbox'];
  var inputCls = 'w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white';
  var miniCls  = 'h-8 px-2.5 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white';

  // State: array of field objects. Editing mutates this, then re-render.
  var state = Array.isArray(window.FG_FIELDS) ? window.FG_FIELDS.map(normalize) : [];

  function normalize(f) {
    f = f || {};
    return {
      type: f.type || 'text',
      label: f.label || '',
      placeholder: f.placeholder || '',
      help: f.help || '',
      required: !!f.required,
      weight: (f.weight != null ? f.weight : 0),
      ratingMax: (f.ratingMax ? f.ratingMax : 5),
      options: Array.isArray(f.options) ? f.options.map(function (o) {
        return { label: (o && o.label) || '', score: (o && o.score != null ? o.score : 0) };
      }) : [],
      stepCid: f.stepCid || ''
    };
  }

  // ── Multi-step state (satu scope dgn fields) ──
  var MODE = (window.FG_MODE === 'multi') ? 'multi' : 'single';
  var STEPS = Array.isArray(window.FG_STEPS) ? window.FG_STEPS.map(function (s) {
    return { cid: (s && s.cid) || newCid(), title: (s && s.title) || '', description: (s && s.description) || '' };
  }) : [];
  var cidSeq = 0;
  function newCid() { cidSeq = (cidSeq || 0) + 1; return 'c' + cidSeq + '_' + Math.floor(performance.now()); }

  var stepsPanel = document.getElementById('fg-steps-panel');
  var stepsWrap  = document.getElementById('fg-steps');
  var addStepBtn = document.getElementById('fg-add-step');
  var multiCard  = document.getElementById('fg-multi-settings');
  var modeInput  = document.getElementById('form_mode');
  var modeHint   = document.getElementById('fg-mode-hint');

  function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }

  function stepOptions(sel) {
    return STEPS.map(function (s, i) {
      var lbl = 'Step ' + (i + 1) + (s.title ? ': ' + s.title : '');
      return '<option value="' + esc(s.cid) + '"' + (s.cid === sel ? ' selected' : '') + '>' + esc(lbl) + '</option>';
    }).join('');
  }
  function ensureFieldSteps() {
    if (MODE !== 'multi' || !STEPS.length) return;
    var valid = {}; STEPS.forEach(function (s) { valid[s.cid] = 1; });
    state.forEach(function (f) { if (!f.stepCid || !valid[f.stepCid]) f.stepCid = STEPS[0].cid; });
  }

  function typeOptions(sel) {
    return TYPES.map(function (t) {
      return '<option value="' + t[0] + '"' + (t[0] === sel ? ' selected' : '') + '>' + t[1] + '</option>';
    }).join('');
  }

  function render() {
    wrap.innerHTML = '';
    emptyEl.classList.toggle('hidden', state.length > 0);
    state.forEach(function (f, i) { wrap.appendChild(card(f, i)); });
    sync();
  }

  function card(f, i) {
    var el = document.createElement('div');
    el.className = 'rounded-xl border border-gray-200 dark:border-gray-700 p-4 bg-gray-50 dark:bg-gray-800';
    var isChoice = CHOICE.indexOf(f.type) !== -1;

    var html = ''
      + '<div class="flex items-center gap-2 mb-3">'
      +   '<span class="w-6 h-6 shrink-0 flex items-center justify-center rounded-md bg-gray-200 dark:bg-gray-700 text-xs font-semibold text-gray-600 dark:text-gray-300">' + (i + 1) + '</span>'
      +   '<select data-k="type" class="' + miniCls + ' flex-1">' + typeOptions(f.type) + '</select>'
      +   '<button type="button" data-act="up" title="Naik" class="w-8 h-8 shrink-0 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-brand transition' + (i === 0 ? ' opacity-30 pointer-events-none' : '') + '"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg></button>'
      +   '<button type="button" data-act="down" title="Turun" class="w-8 h-8 shrink-0 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-brand transition' + (i === state.length - 1 ? ' opacity-30 pointer-events-none' : '') + '"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg></button>'
      +   '<button type="button" data-act="del" title="Hapus" class="w-8 h-8 shrink-0 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-700 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg></button>'
      + '</div>'
      + '<input data-k="label" type="text" value="' + esc(f.label) + '" placeholder="Tulis pertanyaan…" class="' + inputCls + ' mb-2">'
      + '<div class="grid grid-cols-2 gap-2 mb-2">'
      +   '<input data-k="placeholder" type="text" value="' + esc(f.placeholder) + '" placeholder="Placeholder (opsional)" class="' + inputCls + '">'
      +   '<input data-k="help" type="text" value="' + esc(f.help) + '" placeholder="Teks bantuan (opsional)" class="' + inputCls + '">'
      + '</div>';

    // Bagian spesifik per tipe
    if (isChoice) {
      html += '<div data-opts class="space-y-2 mb-2"></div>'
        + '<button type="button" data-act="addopt" class="text-xs font-medium text-brand hover:underline">+ Tambah opsi</button>';
    } else if (f.type === 'number') {
      html += '<div class="flex items-center gap-2"><label class="text-xs text-gray-500">Skor = nilai ×</label>'
        + '<input data-k="weight" type="number" step="0.1" value="' + esc(f.weight) + '" class="' + miniCls + ' w-24"></div>';
    } else if (f.type === 'rating') {
      html += '<div class="flex items-center gap-4 flex-wrap">'
        + '<div class="flex items-center gap-2"><label class="text-xs text-gray-500">Jumlah bintang</label>'
        + '<input data-k="ratingMax" type="number" min="2" max="10" value="' + esc(f.ratingMax) + '" class="' + miniCls + ' w-20"></div>'
        + '<div class="flex items-center gap-2"><label class="text-xs text-gray-500">Skor = bintang ×</label>'
        + '<input data-k="weight" type="number" step="0.1" value="' + esc(f.weight) + '" class="' + miniCls + ' w-24"></div></div>';
    }

    html += '<div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between gap-3 flex-wrap">'
      + '<label class="flex items-center gap-2 cursor-pointer">'
      +   '<input data-k="required" type="checkbox"' + (f.required ? ' checked' : '') + ' class="w-4 h-4 rounded border-gray-300 text-brand focus:ring-brand">'
      +   '<span class="text-sm text-gray-600 dark:text-gray-300">Wajib diisi</span></label>';
    if (MODE === 'multi' && STEPS.length) {
      html += '<div class="flex items-center gap-2"><label class="text-xs text-gray-500 shrink-0">Step:</label>'
        + '<select data-k="stepCid" class="' + miniCls + '">' + stepOptions(f.stepCid) + '</select></div>';
    }
    html += '</div>';

    el.innerHTML = html;

    // Render opsi (choice)
    if (isChoice) renderOpts(el, f, i);

    // Bind field-level inputs
    el.querySelectorAll('[data-k]').forEach(function (inp) {
      var k = inp.getAttribute('data-k');
      if (k === 'type' || inp.closest('[data-opt]')) return; // ditangani terpisah
      var evt = (inp.type === 'checkbox') ? 'change' : 'input';
      inp.addEventListener(evt, function () {
        f[k] = (inp.type === 'checkbox') ? inp.checked : inp.value;
        sync();
      });
    });

    // Type change → re-render kartu ini
    el.querySelector('[data-k="type"]').addEventListener('change', function () {
      f.type = this.value;
      if (CHOICE.indexOf(f.type) !== -1 && (!f.options || !f.options.length)) {
        f.options = [{ label: '', score: 0 }, { label: '', score: 0 }];
      }
      render();
    });

    // Aksi kartu
    el.querySelector('[data-act="up"]').addEventListener('click', function () { if (i > 0) { swap(i, i - 1); } });
    el.querySelector('[data-act="down"]').addEventListener('click', function () { if (i < state.length - 1) { swap(i, i + 1); } });
    el.querySelector('[data-act="del"]').addEventListener('click', function () { state.splice(i, 1); render(); });
    var addOptBtn = el.querySelector('[data-act="addopt"]');
    if (addOptBtn) addOptBtn.addEventListener('click', function () { f.options.push({ label: '', score: 0 }); render(); });

    return el;
  }

  function renderOpts(el, f, i) {
    var box = el.querySelector('[data-opts]');
    box.innerHTML = '';
    f.options.forEach(function (o, oi) {
      var row = document.createElement('div');
      row.setAttribute('data-opt', '1');
      row.className = 'flex items-center gap-2';
      row.innerHTML = ''
        + '<input data-o="label" type="text" value="' + esc(o.label) + '" placeholder="Opsi ' + (oi + 1) + '" class="' + inputCls + ' flex-1">'
        + '<div class="flex items-center gap-1 shrink-0"><span class="text-xs text-gray-400">skor</span>'
        + '<input data-o="score" type="number" step="0.1" value="' + esc(o.score) + '" class="' + miniCls + ' w-16"></div>'
        + '<button type="button" data-o="del" class="w-8 h-8 shrink-0 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-700 text-gray-400 hover:text-red-500 transition"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
      row.querySelector('[data-o="label"]').addEventListener('input', function () { o.label = this.value; sync(); });
      row.querySelector('[data-o="score"]').addEventListener('input', function () { o.score = this.value; sync(); });
      row.querySelector('[data-o="del"]').addEventListener('click', function () { f.options.splice(oi, 1); render(); });
      box.appendChild(row);
    });
  }

  function swap(a, b) { var t = state[a]; state[a] = state[b]; state[b] = t; render(); }

  function sync() {
    // Bersihkan sebelum serialize: number → float
    var out = state.map(function (f) {
      var c = {
        type: f.type, label: f.label, placeholder: f.placeholder,
        help: f.help, required: !!f.required
      };
      if (MODE === 'multi') c.stepCid = f.stepCid || '';
      if (CHOICE.indexOf(f.type) !== -1) {
        c.options = (f.options || []).map(function (o) { return { label: o.label, score: parseFloat(o.score) || 0 }; });
      } else if (f.type === 'number') {
        c.weight = parseFloat(f.weight) || 0;
      } else if (f.type === 'rating') {
        c.weight = parseFloat(f.weight) || 0;
        c.ratingMax = parseInt(f.ratingMax, 10) || 5;
      }
      return c;
    });
    hidden.value = JSON.stringify(out);
  }

  addBtn.addEventListener('click', function () {
    state.push(normalize({ type: 'text', label: '' }));
    render();
  });

  // Sync sekali lagi tepat sebelum submit (jaga-jaga).
  formEl.addEventListener('submit', sync);

  // Email config toggle
  var emailOn = document.getElementById('fg-email-on');
  var emailCfg = document.getElementById('fg-email-cfg');
  if (emailOn && emailCfg) {
    emailOn.addEventListener('change', function () { emailCfg.classList.toggle('hidden', !this.checked); });
  }

  // Slug auto dari judul (hanya bila slug masih kosong / belum disentuh)
  var titleEl = document.getElementById('fg-title');
  var slugEl  = document.getElementById('fg-slug');
  if (titleEl && slugEl) {
    if (slugEl.value.trim() !== '') slugEl.dataset.touched = '1';
    titleEl.addEventListener('input', function () {
      if (!slugEl.dataset.touched) slugEl.value = this.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    });
    slugEl.addEventListener('input', function () { slugEl.dataset.touched = '1'; });
  }

  // ═══ Steps management ═══
  var dragIdx = null;
  function syncSteps() {
    var sj = document.getElementById('steps_json');
    if (sj) sj.value = JSON.stringify(STEPS.map(function (s) { return { cid: s.cid, title: s.title, description: s.description }; }));
  }
  function refreshFieldStepSelects() {
    wrap.querySelectorAll('select[data-k="stepCid"]').forEach(function (sel) { var cur = sel.value; sel.innerHTML = stepOptions(cur); });
  }
  function moveStep(a, b) { var t = STEPS[a]; STEPS.splice(a, 1); STEPS.splice(b, 0, t); renderSteps(); render(); }
  function addStep() {
    STEPS.push({ cid: newCid(), title: 'Langkah ' + (STEPS.length + 1), description: '' });
    ensureFieldSteps(); renderSteps(); render();
  }
  function dupStep(i) {
    var src = STEPS[i], nc = newCid();
    STEPS.splice(i + 1, 0, { cid: nc, title: (src.title || 'Langkah') + ' (copy)', description: src.description });
    var clones = [];
    state.forEach(function (f) { if (f.stepCid === src.cid) { var c = JSON.parse(JSON.stringify(f)); c.stepCid = nc; clones.push(c); } });
    state = state.concat(clones);
    renderSteps(); render();
  }
  function delStep(i) {
    if (STEPS.length <= 1) return;
    var gone = STEPS[i].cid; STEPS.splice(i, 1);
    state.forEach(function (f) { if (f.stepCid === gone) f.stepCid = STEPS[0].cid; });
    renderSteps(); render();
  }
  function renderSteps() {
    if (!stepsWrap) return;
    stepsWrap.innerHTML = '';
    STEPS.forEach(function (s, i) {
      var row = document.createElement('div');
      row.className = 'fg-step-row flex items-start gap-2 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800';
      row.setAttribute('draggable', 'true');
      row.innerHTML = ''
        + '<span class="fg-drag cursor-grab text-gray-300 hover:text-gray-500 shrink-0 pt-1.5" title="Seret untuk urutkan"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg></span>'
        + '<span class="w-6 h-6 shrink-0 flex items-center justify-center rounded-md bg-brand text-white text-xs font-bold mt-1">' + (i + 1) + '</span>'
        + '<div class="flex-1 min-w-0 space-y-1.5">'
        +   '<input data-s="title" type="text" value="' + esc(s.title) + '" placeholder="Nama Step (mis. Informasi Dasar)" class="' + inputCls + '">'
        +   '<input data-s="description" type="text" value="' + esc(s.description) + '" placeholder="Deskripsi (opsional)" class="' + inputCls + '">'
        +   (i === 0 ? '<p class="text-[11px] text-gray-400">Kontak (Nama/WA/Email) otomatis ada di step ini.</p>' : '')
        + '</div>'
        + '<div class="flex items-center gap-1 shrink-0 pt-0.5">'
        +   '<button type="button" data-sact="up" title="Naik" class="w-7 h-7 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-brand transition' + (i === 0 ? ' opacity-30 pointer-events-none' : '') + '"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg></button>'
        +   '<button type="button" data-sact="down" title="Turun" class="w-7 h-7 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-brand transition' + (i === STEPS.length - 1 ? ' opacity-30 pointer-events-none' : '') + '"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg></button>'
        +   '<button type="button" data-sact="dup" title="Duplikat" class="w-7 h-7 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-brand transition"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg></button>'
        +   '<button type="button" data-sact="del" title="Hapus" class="w-7 h-7 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-700 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg></button>'
        + '</div>';
      row.querySelector('[data-s="title"]').addEventListener('input', function () { s.title = this.value; syncSteps(); refreshFieldStepSelects(); });
      row.querySelector('[data-s="description"]').addEventListener('input', function () { s.description = this.value; syncSteps(); });
      row.querySelector('[data-sact="up"]').addEventListener('click', function () { if (i > 0) moveStep(i, i - 1); });
      row.querySelector('[data-sact="down"]').addEventListener('click', function () { if (i < STEPS.length - 1) moveStep(i, i + 1); });
      row.querySelector('[data-sact="dup"]').addEventListener('click', function () { dupStep(i); });
      row.querySelector('[data-sact="del"]').addEventListener('click', function () { delStep(i); });
      row.addEventListener('dragstart', function (e) { dragIdx = i; e.dataTransfer.effectAllowed = 'move'; row.classList.add('opacity-50'); });
      row.addEventListener('dragend', function () { row.classList.remove('opacity-50'); });
      row.addEventListener('dragover', function (e) { e.preventDefault(); });
      row.addEventListener('drop', function (e) { e.preventDefault(); if (dragIdx != null && dragIdx !== i) moveStep(dragIdx, i); dragIdx = null; });
      stepsWrap.appendChild(row);
    });
    syncSteps();
  }
  if (addStepBtn) addStepBtn.addEventListener('click', addStep);

  // ═══ Mode toggle ═══
  var modeBtns = Array.prototype.slice.call(document.querySelectorAll('.fg-mode-btn'));
  function paintMode() {
    modeBtns.forEach(function (b) {
      var on = b.dataset.mode === MODE;
      b.className = 'fg-mode-btn flex items-center gap-2 px-3 py-2.5 rounded-lg border text-sm font-medium transition text-left ' +
        (on ? 'border-brand text-brand' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-gray-300');
    });
    if (modeHint) modeHint.textContent = (MODE === 'multi')
      ? 'Form tampil sebagai wizard beberapa langkah. Kontak selalu di Step 1.'
      : 'Semua field ditampilkan dalam satu halaman.';
  }
  function setMode(m) {
    MODE = (m === 'multi') ? 'multi' : 'single';
    if (modeInput) modeInput.value = MODE;
    if (MODE === 'multi' && !STEPS.length) STEPS.push({ cid: newCid(), title: 'Informasi Dasar', description: '' });
    if (stepsPanel) stepsPanel.classList.toggle('hidden', MODE !== 'multi');
    if (multiCard)  multiCard.classList.toggle('hidden', MODE !== 'multi');
    ensureFieldSteps(); paintMode(); renderSteps(); render();
  }
  modeBtns.forEach(function (b) { b.addEventListener('click', function () { setMode(b.dataset.mode); }); });

  // ═══ Init ═══
  paintMode();
  if (MODE === 'multi') {
    if (!STEPS.length) STEPS.push({ cid: newCid(), title: 'Informasi Dasar', description: '' });
    if (stepsPanel) stepsPanel.classList.remove('hidden');
    if (multiCard)  multiCard.classList.remove('hidden');
    ensureFieldSteps();
  }
  renderSteps();
  render();
})();
</script>
<script>
(function () {
  'use strict';
  var wrap   = document.getElementById('fg-rules');
  var addBtn = document.getElementById('fg-add-rule');
  var hidden = document.getElementById('redirect_rules_json');
  var formEl = document.getElementById('fgForm');
  if (!wrap || !addBtn || !hidden) return;

  var miniCls = 'h-9 px-2.5 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white';
  var state = Array.isArray(window.FG_RULES) ? window.FG_RULES.map(function (r) {
    return {
      min: (r && r.min != null ? r.min : ''),
      max: (r && r.max != null ? r.max : ''),
      url: (r && r.url) || '',
      label: (r && r.label) || ''
    };
  }) : [];

  function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }

  function render() {
    wrap.innerHTML = '';
    state.forEach(function (r, i) {
      var row = document.createElement('div');
      row.className = 'flex flex-wrap items-center gap-2 p-2.5 rounded-lg border border-gray-100 dark:border-gray-800';
      row.innerHTML = ''
        + '<div class="flex items-center gap-1.5"><span class="text-xs text-gray-400">skor</span>'
        +   '<input data-k="min" type="number" step="0.1" value="' + esc(r.min) + '" placeholder="min" class="' + miniCls + ' w-16">'
        +   '<span class="text-xs text-gray-400">–</span>'
        +   '<input data-k="max" type="number" step="0.1" value="' + esc(r.max) + '" placeholder="max" class="' + miniCls + ' w-16"></div>'
        + '<svg class="w-4 h-4 text-gray-300 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>'
        + '<input data-k="url" type="text" value="' + esc(r.url) + '" placeholder="/checkout atau https://…" class="' + miniCls + ' flex-1 font-mono" style="min-width:140px">'
        + '<input data-k="label" type="text" value="' + esc(r.label) + '" placeholder="label (ops.)" class="' + miniCls + ' w-28">'
        + '<button type="button" data-act="del" class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-700 text-gray-400 hover:text-red-500 transition"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
      row.querySelectorAll('[data-k]').forEach(function (inp) {
        inp.addEventListener('input', function () { r[inp.getAttribute('data-k')] = inp.value; sync(); });
      });
      row.querySelector('[data-act="del"]').addEventListener('click', function () { state.splice(i, 1); render(); });
      wrap.appendChild(row);
    });
    sync();
  }

  function sync() {
    hidden.value = JSON.stringify(state.map(function (r) {
      return { min: r.min, max: r.max, url: r.url, label: r.label };
    }));
  }

  addBtn.addEventListener('click', function () { state.push({ min: '', max: '', url: '', label: '' }); render(); });
  if (formEl) formEl.addEventListener('submit', sync);
  render();
})();
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<?php
// Member — Tiket Support: daftar tiket + form buka tiket baru (self-POST PRG).
// Gerbang pembeli: hanya user yang punya akses produk / pernah order lunas.
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

$pageTitle   = 'Tiket Support';
$topbarTitle = 'Tiket Support';

$userId   = (int) $_SESSION['user_id'];
$isBuyer  = ticketIsBuyer($userId);

// ═══ POST: buka tiket baru ═══════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        flash('tiket', 'Token keamanan tidak valid. Muat ulang halaman.', 'error');
        redirect('/bantuan');
    }
    if (!$isBuyer) {
        flash('tiket', 'Fitur tiket hanya untuk pembeli.', 'error');
        redirect('/bantuan');
    }

    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));

    if (mb_strlen($subject) < 3 || mb_strlen($subject) > 200) {
        flash('tiket', 'Subjek harus 3–200 karakter.', 'error');
        redirect('/bantuan');
    }
    if (mb_strlen($message) < 10) {
        flash('tiket', 'Pesan terlalu pendek — jelaskan minimal 10 karakter.', 'error');
        redirect('/bantuan');
    }

    // Rate-limit: maks 5 tiket / jam / user (anti-spam).
    try {
        $rl = getDB()->prepare(
            "SELECT COUNT(*) FROM support_tickets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)"
        );
        $rl->execute([$userId]);
        if ((int) $rl->fetchColumn() >= 5) {
            flash('tiket', 'Kamu membuka terlalu banyak tiket dalam 1 jam terakhir. Coba lagi nanti.', 'error');
            redirect('/bantuan');
        }
    } catch (Throwable $e) { /* tabel belum ada → biarkan lanjut, insert akan gagal & tertangani */ }

    // Lampiran (opsional).
    $attachPath = null;
    if (!empty($_FILES['attachment']) && (($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        $up = ticketSaveAttachment($_FILES['attachment']);
        if (!$up['ok']) { flash('tiket', $up['error'], 'error'); redirect('/bantuan'); }
        $attachPath = $up['path'];
    }

    try {
        $pdo = getDB();
        // Nomor tiket unik (retry bila bentrok — sangat jarang).
        $ticketNumber = ticketGenerateNumber();
        for ($try = 0; $try < 5; $try++) {
            $chk = $pdo->prepare("SELECT 1 FROM support_tickets WHERE ticket_number = ? LIMIT 1");
            $chk->execute([$ticketNumber]);
            if (!$chk->fetchColumn()) break;
            $ticketNumber = ticketGenerateNumber();
        }

        $pdo->beginTransaction();
        $ins = $pdo->prepare(
            "INSERT INTO support_tickets (ticket_number, user_id, subject, status, last_reply_at, last_reply_by, admin_seen_at)
             VALUES (?, ?, ?, 'open', NOW(), 'user', NULL)"
        );
        $ins->execute([$ticketNumber, $userId, $subject]);
        $ticketId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO support_ticket_replies (ticket_id, sender_type, sender_id, message, attachment_path)
             VALUES (?, 'user', ?, ?, ?)"
        )->execute([$ticketId, $userId, $message, $attachPath]);
        $pdo->commit();

        // Notifikasi admin (fail-soft).
        ticketNotifyAdmin(
            ['id' => $ticketId, 'ticket_number' => $ticketNumber, 'subject' => $subject],
            (string) ($_SESSION['name'] ?? 'Member'),
            $message
        );

        flash('tiket', 'Tiket berhasil dibuka. Tim kami akan segera membalas.', 'success');
        redirect('/bantuan/' . $ticketId);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('open ticket error: ' . $e->getMessage());
        flash('tiket', 'Gagal membuka tiket. Coba lagi.', 'error');
        redirect('/bantuan');
    }
}

// ═══ GET: daftar tiket milik user ════════════════════════════════
$tickets = [];
if ($isBuyer) {
    try {
        $stmt = getDB()->prepare(
            "SELECT t.*,
                    (t.last_reply_by = 'admin' AND (t.user_seen_at IS NULL OR t.user_seen_at < t.last_reply_at)) AS has_unread
             FROM support_tickets t
             WHERE t.user_id = ?
             ORDER BY t.last_reply_at DESC, t.created_at DESC
             LIMIT 100"
        );
        $stmt->execute([$userId]);
        $tickets = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('member tickets list error: ' . $e->getMessage());
    }
}
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="max-w-4xl mx-auto">

        <div class="flex items-center justify-between gap-3 mb-5">
          <div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Tiket Support</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Butuh bantuan? Buka tiket dan tim kami akan membalas.</p>
          </div>
          <?php if ($isBuyer): ?>
          <button type="button" id="newTicketToggle"
                  class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white shadow-sm transition-colors"
                  style="background:var(--brand-color)">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Buka Tiket Baru
          </button>
          <?php endif; ?>
        </div>

        <?php if (!$isBuyer): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-10 text-center">
          <div class="w-12 h-12 mx-auto mb-4 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-gray-800">
            <svg class="w-6 h-6 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
          </div>
          <p class="text-sm font-semibold text-gray-900 dark:text-white">Tiket support khusus pembeli</p>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Fitur ini tersedia setelah kamu memiliki produk. Lihat katalog kami untuk memulai.</p>
          <a href="<?= e(APP_URL) ?>/" class="inline-flex items-center gap-2 mt-4 px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background:var(--brand-color)">Lihat Produk</a>
        </div>
        <?php else: ?>

        <!-- Form buka tiket baru (tersembunyi, di-toggle) -->
        <form id="newTicketForm" method="POST" action="<?= e(APP_URL) ?>/bantuan" enctype="multipart/form-data"
              class="hidden bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-5">
          <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">

          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Subjek</label>
              <input type="text" name="subject" required minlength="3" maxlength="200"
                     placeholder="Ringkas masalahmu, mis. 'Tidak bisa akses modul 3'"
                     class="w-full px-3.5 py-2.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] outline-none">
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Pesan</label>
              <textarea name="message" required minlength="10" rows="6"
                        placeholder="Jelaskan detail masalah atau pertanyaanmu…"
                        class="w-full px-3.5 py-2.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] outline-none resize-y"></textarea>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Lampiran <span class="text-gray-400 font-normal">(opsional — JPG/PNG/PDF, maks 2MB)</span></label>
              <input type="file" name="attachment" accept="image/jpeg,image/png,application/pdf"
                     class="block w-full text-sm text-gray-600 dark:text-gray-300 cursor-pointer">
            </div>
          </div>

          <div class="flex items-center gap-2 mt-5">
            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white shadow-sm" style="background:var(--brand-color)">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              Kirim Tiket
            </button>
            <button type="button" id="newTicketCancel" class="px-4 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">Batal</button>
          </div>
        </form>

        <!-- Daftar tiket -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <?php if (empty($tickets)): ?>
          <div class="p-10 text-center">
            <p class="text-sm font-semibold text-gray-900 dark:text-white">Belum ada tiket</p>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Klik "Buka Tiket Baru" untuk mulai bertanya ke tim kami.</p>
          </div>
          <?php else: ?>
          <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($tickets as $t): $m = ticketStatusMeta($t['status']); ?>
            <li>
              <a href="<?= e(APP_URL) ?>/bantuan/<?= (int) $t['id'] ?>" class="flex items-center gap-3 px-5 py-4 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                <div class="min-w-0 flex-1">
                  <div class="flex items-center gap-2">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white truncate"><?= e($t['subject']) ?></p>
                    <?php if ((int) $t['has_unread']): ?>
                    <span class="w-2 h-2 rounded-full shrink-0" style="background:var(--brand-color)" title="Ada balasan baru"></span>
                    <?php endif; ?>
                  </div>
                  <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5 font-mono"><?= e($t['ticket_number']) ?> · <?= e(date('d M Y, H:i', strtotime($t['last_reply_at'] ?: $t['created_at']))) ?></p>
                </div>
                <span class="shrink-0 px-2.5 py-1 rounded-full text-[11px] font-semibold <?= $m['cls'] ?>"><?= e($m['label']) ?></span>
                <svg class="w-4 h-4 text-gray-300 dark:text-gray-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
              </a>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>

        <?php endif; ?>
      </div>

    </main>
  </div>
</div>

<script>
(function () {
  var toggle = document.getElementById('newTicketToggle');
  var form   = document.getElementById('newTicketForm');
  var cancel = document.getElementById('newTicketCancel');
  if (toggle && form) {
    toggle.addEventListener('click', function () {
      form.classList.toggle('hidden');
      if (!form.classList.contains('hidden')) {
        var s = form.querySelector('[name="subject"]'); if (s) s.focus();
        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    });
  }
  if (cancel && form) cancel.addEventListener('click', function () { form.classList.add('hidden'); });
})();
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

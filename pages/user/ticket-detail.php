<?php
// Member — detail tiket (thread) + balas + tutup (self-POST PRG).
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

$userId   = (int) $_SESSION['user_id'];
$ticketId = (int) ($_GET['id'] ?? 0);

$pdo = getDB();

// Muat tiket milik user ini saja.
$ticket = null;
try {
    $st = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1");
    $st->execute([$ticketId, $userId]);
    $ticket = $st->fetch();
} catch (Throwable $e) { error_log('member ticket load: ' . $e->getMessage()); }

if (!$ticket) {
    flash('tiket', 'Tiket tidak ditemukan.', 'error');
    redirect('/bantuan');
}

// ═══ POST ════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        flash('tiket', 'Token keamanan tidak valid.', 'error');
        redirect('/bantuan/' . $ticketId);
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'close') {
        try {
            $pdo->prepare("UPDATE support_tickets SET status = 'closed' WHERE id = ? AND user_id = ?")
                ->execute([$ticketId, $userId]);
            flash('tiket', 'Tiket ditutup. Terima kasih!', 'success');
        } catch (Throwable $e) { error_log('member close ticket: ' . $e->getMessage()); }
        redirect('/bantuan/' . $ticketId);
    }

    if ($action === 'reply') {
        $message = trim((string) ($_POST['message'] ?? ''));
        if (mb_strlen($message) < 2) {
            flash('tiket', 'Pesan balasan terlalu pendek.', 'error');
            redirect('/bantuan/' . $ticketId);
        }
        $attachPath = null;
        if (!empty($_FILES['attachment']) && (($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
            $up = ticketSaveAttachment($_FILES['attachment']);
            if (!$up['ok']) { flash('tiket', $up['error'], 'error'); redirect('/bantuan/' . $ticketId); }
            $attachPath = $up['path'];
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO support_ticket_replies (ticket_id, sender_type, sender_id, message, attachment_path)
                 VALUES (?, 'user', ?, ?, ?)"
            )->execute([$ticketId, $userId, $message, $attachPath]);
            // Balasan member → tiket kembali menunggu jawaban admin (belum dilihat admin).
            $pdo->prepare(
                "UPDATE support_tickets
                 SET status = 'open', last_reply_at = NOW(), last_reply_by = 'user', admin_seen_at = NULL
                 WHERE id = ?"
            )->execute([$ticketId]);
            $pdo->commit();

            ticketNotifyAdmin(
                ['id' => $ticketId, 'ticket_number' => $ticket['ticket_number'], 'subject' => $ticket['subject']],
                (string) ($_SESSION['name'] ?? 'Member'),
                $message
            );
            flash('tiket', 'Balasan terkirim.', 'success');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('member reply ticket: ' . $e->getMessage());
            flash('tiket', 'Gagal mengirim balasan.', 'error');
        }
        redirect('/bantuan/' . $ticketId);
    }
    redirect('/bantuan/' . $ticketId);
}

// Tandai balasan admin sudah dilihat member.
try {
    $pdo->prepare("UPDATE support_tickets SET user_seen_at = NOW() WHERE id = ?")->execute([$ticketId]);
} catch (Throwable $e) { /* abaikan */ }

// Muat thread.
$replies = [];
try {
    $rs = $pdo->prepare(
        "SELECT r.*, u.name AS sender_name
         FROM support_ticket_replies r
         LEFT JOIN users u ON u.id = r.sender_id
         WHERE r.ticket_id = ?
         ORDER BY r.created_at ASC, r.id ASC"
    );
    $rs->execute([$ticketId]);
    $replies = $rs->fetchAll();
} catch (Throwable $e) { error_log('member thread load: ' . $e->getMessage()); }

$meta        = ticketStatusMeta($ticket['status']);
$isClosed    = $ticket['status'] === 'closed';
$pageTitle   = 'Tiket ' . $ticket['ticket_number'];
$topbarTitle = 'Tiket Support';
$appName     = getSetting('app_name', 'STIFIn Mulia');
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="max-w-3xl mx-auto">

        <a href="<?= e(APP_URL) ?>/bantuan" class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white mb-4">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
          Semua Tiket
        </a>

        <!-- Header tiket -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-5">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <h2 class="text-lg font-bold text-gray-900 dark:text-white break-words"><?= e($ticket['subject']) ?></h2>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 font-mono"><?= e($ticket['ticket_number']) ?> · dibuka <?= e(date('d M Y, H:i', strtotime($ticket['created_at']))) ?></p>
            </div>
            <span class="shrink-0 px-2.5 py-1 rounded-full text-[11px] font-semibold <?= $meta['cls'] ?>"><?= e($meta['label']) ?></span>
          </div>
        </div>

        <!-- Thread -->
        <div class="space-y-4 mb-5">
          <?php foreach ($replies as $r):
            $isAdmin = $r['sender_type'] === 'admin';
            $who     = $isAdmin ? ($appName . ' · Tim Support') : ($r['sender_name'] ?: 'Kamu');
          ?>
          <div class="flex <?= $isAdmin ? 'justify-start' : 'justify-end' ?>">
            <div class="max-w-lg <?= $isAdmin ? '' : 'items-end' ?>">
              <div class="flex items-center gap-2 mb-1 <?= $isAdmin ? '' : 'justify-end' ?>">
                <span class="text-xs font-semibold <?= $isAdmin ? 'text-[var(--brand-color)]' : 'text-gray-700 dark:text-gray-300' ?>"><?= e($who) ?></span>
                <span class="text-[11px] text-gray-400"><?= e(date('d M Y, H:i', strtotime($r['created_at']))) ?></span>
              </div>
              <div class="rounded-xl px-4 py-3 text-sm leading-relaxed whitespace-pre-wrap break-words <?= $isAdmin
                    ? 'bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-gray-800 dark:text-gray-200'
                    : 'text-white' ?>"
                   <?= $isAdmin ? '' : 'style="background:var(--brand-color)"' ?>><?= e($r['message']) ?></div>
              <?php if (!empty($r['attachment_path'])):
                $ext = strtolower(pathinfo($r['attachment_path'], PATHINFO_EXTENSION)); ?>
              <a href="<?= e(APP_URL) ?>/actions/user/ticket-file?r=<?= (int) $r['id'] ?>" target="_blank" rel="noopener"
                 class="inline-flex items-center gap-1.5 mt-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                Lampiran (<?= e(strtoupper($ext)) ?>)
              </a>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Balas / Tutup -->
        <?php if ($isClosed): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 text-center">
          <p class="text-sm text-gray-500 dark:text-gray-400">Tiket ini sudah ditutup. Masih ada kendala? Balas di bawah untuk membukanya kembali.</p>
        </div>
        <div class="mt-4"></div>
        <?php endif; ?>

        <form id="replyForm" method="POST" action="<?= e(APP_URL) ?>/bantuan/<?= $ticketId ?>" enctype="multipart/form-data"
              class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
          <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
          <input type="hidden" name="action" value="reply">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5"><?= $isClosed ? 'Buka kembali & balas' : 'Balas tiket' ?></label>
          <textarea name="message" required minlength="2" rows="4" placeholder="Tulis balasanmu…"
                    class="w-full px-3.5 py-2.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] outline-none resize-y"></textarea>
          <div class="mt-3">
            <input type="file" name="attachment" accept="image/jpeg,image/png,application/pdf"
                   class="block w-full text-sm text-gray-600 dark:text-gray-300 cursor-pointer">
            <p class="text-xs text-gray-400 mt-1">Opsional — JPG/PNG/PDF, maks 2MB.</p>
          </div>
          <div class="flex items-center justify-between gap-2 mt-4">
            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white shadow-sm" style="background:var(--brand-color)">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              Kirim Balasan
            </button>
            <?php if (!$isClosed): ?>
            <button type="button" onclick="confirmAndSubmit('closeForm', {title:'Tutup Tiket', message:'Tandai tiket ini selesai? Kamu tetap bisa membukanya lagi dengan membalas.', confirmText:'Ya, Tutup', variant:'danger'})"
                    class="px-4 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">
              Tutup Tiket
            </button>
            <?php endif; ?>
          </div>
        </form>

        <?php if (!$isClosed): ?>
        <form id="closeForm" method="POST" action="<?= e(APP_URL) ?>/bantuan/<?= $ticketId ?>" class="hidden">
          <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
          <input type="hidden" name="action" value="close">
        </form>
        <?php endif; ?>

      </div>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

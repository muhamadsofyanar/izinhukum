<?php
// Admin — detail tiket (thread) + balas + tutup/buka (self-POST PRG).
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$ticketId = (int) ($_GET['id'] ?? 0);
$adminId  = (int) ($_SESSION['user_id'] ?? 0);
$pdo      = getDB();

// Muat tiket + data pemilik (untuk notifikasi).
$ticket = null;
try {
    $st = $pdo->prepare(
        "SELECT t.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone
         FROM support_tickets t JOIN users u ON u.id = t.user_id
         WHERE t.id = ? LIMIT 1"
    );
    $st->execute([$ticketId]);
    $ticket = $st->fetch();
} catch (Throwable $e) { error_log('admin ticket load: ' . $e->getMessage()); }

if (!$ticket) {
    flash('tiket', 'Tiket tidak ditemukan.', 'error');
    redirect('/admin/tickets');
}

// ═══ POST ════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        flash('tiket', 'Token keamanan tidak valid.', 'error');
        redirect('/admin/tickets/' . $ticketId);
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'close' || $action === 'reopen') {
        $newStatus = $action === 'close' ? 'closed' : 'open';
        try {
            $pdo->prepare("UPDATE support_tickets SET status = ? WHERE id = ?")->execute([$newStatus, $ticketId]);
            flash('tiket', $action === 'close' ? 'Tiket ditutup.' : 'Tiket dibuka kembali.', 'success');
        } catch (Throwable $e) { error_log('admin close/reopen: ' . $e->getMessage()); }
        redirect('/admin/tickets/' . $ticketId);
    }

    if ($action === 'reply') {
        $message  = trim((string) ($_POST['message'] ?? ''));
        $andClose = ($_POST['and_close'] ?? '') === '1';
        if (mb_strlen($message) < 2) {
            flash('tiket', 'Balasan terlalu pendek.', 'error');
            redirect('/admin/tickets/' . $ticketId);
        }
        $attachPath = null;
        if (!empty($_FILES['attachment']) && (($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
            $up = ticketSaveAttachment($_FILES['attachment']);
            if (!$up['ok']) { flash('tiket', $up['error'], 'error'); redirect('/admin/tickets/' . $ticketId); }
            $attachPath = $up['path'];
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO support_ticket_replies (ticket_id, sender_type, sender_id, message, attachment_path)
                 VALUES (?, 'admin', ?, ?, ?)"
            )->execute([$ticketId, $adminId, $message, $attachPath]);
            // Balasan admin → 'answered' (atau 'closed' bila diminta); member belum melihat.
            $newStatus = $andClose ? 'closed' : 'answered';
            $pdo->prepare(
                "UPDATE support_tickets
                 SET status = ?, last_reply_at = NOW(), last_reply_by = 'admin', admin_seen_at = NOW(), user_seen_at = NULL
                 WHERE id = ?"
            )->execute([$newStatus, $ticketId]);
            $pdo->commit();

            ticketNotifyMember(
                ['id' => $ticketId, 'ticket_number' => $ticket['ticket_number'], 'subject' => $ticket['subject']],
                ['name' => $ticket['user_name'], 'email' => $ticket['user_email'], 'phone' => $ticket['user_phone']],
                $message
            );
            flash('tiket', $andClose ? 'Balasan terkirim & tiket ditutup.' : 'Balasan terkirim.', 'success');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('admin reply ticket: ' . $e->getMessage());
            flash('tiket', 'Gagal mengirim balasan.', 'error');
        }
        redirect('/admin/tickets/' . $ticketId);
    }
    redirect('/admin/tickets/' . $ticketId);
}

// Tandai tiket sudah dilihat admin (badge "BARU" hilang).
try {
    $pdo->prepare("UPDATE support_tickets SET admin_seen_at = NOW() WHERE id = ?")->execute([$ticketId]);
} catch (Throwable $e) { /* abaikan */ }

// Thread.
$replies = [];
try {
    $rs = $pdo->prepare(
        "SELECT r.*, u.name AS sender_name
         FROM support_ticket_replies r
         LEFT JOIN users u ON u.id = r.sender_id
         WHERE r.ticket_id = ? ORDER BY r.created_at ASC, r.id ASC"
    );
    $rs->execute([$ticketId]);
    $replies = $rs->fetchAll();
} catch (Throwable $e) { error_log('admin thread load: ' . $e->getMessage()); }

$meta        = ticketStatusMeta($ticket['status']);
$isClosed    = $ticket['status'] === 'closed';
$pageTitle   = 'Tiket ' . $ticket['ticket_number'];
$topbarTitle = 'Tiket Support';
$appName     = getSetting('app_name', 'STIFIn Mulia');
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="max-w-3xl mx-auto">

        <a href="<?= e(APP_URL) ?>/admin/tickets" class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white mb-4">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
          Semua Tiket
        </a>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-5">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <h2 class="text-lg font-bold text-gray-900 dark:text-white break-words"><?= e($ticket['subject']) ?></h2>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                <span class="font-mono"><?= e($ticket['ticket_number']) ?></span> ·
                <?= e($ticket['user_name']) ?> (<?= e($ticket['user_email']) ?>) ·
                dibuka <?= e(date('d M Y, H:i', strtotime($ticket['created_at']))) ?>
              </p>
            </div>
            <span class="shrink-0 px-2.5 py-1 rounded-full text-[11px] font-semibold <?= $meta['cls'] ?>"><?= e($meta['label']) ?></span>
          </div>
        </div>

        <!-- Thread -->
        <div class="space-y-4 mb-5">
          <?php foreach ($replies as $r):
            $isAdmin = $r['sender_type'] === 'admin';
            $who     = $isAdmin ? ($r['sender_name'] ? $r['sender_name'] . ' · Admin' : 'Admin') : ($r['sender_name'] ?: 'Member');
          ?>
          <div class="flex <?= $isAdmin ? 'justify-end' : 'justify-start' ?>">
            <div class="max-w-lg">
              <div class="flex items-center gap-2 mb-1 <?= $isAdmin ? 'justify-end' : '' ?>">
                <span class="text-xs font-semibold <?= $isAdmin ? 'text-[var(--brand-color)]' : 'text-gray-700 dark:text-gray-300' ?>"><?= e($who) ?></span>
                <span class="text-[11px] text-gray-400"><?= e(date('d M Y, H:i', strtotime($r['created_at']))) ?></span>
              </div>
              <div class="rounded-xl px-4 py-3 text-sm leading-relaxed whitespace-pre-wrap break-words <?= $isAdmin
                    ? 'text-white' : 'bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-gray-800 dark:text-gray-200' ?>"
                   <?= $isAdmin ? 'style="background:var(--brand-color)"' : '' ?>><?= e($r['message']) ?></div>
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

        <!-- Balas -->
        <form id="replyForm" method="POST" action="<?= e(APP_URL) ?>/admin/tickets/<?= $ticketId ?>" enctype="multipart/form-data"
              class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
          <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
          <input type="hidden" name="action" value="reply">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Balas ke <?= e($ticket['user_name']) ?></label>
          <textarea name="message" required minlength="2" rows="5" placeholder="Tulis balasan…"
                    class="w-full px-3.5 py-2.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] outline-none resize-y"></textarea>
          <div class="mt-3 flex flex-wrap items-center gap-4">
            <input type="file" name="attachment" accept="image/jpeg,image/png,application/pdf"
                   class="block text-sm text-gray-600 dark:text-gray-300 cursor-pointer">
            <label class="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 cursor-pointer select-none">
              <input type="checkbox" name="and_close" value="1" class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-[var(--brand-color)] focus:ring-[var(--brand-color)]">
              Tutup tiket setelah balas
            </label>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-4">
            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white shadow-sm" style="background:var(--brand-color)">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              Kirim Balasan
            </button>
            <?php if ($isClosed): ?>
            <button type="button" onclick="confirmAndSubmit('reopenForm', {title:'Buka Kembali', message:'Buka kembali tiket ini?', confirmText:'Ya, Buka', variant:'info'})"
                    class="px-4 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">Buka Kembali</button>
            <?php else: ?>
            <button type="button" onclick="confirmAndSubmit('closeForm', {title:'Tutup Tiket', message:'Tutup tiket ini tanpa balasan?', confirmText:'Ya, Tutup', variant:'danger'})"
                    class="px-4 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">Tutup Tiket</button>
            <?php endif; ?>
          </div>
        </form>

        <form id="closeForm" method="POST" action="<?= e(APP_URL) ?>/admin/tickets/<?= $ticketId ?>" class="hidden">
          <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
          <input type="hidden" name="action" value="close">
        </form>
        <form id="reopenForm" method="POST" action="<?= e(APP_URL) ?>/admin/tickets/<?= $ticketId ?>" class="hidden">
          <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
          <input type="hidden" name="action" value="reopen">
        </form>

      </div>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

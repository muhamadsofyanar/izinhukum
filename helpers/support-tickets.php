<?php
// Helper Tiket Support — dipakai halaman member & admin serta action terkait.
// Semua fail-soft: notifikasi tak pernah memblokir alur utama.

if (!defined('TICKET_ATTACH_DIR')) {
    // Di luar akses HTTP langsung (storage/ punya .htaccess deny-all).
    define('TICKET_ATTACH_DIR', __DIR__ . '/../storage/ticket-attachments');
}

/**
 * Gerbang pembeli: hanya user yang punya akses produk (member_access) ATAU
 * pernah punya order lunas yang boleh membuka tiket. Fail-soft → false.
 */
function ticketIsBuyer(int $userId): bool
{
    if ($userId <= 0) return false;
    try {
        $pdo = getDB();
        $s = $pdo->prepare("SELECT 1 FROM member_access WHERE user_id = ? LIMIT 1");
        $s->execute([$userId]);
        if ($s->fetchColumn()) return true;
        $s = $pdo->prepare("SELECT 1 FROM orders WHERE user_id = ? AND status = 'paid' LIMIT 1");
        $s->execute([$userId]);
        return (bool) $s->fetchColumn();
    } catch (Throwable $e) {
        error_log('ticketIsBuyer: ' . $e->getMessage());
        return false;
    }
}

/** Nomor tiket mudah dibaca & cukup unik (dijamin UNIQUE oleh kolom). */
function ticketGenerateNumber(): string
{
    return 'TKT-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

/** Ringkas pesan untuk badan notifikasi (hindari kirim ratusan baris ke WA/Telegram). */
function ticketExcerpt(string $message, int $len = 300): string
{
    $t = trim(preg_replace('/\s+/', ' ', $message));
    return mb_strlen($t) > $len ? mb_substr($t, 0, $len) . '…' : $t;
}

/**
 * Validasi + simpan lampiran tiket. Tanpa lampiran = sah (path null).
 * @return array{ok:bool, path?:?string, error?:string} path relatif thd root aplikasi.
 */
function ticketSaveAttachment(array $file): array
{
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'path' => null];
    if ($err !== UPLOAD_ERR_OK)      return ['ok' => false, 'error' => 'Gagal mengunggah lampiran.'];
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) return ['ok' => false, 'error' => 'Ukuran lampiran maksimal 2MB.'];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!isset($allowed[$mime])) return ['ok' => false, 'error' => 'Lampiran harus JPG, PNG, atau PDF.'];

    $dir = TICKET_ATTACH_DIR;
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $ext  = $allowed[$mime];
    $name = 't_' . date('ymd') . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Gagal menyimpan lampiran di server.'];
    }
    return ['ok' => true, 'path' => 'storage/ticket-attachments/' . $name];
}

/** Notifikasi ke ADMIN (Telegram internal + WA admin) saat member kirim tiket/balasan. */
function ticketNotifyAdmin(array $ticket, string $senderName, string $message): void
{
    if (!file_exists(__DIR__ . '/../notifications/NotificationDispatcher.php')) return;
    try {
        require_once __DIR__ . '/../notifications/NotificationDispatcher.php';
        $base     = rtrim((string) (defined('APP_URL') ? APP_URL : getSetting('app_url', '')), '/');
        $adminUrl = $base . '/admin/tickets/' . (int) $ticket['id'];
        NotificationDispatcher::trigger('support_admin_notify', [
            'name'          => $senderName,
            'subject'       => (string) $ticket['subject'],
            'ticket_number' => (string) $ticket['ticket_number'],
            'message'       => ticketExcerpt($message),
            'ticket_url'    => $adminUrl,
        ], '', function_exists('resolveAdminNotifyPhone') ? resolveAdminNotifyPhone() : '');
    } catch (Throwable $e) {
        error_log('ticketNotifyAdmin: ' . $e->getMessage());
    }
}

/** Notifikasi ke MEMBER (email + WA) saat admin membalas tiketnya. */
function ticketNotifyMember(array $ticket, array $user, string $message): void
{
    if (!file_exists(__DIR__ . '/../notifications/NotificationDispatcher.php')) return;
    try {
        require_once __DIR__ . '/../notifications/NotificationDispatcher.php';
        $base      = rtrim((string) (defined('APP_URL') ? APP_URL : getSetting('app_url', '')), '/');
        $memberUrl = $base . '/bantuan/' . (int) $ticket['id'];
        NotificationDispatcher::trigger('support_reply', [
            'name'          => (string) ($user['name'] ?? ''),
            'subject'       => (string) $ticket['subject'],
            'ticket_number' => (string) $ticket['ticket_number'],
            'message'       => ticketExcerpt($message),
            'ticket_url'    => $memberUrl,
        ], (string) ($user['email'] ?? ''), (string) ($user['phone'] ?? ''));
    } catch (Throwable $e) {
        error_log('ticketNotifyMember: ' . $e->getMessage());
    }
}

/** Label status → teks Indonesia + warna badge (dipakai member & admin). */
function ticketStatusMeta(string $status): array
{
    switch ($status) {
        case 'open':     return ['label' => 'Menunggu Jawaban', 'cls' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'];
        case 'answered': return ['label' => 'Dijawab',          'cls' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'];
        case 'closed':   return ['label' => 'Ditutup',          'cls' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'];
        default:         return ['label' => ucfirst($status),   'cls' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'];
    }
}

<?php
// ─── Mailketing: auto add subscriber ke list ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/**
 * Antre 1 subscriber ke list Mailketing. $event: 'order' (checkout) | 'paid'.
 * List diambil dari setting per-event (mailketing_list_order / _paid); kosong =
 * fitur mati utk event itu. Fail-soft — TIDAK boleh menggagalkan checkout/paid.
 */
function mailketing_enqueue_list(string $event, string $email, string $fullName = '', string $phone = ''): void
{
    try {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
        $listKey = $event === 'paid' ? 'mailketing_list_paid' : 'mailketing_list_order';
        $listId  = trim((string) getSetting($listKey, ''));
        if ($listId === '') return;   // list belum dipilih → lewati
        // Pecah nama depan/belakang.
        $first = $last = '';
        $name  = trim(preg_replace('/\s+/', ' ', $fullName));
        if ($name !== '') {
            $parts = explode(' ', $name);
            $first = array_shift($parts);
            $last  = $parts ? implode(' ', $parts) : '';
        }
        getDB()->prepare(
            "INSERT INTO mailketing_queue (event, list_id, email, first_name, last_name, mobile)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $event === 'paid' ? 'paid' : 'order',
            $listId,
            substr($email, 0, 255),
            $first !== '' ? substr($first, 0, 120) : null,
            $last  !== '' ? substr($last, 0, 120) : null,
            $phone !== '' ? substr(trim($phone), 0, 40) : null,
        ]);
    } catch (Throwable $e) {
        error_log('mailketing_enqueue_list: ' . $e->getMessage());
    }
}

/**
 * Enqueue event 'order' (order dibuat/checkout) ke list Mailketing. Ambil data
 * user bila login; jika tidak, pakai fallback (guest checkout). Guard list-kosong
 * di depan agar NOL overhead saat fitur mati. Fail-soft.
 */
function mailketing_enqueue_order_created(int $userId, array $fallback = []): void
{
    try {
        if (trim((string) getSetting('mailketing_list_order', '')) === '') return;   // fitur mati
        $email = trim((string) ($fallback['email'] ?? ''));
        $name  = trim((string) ($fallback['name'] ?? ''));
        $phone = trim((string) ($fallback['phone'] ?? ''));
        if ($userId > 0) {
            $st = getDB()->prepare("SELECT name, email, phone FROM users WHERE id = ? LIMIT 1");
            $st->execute([$userId]);
            if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                $email = $email !== '' ? $email : (string) ($u['email'] ?? '');
                $name  = $name  !== '' ? $name  : (string) ($u['name'] ?? '');
                $phone = $phone !== '' ? $phone : (string) ($u['phone'] ?? '');
            }
        }
        mailketing_enqueue_list('order', $email, $name, $phone);
    } catch (Throwable $e) {
        error_log('mailketing_enqueue_order_created: ' . $e->getMessage());
    }
}

/**
 * Flush antre Mailketing → addsubtolist (dipanggil auto-cron, non-blocking).
 * Retry sampai 5x, housekeeping baris >7 hari, log ke integration_logs.
 */
function processMailketingQueue(int $limit = 40): void
{
    $locked = false;
    try {
        try { getDB()->exec("DELETE FROM mailketing_queue WHERE created_at < NOW() - INTERVAL 7 DAY"); }
        catch (Throwable $e) {}
        $token = getSetting('mailketing_api_token', '') ?: getSetting('mailketing_api_key', '');
        if (trim((string) $token) === '') return;   // token belum diisi
        $pdo = getDB();
        // Auto-cron dan cron server memakai antrean yang sama.
        $lockName = 'mailketing:' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 48);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        $locked = (int) $lock->fetchColumn() === 1;
        if (!$locked) return;
        require_once dirname(__DIR__) . '/helpers/MailketingClient.php';
        $limit = max(1, min(100, $limit));
        $rows = getDB()->query(
            "SELECT * FROM mailketing_queue WHERE status = 'pending' ORDER BY id ASC LIMIT " . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $attempts = (int) $row['attempts'] + 1;
            $res = MailketingClient::addSubscriberToList((string) $row['list_id'], (string) $row['email'], [
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name'  => (string) ($row['last_name'] ?? ''),
                'mobile'     => (string) ($row['mobile'] ?? ''),
            ]);
            $status = $res['ok'] ? 'sent' : ($attempts >= 5 ? 'failed' : 'pending');
            getDB()->prepare("UPDATE mailketing_queue SET status = ?, attempts = ?, sent_at = ? WHERE id = ?")
                ->execute([$status, $attempts, $res['ok'] ? date('Y-m-d H:i:s') : null, (int) $row['id']]);
            try {
                getDB()->prepare(
                    "INSERT INTO integration_logs (provider, action, request, response, status, created_at)
                     VALUES ('mailketing', 'addsubtolist', ?, ?, ?, NOW())"
                )->execute([
                    json_encode(['list_id' => $row['list_id'], 'email' => $row['email'], 'event' => $row['event']]),
                    substr((string) ($res['raw'] ?: $res['message']), 0, 1000),
                    $res['ok'] ? 'success' : 'failed',
                ]);
            } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        error_log('processMailketingQueue: ' . $e->getMessage());
    } finally {
        if ($locked) {
            try {
                $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            } catch (Throwable $e) {
                error_log('Mailketing release lock: ' . $e->getMessage());
            }
        }
    }
}

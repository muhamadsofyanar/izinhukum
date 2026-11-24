<?php
// Hermes hanya menjadwalkan pekerjaan. Pengiriman memakai jalur pemulihan Averion.

/** Email disamarkan sebelum kandidat diberikan kepada sistem eksternal. */
function hermesFollowupMaskEmail(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) return '***';
    $domain = explode('.', $parts[1], 2);
    return mb_substr($parts[0], 0, 1) . '***@' . mb_substr($domain[0], 0, 1)
        . '***' . (isset($domain[1]) ? '.' . $domain[1] : '');
}

function hermesFollowupMaskPhone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);
    return strlen($digits) >= 4 ? '***' . substr($digits, -4) : '***';
}

/** Hanya aktif bila admin mengizinkan Hermes dan pemulihan lewat email. */
function hermesFollowupSendingEnabled(): bool
{
    return hermesEnabled() && hermesManageEnabled()
        && getSetting('abandoned_cart_enabled', '0') === '1'
        && getSetting('abandoned_cart_channel', 'email') === 'email'
        && trim((string) (getSetting('mailketing_api_token', '') ?: getSetting('mailketing_api_key', ''))) !== '';
}

/** Satu pekerjaan per order dan satu request_id per panggilan Hermes. */
function hermesFollowupFindJob(PDO $pdo, string $requestId): ?array
{
    $st = $pdo->prepare('SELECT j.id, j.order_id, j.request_id, j.status, j.created_at, o.order_number
                         FROM hermes_followup_jobs j JOIN orders o ON o.id=j.order_id
                         WHERE j.request_id=? LIMIT 1');
    $st->execute([$requestId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Worker dipanggil dari processAbandonedCarts sebelum pemulihan otomatis biasa. */
function processHermesFollowupJobs(int $limit = 20, ?callable $sender = null): array
{
    $result = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    if (!hermesFollowupSendingEnabled()) return $result;

    $pdo = getDB();
    // Bila proses mati setelah klaim, jangan kirim ulang karena hasil HTTP bisa ambigu.
    $pdo->exec("UPDATE hermes_followup_jobs SET status='failed', last_error='Proses pengiriman terputus. Periksa log sebelum mencoba lagi.', processed_at=NOW()
                WHERE status='processing' AND updated_at < NOW() - INTERVAL 10 MINUTE");
    $ids = $pdo->query("SELECT id FROM hermes_followup_jobs WHERE status='queued' ORDER BY id ASC LIMIT "
        . max(1, min(100, $limit)))->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return $result;

    require_once dirname(__DIR__) . '/notifications/NotificationDispatcher.php';
    $templateSt = $pdo->prepare("SELECT subject,body FROM notification_templates
                                 WHERE event_key='abandoned_cart' AND channel='email' AND is_active=1 LIMIT 1");
    $templateSt->execute();
    $template = $templateSt->fetch(PDO::FETCH_ASSOC);
    if (!$template) return $result;

    foreach ($ids as $id) {
        $id = (int) $id;
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT j.status AS job_status, o.id AS order_id, o.order_number, o.status AS order_status,
                                       o.total, o.recovery_sent_at, u.id AS user_id, u.name AS user_name,
                                       u.email AS user_email, u.email_opt_out
                                FROM hermes_followup_jobs j
                                JOIN orders o ON o.id=j.order_id JOIN users u ON u.id=o.user_id
                                WHERE j.id=? FOR UPDATE');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['job_status'] !== 'queued') { $pdo->rollBack(); continue; }
            $skip = null;
            if ($row['order_status'] === 'paid') $skip = 'skipped_paid';
            elseif ($row['order_status'] !== 'pending') $skip = 'skipped_expired';
            elseif ($row['recovery_sent_at'] !== null) $skip = 'skipped_cooldown';
            elseif ((int) $row['email_opt_out'] === 1 || !filter_var($row['user_email'], FILTER_VALIDATE_EMAIL)) $skip = 'skipped_opt_out';
            else {
                $cool = $pdo->prepare("SELECT 1 FROM abandoned_cart_logs
                                       WHERE user_id=? AND channel='email' AND status='sent'
                                         AND sent_at >= NOW() - INTERVAL 24 HOUR LIMIT 1");
                $cool->execute([(int) $row['user_id']]);
                if ($cool->fetchColumn()) $skip = 'skipped_cooldown';
            }
            if ($skip !== null) {
                $pdo->prepare('UPDATE hermes_followup_jobs SET status=?, processed_at=NOW() WHERE id=?')->execute([$skip, $id]);
                $pdo->commit();
                $result['skipped']++; $result['processed']++;
                continue;
            }
            $claim = $pdo->prepare("UPDATE orders SET recovery_sent_at=NOW()
                                    WHERE id=? AND status='pending' AND recovery_sent_at IS NULL");
            $claim->execute([(int) $row['order_id']]);
            if ($claim->rowCount() !== 1) {
                $pdo->prepare("UPDATE hermes_followup_jobs SET status='skipped_cooldown', processed_at=NOW() WHERE id=?")->execute([$id]);
                $pdo->commit();
                $result['skipped']++; $result['processed']++;
                continue;
            }
            $pdo->prepare("UPDATE hermes_followup_jobs SET status='processing' WHERE id=?")->execute([$id]);
            $pdo->commit();

            // Cek lagi tepat sebelum adapter dipanggil.
            $state = $pdo->prepare('SELECT status FROM orders WHERE id=?');
            $state->execute([(int) $row['order_id']]);
            $current = $state->fetchColumn();
            if ($current !== 'pending') {
                $skip = $current === 'paid' ? 'skipped_paid' : 'skipped_expired';
                $pdo->prepare('UPDATE hermes_followup_jobs SET status=?, processed_at=NOW() WHERE id=?')->execute([$skip, $id]);
                $result['skipped']++; $result['processed']++;
                continue;
            }
            $opt = $pdo->prepare('SELECT email_opt_out FROM users WHERE id=?');
            $opt->execute([(int) $row['user_id']]);
            if ((int) $opt->fetchColumn() === 1) {
                $pdo->prepare("UPDATE hermes_followup_jobs SET status='skipped_opt_out', processed_at=NOW() WHERE id=?")->execute([$id]);
                $result['skipped']++; $result['processed']++;
                continue;
            }
            $product = $pdo->prepare('SELECT product_name FROM order_items WHERE order_id=? AND is_bump=0 ORDER BY id LIMIT 1');
            $product->execute([(int) $row['order_id']]);
            $ph = ['name' => $row['user_name'], 'product_name' => (string) $product->fetchColumn(),
                   'total' => formatRupiah((float) $row['total']), 'order_number' => $row['order_number'],
                   'checkout_url' => rtrim(APP_URL, '/') . '/transactions/' . $row['order_number'],
                   'app_name' => getSetting('app_name', 'STIFIn Mulia')];
            $subject = renderPlaceholders($template['subject'] ?? '', $ph, 'email');
            $body = renderPlaceholders($template['body'] ?? '', $ph, 'email');
            if (function_exists('emailLayout')) $body = emailLayout($subject, $body, 'info');
            $body = sequenceApplyUnsubscribeFooter($body, (string) $row['user_email']);
            $adapter = $sender ? null : NotificationDispatcher::getAdapterPublic('mailketing');
            $res = $sender ? $sender($row['user_email'], $subject, $body)
                           : ($adapter ? $adapter->send($row['user_email'], $subject, $body) : ['success' => false]);
            $ok = !empty($res['success']);
            $status = $ok ? 'sent' : 'failed';
            $pdo->prepare('UPDATE hermes_followup_jobs SET status=?, last_error=?, processed_at=NOW() WHERE id=?')
                ->execute([$status, $ok ? null : 'Provider email belum mengonfirmasi pengiriman.', $id]);
            $pdo->prepare("INSERT INTO abandoned_cart_logs (order_id,user_id,channel,provider,recipient,status,response)
                           VALUES (?,?,'email','mailketing',?,?,?)")
                ->execute([(int) $row['order_id'], (int) $row['user_id'], $row['user_email'], $status, $ok ? 'Hermes follow-up' : 'Pengiriman gagal']);
            $pdo->prepare("INSERT INTO notification_logs (event_key,channel,provider,recipient,status,response,created_at)
                           VALUES ('abandoned_cart','email','mailketing',?,?,?,NOW())")
                ->execute([$row['user_email'], $status, $ok ? 'Hermes follow-up' : 'Pengiriman gagal']);
            $result[$ok ? 'sent' : 'failed']++; $result['processed']++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Hermes follow-up job #' . $id . ': ' . $e->getMessage());
            $pdo->prepare("UPDATE hermes_followup_jobs SET status='failed', last_error='Kesalahan internal.', processed_at=NOW() WHERE id=? AND status IN ('queued','processing')")
                ->execute([$id]);
            $result['failed']++; $result['processed']++;
        }
    }
    return $result;
}

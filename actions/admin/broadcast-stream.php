<?php
/**
 * Broadcast — SSE stream pengiriman per penerima (real-time progress).
 *
 * METHOD : GET (EventSource)
 * URL    : /actions/admin/broadcast-stream?id={campaign_id}
 *
 * Memproses baris `broadcast_recipients` berstatus 'pending' untuk campaign yang
 * sudah ditandai 'sending' oleh send-broadcast.php. Mengirim event SSE progress
 * tiap penerima, jeda 300ms antar kirim untuk menghindari rate-limit provider.
 */

require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('broadcast')) {
    http_response_code(403);
    exit;
}

// Lepas lock session agar request lain (mis. buka halaman) tidak ikut ter-block
// selama stream berjalan lama. Auth sudah selesai dicek di atas.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
ignore_user_abort(true);
@set_time_limit(0);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // wajib untuk Nginx/CloudPanel agar tidak di-buffer

// Bersihkan semua buffer agar event terkirim instan.
while (ob_get_level() > 0) { ob_end_flush(); }

function sse_send(array $data): void {
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush();
    @flush();
}

// ── DEPRECATED (sejak 1.2.73) ──────────────────────────────────
// Pengiriman broadcast kini via broadcast-pump + cron (processBroadcastQueue),
// lock-guarded (GET_LOCK) agar tak dobel-kirim. Endpoint SSE lama ini TIDAK lagi
// mengirim — hanya menyuruh klien basi memuat ulang halaman. Mencegah dua worker
// (stream vs pump/cron) memproses campaign yang sama secara paralel.
sse_send(['error' => 'Metode pengiriman broadcast telah diperbarui. Muat ulang halaman broadcast untuk melanjutkan pengiriman.']);
exit;

$campaignId = (int) ($_GET['id'] ?? 0);
if ($campaignId <= 0) {
    sse_send(['error' => 'ID campaign tidak valid.']);
    exit;
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT * FROM broadcast_campaigns WHERE id = ? LIMIT 1");
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch();

    if (!$campaign) {
        sse_send(['error' => 'Campaign tidak ditemukan.']);
        exit;
    }
    if ($campaign['status'] !== 'sending') {
        sse_send(['error' => 'Campaign tidak dalam status pengiriman. Klik "Kirim" terlebih dahulu.']);
        exit;
    }

    $channel      = $campaign['channel'];
    $providerName = $campaign['provider'];

    // ── Adapter ──
    require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';

    if ($channel === 'whatsapp' && getSetting($providerName . '_enabled', '0') !== '1') {
        $pdo->prepare("UPDATE broadcast_campaigns SET status = 'failed' WHERE id = ?")->execute([$campaignId]);
        sse_send(['error' => 'Provider "' . $providerName . '" sedang dinonaktifkan.']);
        exit;
    }

    $adapter = NotificationDispatcher::getAdapterPublic($providerName);
    if (!$adapter) {
        $pdo->prepare("UPDATE broadcast_campaigns SET status = 'failed' WHERE id = ?")->execute([$campaignId]);
        sse_send(['error' => 'Provider "' . $providerName . '" tidak dikenali / tidak aktif.']);
        exit;
    }

    // ── Worklist: penerima yang masih pending ──
    $rStmt = $pdo->prepare(
        "SELECT br.id, br.recipient, br.user_id, br.lead_id,
                COALESCE(u.name, l.name, '') AS name
         FROM broadcast_recipients br
         LEFT JOIN users u ON u.id = br.user_id
         LEFT JOIN leads l ON l.id = br.lead_id
         WHERE br.campaign_id = ? AND br.status = 'pending'
         ORDER BY br.id ASC"
    );
    $rStmt->execute([$campaignId]);
    $pending = $rStmt->fetchAll();

    $total = count($pending);

    // Tidak ada pending (mis. stream dibuka ulang setelah selesai) → finalisasi.
    if ($total === 0) {
        finalizeBroadcast($pdo, $campaignId, $campaign['name'] ?? '');
        $counts = recipientCounts($pdo, $campaignId);
        sse_send(['done' => true, 'campaign_id' => $campaignId, 'sent' => $counts['sent'], 'failed' => $counts['failed']]);
        exit;
    }

    $subject    = $campaign['subject'] ?? '';
    $bodyFormat = $campaign['body_format'] ?? 'text';

    // Prepared statements untuk update per-recipient + log.
    $updOk   = $pdo->prepare("UPDATE broadcast_recipients SET status = 'sent', sent_at = NOW(), error_message = NULL WHERE id = ?");
    $updFail = $pdo->prepare("UPDATE broadcast_recipients SET status = 'failed', error_message = ? WHERE id = ?");
    $insLog  = $pdo->prepare(
        "INSERT INTO notification_logs (event_key, channel, provider, recipient, status, response, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );

    $i = 0;
    foreach ($pending as $row) {
        if (connection_aborted()) {
            break;
        }
        $i++;

        $recipientField = $row['recipient'];
        $body = str_replace('{{name}}', $row['name'] ?? '', $campaign['body']);
        if ($channel === 'email' && $bodyFormat !== 'html') {
            $body = nl2br($body);
        }

        $ok      = false;
        $logResp = '';
        try {
            $result  = $adapter->send($recipientField, $subject, $body);
            $ok      = !empty($result['success']);
            $logResp = is_string($result['raw_response'] ?? null)
                ? substr($result['raw_response'], 0, 500)
                : (string) ($result['message'] ?? '');
        } catch (Exception $e) {
            $ok      = false;
            $logResp = substr($e->getMessage(), 0, 500);
            error_log('[BROADCAST-STREAM] EXCEPTION ' . $recipientField . ': ' . $e->getMessage());
        }

        if ($ok) {
            $updOk->execute([$row['id']]);
        } else {
            $updFail->execute([substr($logResp, 0, 255), $row['id']]);
        }
        $insLog->execute(['broadcast_' . $campaignId, $channel, $providerName, $recipientField, $ok ? 'sent' : 'failed', $logResp]);

        sse_send([
            'current'   => $i,
            'total'     => $total,
            'recipient' => $recipientField,
            'status'    => $ok ? 'sent' : 'failed',
            'percent'   => (int) round($i / $total * 100),
        ]);

        // Jeda antar kirim untuk menghindari rate-limit provider.
        usleep(300000); // 300ms
    }

    // ── Finalisasi ──
    $counts = finalizeBroadcast($pdo, $campaignId, $campaign['name'] ?? '');
    sse_send(['done' => true, 'campaign_id' => $campaignId, 'sent' => $counts['sent'], 'failed' => $counts['failed']]);
    exit;

} catch (Exception $e) {
    error_log('[BROADCAST-STREAM] error: ' . $e->getMessage());
    sse_send(['error' => 'Terjadi kesalahan sistem saat mengirim.']);
    exit;
}

/** Hitung jumlah recipient per status untuk campaign. */
function recipientCounts(PDO $pdo, int $campaignId): array {
    $stmt = $pdo->prepare(
        "SELECT
            SUM(status = 'sent')   AS sent,
            SUM(status = 'failed') AS failed,
            SUM(status = 'pending') AS pending
         FROM broadcast_recipients WHERE campaign_id = ?"
    );
    $stmt->execute([$campaignId]);
    $r = $stmt->fetch() ?: [];
    return [
        'sent'    => (int) ($r['sent'] ?? 0),
        'failed'  => (int) ($r['failed'] ?? 0),
        'pending' => (int) ($r['pending'] ?? 0),
    ];
}

/** Update total + status campaign berdasarkan hasil di broadcast_recipients. */
function finalizeBroadcast(PDO $pdo, int $campaignId, string $name): array {
    $counts = recipientCounts($pdo, $campaignId);
    // Masih ada pending (mis. koneksi putus) → biarkan 'sending' agar bisa dilanjutkan.
    $status = $counts['pending'] > 0 ? 'sending' : 'sent';
    $pdo->prepare(
        "UPDATE broadcast_campaigns
         SET total_sent = ?, total_failed = ?, status = ?,
             sent_at = CASE WHEN ? = 'sent' THEN NOW() ELSE sent_at END
         WHERE id = ?"
    )->execute([$counts['sent'], $counts['failed'], $status, $status, $campaignId]);

    if ($status === 'sent' && function_exists('logActivity')) {
        logActivity('admin', 'broadcast.sent', 'broadcast_campaigns', $campaignId,
            'Broadcast "' . $name . '": ' . $counts['sent'] . ' terkirim, ' . $counts['failed'] . ' gagal.');
    }
    return $counts;
}

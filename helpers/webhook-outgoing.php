<?php
// ─── Integrasi Webhook Keluar ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/**
 * Antre pengiriman webhook order.paid ke tiap endpoint aktif, per produk yang
 * punya external_program_code (Opsi A). Payload = event/status/email/name/phone/
 * program_code/order_id. timestamp di-set saat kirim (retry-friendly).
 */
function webhookEnqueueOrderPaid(PDO $pdo, array $order, array $ctx): void
{
    try {
        $hooks = $pdo->query("SELECT id FROM outgoing_webhooks WHERE is_active = 1 AND event = 'order.paid'")->fetchAll(PDO::FETCH_COLUMN);
        if (!$hooks) return;

        $user  = $ctx['user'] ?? [];
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '') return; // tanpa email → tak bisa dipetakan di app tujuan
        $name  = (string) ($user['name'] ?? '');
        $phone = (string) ($user['phone'] ?? '');
        $orderNo = (string) ($order['order_number'] ?? '');

        // Produk pada order yang punya kode program eksternal.
        $pids = [];
        foreach (($ctx['items'] ?? []) as $it) { $pid = (int) ($it['product_id'] ?? 0); if ($pid > 0) $pids[$pid] = 1; }
        if (!$pids) return;
        $in = implode(',', array_map('intval', array_keys($pids)));
        $codes = $pdo->query("SELECT id, external_program_code FROM products WHERE id IN ($in) AND external_program_code IS NOT NULL AND external_program_code <> ''")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!$codes) return; // tak ada produk termapping → tak kirim apa-apa

        $ins = $pdo->prepare(
            "INSERT INTO webhook_deliveries (webhook_id, event, payload, status, order_number, next_attempt_at, created_at)
             VALUES (?, 'order.paid', ?, 'pending', ?, NOW(), NOW())"
        );
        foreach ($hooks as $hid) {
            foreach ($codes as $pid => $code) {
                $payload = json_encode([
                    'event'        => 'order.paid',
                    'status'       => 'active',
                    'email'        => $email,
                    'name'         => $name,
                    'phone'        => $phone,
                    'program_code' => (string) $code,
                    'order_id'     => $orderNo,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $ins->execute([(int) $hid, $payload, $orderNo]);
            }
        }
    } catch (Throwable $e) {
        error_log('webhookEnqueueOrderPaid: ' . $e->getMessage());
    }
}

/** POST JSON ber-header ke URL. Return [http_code, response_body, ok(bool 2xx)]. */
function webhookHttpPost(string $url, string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return [0, 'cURL: ' . $err, false];
    return [$code, (string) $resp, $code >= 200 && $code < 300];
}

/**
 * Proses antrean webhook (cron). Kirim ber-HMAC (X-Averion-Signature = sha256 dari
 * raw body + secret), timestamp fresh tiap kirim, retry backoff (maks 6x).
 */
function processWebhookQueue(int $limit = 50): array
{
    $out = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'retry' => 0];
    try {
        $pdo = getDB();
        $rows = $pdo->query(
            "SELECT d.id, d.event, d.payload, d.attempts, w.url, w.secret
             FROM webhook_deliveries d JOIN outgoing_webhooks w ON w.id = d.webhook_id
             WHERE d.status = 'pending' AND (d.next_attempt_at IS NULL OR d.next_attempt_at <= NOW())
             ORDER BY d.id ASC LIMIT " . max(1, (int) $limit)
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return $out;

        $maxAttempts = 6;
        $updSent  = $pdo->prepare("UPDATE webhook_deliveries SET status='sent', attempts=attempts+1, http_code=?, response=?, next_attempt_at=NULL WHERE id=?");
        $updRetry = $pdo->prepare("UPDATE webhook_deliveries SET status='pending', attempts=attempts+1, http_code=?, response=?, next_attempt_at=? WHERE id=?");
        $updFail  = $pdo->prepare("UPDATE webhook_deliveries SET status='failed', attempts=attempts+1, http_code=?, response=?, next_attempt_at=NULL WHERE id=?");

        foreach ($rows as $r) {
            $out['processed']++;
            $ts   = time();
            $data = json_decode((string) $r['payload'], true) ?: [];
            $data['timestamp'] = $ts;
            $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $sig  = 'sha256=' . hash_hmac('sha256', $body, (string) $r['secret']);

            [$code, $resp, $okHttp] = webhookHttpPost((string) $r['url'], $body, [
                'Content-Type: application/json',
                'X-Averion-Event: ' . (string) $r['event'],
                'X-Averion-Timestamp: ' . $ts,
                'X-Averion-Signature: ' . $sig,
            ]);
            $respShort = mb_substr((string) $resp, 0, 500);
            $attempts  = (int) $r['attempts'] + 1;

            if ($okHttp) {
                $updSent->execute([$code, $respShort, $r['id']]); $out['sent']++;
            } elseif ($attempts >= $maxAttempts) {
                $updFail->execute([$code, $respShort, $r['id']]); $out['failed']++;
            } else {
                $backoff = min(3600, 60 * (2 ** ($attempts - 1))); // 1,2,4,8,16 menit
                $updRetry->execute([$code, $respShort, date('Y-m-d H:i:s', time() + $backoff), $r['id']]);
                $out['retry']++;
            }
        }
    } catch (Throwable $e) {
        error_log('processWebhookQueue: ' . $e->getMessage());
    }
    return $out;
}

<?php
// ─── Kesehatan Sistem ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/**
 * Catat detak jantung cron — dipanggil di AWAL setiap giliran cron
 * (Auto-Cron maupun cron asli). Dibaca dashboard & /admin/system-health.
 */
function cronHeartbeat(string $source): void
{
    try {
        $now = (string) time();
        setSetting('cron_last_heartbeat', $now, 'system');
        if ($source === 'cron') setSetting('cron_last_external', $now, 'system');
        setSetting('cron_last_source', $source, 'system');
    } catch (Throwable $e) {
        error_log('cronHeartbeat error: ' . $e->getMessage());
    }
}

/**
 * Cegah dua proses cron berjalan bersamaan dalam container.
 * Resource ditahan selama worker berjalan dan dilepas saat proses berhenti.
 */
function acquireContainerCronLock()
{
    $lock = fopen('/data/darmutiara-cron.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('File lock cron tidak bisa dibuka.');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return null;
    }
    return $lock;
}

/**
 * Ringkasan kesehatan sistem (di-cache per request — dipakai sidebar,
 * dashboard, dan halaman detail tanpa query ganda).
 * Return:
 *   cron:  ['last'=>int, 'source'=>string, 'age'=>int detik (-1 belum pernah), 'level'=>'ok'|'warn'|'danger']
 *   notif: ['failed_24h'=>int, 'failed_7d'=>int, 'sent_7d'=>int]
 *   backup:['last'=>int|null umur file terbaru (detik), 'count'=>int]
 */
function systemHealthSnapshot(): array
{
    static $snap = null;
    if ($snap !== null) return $snap;

    $snap = [
        'cron'   => ['last' => 0, 'source' => '', 'age' => -1, 'level' => 'danger'],
        'notif'  => ['failed_24h' => 0, 'failed_7d' => 0, 'sent_7d' => 0],
        'backup' => ['last' => null, 'count' => 0],
    ];
    try {
        $pdo = getDB();

        $last = (int) (getSetting('cron_last_heartbeat', '0') ?: 0);
        // Fallback instalasi lama: penanda klaim Auto-Cron (sudah ada sejak awal).
        if ($last === 0) $last = (int) (getSetting('cron_auto_last_run', '0') ?: 0);
        if ($last > 0) {
            $age = max(0, time() - $last);
            // Hijau < 30 menit, kuning 30 menit–3 jam, merah > 3 jam / belum pernah.
            $snap['cron'] = [
                'last'   => $last,
                'source' => (string) (getSetting('cron_last_source', '') ?: ''),
                'age'    => $age,
                'level'  => $age < 1800 ? 'ok' : ($age < 10800 ? 'warn' : 'danger'),
            ];
        }

        $q = $pdo->query(
            "SELECT
               SUM(status = 'failed' AND created_at >= NOW() - INTERVAL 1 DAY) AS f24,
               SUM(status = 'failed' AND created_at >= NOW() - INTERVAL 7 DAY) AS f7,
               SUM(status = 'sent'   AND created_at >= NOW() - INTERVAL 7 DAY) AS s7
             FROM notification_logs WHERE created_at >= NOW() - INTERVAL 7 DAY"
        )->fetch();
        $snap['notif'] = [
            'failed_24h' => (int) ($q['f24'] ?? 0),
            'failed_7d'  => (int) ($q['f7'] ?? 0),
            'sent_7d'    => (int) ($q['s7'] ?? 0),
        ];

        if (function_exists('listDbBackups')) {
            $bk = listDbBackups();
            $snap['backup']['count'] = count($bk);
            if ($bk) $snap['backup']['last'] = max(0, time() - (int) $bk[0]['mtime']);
        }
    } catch (Throwable $e) {
        error_log('systemHealthSnapshot error: ' . $e->getMessage());
    }
    return $snap;
}

/**
 * Housekeeping harian (klaim optimistik spt backup): pangkas notification_logs
 * lebih tua dari 90 hari + trusted device 2FA kedaluwarsa.
 */
function processHealthHousekeeping(): array
{
    $result = ['pruned_logs' => 0, 'pruned_devices' => 0];
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'health_last_prune' LIMIT 1");
        $stmt->execute();
        $lastRaw = $stmt->fetchColumn();
        if ((int) ($lastRaw ?: 0) > time() - 24 * 3600) return $result;

        if ($lastRaw === false) {
            $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value, setting_group) VALUES ('health_last_prune', '0', 'system')")->execute();
            $lastRaw = '0';
        }
        $claim = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'health_last_prune' AND setting_value = ?");
        $claim->execute([(string) time(), (string) $lastRaw]);
        if ($claim->rowCount() < 1) return $result;

        $d1 = $pdo->prepare("DELETE FROM notification_logs WHERE created_at < NOW() - INTERVAL 90 DAY");
        $d1->execute();
        $result['pruned_logs'] = (int) $d1->rowCount();

        try {
            $d2 = $pdo->prepare('DELETE FROM user_trusted_devices WHERE expires_at < NOW()');
            $d2->execute();
            $result['pruned_devices'] = (int) $d2->rowCount();
        } catch (Throwable $e) {
            // tabel belum ada (pra-migrasi 1.1.49) — abaikan
        }

        try {
            // Token reset password kadaluarsa/terpakai > 7 hari — tak berguna lagi.
            $pdo->exec("DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 7 DAY");
        } catch (Throwable $e) {
            // tabel belum ada (pra-migrasi 1.1.95) — abaikan
        }
    } catch (Throwable $e) {
        error_log('processHealthHousekeeping error: ' . $e->getMessage());
    }
    return $result;
}

/**
 * Kirim ulang satu baris notification_logs yang gagal — logika inti dipakai
 * bersama oleh tombol "Kirim Ulang" admin (actions/admin/retry-notification.php)
 * dan auto-retry cron (processNotificationRetries()). $log wajib row lengkap
 * hasil SELECT * (butuh id, channel, provider, recipient, subject, body).
 * Return: ['ok'=>bool, 'message'=>string (respons/alasan, dipotong 160 char)].
 */
function retryNotificationLog(array $log): array
{
    require_once dirname(__DIR__) . '/notifications/NotificationDispatcher.php';
    $pdo = getDB();
    $id  = (int) $log['id'];

    // WhatsApp: bila provider asal sedang nonaktif, pakai provider WA yang aktif
    // sekarang (pola fallback yang sama dengan dispatcher).
    $provider = (string) $log['provider'];
    if ($log['channel'] === 'whatsapp' && getSetting($provider . '_enabled', '0') !== '1') {
        foreach (['fonte', 'onesender', 'starsender', 'dripsender', 'kirimdev'] as $wp) {
            if (getSetting($wp . '_enabled', '0') === '1') { $provider = $wp; break; }
        }
    }

    $adapter = NotificationDispatcher::getAdapterPublic($provider);
    if (!$adapter) {
        return ['ok' => false, 'message' => 'Provider "' . $provider . '" tidak dikenali / tidak tersedia.'];
    }

    $result = $adapter->send((string) $log['recipient'], (string) ($log['subject'] ?? ''), (string) $log['body']);
    $ok     = !empty($result['success']);
    $resp   = is_array($result['raw_response'] ?? null)
        ? json_encode($result['raw_response'])
        : (string) ($result['raw_response'] ?? $result['message'] ?? '');

    if ($ok) {
        // Sukses → tandai sent, kosongkan payload (tidak perlu disimpan lagi).
        $pdo->prepare("UPDATE notification_logs SET status = 'sent', provider = ?, response = CONCAT('KIRIM ULANG OK — ', ?), subject = NULL, body = NULL WHERE id = ?")
            ->execute([$provider, mb_substr($resp, 0, 500), $id]);
    } else {
        // Masih gagal → catat respons terbaru, payload dipertahankan untuk percobaan lagi.
        $pdo->prepare("UPDATE notification_logs SET provider = ?, response = CONCAT('KIRIM ULANG GAGAL — ', ?) WHERE id = ?")
            ->execute([$provider, mb_substr($resp, 0, 500), $id]);
    }

    return ['ok' => $ok, 'message' => mb_substr($resp, 0, 160)];
}

/**
 * Auto-retry notifikasi yang gagal — tanpa perlu admin klik "Kirim Ulang" manual.
 * Cakupan: status='failed', payload masih tersimpan (body terisi), gagal minimal
 * 5 menit lalu (kasih waktu isu jaringan sesaat reda), maksimal 24 jam lalu
 * (lebih tua dari itu dianggap basi, biar admin yang putuskan manual), dan BELUM
 * pernah dicoba ulang (response tidak diawali 'KIRIM ULANG' — baik manual maupun
 * auto — supaya tidak retry berulang-ulang untuk kegagalan permanen).
 *
 * PENTING — channel 'whatsapp' SENGAJA DIKECUALIKAN dari auto-retry. WA dikirim
 * lewat gateway HTTP (StarSender dll) yang tak memberi kepastian pengiriman saat
 * responsnya tak terbaca (read-timeout setelah connect): pesan sering SUDAH terkirim
 * meski kita mencatatnya gagal. Auto-mengirim ulang kasus seperti ini = WA DOBEL ke
 * pembeli (persis keluhan 1 pembelian -> 2 notif berjarak ~menit). Karena non-dobel
 * tak bisa dijamin, WA hanya dikirim ulang lewat keputusan admin (tombol "Kirim
 * Ulang" manual tetap tersedia). Email tetap di-auto-retry (dampak dobel minim &
 * kegagalan SMTP sementara lazim). Lihat StarSenderAdapter::requestLikelySent().
 */
function processNotificationRetries(int $limit = 20): array
{
    $result = ['attempted' => 0, 'recovered' => 0];
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            "SELECT * FROM notification_logs
             WHERE status = 'failed' AND body IS NOT NULL AND body <> ''
               AND channel <> 'whatsapp'
               AND created_at <= NOW() - INTERVAL 5 MINUTE
               AND created_at >= NOW() - INTERVAL 24 HOUR
               AND (response IS NULL OR response NOT LIKE 'KIRIM ULANG%')
             ORDER BY id ASC LIMIT " . max(1, min(100, $limit))
        );
        $stmt->execute();

        foreach ($stmt->fetchAll() as $log) {
            $result['attempted']++;
            try {
                if (retryNotificationLog($log)['ok']) {
                    $result['recovered']++;
                }
            } catch (Throwable $e) {
                error_log('processNotificationRetries retry #' . $log['id'] . ': ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('processNotificationRetries error: ' . $e->getMessage());
        $result['error'] = $e->getMessage();
    }
    return $result;
}

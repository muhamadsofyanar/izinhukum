<?php
/**
 * Integrasi Lisensi Vendor (Fulfillment) — averion.id memesan lisensi ke server
 * lisensi (vendor) begitu order buyer LUNAS, supaya buyer tak perlu mengisi form
 * pengajuan lalu menunggu penerbitan manual.
 *
 * FITUR KHUSUS PEMILIK PRODUK BERLISENSI VENDOR — default MATI. Instalasi yang
 * tidak mengisi URL+rahasia di Admin -> Integrasi tak terpengaruh sama sekali
 * (semua fungsi di sini langsung return).
 *
 * Prinsip: VENDOR tetap satu-satunya penerbit & sumber kebenaran lisensi
 * (masa berlaku, domain, status). averion.id hanya MEMESAN lalu MENCERMINKAN
 * kunci ke product_licenses supaya tampil di dashboard buyer. Kalau keduanya
 * sama-sama merasa memiliki lisensi, cepat atau lambat mereka beda pendapat soal
 * kapan lisensi habis — dan yang kalah adalah buyer yang terkunci.
 */

/**
 * Fitur ini BOLEH tampil di instalasi ini?
 *
 * Ini fitur khusus PEMILIK server lisensi (vendor), bukan untuk pembeli Averion
 * pada umumnya — pembeli yang jualan ebook tak punya server lisensi & hanya akan
 * bingung melihat menunya. Tapi kodenya TETAP harus ikut ter-ship: instalasi
 * vendor menjalankan codebase yang sama, dan modifikasi lokal apa pun akan
 * TERTIMPA saat self-update.
 *
 * Kuncinya: `config.php` satu-satunya file yang neverOverwrite (lihat
 * apply-update.php) → konstanta di sana selamat dari update DAN tidak pernah ada
 * di instalasi pembeli (installer tak menulisnya). Pola yang sama dipakai
 * LICENSE_ENFORCEMENT_ENABLED.
 *
 * Default TIDAK ADA = tidak tampil. Aktifkan HANYA di server vendor dgn menambah
 * satu baris ke config.php:
 *
 *     define('AVERION_VENDOR_INSTALL', true);
 */
function vfAvailable(): bool
{
    return defined('AVERION_VENDOR_INSTALL') && AVERION_VENDOR_INSTALL === true;
}

/** Integrasi aktif? (instalasi vendor + URL + rahasia terisi & saklar on) */
function vfEnabled(): bool
{
    if (!vfAvailable()) return false;

    return getSetting('vendor_fulfillment_enabled', '0') === '1'
        && trim((string) getSetting('vendor_fulfillment_url', '')) !== ''
        && trim((string) getSetting('vendor_fulfillment_secret', '')) !== '';
}

/**
 * Normalisasi satu nilai peta ke bentuk kanonik ['plan','product_code','tier'].
 *
 * DUA FORMAT didukung supaya peta lama tak perlu dimigrasi:
 *   - Legacy (produk Averion): nilai berupa STRING = slug plan; product_code
 *     diasumsikan 'averion' (satu-satunya produk saat format lama dibuat).
 *   - Baru (multi-produk):     nilai berupa OBJEK {"plan":..,"product_code":..
 *     [,"tier":..]}.
 * Pemisahan produk di server lisensi lewat product_code (lihat api/fulfill.php
 * vendor: default 'averion' bila tak dikirim). `tier` HANYA relevan untuk produk
 * Relay: menentukan kuota nomor (Starter/Business) di sisi vendor; produk lain
 * mengabaikannya.
 */
function vfNormalizeMapValue($val): array
{
    if (is_array($val)) {
        $plan = trim((string) ($val['plan'] ?? ''));
        $code = trim((string) ($val['product_code'] ?? '')) ?: 'averion';
        $tier = trim((string) ($val['tier'] ?? ''));
    } else {
        $plan = trim((string) $val);
        $code = 'averion';
        $tier = '';
    }
    return ['plan' => $plan, 'product_code' => $code, 'tier' => $tier];
}

/**
 * Peta produk averion.id -> tujuan vendor. Disimpan JSON, nilai boleh string
 * (legacy = produk Averion) ATAU {"plan":..,"product_code":..}.
 * @return array<int,array{plan:string,product_code:string}>
 */
function vfProductMap(): array
{
    $raw = json_decode((string) getSetting('vendor_fulfillment_map', ''), true);
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $pid => $val) {
        $pid   = (int) $pid;
        $entry = vfNormalizeMapValue($val);
        if ($pid > 0 && $entry['plan'] !== '') $out[$pid] = $entry;
    }
    return $out;
}

/** Entri peta ternormalisasi untuk sebuah produk, [] bila tak dipetakan. */
function vfEntryForProduct(int $productId): array
{
    if (!vfEnabled()) return [];
    return vfProductMap()[$productId] ?? [];
}

/** Plan vendor untuk sebuah produk, '' bila produk ini tak dipetakan. */
function vfPlanForProduct(int $productId): string
{
    return (string) (vfEntryForProduct($productId)['plan'] ?? '');
}

/**
 * Antrekan penerbitan/perpanjangan. DIPANGGIL DI DALAM transaksi order (onOrderPaid)
 * → kalau order lunas tercatat, antreannya PASTI ikut tercatat; tak ada order lunas
 * yang lisensinya tak pernah diantre.
 *
 * Sengaja TIDAK memanggil vendor di sini: HTTP call di tengah transaksi checkout
 * = buyer menunggu server orang lain, dan vendor down berarti checkout gagal.
 * Pengiriman dilakukan cron (processVendorFulfillments).
 */
function vfEnqueue(PDO $pdo, array $order, int $productId, int $userId): void
{
    try {
        $plan = vfPlanForProduct($productId);
        if ($plan === '') return;

        $orderNumber = (string) ($order['order_number'] ?? '');
        if ($orderNumber === '') return;

        // Sudah punya lisensi vendor utk produk ini? → PERPANJANG, bukan terbit baru.
        $prev = $pdo->prepare(
            "SELECT license_key FROM product_licenses
              WHERE user_id = ? AND product_id = ? AND license_key IS NOT NULL AND license_key <> ''
              ORDER BY id DESC LIMIT 1"
        );
        $prev->execute([$userId, $productId]);
        $existingKey = (string) ($prev->fetchColumn() ?: '');
        $action      = $existingKey !== '' ? 'renew' : 'new';

        // INSERT IGNORE + uk_order_action: webhook gateway yang mengirim dua kali
        // tak menumpuk antrean kembar.
        $pdo->prepare(
            "INSERT IGNORE INTO license_fulfillments
                (order_id, user_id, product_id, order_number, action, plan, license_key, status, next_attempt_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())"
        )->execute([
            (int) ($order['id'] ?? 0), $userId, $productId, $orderNumber,
            $action, $plan, ($existingKey !== '' ? $existingKey : null),
        ]);
    } catch (Throwable $e) {
        // Tabel belum ada (pra-migrasi) / error lain — JANGAN pernah menggagalkan
        // order yang sudah dibayar hanya karena antrean lisensi bermasalah.
        error_log('vfEnqueue error: ' . $e->getMessage());
    }
}

/**
 * Kirim satu permintaan ke vendor. Return ['ok'=>bool,'data'=>array,'error'=>string,
 * 'retryable'=>bool].
 *
 * retryable=false hanya untuk penolakan yang TIDAK akan berubah kalau diulang
 * (400/401/403/404 = salah konfigurasi/data). 5xx, timeout, koneksi putus =
 * retryable → antrean coba lagi nanti.
 */
function vfCall(string $action, array $payload): array
{
    $url    = rtrim(trim((string) getSetting('vendor_fulfillment_url', '')), '/');
    $secret = trim((string) getSetting('vendor_fulfillment_secret', ''));
    if ($url === '' || $secret === '') {
        return ['ok' => false, 'error' => 'URL/rahasia vendor belum diatur.', 'retryable' => false, 'data' => []];
    }

    $body = json_encode($payload + ['action' => $action]);
    $ts   = (string) time();
    $sig  = hash_hmac('sha256', $ts . '.' . $body, $secret);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Averion-Timestamp: ' . $ts,
            'X-Averion-Signature: ' . $sig,
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($res === false || $err !== '') {
        return ['ok' => false, 'error' => 'Koneksi ke vendor gagal: ' . $err, 'retryable' => true, 'data' => []];
    }
    $data = json_decode((string) $res, true);
    if (!is_array($data)) {
        // Respons bukan JSON (mis. halaman error 502 proxy) = gangguan sementara.
        return ['ok' => false, 'error' => 'Respons vendor tidak valid (HTTP ' . $code . ').', 'retryable' => true, 'data' => []];
    }
    if ($code === 200 && !empty($data['ok'])) {
        return ['ok' => true, 'data' => $data, 'error' => '', 'retryable' => false];
    }

    $msg = (string) ($data['message'] ?? 'HTTP ' . $code);
    return [
        'ok'        => false,
        'error'     => 'Vendor menolak (HTTP ' . $code . '): ' . $msg,
        'retryable' => $code >= 500 || $code === 429,
        'data'      => $data,
    ];
}

/**
 * Proses antrean: kirim ke vendor, cerminkan kunci ke product_licenses.
 * Fail-safe: tak pernah melempar. Dipakai cron & Auto-Cron.
 *
 * @return array ['processed'=>int,'done'=>int,'retry'=>int,'failed'=>int]
 */
function processVendorFulfillments(int $limit = 20): array
{
    $out = ['processed' => 0, 'done' => 0, 'retry' => 0, 'failed' => 0];
    if (!vfEnabled()) return $out;

    $maxAttempts = 8;   // ~ sampai beberapa jam dgn backoff di bawah

    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            "SELECT f.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone
               FROM license_fulfillments f
               JOIN users u ON u.id = f.user_id
              WHERE f.status = 'pending'
                AND (f.next_attempt_at IS NULL OR f.next_attempt_at <= NOW())
              ORDER BY f.id ASC
              LIMIT " . (int) $limit
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('processVendorFulfillments query error: ' . $e->getMessage());
        return $out;
    }

    foreach ($rows as $r) {
        $out['processed']++;
        $id      = (int) $r['id'];
        $attempt = (int) $r['attempts'] + 1;

        try {
            $payload = [
                'order_ref' => (string) $r['order_number'],
                'plan'      => (string) $r['plan'],
            ];
            // product_code di-RESOLVE saat kirim dari peta (bukan disnapshot di DB) —
            // supaya tak perlu kolom/migrasi baru yang ikut ke DB pembeli. Hanya
            // dikirim bila BUKAN 'averion': menjaga payload produk Averion yang sudah
            // live tetap identik byte-per-byte (vendor default-nya juga 'averion').
            // Drift teoretis (admin mengubah pemetaan di antara antre & kirim) tak
            // berbahaya: product_code sebuah produk pada praktiknya tak berganti.
            $entry      = vfEntryForProduct((int) $r['product_id']);
            $vendorCode = (string) ($entry['product_code'] ?? '');
            if ($vendorCode !== '' && $vendorCode !== 'averion') {
                $payload['product_code'] = $vendorCode;
            }
            // Tier Relay → menentukan quota_numbers (Starter/Business) di vendor.
            // Hanya dikirim bila terisi: produk non-Relay & payload Averion yang
            // sudah live tetap tanpa field ini.
            $tier = (string) ($entry['tier'] ?? '');
            if ($tier !== '') {
                $payload['tier'] = $tier;
            }
            // Agency: vendor menentukan sendiri new vs top-up berdasarkan email
            // pembeli (bukan license_key per-perangkat). Produk agency tak pernah
            // mencerminkan license_key ke product_licenses → action selalu 'new' di
            // praktiknya; guard ini memastikan customer_* TETAP terkirim meski ada
            // baris product_licenses nyasar yang membuat vfEnqueue menebak 'renew'.
            $isAgency = ($vendorCode === 'agency');
            if ($r['action'] === 'renew' && !$isAgency) {
                $payload['license_key'] = (string) $r['license_key'];
            } else {
                $payload['customer_name']  = (string) ($r['user_name'] ?? '');
                $payload['customer_email'] = (string) ($r['user_email'] ?? '');
                $payload['customer_phone'] = (string) ($r['user_phone'] ?? '');
            }

            $res = vfCall((string) $r['action'], $payload);

            if ($res['ok']) {
                vfMirror($pdo, $r, $res['data']);
                $pdo->prepare(
                    "UPDATE license_fulfillments
                        SET status='done', attempts=?, license_key=?, last_error=NULL, next_attempt_at=NULL
                      WHERE id=?"
                )->execute([$attempt, (string) ($res['data']['license_key'] ?? ''), $id]);
                $out['done']++;
                continue;
            }

            // Gagal permanen (salah plan/rahasia) atau sudah kehabisan percobaan →
            // tandai failed supaya MUNCUL di admin, bukan hilang diam-diam.
            $giveUp = !$res['retryable'] || $attempt >= $maxAttempts;
            if ($giveUp) {
                $pdo->prepare("UPDATE license_fulfillments SET status='failed', attempts=?, last_error=? WHERE id=?")
                    ->execute([$attempt, substr($res['error'], 0, 1000), $id]);
                $out['failed']++;
                error_log('Fulfillment GAGAL order ' . $r['order_number'] . ': ' . $res['error']);
                // Pembeli sudah BAYAR tapi lisensinya tak sampai — admin harus tahu
                // sekarang, bukan menunggu kebetulan membuka halaman antrean.
                vfNotifyAdminFailed($r, $res['error']);
            } else {
                // Backoff eksponensial: 1, 2, 4, 8 ... menit (maks ~2 jam).
                $delay = min(120, (int) pow(2, $attempt - 1));
                $pdo->prepare(
                    "UPDATE license_fulfillments
                        SET attempts=?, last_error=?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                      WHERE id=?"
                )->execute([$attempt, substr($res['error'], 0, 1000), $delay, $id]);
                $out['retry']++;
            }
        } catch (Throwable $e) {
            error_log('processVendorFulfillments item error: ' . $e->getMessage());
        }
    }

    return $out;
}

/**
 * Cerminkan hasil vendor ke product_licenses (yang dibaca halaman "Lisensi Saya").
 * CERMIN, bukan sumber: masa berlaku selalu diambil dari respons vendor.
 */
function vfMirror(PDO $pdo, array $row, array $data): void
{
    $key     = (string) ($data['license_key'] ?? '');
    $expires = !empty($data['expires_at']) ? (string) $data['expires_at'] : null;
    if ($key === '') return;

    $find = $pdo->prepare("SELECT id FROM product_licenses WHERE license_key = ? LIMIT 1");
    $find->execute([$key]);
    $existing = (int) ($find->fetchColumn() ?: 0);

    if ($existing > 0) {
        $pdo->prepare(
            "UPDATE product_licenses SET status='active', expires_at=?, last_checked_at=NOW() WHERE id=?"
        )->execute([$expires, $existing]);
        return;
    }

    $pdo->prepare(
        "INSERT INTO product_licenses
            (license_key, order_id, product_id, user_id, status, max_seats, used_seats, expires_at, issued_at, notes)
         VALUES (?, ?, ?, ?, 'active', 1, 0, ?, NOW(), 'Diterbitkan server lisensi vendor')"
    )->execute([
        $key, (int) $row['order_id'], (int) $row['product_id'], (int) $row['user_id'], $expires,
    ]);
}

/**
 * Beri tahu ADMIN saat sebuah pesanan lisensi menyerah (status → failed).
 * Dikirim SEKALI per baris, tepat saat transisi ke failed — bukan tiap cron.
 * Fail-safe: kegagalan notifikasi tak boleh mengganggu proses antrean.
 */
function vfNotifyAdminFailed(array $row, string $error): void
{
    try {
        if (!file_exists(__DIR__ . '/../notifications/NotificationDispatcher.php')) return;
        require_once __DIR__ . '/../notifications/NotificationDispatcher.php';
        if (!class_exists('NotificationDispatcher')) return;

        $adminPhone = function_exists('resolveAdminNotifyPhone') ? resolveAdminNotifyPhone() : '';
        $adminEmail = (string) getSetting('mailketing_sender_email', '');
        if ($adminPhone === '' && $adminEmail === '') {
            error_log('vfNotifyAdminFailed dilewati: nomor WA & email admin kosong. Order: ' . ($row['order_number'] ?? ''));
            return;
        }

        $produk = '';
        try {
            $st = getDB()->prepare("SELECT name FROM products WHERE id = ? LIMIT 1");
            $st->execute([(int) ($row['product_id'] ?? 0)]);
            $produk = (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) { /* nama produk opsional */ }

        NotificationDispatcher::trigger('license_fulfillment_failed', [
            'order_number'  => (string) ($row['order_number'] ?? ''),
            'product_name'  => $produk,
            'customer_name' => (string) ($row['user_name'] ?? ''),
            'email'         => (string) ($row['user_email'] ?? ''),
            'reason'        => $error,
            'admin_url'     => baseUrl('admin/license-fulfillments'),
        ], $adminEmail, $adminPhone);
    } catch (Throwable $e) {
        error_log('vfNotifyAdminFailed error: ' . $e->getMessage());
    }
}

/** Ada lisensi vendor yang masih disiapkan untuk user ini? (dipakai Lisensi Saya) */
function vfPendingForUser(int $userId): array
{
    if (!vfEnabled()) return [];
    try {
        $stmt = getDB()->prepare(
            "SELECT f.id, f.status, f.order_number, p.name AS product_name
               FROM license_fulfillments f
               JOIN products p ON p.id = f.product_id
              WHERE f.user_id = ? AND f.status IN ('pending','failed')
              ORDER BY f.id DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

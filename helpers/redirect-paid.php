<?php
// ─── Redirect Setelah Bayar ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/**
 * Signing secret utk placeholder {sig} pada fitur "Redirect Setelah Bayar".
 * Dibuat sekali lalu disimpan di settings; dibagikan ke aplikasi eksternal
 * supaya mereka bisa memverifikasi handoff (sig = HMAC-SHA256 dari
 * "nomor_order|email"). Panjang cukup untuk tidak mudah ditebak.
 */
function redirectSigningSecret(): string
{
    // Cache lokal: getSetting meng-cache nilai MISS ('') sebelum kita menulis
    // secret baru, jadi tanpa static ini panggilan kedua di proses yang sama
    // akan menghasilkan (dan menimpa) secret berbeda → sig tak konsisten.
    static $mem = null;
    if ($mem !== null) return $mem;

    $secret = (string) getSetting('redirect_signing_secret', '');
    if (strlen($secret) < 16) {
        $secret = bin2hex(random_bytes(24));
        setSetting('redirect_signing_secret', $secret, 'integrations');
    }
    return $mem = $secret;
}

/**
 * Bangun URL "Redirect Setelah Bayar" untuk sebuah order (berbasis produk
 * utama = item non-bump pertama). Mengembalikan URL final dengan placeholder
 * terisi & ter-encode, atau null bila produk utama tak mengisi
 * redirect_after_paid_url (perilaku lama: tetap di halaman sukses).
 *
 * Placeholder didukung: {order_id} {email} {name} {phone} {product_id}
 * {product_name} {amount} {sig} {token}. Nilai di-rawurlencode agar aman di
 * query/URL. {token} = JWT auto-login ber-kedaluwarsa (lihat redirectLoginToken).
 */
function buildPaidRedirectUrl(int $orderId): ?string
{
    if ($orderId <= 0) return null;
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            "SELECT o.order_number, o.total,
                    u.name AS buyer_name, u.email AS buyer_email, u.phone AS buyer_phone,
                    p.id AS product_id, p.name AS product_name,
                    p.external_program_code AS program_code,
                    p.redirect_after_paid_url AS redirect_url
             FROM orders o
             JOIN users u ON u.id = o.user_id
             JOIN order_items oi ON oi.order_id = o.id AND oi.is_bump = 0
             JOIN products p ON p.id = oi.product_id
             WHERE o.id = ?
             ORDER BY oi.id
             LIMIT 1"
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        // Kolom belum ada (install lama belum migrasi) → tanpa redirect.
        return null;
    }
    if (!$row) return null;

    $tpl = trim((string) ($row['redirect_url'] ?? ''));
    if ($tpl === '') return null;

    $orderNo = (string) $row['order_number'];
    $email   = (string) $row['buyer_email'];
    $name    = (string) ($row['buyer_name'] ?? '');
    $prog    = $row['program_code'] !== null ? (string) $row['program_code'] : null;
    $sig     = hash_hmac('sha256', $orderNo . '|' . $email, redirectSigningSecret());

    $map = [
        '{order_id}'     => $orderNo,
        '{email}'        => $email,
        '{name}'         => $name,
        '{phone}'        => (string) ($row['buyer_phone'] ?? ''),
        '{product_id}'   => (string) (int) $row['product_id'],
        '{product_name}' => (string) ($row['product_name'] ?? ''),
        '{amount}'       => (string) (int) round((float) $row['total']),
        '{sig}'          => $sig,
        // Token auto-login ber-kedaluwarsa (JWT HS256) utk app tujuan.
        '{token}'        => redirectLoginToken($email, $orderNo, $prog, $name),
    ];
    return strtr($tpl, array_map('rawurlencode', $map));
}

/**
 * Token auto-login untuk fitur "Redirect Setelah Bayar" — dipakai app tujuan
 * (mis. aginggracefully) agar bisa melogin-kan pembeli SECARA AMAN tanpa form.
 * Berupa JWT HS256 standar yang ditandatangani redirectSigningSecret() dan
 * BER-KEDALUWARSA (klaim exp), jadi URL tidak bisa dipakai ulang selamanya.
 * Klaim: sub(email), name, order_id, program_code, iat, exp. App tujuan cukup
 * memverifikasi dengan pustaka JWT + shared secret, lalu cek exp.
 */
function redirectLoginToken(string $email, string $orderNumber, ?string $programCode, string $name = ''): string
{
    $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $now = time();
    $header  = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = $b64(json_encode([
        'sub'          => $email,
        'name'         => $name,
        'order_id'     => $orderNumber,
        'program_code' => $programCode,
        'iat'          => $now,
        'exp'          => $now + REDIRECT_TOKEN_TTL,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $sig = $b64(hash_hmac('sha256', $header . '.' . $payload, redirectSigningSecret(), true));
    return $header . '.' . $payload . '.' . $sig;
}

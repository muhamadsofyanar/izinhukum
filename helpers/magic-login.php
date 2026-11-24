<?php
/**
 * Magic-login: akses instan pasca-bayar untuk pembeli berakun-lama (Segmen B).
 *
 * Alur: pembeli yang SUDAH punya akun checkout sebagai tamu (tidak login) lalu bayar
 * → dikirim link sekali-pakai ke email + WhatsApp. Link me-login-kan mereka ke area
 * member & membuka produk tanpa harus ingat password. Login manual tetap berfungsi.
 *
 * Keamanan:
 * - Token mentah hanya ada di link (dikirim ke email/WA pemilik akun = bukti kepemilikan).
 *   Di DB disimpan HANYA sha256-nya (char(64)); bocornya baris DB tak memberi link aktif.
 * - Sekali-pakai: klaim atomik (UPDATE ... WHERE used_at IS NULL AND expires_at > NOW()).
 * - Kedaluwarsa 30 menit (default).
 * - HANYA role 'user'. Akun admin/staff DITOLAK (mereka punya 2FA → login manual saja),
 *   mempertahankan guard anti-takeover: tahu email saja tak cukup untuk masuk akun lama.
 */

if (!defined('MAGIC_LOGIN_TTL_MINUTES')) {
    define('MAGIC_LOGIN_TTL_MINUTES', 30);
}

/**
 * Buat token magic-login untuk user. Return token MENTAH (untuk dipasang di URL),
 * atau null bila gagal. Hanya simpan sha256(token) di DB.
 */
function createMagicLoginToken(int $userId, ?int $orderId = null, ?int $productId = null, int $ttlMinutes = MAGIC_LOGIN_TTL_MINUTES, string $purpose = 'access_link'): ?string
{
    if ($userId <= 0) return null;
    try {
        $pdo = getDB();

        // Housekeeping ringan (~3%): bersihkan token basi >1 hari agar tabel tak membengkak.
        if (random_int(1, 100) <= 3) {
            try {
                $pdo->exec("DELETE FROM login_tokens WHERE (used_at IS NOT NULL OR expires_at < NOW()) AND created_at < (NOW() - INTERVAL 1 DAY)");
            } catch (Throwable $e) { /* abaikan */ }
        }

        $raw  = bin2hex(random_bytes(24)); // 48 hex char — entropi cukup
        $hash = hash('sha256', $raw);       // 64 hex char → kolom token_hash

        $ttl = max(1, $ttlMinutes);
        $stmt = $pdo->prepare(
            'INSERT INTO login_tokens (user_id, token_hash, order_id, product_id, purpose, expires_at)
             VALUES (?, ?, ?, ?, ?, (NOW() + INTERVAL ? MINUTE))'
        );
        $stmt->execute([
            $userId,
            $hash,
            $orderId > 0 ? $orderId : null,
            $productId > 0 ? $productId : null,
            $purpose,
            $ttl,
        ]);
        return $raw;
    } catch (Throwable $e) {
        error_log('createMagicLoginToken error: ' . $e->getMessage());
        return null;
    }
}

/** URL absolut magic-login dari token mentah. */
function magicLoginUrl(string $rawToken): string
{
    return baseUrl('akses-cepat/' . $rawToken);
}

/**
 * Konsumsi token (sekali-pakai, atomik). Return array konteks bila valid:
 *   ['user' => ['id','name','email','role'], 'product_id' => int, 'order_id' => int]
 * atau null bila token tidak ada / sudah dipakai / kedaluwarsa / bukan role 'user'.
 */
function consumeMagicLoginToken(string $rawToken): ?array
{
    $rawToken = trim($rawToken);
    // Token kita selalu hex 48 char. Tolak cepat input aneh (hindari query sia-sia).
    if ($rawToken === '' || !ctype_xdigit($rawToken) || strlen($rawToken) > 128) return null;

    try {
        $pdo  = getDB();
        $hash = hash('sha256', $rawToken);

        // Klaim atomik: hanya satu permintaan yang bisa menandai used_at.
        $claim = $pdo->prepare(
            'UPDATE login_tokens SET used_at = NOW()
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()'
        );
        $claim->execute([$hash]);
        if ($claim->rowCount() !== 1) return null; // tak ada / sudah dipakai / kedaluwarsa

        $row = $pdo->prepare(
            'SELECT lt.user_id, lt.order_id, lt.product_id,
                    u.name, u.email, u.role
             FROM login_tokens lt
             JOIN users u ON u.id = lt.user_id
             WHERE lt.token_hash = ? LIMIT 1'
        );
        $row->execute([$hash]);
        $data = $row->fetch();
        if (!$data) return null;

        // Hanya role 'user'. Admin/staff ditolak (anti-takeover + mereka punya 2FA).
        if (($data['role'] ?? '') !== 'user') return null;

        return [
            'user' => [
                'id'    => (int) $data['user_id'],
                'name'  => (string) ($data['name'] ?? ''),
                'email' => (string) ($data['email'] ?? ''),
                'role'  => (string) $data['role'],
            ],
            'product_id' => (int) ($data['product_id'] ?? 0),
            'order_id'   => (int) ($data['order_id'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('consumeMagicLoginToken error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Kirim notifikasi magic-link akses (event 'access_link') ke pembeli role 'user'.
 * Dipanggil di SEMUA jalur paid: onOrderPaidPostCommit (webhook/konfirmasi admin) &
 * cabang order gratis checkout publik. Fail-soft: tak pernah menggagalkan transaksi.
 */
function dispatchAccessLink(int $userId, ?int $orderId = null, ?int $productId = null, string $productName = ''): void
{
    if ($userId <= 0) return;
    try {
        $pdo  = getDB();
        $uStmt = $pdo->prepare('SELECT name, email, phone, role FROM users WHERE id = ? LIMIT 1');
        $uStmt->execute([$userId]);
        $u = $uStmt->fetch();
        if (!$u) return;
        if (($u['role'] ?? '') !== 'user') return; // admin/staff → login manual saja

        $email = (string) ($u['email'] ?? '');
        $phone = (string) ($u['phone'] ?? '');
        if ($email === '' && $phone === '') return; // tak ada kanal → tak ada gunanya

        if ($productName === '' && $productId && $productId > 0) {
            try {
                $pStmt = $pdo->prepare('SELECT name FROM products WHERE id = ? LIMIT 1');
                $pStmt->execute([$productId]);
                $productName = (string) ($pStmt->fetchColumn() ?: '');
            } catch (Throwable $e) { /* abaikan */ }
        }
        if ($productName === '') $productName = 'produk kamu';

        $raw = createMagicLoginToken($userId, $orderId, $productId);
        if ($raw === null) return; // gagal buat token → jangan kirim link mati

        if (!file_exists(dirname(__DIR__) . '/notifications/NotificationDispatcher.php')) return;
        require_once dirname(__DIR__) . '/notifications/NotificationDispatcher.php';

        NotificationDispatcher::trigger('access_link', [
            'name'            => (string) ($u['name'] ?? ''),
            'email'           => $email,
            'product_name'    => $productName,
            'access_url'      => magicLoginUrl($raw),
            'expiry_minutes'  => (string) MAGIC_LOGIN_TTL_MINUTES,
            'member_url'      => baseUrl('member'),
        ], $email, $phone);
    } catch (Throwable $e) {
        error_log('dispatchAccessLink error: ' . $e->getMessage());
    }
}

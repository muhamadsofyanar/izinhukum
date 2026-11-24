<?php
// ─── 2FA TOTP + Trusted device ─────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Pure PHP RFC 6238 (HMAC-SHA1). Behavior-preserving: pindah utuh.
// Pure PHP RFC 6238 (HMAC-SHA1, 6 digit, periode 30 detik) — tanpa dependensi.
// Berlaku untuk login admin/staff (opt-in per akun via /profile/2fa).

function totpBase32Encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = ''; $bits = 0; $value = 0;
    foreach (str_split($bin) as $byte) {
        $value = ($value << 8) | ord($byte);
        $bits += 8;
        while ($bits >= 5) {
            $out .= $alphabet[($value >> ($bits - 5)) & 31];
            $bits -= 5;
        }
    }
    if ($bits > 0) $out .= $alphabet[($value << (5 - $bits)) & 31];
    return $out;
}

function totpBase32Decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $out = ''; $bits = 0; $value = 0;
    foreach (str_split($b32) as $ch) {
        $idx = strpos($alphabet, $ch);
        if ($idx === false) continue;
        $value = ($value << 5) | $idx;
        $bits += 5;
        if ($bits >= 8) {
            $out .= chr(($value >> ($bits - 8)) & 255);
            $bits -= 8;
        }
    }
    return $out;
}

function totpGenerateSecret(): string
{
    return totpBase32Encode(random_bytes(20)); // 160-bit, 32 karakter base32
}

function totpCodeAt(string $secretB32, int $timeSlice): string
{
    $key    = totpBase32Decode($secretB32);
    $binMsg = pack('N*', 0, $timeSlice); // 64-bit big-endian counter
    $hash   = hash_hmac('sha1', $binMsg, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $trunc  = (ord($hash[$offset]) & 0x7F) << 24
            | (ord($hash[$offset + 1]) & 0xFF) << 16
            | (ord($hash[$offset + 2]) & 0xFF) << 8
            | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string) ($trunc % 1000000), 6, '0', STR_PAD_LEFT);
}

function totpVerify(string $secretB32, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6 || $secretB32 === '') return false;
    $slice = (int) floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totpCodeAt($secretB32, $slice + $i), $code)) return true;
    }
    return false;
}

function totpOtpauthUri(string $secretB32, string $accountLabel, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($accountLabel)
         . '?secret=' . $secretB32
         . '&issuer=' . rawurlencode($issuer)
         . '&algorithm=SHA1&digits=6&period=30';
}

/**
 * Backup codes: 8 kode sekali-pakai format XXXX-XXXX (tanpa karakter ambigu).
 * Return ['plain' => [...], 'hashes' => [...sha256...]] — plain HANYA ditampilkan
 * sekali saat setup; DB menyimpan hash-nya (JSON di users.totp_backup_codes).
 */
function totpGenerateBackupCodes(int $count = 8): array
{
    $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    $plain = []; $hashes = [];
    for ($i = 0; $i < $count; $i++) {
        $c = '';
        for ($j = 0; $j < 8; $j++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $c = substr($c, 0, 4) . '-' . substr($c, 4);
        $plain[]  = $c;
        $hashes[] = hash('sha256', $c);
    }
    return ['plain' => $plain, 'hashes' => $hashes];
}

/**
 * Verifikasi + KONSUMSI backup code (sekali pakai). Return true bila valid;
 * kode yang terpakai langsung dihapus dari users.totp_backup_codes.
 */
function totpConsumeBackupCode(int $userId, string $code): bool
{
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z0-9]{4}-?[A-Z0-9]{4}$/', $code)) return false;
    if (strpos($code, '-') === false) $code = substr($code, 0, 4) . '-' . substr($code, 4);
    try {
        $pdo = getDB();
        $row = $pdo->prepare('SELECT totp_backup_codes FROM users WHERE id = ? LIMIT 1');
        $row->execute([$userId]);
        $hashes = json_decode((string) $row->fetchColumn(), true);
        if (!is_array($hashes) || !$hashes) return false;
        $target = hash('sha256', $code);
        foreach ($hashes as $i => $h) {
            if (hash_equals((string) $h, $target)) {
                unset($hashes[$i]);
                $pdo->prepare('UPDATE users SET totp_backup_codes = ? WHERE id = ?')
                    ->execute([json_encode(array_values($hashes)), $userId]);
                return true;
            }
        }
    } catch (Exception $e) {
        error_log('totpConsumeBackupCode error: ' . $e->getMessage());
    }
    return false;
}

// ── Trusted device ("ingat perangkat ini 30 hari") ─────────────

function totpTrustedDeviceValid(int $userId): bool
{
    $token = (string) ($_COOKIE['averion_trust'] ?? '');
    if ($token === '' || strlen($token) < 32) return false;
    try {
        $stmt = getDB()->prepare(
            'SELECT 1 FROM user_trusted_devices
             WHERE user_id = ? AND token_hash = ? AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$userId, hash('sha256', $token)]);
        return (bool) $stmt->fetchColumn();
    } catch (Exception $e) {
        return false; // tabel belum ada (pra-migrasi) → anggap tidak trusted
    }
}

function totpTrustDevice(int $userId, int $days = 30): void
{
    try {
        $token = bin2hex(random_bytes(32));
        $ua    = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        getDB()->prepare(
            'INSERT INTO user_trusted_devices (user_id, token_hash, user_agent, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
        )->execute([$userId, hash('sha256', $token), $ua, $days]);
        setcookie('averion_trust', $token, [
            'expires'  => time() + $days * 86400,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        // Bersihkan token kedaluwarsa milik user ini (housekeeping ringan).
        getDB()->prepare('DELETE FROM user_trusted_devices WHERE user_id = ? AND expires_at <= NOW()')
            ->execute([$userId]);
    } catch (Exception $e) {
        error_log('totpTrustDevice error: ' . $e->getMessage());
    }
}

function totpForgetDevices(int $userId): void
{
    try {
        getDB()->prepare('DELETE FROM user_trusted_devices WHERE user_id = ?')->execute([$userId]);
    } catch (Exception $e) {
        error_log('totpForgetDevices error: ' . $e->getMessage());
    }
}

/**
 * Gerbang 2FA bersama untuk SEMUA jalur login (password / Google / WA OTP).
 * Panggil SETELAH kredensial valid & status active, SEBELUM set session login.
 * Return true bila login harus DIALIHKAN ke tantangan 2FA (caller redirect ke
 * /login/2fa); false bila boleh lanjut set session seperti biasa.
 */
function totpChallengeRequired(array $user): bool
{
    if (!in_array($user['role'] ?? '', ['admin', 'staff'], true)) return false;
    if (empty($user['totp_enabled'])) return false;
    if (totpTrustedDeviceValid((int) $user['id'])) return false;

    session_regenerate_id(true);
    $_SESSION['totp_pending'] = [
        'user_id' => (int) $user['id'],
        'expires' => time() + 600,
    ];
    return true;
}

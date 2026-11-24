<?php
// ─── Keamanan (login/IP rate-limit, log, WA, OTP) ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

function logActivity(
    string  $actorRole,
    string  $action,
    ?string $entityType  = null,
    ?int    $entityId    = null,
    ?string $description = null
): void {
    try {
        $pdo    = getDB();
        $userId = $_SESSION['user_id'] ?? null;
        $pdo->prepare(
            "INSERT INTO activity_logs
             (user_id, actor_role, action, entity_type, entity_id, description, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $userId,
            $actorRole,
            $action,
            $entityType,
            $entityId,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Exception $e) {
        error_log('logActivity error: ' . $e->getMessage());
        // Tidak throw — logging gagal tidak menghentikan flow utama
    }
}

/**
 * Cek apakah kombinasi email+IP sedang ter-lockout.
 * Aturan: maksimal 5 percobaan login GAGAL per (email, IP) dalam 15 menit.
 * Fail-open saat DB error agar user sah tidak ikut terkunci.
 *
 * @return array ['allowed' => bool, 'message' => string]
 */
function checkLoginRateLimit(string $email, string $ip): array {
    $MAX_ATTEMPTS = 5;
    $WINDOW_MIN   = 15;

    try {
        $pdo  = getDB();

        // Limit AGREGAT per-IP lintas semua email (anti password-spraying:
        // 1 IP mencoba 1 password ke banyak email tak pernah kena limit
        // per-email+IP di bawah). Sentinel rate-limit aksi publik ("__xxx__")
        // dikecualikan agar checkout/register tak menggerus jatah login.
        $MAX_IP = 30;
        $ipStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = ? AND email NOT LIKE '\\_\\_%'
               AND created_at >= DATE_SUB(NOW(), INTERVAL {$WINDOW_MIN} MINUTE)"
        );
        $ipStmt->execute([$ip]);
        if (((int) $ipStmt->fetchColumn()) >= $MAX_IP) {
            return [
                'allowed' => false,
                'message' => 'Terlalu banyak percobaan login dari jaringan kamu. Coba lagi dalam beberapa menit.',
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE email = ? AND ip_address = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL {$WINDOW_MIN} MINUTE)"
        );
        $stmt->execute([$email, $ip]);
        $fails = (int) $stmt->fetchColumn();

        if ($fails < $MAX_ATTEMPTS) {
            return ['allowed' => true, 'message' => ''];
        }

        // Lockout aktif. Hitung sisa waktu dari percobaan ke-5 paling lama:
        // saat baris itu keluar dari jendela 15 menit, hitungan turun di bawah batas.
        $tStmt = $pdo->prepare(
            "SELECT created_at FROM login_attempts
             WHERE email = ? AND ip_address = ?
             ORDER BY created_at DESC LIMIT 1 OFFSET ?"
        );
        $tStmt->execute([$email, $ip, $MAX_ATTEMPTS - 1]);
        $anchor = $tStmt->fetchColumn();

        $unlockTs = ($anchor !== false ? strtotime($anchor) : time()) + ($WINDOW_MIN * 60);
        $waitMin  = max(1, (int) ceil(($unlockTs - time()) / 60));

        return [
            'allowed' => false,
            'message' => "Terlalu banyak percobaan login gagal. Coba lagi dalam {$waitMin} menit, "
                       . "atau reset password jika kamu lupa.",
        ];

    } catch (Exception $e) {
        error_log('checkLoginRateLimit error: ' . $e->getMessage());
        return ['allowed' => true, 'message' => ''];
    }
}

/** Catat satu percobaan login gagal untuk (email, IP). */
function recordLoginFailure(string $email, string $ip): void {
    try {
        $pdo = getDB();
        $pdo->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)')
            ->execute([$email, $ip]);

        // GC oportunistik (~2% peluang): buang baris di luar jendela rate-limit
        // (jendela 15 menit; ambil margin 1 jam) agar tabel tetap kecil tanpa cron.
        if (random_int(1, 50) === 1) {
            $pdo->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 HOUR)');
        }
    } catch (Exception $e) {
        error_log('recordLoginFailure error: ' . $e->getMessage());
    }
}

/** Bersihkan percobaan gagal setelah login sukses (reset counter). */
function clearLoginAttempts(string $email, string $ip): void {
    try {
        getDB()->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_address = ?')
            ->execute([$email, $ip]);
    } catch (Exception $e) {
        error_log('clearLoginAttempts error: ' . $e->getMessage());
    }
}

/**
 * Rate limit generik per-IP untuk aksi publik (register, forgot-password,
 * checkout publik). Menumpang tabel login_attempts dengan sentinel di kolom
 * email ("__register__", dst) — index (email, ip_address, created_at) sudah
 * pas, dan GC oportunistik recordLoginFailure() ikut membersihkannya, jadi
 * $windowMin WAJIB ≤ 60 (baris >1 jam dibuang GC).
 *
 * @return bool true bila masih di bawah batas (boleh lanjut).
 */
function checkIpRateLimit(string $action, string $ip, int $max, int $windowMin): bool {
    try {
        $windowMin = min(60, max(1, $windowMin));
        $stmt = getDB()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE email = ? AND ip_address = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL {$windowMin} MINUTE)"
        );
        $stmt->execute(['__' . $action . '__', $ip]);
        return ((int) $stmt->fetchColumn()) < $max;
    } catch (Exception $e) {
        error_log('checkIpRateLimit error: ' . $e->getMessage());
        return true; // fail-open: jangan blokir user sah karena DB error
    }
}

/** Catat satu kejadian aksi publik utk rate limit per-IP (pasangan checkIpRateLimit). */
function recordIpAction(string $action, string $ip): void {
    try {
        getDB()->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)')
            ->execute(['__' . $action . '__', $ip]);
    } catch (Exception $e) {
        error_log('recordIpAction error: ' . $e->getMessage());
    }
}

/**
 * Normalisasi nomor WhatsApp ke format internasional tanpa tanda '+'.
 * Mendukung Indonesia (62) dan Malaysia (60).
 *
 *  - Sudah diawali 62/60                → dipertahankan apa adanya.
 *  - Diawali 0 (format lokal):
 *      • 01x  → 60 + sisanya  (mobile Malaysia)
 *      • 08x  → 62 + sisanya  (mobile Indonesia)
 *      • lainnya → $defaultCc + sisanya (landline/ambigu → default)
 *  - Selain itu (sudah tanpa 0 & tanpa kode negara) → dikembalikan apa adanya.
 *
 * Catatan: diskriminasi mobile 01x(MY)/08x(ID) menutup kasus WhatsApp yang umum.
 * Untuk landline yang ambigu, dipakai $defaultCc.
 */
function normalizeWaNumber(string $number, string $defaultCc = '62'): string
{
    $d = preg_replace('/\D/', '', $number);
    if ($d === '') return '';

    // Sudah format internasional ID/MY.
    if (str_starts_with($d, '62') || str_starts_with($d, '60')) {
        return $d;
    }

    // Format lokal diawali 0.
    if ($d[0] === '0') {
        $rest = substr($d, 1);
        if ($rest === '') return '';
        if ($rest[0] === '1') return '60' . $rest; // Malaysia mobile (01x)
        if ($rest[0] === '8') return '62' . $rest; // Indonesia mobile (08x)
        return $defaultCc . $rest;
    }

    // Nomor tanpa 0/62/60 di depan. Form checkout menampilkan prefix "+62"
    // visual, jadi nomor mobile Indonesia sering diketik tanpa 0 (mis. "8xxx")
    // → tambahkan kode negara. Tanpa ini nomor dikirim apa adanya ke gateway
    // WA: status "sent" tapi pesan tak pernah sampai (gagal senyap) — kasus
    // sama sudah pernah terjadi & difix di averion-license v1.2.9.
    if ($d[0] === '8') return $defaultCc . $d;

    return $d;
}

function generateOTPCode(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function checkOTPRateLimit(string $phone): array {
    try {
        $pdo = getDB();

        // Minimal 60 detik sejak OTP terakhir ke nomor ini
        $stmt = $pdo->prepare(
            "SELECT created_at FROM otp_codes WHERE phone = ? ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$phone]);
        $last = $stmt->fetch();

        if ($last) {
            $elapsed = time() - strtotime($last['created_at']);
            if ($elapsed < 60) {
                $wait = 60 - $elapsed;
                return ['allowed' => false, 'message' => "Tunggu {$wait} detik sebelum minta kode baru."];
            }
        }

        // Maksimal 5 OTP per nomor per jam
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM otp_codes WHERE phone = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$phone]);
        if ((int) $stmt->fetchColumn() >= 5) {
            return ['allowed' => false, 'message' => 'Terlalu banyak permintaan kode. Coba lagi dalam 1 jam.'];
        }

        return ['allowed' => true, 'message' => ''];

    } catch (Exception $e) {
        error_log('checkOTPRateLimit error: ' . $e->getMessage());
        return ['allowed' => false, 'message' => 'Terjadi kesalahan sistem.'];
    }
}

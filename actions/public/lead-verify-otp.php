<?php
// ════════════════════════════════════════════════════════════════
// Lead Magnet — verifikasi OTP & promote lead anonim → user.
// Sukses → resolve/buat akun (passwordless), grant membership level
// (no-downgrade), link lead, login, arahkan ke member area.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Request tidak valid.']);
    exit;
}

$pending = $_SESSION['lead_pending'] ?? null;
$otp     = $_SESSION['lead_otp'] ?? null;
if (!is_array($pending) || empty($pending['lead_id']) || !is_array($otp) || empty($otp['code'])) {
    echo json_encode(['success' => false, 'message' => 'Sesi verifikasi tidak ditemukan. Minta kode baru.']);
    exit;
}

$code = preg_replace('/\D/', '', $_POST['code'] ?? '');
if (strlen($code) !== 6) {
    echo json_encode(['success' => false, 'message' => 'Masukkan 6 digit kode OTP.']);
    exit;
}

// ─── Validasi OTP (expiry, attempts, kecocokan) ──────────────
if (time() > (int) $otp['expires']) {
    unset($_SESSION['lead_otp']);
    echo json_encode(['success' => false, 'message' => 'Kode OTP kedaluwarsa. Minta kode baru.']);
    exit;
}
if ((int) $otp['attempts'] >= 5) {
    unset($_SESSION['lead_otp']);
    echo json_encode(['success' => false, 'message' => 'Terlalu banyak percobaan salah. Minta kode baru.']);
    exit;
}
if (!hash_equals((string) $otp['code'], $code)) {
    $_SESSION['lead_otp']['attempts'] = (int) $otp['attempts'] + 1;
    $remaining = 5 - (int) $_SESSION['lead_otp']['attempts'];
    echo json_encode(['success' => false, 'message' => 'Kode salah.' . ($remaining > 0 ? " Sisa percobaan: {$remaining}." : ' Minta kode baru.')]);
    exit;
}

$phone   = (string) $otp['phone'];
$leadId  = (int) $pending['lead_id'];
$magnetId = (int) ($pending['magnet_id'] ?? 0);

try {
    $pdo = getDB();

    // Data lead + bonus level magnet
    $st = $pdo->prepare(
        "SELECT l.name, l.email, lm.bonus_membership_level_id
         FROM leads l INNER JOIN lead_magnets lm ON lm.id = l.lead_magnet_id
         WHERE l.id = ? LIMIT 1"
    );
    $st->execute([$leadId]);
    $lead = $st->fetch();
    if (!$lead) {
        echo json_encode(['success' => false, 'message' => 'Data pendaftaran tidak ditemukan.']);
        exit;
    }
    $leadName  = trim((string) $lead['name']) !== '' ? trim((string) $lead['name']) : 'Member';
    $leadEmail = strtolower(trim((string) ($lead['email'] ?? '')));
    $bonusLevel = (int) ($lead['bonus_membership_level_id'] ?? 0);

    // ── Resolve akun: cocokkan via NOMOR (OTP membuktikan kepemilikan) ──
    $findByPhone = $pdo->prepare("SELECT id, name, email, role, status FROM users WHERE phone = ? LIMIT 1");
    $findByPhone->execute([$phone]);
    $user = $findByPhone->fetch() ?: null;
    if (!$user && str_starts_with($phone, '62')) {
        $alt = '0' . substr($phone, 2);
        $findByPhone->execute([$alt]);
        $user = $findByPhone->fetch() ?: null;
    }

    if ($user) {
        if (($user['status'] ?? '') !== 'active') {
            echo json_encode(['success' => false, 'message' => 'Akun terkait nomor ini tidak aktif. Hubungi admin.']);
            exit;
        }
        // Tandai nomor terverifikasi.
        $pdo->prepare("UPDATE users SET phone_verified = 1 WHERE id = ?")->execute([(int) $user['id']]);
    } else {
        // Tidak ada akun dgn nomor ini → buat baru.
        // Keputusan keamanan: bila EMAIL lead sudah dipakai akun lain → minta login
        // (jangan menempel otomatis ke akun orang lain / cegah ambil-alih).
        if ($leadEmail !== '') {
            $ce = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $ce->execute([$leadEmail]);
            if ($ce->fetchColumn()) {
                echo json_encode([
                    'success'  => false,
                    'message'  => 'Email kamu sudah terdaftar. Silakan login untuk mengakses bonus.',
                    'login_url' => rtrim(APP_URL, '/') . '/login',
                ]);
                exit;
            }
        }

        $finalEmail = $leadEmail !== '' ? $leadEmail : ('wa' . $phone . '@leads.local');
        $affCode    = generateAffiliateCode();

        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO users (name, email, password, phone, phone_verified, role, status, affiliate_code, created_at, updated_at)
             VALUES (?, ?, NULL, ?, 1, 'user', 'active', ?, NOW(), NOW())"
        )->execute([$leadName, $finalEmail, $phone, $affCode]);
        $newId = (int) $pdo->lastInsertId();

        $defaultRate = (float) (getSetting('affiliate_commission_rate', '10') ?: 10);
        try {
            $pdo->prepare(
                "INSERT INTO affiliates (user_id, code, commission_rate, status) VALUES (?, ?, ?, 'active')"
            )->execute([$newId, $affCode, $defaultRate]);
        } catch (Exception $affEx) {
            error_log('Lead promote affiliate insert error: ' . $affEx->getMessage());
        }
        $pdo->commit();

        $user = ['id' => $newId, 'name' => $leadName, 'email' => $finalEmail, 'role' => 'user'];
        logActivity('user', 'auth.register', 'user', $newId, 'Lead promote via OTP: ' . $phone);
    }

    $userId = (int) $user['id'];

    // ── Grant membership level (no-downgrade) + tandai lead ──
    if ($bonusLevel > 0) {
        lead_grant_membership_level($userId, $bonusLevel);
    }
    $pdo->prepare(
        "UPDATE leads SET promoted_user_id = ?, status = 'engaged',
                          bonus_granted_at = COALESCE(bonus_granted_at, NOW())
         WHERE id = ?"
    )->execute([$userId, $leadId]);

    // Email sequence trigger lead_captured — lead anonim baru punya akun sekarang.
    // Idempotent (unique sequence+user): tak dobel bila sudah ter-enroll saat submit.
    try {
        enrollUserToSequences('lead_captured', $userId, 0, 'lead:' . $leadId);
    } catch (Throwable $e) {
        error_log('Lead promote sequence enroll error: ' . $e->getMessage());
    }

    // ── Login ──
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['name']    = $user['name'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['role']    = $user['role'] ?? 'user';

    unset($_SESSION['lead_pending'], $_SESSION['lead_otp']);

    logActivity('user', 'lead.promote_otp', 'leads', $leadId, 'Lead diaktifkan via OTP → user #' . $userId);

    echo json_encode(['success' => true, 'redirect_to' => rtrim(APP_URL, '/') . '/member']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Lead verify OTP error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem. Coba lagi.']);
}

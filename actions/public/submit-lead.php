<?php
require_once __DIR__ . '/../../bootstrap.php';

// Selalu kembali ke landing (PRG). Slug dipakai untuk redirect.
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['slug'] ?? '')));
$backUrl = $slug !== '' ? ('/lp/' . $slug) : '/';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($backUrl);
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('lead_form', 'Sesi kedaluwarsa. Silakan muat ulang halaman.', 'error');
    redirect($backUrl);
}

// ─── Throttle ringan: min 3 detik antar submit per sesi ───────
$now = time();
if (isset($_SESSION['lead_last_submit']) && ($now - (int) $_SESSION['lead_last_submit']) < 3) {
    flash('lead_form', 'Tunggu sebentar sebelum mengirim lagi.', 'error');
    redirect($backUrl);
}

$magnetId = (int) ($_POST['lead_magnet_id'] ?? 0);
$pdo = getDB();

// ─── Pastikan magnet ada & published ─────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT id, slug, title, bonus_membership_level_id, require_otp FROM lead_magnets
         WHERE id = ? AND slug = ? AND status = 'published' LIMIT 1"
    );
    $stmt->execute([$magnetId, $slug]);
    $magnet = $stmt->fetch();
} catch (Exception $e) {
    error_log('Submit lead load magnet error: ' . $e->getMessage());
    $magnet = null;
}
if (!$magnet) {
    flash('lead_form', 'Form ini sedang tidak tersedia.', 'error');
    redirect($backUrl);
}

// ─── Data lead: dari akun bila sudah login, atau dari form bila anonim ──
$userId = 0;
if (isLoggedIn()) {
    $acct = null;
    try {
        $u = $pdo->prepare('SELECT id, name, email, phone FROM users WHERE id = ? LIMIT 1');
        $u->execute([(int) ($_SESSION['user_id'] ?? 0)]);
        $acct = $u->fetch() ?: null;
    } catch (Exception $e) {
        $acct = null;
    }
    if ($acct) {
        $userId   = (int) $acct['id'];
        $name     = trim((string) $acct['name']) !== '' ? trim((string) $acct['name']) : 'Member';
        $email    = trim((string) ($acct['email'] ?? ''));
        $acctWa   = lead_normalize_wa((string) ($acct['phone'] ?? ''));
        // Akun tanpa nomor → pakai WA dari form (field hanya tampil saat ini).
        $whatsapp = $acctWa !== '' ? $acctWa : lead_normalize_wa(trim((string) ($_POST['whatsapp'] ?? '')));
    } else {
        $name = ''; $email = ''; $whatsapp = '';
    }
} else {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $whatsapp = lead_normalize_wa(trim($_POST['whatsapp'] ?? ''));
}

// ─── Validasi ─────────────────────────────────────────────────
if ($name === '' || mb_strlen($name) < 2) {
    flash('lead_form', 'Nama lengkap wajib diisi.', 'error');
    redirect($backUrl);
}
if ($whatsapp === '' || strlen($whatsapp) < 10 || strlen($whatsapp) > 15) {
    flash('lead_form', 'Nomor WhatsApp tidak valid.', 'error');
    redirect($backUrl);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('lead_form', 'Format email tidak valid.', 'error');
    redirect($backUrl);
}

// ─── Cegah klaim ganda (per magnet): cocokkan via user / WA / email ──
try {
    $params = [(int) $magnet['id'], $whatsapp];
    $cond   = 'whatsapp = ?';
    if ($userId > 0)   { $cond .= ' OR promoted_user_id = ?'; $params[] = $userId; }
    if ($email !== '') { $cond .= ' OR email = ?';            $params[] = $email; }
    $dupSt = $pdo->prepare("SELECT id FROM leads WHERE lead_magnet_id = ? AND ($cond) LIMIT 1");
    $dupSt->execute($params);
    $existingId = (int) ($dupSt->fetchColumn() ?: 0);
} catch (Exception $e) {
    $existingId = 0;
}

$bonusLevelId     = (int) ($magnet['bonus_membership_level_id'] ?? 0);
// Magnet dengan konten (modul) sendiri butuh akun agar bisa diakses di member-area,
// meski tak memberi bonus level → tetap promote lead anonim → user via OTP.
$magnetHasContent = lead_magnet_has_content($magnetId);

if ($existingId > 0) {
    // Sudah pernah akses → perbarui data ringan, JANGAN buat baris baru.
    try {
        $pdo->prepare(
            "UPDATE leads
             SET name = ?, email = COALESCE(NULLIF(?, ''), email),
                 promoted_user_id = COALESCE(?, promoted_user_id)
             WHERE id = ?"
        )->execute([$name, $email, ($userId > 0 ? $userId : null), $existingId]);
    } catch (Exception $e) {
        error_log('Submit lead update error: ' . $e->getMessage());
    }
    $leadId = $existingId;
} else {
    // ─── Insert lead baru ──
    try {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        // Atribusi affiliate: dari ref (sesi/cookie) yg di-set bootstrap saat ?ref=CODE
        // (link /lp/{slug}?ref= atau link affiliate Canvas). Hanya dicatat utk lead BARU.
        $affId = null;
        // Atribusi ambient → hormati toggle yang sama dengan checkout: bila program
        // afiliasi mati atau General Cookie mati, jangan atribusikan lead ke afiliat.
        $refCode = affiliateAmbientEnabled()
            ? strtoupper(trim((string) ($_SESSION['ref_code'] ?? ($_COOKIE['aff_code'] ?? ($_COOKIE['averion_ref'] ?? '')))))
            : '';
        if ($refCode !== '') {
            try {
                $af = $pdo->prepare("SELECT id FROM affiliates WHERE code = ? AND status = 'active' LIMIT 1");
                $af->execute([$refCode]);
                $affId = ($r = $af->fetchColumn()) ? (int) $r : null;
            } catch (Exception $e) { /* abaikan — atribusi opsional */ }
        }
        $pdo->prepare(
            "INSERT INTO leads (lead_magnet_id, name, whatsapp, email, ip_address, source, status, promoted_user_id, affiliate_id)
             VALUES (?, ?, ?, ?, ?, 'lead_magnet', 'new', ?, ?)"
        )->execute([$magnetId, $name, $whatsapp, ($email ?: null), $ip, ($userId > 0 ? $userId : null), $affId]);
        $leadId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'UPDATE lead_magnets
             SET total_leads = (SELECT COUNT(*) FROM leads WHERE lead_magnet_id = ?)
             WHERE id = ?'
        )->execute([$magnetId, $magnetId]);
    } catch (Exception $e) {
        error_log('Submit lead insert error: ' . $e->getMessage());
        flash('lead_form', 'Terjadi kesalahan. Coba lagi sebentar.', 'error');
        redirect($backUrl);
    }
}

// ─── Grant bonus membership level (hanya user login) ──────────
// Guard: JANGAN downgrade — hanya naikkan bila level bonus lebih tinggi.
// Lead anonim belum punya akun → grant ditunda sampai promote-to-user (Phase 3).
if ($userId > 0 && $bonusLevelId > 0) {
    $grant = lead_grant_membership_level($userId, $bonusLevelId);
    if ($grant === 'granted' || $grant === 'kept_higher') {
        try {
            $pdo->prepare('UPDATE leads SET bonus_granted_at = NOW() WHERE id = ? AND bonus_granted_at IS NULL')
                ->execute([$leadId]);
        } catch (Exception $e) {
            error_log('Submit lead mark bonus error: ' . $e->getMessage());
        }
    }
}

// ─── Follow-up otomatis (hanya lead BARU; tidak spam saat re-claim) ──
// No-op bila admin belum membuat/mengaktifkan template lead_captured / lead_admin_notify.
if ($existingId <= 0) {
    try {
        require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
        $notifData = [
            'name'        => $name,
            'whatsapp'    => $whatsapp,
            'email'       => $email,
            'lead_magnet' => (string) ($magnet['title'] ?? ''),
        ];
        // Ke lead (email + WA, sesuai template aktif per channel).
        NotificationDispatcher::trigger('lead_captured', $notifData, $email, $whatsapp);

        // Ke admin (WA). Nomor: setting lead_admin_wa, fallback ke nomor admin pertama.
        $adminPhone = resolveAdminNotifyPhone();
        if ($adminPhone !== '') {
            NotificationDispatcher::trigger('lead_admin_notify', $notifData, '', $adminPhone);
        }
    } catch (Throwable $e) {
        error_log('Lead notify error: ' . $e->getMessage());
    }

    // Email sequence trigger lead_captured — enrollment butuh akun user:
    // pakai user login, atau cocokkan email lead ke user eksisting. Lead anonim
    // tanpa akun di-enroll nanti saat promote via OTP (lead-verify-otp.php).
    try {
        $seqUserId = $userId;
        if ($seqUserId <= 0 && $email !== '') {
            $uq = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $uq->execute([$email]);
            $seqUserId = (int) ($uq->fetchColumn() ?: 0);
        }
        if ($seqUserId > 0) {
            enrollUserToSequences('lead_captured', $seqUserId, 0, 'lead:' . $leadId);
        }
    } catch (Throwable $e) {
        error_log('Lead sequence enroll error: ' . $e->getMessage());
    }
}

// ─── Promote-to-user via OTP (anonim + magnet ber-bonus ATAU ber-konten) ──
// Lead anonim belum punya akun → siapkan langkah verifikasi WhatsApp agar bisa
// menerima bonus level + akses member-area. Lead tetap tersimpan walau OTP di-skip.
$requireOtp = ((int) ($magnet['require_otp'] ?? 1)) === 1;
if ($userId === 0 && ($bonusLevelId > 0 || $magnetHasContent)) {
    if ($requireOtp) {
        // Alur lama: verifikasi WhatsApp (OTP) dulu → baru akun dibuat & login.
        $_SESSION['lead_pending'] = [
            'lead_id'     => (int) $leadId,
            'phone'       => $whatsapp,
            'magnet_id'   => (int) $magnetId,
            'magnet_slug' => $slug,
        ];
        unset($_SESSION['lead_otp']); // reset OTP lama bila ganti lead/magnet
    } else {
        // OTP dimatikan admin utk magnet ini → promote LANGSUNG bila aman (nomor &
        // email belum dipakai akun lain). Bila bentrok → JANGAN auto-login (cegah
        // ambil-alih); lead tetap tercatat, jatuh ke thank-you + tombol login.
        unset($_SESSION['lead_pending'], $_SESSION['lead_otp']);
        $promo = leadPromoteNoOtp($pdo, (int) $leadId, $whatsapp, $email, $name, $bonusLevelId);
        if (!empty($promo['ok'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $promo['user_id'];
            $_SESSION['name']    = (string) $promo['name'];
            $_SESSION['email']   = (string) $promo['email'];
            $_SESSION['role']    = 'user';
            $_SESSION['lead_last_submit'] = $now;
            redirect('/member'); // masuk dashboard langsung, tanpa OTP
        }
        // collision / no_phone / error → biarkan lanjut ke thank-you (lead aman tercatat)
    }
} else {
    unset($_SESSION['lead_pending'], $_SESSION['lead_otp']);
}

$_SESSION['lead_last_submit'] = $now;
flash('lead_form', 'ok', 'success');
redirect($backUrl . '?submitted=1');

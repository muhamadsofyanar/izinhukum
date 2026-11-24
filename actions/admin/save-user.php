<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('users')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/users');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/users');
}

$userId  = (int) ($_POST['user_id'] ?? 0);
$name    = trim($_POST['name']   ?? '');
$email   = trim($_POST['email']  ?? '');
$phone   = trim($_POST['phone']  ?? '');
$role    = trim($_POST['role']   ?? 'user');
$status  = trim($_POST['status'] ?? 'active');
$pw      = $_POST['password']    ?? '';

// Validate
$validRoles    = ['user', 'admin'];
$validStatuses = ['active', 'inactive', 'banned'];

if ($name === '' || strlen($name) > 100) {
    flash('error', 'Nama tidak valid (1-100 karakter).', 'error');
    redirect('/admin/users');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
    flash('error', 'Alamat email tidak valid.', 'error');
    redirect('/admin/users');
}
if (!in_array($role,   $validRoles,   true)) { $role   = 'user'; }
if (!in_array($status, $validStatuses, true)) { $status = 'active'; }

// Phone wajib, numeric 10-15 digit
$phoneClean = preg_replace('/[^0-9]/', '', $phone);
if (!preg_match('/^[0-9]{10,15}$/', $phoneClean)) {
    flash('error', 'Nomor WhatsApp wajib diisi (10–15 digit).', 'error');
    redirect('/admin/users');
}
$phone = normalizeWaNumber($phoneClean);

try {
    $pdo = getDB();

    // ── Cegah privilege escalation ──────────────────────────────
    // Hanya ADMIN yang boleh menetapkan/mengubah role (mencegah staff mengangkat
    // dirinya/orang lain jadi admin) dan hanya admin yang boleh mengelola akun
    // non-'user' (admin/staff). Staff berizin 'users' hanya kelola user biasa.
    $isActorAdmin = isAdmin();
    if (!$isActorAdmin) {
        $role = 'user'; // staff tak bisa menetapkan admin
        if ($userId > 0) {
            $tgt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $tgt->execute([$userId]);
            if (($tgt->fetchColumn() ?: 'user') !== 'user') {
                flash('error', 'Hanya admin yang dapat mengelola akun admin/staff.', 'error');
                redirect('/admin/users');
            }
        }
    }

    if ($userId > 0) {
        // ── Update user ─────────────────────────────────────────
        // Check email unique (exclude self)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) {
            flash('error', 'Email sudah digunakan oleh pengguna lain.', 'error');
            redirect('/admin/users');
        }

        if ($pw !== '') {
            if (strlen($pw) < 8) {
                flash('error', 'Password minimal 8 karakter.', 'error');
                redirect('/admin/users');
            }
            $hash = password_hash($pw, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                "UPDATE users SET name=?, email=?, phone=?, role=?, status=?, password=?, updated_at=NOW()
                 WHERE id=?"
            );
            $stmt->execute([$name, $email, $phone ?: null, $role, $status, $hash, $userId]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE users SET name=?, email=?, phone=?, role=?, status=?, updated_at=NOW()
                 WHERE id=?"
            );
            $stmt->execute([$name, $email, $phone ?: null, $role, $status, $userId]);
        }

        logActivity('admin', 'user.update', 'user', $userId, 'User: ' . $name);
        flash('success', 'Pengguna berhasil diperbarui.');

    } else {
        // ── Create user ─────────────────────────────────────────
        if ($pw === '') {
            flash('error', 'Password wajib diisi untuk pengguna baru.', 'error');
            redirect('/admin/users');
        }
        if (strlen($pw) < 8) {
            flash('error', 'Password minimal 8 karakter.', 'error');
            redirect('/admin/users');
        }

        // Check email unique
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            flash('error', 'Email sudah terdaftar.', 'error');
            redirect('/admin/users');
        }

        $hash        = password_hash($pw, PASSWORD_BCRYPT);
        // Kode afiliasi unik (cek ke users & affiliates), bukan random tanpa cek.
        $affCode     = generateAffiliateCode();

        $stmt = $pdo->prepare(
            "INSERT INTO users (name, email, phone, password, role, status, affiliate_code, email_verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$name, $email, $phone ?: null, $hash, $role, $status, $affCode]);

        // Auto-create affiliate record — WAJIB sertakan code, kalau tidak
        // affiliates.code akan kosong dan link /go/{code}/{slug} jadi /go//{slug}.
        $newId       = (int) $pdo->lastInsertId();
        $defaultRate = (float) (getSetting('affiliate_commission_rate', '10') ?: 10);
        try {
            $pdo->prepare(
                "INSERT INTO affiliates (user_id, code, commission_rate, status)
                 VALUES (?, ?, ?, 'active')"
            )->execute([$newId, $affCode, $defaultRate]);
        } catch (Exception $e) {
            error_log('save-user: affiliate insert failed: ' . $e->getMessage());
        }

        logActivity('admin', 'user.create', 'user', $newId, 'User: ' . $name);
        flash('success', 'Pengguna baru berhasil ditambahkan.');
    }

    redirect('/admin/users');

} catch (Exception $e) {
    error_log('save-user error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan: ' . $e->getMessage(), 'error');
    redirect('/admin/users');
}

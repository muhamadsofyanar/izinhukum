<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/staff');
}

$name     = trim($_POST['name'] ?? '');
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$perms    = $_POST['permissions'] ?? [];

$allowedPerms = ['dashboard','products','transactions','users','content','coupons','affiliates',
                 'notifications','activity_logs','downloads','email_sequences','certificates','abandoned_carts','broadcast'];
$perms = is_array($perms) ? array_values(array_filter($perms, fn($p) => in_array($p, $allowedPerms, true))) : [];

if (strlen($name) < 2)                                   { flash('error', 'Nama minimal 2 karakter.', 'error'); redirect('/admin/staff'); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL))          { flash('error', 'Email tidak valid.', 'error'); redirect('/admin/staff'); }
if (strlen($password) < 8)                               { flash('error', 'Password minimal 8 karakter.', 'error'); redirect('/admin/staff'); }

$pdo = getDB();
try {
    $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $chk->execute([$email]);
    if ((int) $chk->fetchColumn() > 0) { flash('error', 'Email sudah digunakan.', 'error'); redirect('/admin/staff'); }

    $pdo->beginTransaction();

    $affiliateCode = generateAffiliateCode();
    $pdo->prepare("INSERT INTO users (name, email, password, affiliate_code, role, status) VALUES (?, ?, ?, ?, 'staff', 'active')")
        ->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT), $affiliateCode]);
    $staffId = (int) $pdo->lastInsertId();

    $rate = (float) (getSetting('affiliate_commission_rate', '10') ?: 10);
    $pdo->prepare("INSERT INTO affiliates (user_id, code, commission_rate, status) VALUES (?, ?, ?, 'active')")
        ->execute([$staffId, $affiliateCode, $rate]);

    if ($perms) {
        $ins = $pdo->prepare("INSERT INTO staff_permissions (user_id, permission, granted_by) VALUES (?, ?, ?)");
        foreach ($perms as $p) $ins->execute([$staffId, $p, $_SESSION['user_id']]);
    }

    $pdo->commit();

    logActivity('admin', 'staff.create', 'user', $staffId, "Buat akun staff: {$email} dengan " . count($perms) . " permission");
    flash('success', "Akun staff {$name} berhasil dibuat.");
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('save-staff error: ' . $e->getMessage());
    flash('error', 'Gagal membuat akun staff.', 'error');
}
redirect('/admin/staff');

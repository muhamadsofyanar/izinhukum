<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/staff');
}

$staffId = (int) ($_POST['staff_id'] ?? 0);
$perms   = $_POST['permissions'] ?? [];

$allowedPerms = ['dashboard','products','transactions','users','content','coupons','affiliates',
                 'notifications','activity_logs','downloads','email_sequences','certificates','abandoned_carts','broadcast'];
$perms = is_array($perms) ? array_values(array_filter($perms, fn($p) => in_array($p, $allowedPerms, true))) : [];

$pdo = getDB();
$stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'staff' LIMIT 1");
$stmt->execute([$staffId]);
$staff = $stmt->fetch();
if (!$staff) { flash('error', 'Staff tidak ditemukan.', 'error'); redirect('/admin/staff'); }

try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM staff_permissions WHERE user_id = ?")->execute([$staffId]);
    if ($perms) {
        $ins = $pdo->prepare("INSERT INTO staff_permissions (user_id, permission, granted_by) VALUES (?, ?, ?)");
        foreach ($perms as $p) $ins->execute([$staffId, $p, $_SESSION['user_id']]);
    }
    // Bump updated_at → invalidate cache permission staff di session-nya
    $pdo->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?")->execute([$staffId]);
    $pdo->commit();

    logActivity('admin', 'staff.permissions_update', 'user', $staffId, "Update permission staff {$staff['name']}: " . implode(', ', $perms));
    flash('success', "Akses staff {$staff['name']} berhasil diperbarui.");
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('save-staff-permissions error: ' . $e->getMessage());
    flash('error', 'Gagal memperbarui akses staff.', 'error');
}
redirect('/admin/staff');

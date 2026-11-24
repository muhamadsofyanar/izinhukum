<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/staff');
}

$staffId = (int) ($_POST['staff_id'] ?? 0);
$pdo = getDB();
$stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ? AND role = 'staff' LIMIT 1");
$stmt->execute([$staffId]);
$staff = $stmt->fetch();
if (!$staff) { flash('error', 'Staff tidak ditemukan.', 'error'); redirect('/admin/staff'); }

try {
    // Soft delete: turunkan ke user biasa + nonaktifkan (jaga data historis). Permission ikut cascade.
    $pdo->prepare("DELETE FROM staff_permissions WHERE user_id = ?")->execute([$staffId]);
    $pdo->prepare("UPDATE users SET role = 'user', status = 'inactive', updated_at = NOW() WHERE id = ?")->execute([$staffId]);

    logActivity('admin', 'staff.delete', 'user', $staffId, "Hapus akun staff: {$staff['email']}");
    flash('success', "Akun staff {$staff['name']} berhasil dihapus.");
} catch (Exception $e) {
    error_log('delete-staff error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus staff.', 'error');
}
redirect('/admin/staff');

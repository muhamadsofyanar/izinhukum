<?php
// Admin mulai "login as" user untuk troubleshoot/support. Session-only, tanpa DB write.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/users');
}

$targetId = (int) ($_POST['user_id'] ?? 0);
if (!$targetId) {
    flash('error', 'User tidak valid.', 'error');
    redirect('/admin/users');
}

$stmt = getDB()->prepare("SELECT id, name, email, role, status FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$targetId]);
$target = $stmt->fetch();

if (!$target) {
    flash('error', 'User tidak ditemukan.', 'error');
    redirect('/admin/users');
}
if ($target['role'] === 'admin') {
    flash('error', 'Tidak bisa impersonate akun admin.', 'error');
    redirect('/admin/users');
}
if ($target['status'] !== 'active') {
    flash('error', 'Tidak bisa impersonate user yang tidak aktif.', 'error');
    redirect('/admin/users');
}

// Snapshot admin saat ini
$_SESSION['impersonating']  = true;
$_SESSION['original_admin'] = [
    'user_id' => $_SESSION['user_id'],
    'name'    => $_SESSION['name']  ?? '',
    'email'   => $_SESSION['email'] ?? '',
    'role'    => $_SESSION['role']  ?? 'admin',
];

// Switch ke user target
$_SESSION['user_id'] = (int) $target['id'];
$_SESSION['name']    = $target['name'];
$_SESSION['email']   = $target['email'];
$_SESSION['role']    = $target['role'];
// Bersihkan cache permission staff jika ada (mencegah kebocoran konteks)
unset($_SESSION['staff_permissions'], $_SESSION['permissions_cached_at']);

logActivity('admin', 'user.impersonate_start', 'user', (int) $target['id'],
    'Admin mulai impersonate: ' . $target['email']);

flash('success', 'Mode pratinjau aktif — kamu sedang melihat sebagai ' . $target['name'] . '.', 'warning');
redirect('/dashboard');

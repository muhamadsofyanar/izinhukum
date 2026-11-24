<?php
// Keluar dari mode impersonate, kembalikan session admin asli.
require_once __DIR__ . '/../../bootstrap.php';

// Tidak pakai requireAdmin() — session saat ini adalah user yang di-impersonate.
if (empty($_SESSION['impersonating']) || empty($_SESSION['original_admin'])) {
    redirect('/dashboard');
}

if (!validateCSRF($_GET['csrf_token'] ?? '')) {
    flash('error', 'Token tidak valid.', 'error');
    redirect('/dashboard');
}

$impersonatedUserId = (int) ($_SESSION['user_id'] ?? 0);

$admin = $_SESSION['original_admin'];
$_SESSION['user_id'] = (int) $admin['user_id'];
$_SESSION['name']    = $admin['name'];
$_SESSION['email']   = $admin['email'];
$_SESSION['role']    = $admin['role'];

unset($_SESSION['impersonating'], $_SESSION['original_admin'],
      $_SESSION['staff_permissions'], $_SESSION['permissions_cached_at']);

logActivity('admin', 'user.impersonate_stop', 'user', $impersonatedUserId,
    'Admin selesai impersonate user ID: ' . $impersonatedUserId);

redirect('/admin/users');

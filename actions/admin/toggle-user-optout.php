<?php
// ─── Toggle berhenti berlangganan email promosi (email_opt_out) per-user ──
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('users')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin/users'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/admin/users'); }
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/users');
}

// Tujuan redirect kembali: hanya path internal (cegah open-redirect).
$back = (string) ($_POST['back'] ?? '/admin/users');
if ($back === '' || $back[0] !== '/' || str_contains($back, '//') || str_contains($back, '\\')) $back = '/admin/users';

$userId = (int) ($_POST['user_id'] ?? 0);
$optOut = (int) ($_POST['opt_out'] ?? 0) === 1 ? 1 : 0;
if ($userId <= 0) { flash('error', 'Pengguna tidak valid.', 'error'); redirect($back); }

try {
    $pdo = getDB();
    if (function_exists('ensureUserEmailOptOutColumn')) ensureUserEmailOptOutColumn($pdo);

    $st = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $user = $st->fetch();
    if (!$user) { flash('error', 'Pengguna tidak ditemukan.', 'error'); redirect($back); }

    $pdo->prepare("UPDATE users SET email_opt_out = ? WHERE id = ?")->execute([$optOut, $userId]);
    if (function_exists('logActivity')) {
        logActivity('admin', $optOut ? 'user.email_opt_out' : 'user.email_opt_in', 'user', $userId,
            ($optOut ? 'Berhenti langganan email promosi: ' : 'Aktifkan email promosi: ') . ($user['name'] ?? ''));
    }
    flash('success', $optOut
        ? '"' . ($user['name'] ?? '') . '" tidak akan menerima email promosi lagi.'
        : '"' . ($user['name'] ?? '') . '" kembali menerima email promosi.');
} catch (Exception $e) {
    error_log('toggle-user-optout error: ' . $e->getMessage());
    flash('error', 'Gagal memperbarui pengaturan.', 'error');
}

redirect($back);

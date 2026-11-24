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

$userId    = (int) ($_POST['user_id'] ?? 0);
$currentId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    flash('error', 'Pengguna tidak valid.', 'error');
    redirect('/admin/users');
}

// Tidak boleh menghapus akun sendiri
if ($userId === $currentId) {
    flash('error', 'Kamu tidak dapat menghapus akun sendiri.', 'error');
    redirect('/admin/users');
}

try {
    $pdo = getDB();

    // Verifikasi pengguna ada
    $stmt = $pdo->prepare('SELECT id, name, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        flash('error', 'Pengguna tidak ditemukan.', 'error');
        redirect('/admin/users');
    }

    // Cegah staff menghapus/ban akun admin/staff (privilege escalation) — admin-only.
    if (!isAdmin() && ($user['role'] ?? 'user') !== 'user') {
        flash('error', 'Hanya admin yang dapat menghapus akun admin/staff.', 'error');
        redirect('/admin/users');
    }

    // Jangan biarkan admin terakhir terhapus (cegah lockout)
    if ($user['role'] === 'admin') {
        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($adminCount <= 1) {
            flash('error', 'Tidak dapat menghapus admin terakhir.', 'error');
            redirect('/admin/users');
        }
    }

    // Cek riwayat pesanan yang sudah dibayar — jika ada, JANGAN hapus permanen
    // (FK orders ON DELETE CASCADE akan ikut menghapus histori keuangan).
    $paidOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'paid'");
    $paidOrders->execute([$userId]);
    $hasPaid = (int) $paidOrders->fetchColumn() > 0;

    if ($hasPaid) {
        // Punya histori transaksi — banned saja agar data keuangan tetap utuh.
        $pdo->prepare("UPDATE users SET status = 'banned' WHERE id = ?")->execute([$userId]);
        logActivity('admin', 'user.ban', 'user', $userId,
            'Pengguna punya histori transaksi — di-banned (tidak dihapus): ' . $user['name']);
        flash('success', 'Pengguna "' . $user['name'] . '" memiliki riwayat transaksi — akun di-banned (tidak dihapus permanen untuk menjaga data keuangan).', 'success');
    } else {
        // Tidak ada transaksi — hapus permanen (relasi dependent ikut terhapus via CASCADE).
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        logActivity('admin', 'user.delete', 'user', $userId, 'Pengguna: ' . $user['name']);
        flash('success', 'Pengguna "' . $user['name'] . '" berhasil dihapus.');
    }

} catch (Exception $e) {
    error_log('Delete user error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menghapus pengguna.', 'error');
}

redirect('/admin/users');

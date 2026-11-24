<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('coupons')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/coupons');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/coupons');
}

$couponId = (int) ($_POST['coupon_id'] ?? 0);

if ($couponId <= 0) {
    flash('error', 'Kupon tidak valid.', 'error');
    redirect('/admin/coupons');
}

try {
    $pdo = getDB();

    // Verifikasi kupon ada
    $stmt = $pdo->prepare('SELECT id, code FROM coupons WHERE id = ? LIMIT 1');
    $stmt->execute([$couponId]);
    $coupon = $stmt->fetch();

    if (!$coupon) {
        flash('error', 'Kupon tidak ditemukan.', 'error');
        redirect('/admin/coupons');
    }

    // Cek apakah kupon sudah pernah dipakai
    $useCount = $pdo->prepare('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ?');
    $useCount->execute([$couponId]);
    $used = (int) $useCount->fetchColumn();

    if ($used > 0) {
        // Sudah pernah dipakai — nonaktifkan saja, jangan hapus
        $pdo->prepare('UPDATE coupons SET status = ? WHERE id = ?')
            ->execute(['inactive', $couponId]);
        flash('success', 'Kupon "' . $coupon['code'] . '" sudah pernah dipakai — dinonaktifkan (tidak dihapus).', 'success');
    } else {
        // Belum pernah dipakai — hapus langsung
        $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([$couponId]);
        flash('success', 'Kupon "' . $coupon['code'] . '" berhasil dihapus.');
    }

} catch (Exception $e) {
    error_log('Delete coupon error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menghapus kupon.', 'error');
}

redirect('/admin/coupons');

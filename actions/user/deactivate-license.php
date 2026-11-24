<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/member/my-licenses');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/member/my-licenses');
}

$activationId = (int) ($_POST['activation_id'] ?? 0);
$licenseId    = (int) ($_POST['license_id']    ?? 0);
$userId       = (int) $_SESSION['user_id'];

try {
    $pdo = getDB();

    // Verifikasi: license & aktivasi ini benar milik user yang login (security!)
    $chk = $pdo->prepare(
        "SELECT pl.id
         FROM product_licenses pl
         JOIN license_activations la ON la.license_id = pl.id
         WHERE pl.user_id = ? AND pl.id = ? AND la.id = ? AND la.status = 'active'
         LIMIT 1"
    );
    $chk->execute([$userId, $licenseId, $activationId]);

    if (!$chk->fetch()) {
        flash('error', 'Data tidak ditemukan atau bukan milikmu.', 'error');
        redirect('/member/my-licenses');
    }

    $pdo->beginTransaction();

    $pdo->prepare(
        "UPDATE license_activations SET status = 'deactivated', deactivated_at = NOW() WHERE id = ?"
    )->execute([$activationId]);

    $pdo->prepare(
        "UPDATE product_licenses SET used_seats = GREATEST(0, used_seats - 1) WHERE id = ?"
    )->execute([$licenseId]);

    $pdo->commit();
    flash('success', 'Aktivasi berhasil dihapus. Slot siap digunakan di domain/device lain.');

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Deactivate license (user) error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan.', 'error');
}

redirect('/member/my-licenses');

<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/bank-accounts');
}

$id = (int) ($_POST['id'] ?? 0);
if ($id < 1) {
    flash('error', 'Rekening tidak valid.', 'error');
    redirect('/admin/bank-accounts');
}

try {
    $pdo = getDB();

    // Tolak hapus bila sudah pernah dipakai di transaksi (jaga integritas riwayat).
    $used = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE bank_account_id = ?");
    $used->execute([$id]);
    if ((int) $used->fetchColumn() > 0) {
        flash('error', 'Rekening tidak bisa dihapus karena sudah pernah dipakai dalam transaksi. Nonaktifkan saja.', 'error');
        redirect('/admin/bank-accounts');
    }

    $pdo->prepare("DELETE FROM bank_accounts WHERE id = ?")->execute([$id]);
    logActivity('admin', 'bank_account.delete', 'bank_account', $id, 'Hapus rekening ID ' . $id);
    flash('success', 'Rekening berhasil dihapus.');
} catch (Exception $e) {
    error_log('delete-bank-account error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus rekening.', 'error');
}

redirect('/admin/bank-accounts');

<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('users')) { redirect('/admin/no-access'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/subscriptions');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/admin/subscriptions');
}

$action   = $_POST['action']    ?? '';
$accessId = (int) ($_POST['access_id'] ?? 0);

if ($accessId < 1 || !in_array($action, ['extend', 'revoke'], true)) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/subscriptions');
}

try {
    $pdo = getDB();

    // Ambil konteks akses (untuk log & validasi keberadaan).
    $stmt = $pdo->prepare(
        "SELECT ma.id, ma.expires_at, u.name AS user_name, p.name AS product_name
         FROM member_access ma
         JOIN users u    ON u.id = ma.user_id
         JOIN products p ON p.id = ma.product_id
         WHERE ma.id = ? LIMIT 1"
    );
    $stmt->execute([$accessId]);
    $acc = $stmt->fetch();
    if (!$acc) {
        flash('error', 'Data langganan tidak ditemukan.', 'error');
        redirect('/admin/subscriptions');
    }

    if ($action === 'extend') {
        $days = (int) ($_POST['days'] ?? 0);
        if ($days < 1 || $days > 3650) {
            flash('error', 'Jumlah hari perpanjangan tidak valid (1–3650).', 'error');
            redirect('/admin/subscriptions');
        }
        // Tambah hari dari tanggal berakhir saat ini, atau dari sekarang bila sudah
        // lewat/lifetime. Aktifkan kembali & reset siklus reminder renewal.
        $pdo->prepare(
            "UPDATE member_access
             SET expires_at           = DATE_ADD(GREATEST(COALESCE(expires_at, NOW()), NOW()), INTERVAL ? DAY),
                 status               = 'active',
                 renewal_remind_stage = 0
             WHERE id = ?"
        )->execute([$days, $accessId]);

        logActivity('admin', 'subscription.extend', 'member_access', $accessId,
            'Perpanjang ' . $days . ' hari: ' . $acc['product_name'] . ' — ' . $acc['user_name']);
        flash('success', 'Akses "' . $acc['product_name'] . '" diperpanjang ' . $days . ' hari.');

    } elseif ($action === 'revoke') {
        $pdo->prepare("UPDATE member_access SET status = 'revoked' WHERE id = ?")->execute([$accessId]);
        logActivity('admin', 'subscription.revoke', 'member_access', $accessId,
            'Cabut akses: ' . $acc['product_name'] . ' — ' . $acc['user_name']);
        flash('success', 'Akses "' . $acc['product_name'] . '" milik ' . $acc['user_name'] . ' dicabut.');
    }

} catch (Exception $e) {
    error_log('manage-subscription error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan. Silakan coba lagi.', 'error');
}

redirect('/admin/subscriptions');

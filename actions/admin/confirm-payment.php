<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('transactions')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/transactions');
}

$orderId = (int) ($_POST['order_id'] ?? 0);

try {
    $pdo = getDB();

    $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
    $oStmt->execute([$orderId]);
    $order = $oStmt->fetch();

    if (!$order) {
        flash('error', 'Order tidak ditemukan.', 'error');
        redirect('/admin/transactions');
    }
    if ($order['status'] !== 'pending') {
        flash('info', 'Order ini sudah diproses (status: ' . $order['status'] . ').', 'info');
        redirect('/admin/transactions/' . $order['order_number']);
    }

    $pdo->beginTransaction();

    // Lock + re-check untuk cegah double-konfirmasi (race).
    $lock = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
    $lock->execute([$orderId]);
    if ($lock->fetchColumn() !== 'pending') {
        $pdo->rollBack();
        flash('info', 'Order sudah diproses oleh request lain.', 'info');
        redirect('/admin/transactions/' . $order['order_number']);
    }

    // 1. Order → paid
    $pdo->prepare("UPDATE orders SET status = 'paid', paid_at = NOW(), updated_at = NOW() WHERE id = ?")
        ->execute([$orderId]);

    // 2. Payment → success + jejak konfirmasi admin
    $pdo->prepare(
        "UPDATE payments SET status = 'success', paid_at = NOW(), confirmed_by = ?, confirmed_at = NOW(), rejection_reason = NULL WHERE order_id = ?"
    )->execute([(int) $_SESSION['user_id'], $orderId]);

    // 3. Jalur paid terpusat: aktivasi akses (guard booking di dalam), sequence,
    //    lisensi, komisi afiliasi, membership level. Lihat helpers.php.
    $paidCtx = onOrderPaid($pdo, $order);

    $pdo->commit();

    // 4. Notifikasi payment_success + access_activated (skip utk booking, di luar transaksi).
    //    capi=true: kirim Purchase ke Meta CAPI juga utk pembayaran yang dikonfirmasi
    //    manual (mis. transfer bank) — tanpa ini, pembeli dari iklan yang bayar manual
    //    tak pernah tercatat Purchase-nya. Idempoten: event_id = purchase_{order_number}
    //    sama dgn Purchase browser (thank-you) & webhook → Meta dedup, tak dobel.
    onOrderPaidPostCommit($order, $paidCtx, ['capi' => true, 'notify_access' => true]);

    logActivity('admin', 'payment.confirm', 'order', $orderId, 'Konfirmasi pembayaran manual order ' . $order['order_number']);
    flash('success', $paidCtx['is_booking']
        ? 'Pembayaran booking dikonfirmasi. Akses terbuka setelah pelunasan.'
        : 'Pembayaran dikonfirmasi. Akses member sudah diaktifkan.');
    redirect('/admin/transactions/' . $order['order_number']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('confirm-payment error: ' . $e->getMessage());
    flash('error', 'Gagal mengkonfirmasi pembayaran.', 'error');
    redirect('/admin/transactions');
}

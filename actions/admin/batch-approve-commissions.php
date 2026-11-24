<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('affiliates')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/commissions'); }

$ids = array_values(array_filter(array_map('intval', (array) ($_POST['commission_ids'] ?? []))));
if (!$ids) { flash('error', 'Tidak ada komisi yang dipilih.', 'error'); redirect('/admin/commissions'); }

$pdo = getDB();
try {
    $pdo->beginTransaction();
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sel = $pdo->prepare("SELECT id, affiliate_id, amount, order_id FROM commissions WHERE id IN ($ph) AND status = 'pending'");
    $sel->execute($ids);
    $valid = $sel->fetchAll();
    $validIds = array_column($valid, 'id');
    if (!$validIds) { $pdo->rollBack(); flash('error', 'Tidak ada komisi pending valid.', 'error'); redirect('/admin/commissions'); }

    $ph2 = implode(',', array_fill(0, count($validIds), '?'));
    $pdo->prepare("UPDATE commissions SET status='approved', approved_at=NOW() WHERE id IN ($ph2)")->execute($validIds);

    // Selaras jalur otomatis (onOrderPaid): naikkan total_earned per affiliate untuk
    // komisi yang di-approve. Tanpa ini, approve massal tak menaikkan total_earned →
    // dashboard affiliate & Leaderboard understate.
    $earnByAff = [];
    foreach ($valid as $c) {
        $aid = (int) $c['affiliate_id'];
        $earnByAff[$aid] = ($earnByAff[$aid] ?? 0) + (float) $c['amount'];
    }
    $incStmt = $pdo->prepare("UPDATE affiliates SET total_earned = total_earned + ? WHERE id = ?");
    foreach ($earnByAff as $aid => $sum) {
        $incStmt->execute([$sum, $aid]);
    }
    $pdo->commit();

    $count = count($validIds);
    logActivity('admin', 'commission.batch_approve', 'commission', null, "Approve $count komisi");

    // Notifikasi per affiliate (digabung)
    require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
    $byAff = [];
    foreach ($valid as $c) $byAff[$c['affiliate_id']][] = $c;
    foreach ($byAff as $affId => $comms) {
        $uStmt = $pdo->prepare("SELECT u.name, u.email, u.phone FROM users u JOIN affiliates a ON a.user_id = u.id WHERE a.id = ?");
        $uStmt->execute([$affId]);
        $au = $uStmt->fetch();
        if (!$au) continue;
        $totalApproved = array_sum(array_column($comms, 'amount'));
        $orderLabel = count($comms) > 1 ? (count($comms) . ' order') : ('order #' . ($comms[0]['order_id'] ?? '-'));
        NotificationDispatcher::trigger('commission_approved', [
            'name'          => $au['name'],
            'email'         => $au['email'],
            'amount'        => formatRupiah($totalApproved),
            'order_number'  => $orderLabel,
            'affiliate_url' => baseUrl('affiliate'),
        ], $au['email'], $au['phone'] ?? '');
    }
    flash('success', "$count komisi berhasil disetujui.");
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('batch-approve-commissions error: ' . $e->getMessage());
    flash('error', 'Gagal approve komisi.', 'error');
}
redirect('/admin/commissions');

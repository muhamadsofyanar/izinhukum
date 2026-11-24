<?php
// Aksi massal transaksi: konfirmasi LUNAS / BATALKAN beberapa order sekaligus.
// Memakai changeOrderStatus() (pipeline sama dgn aksi tunggal) → per order 1
// transaksi DB sendiri; satu gagal tak menggagalkan yang lain.
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('transactions')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin/transactions'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/admin/transactions');
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/transactions');
}

$action   = $_POST['bulk_action'] ?? '';
$rawIds   = (array) ($_POST['order_ids'] ?? []);
$orderIds = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn($n) => $n > 0)));
$backTo   = trim($_POST['back_to'] ?? '');   // querystring filter utk kembali ke tampilan semula

// Redirect helper: kembali ke daftar dgn filter yang sama bila ada.
$redirectBack = function () use ($backTo) {
    $url = '/admin/transactions';
    if ($backTo !== '') $url .= '?' . ltrim($backTo, '?');
    redirect($url);
};

if (!in_array($action, ['confirm', 'cancel', 'delete'], true)) {
    flash('error', 'Aksi massal tidak dikenal.', 'error');
    $redirectBack();
}
// Hapus permanen = aksi sensitif → khusus admin (sejalan dgn delete-transaction.php).
if ($action === 'delete' && !isAdmin()) {
    flash('error', 'Hanya admin yang dapat menghapus transaksi.', 'error');
    $redirectBack();
}
if (!$orderIds) {
    flash('error', 'Pilih minimal satu transaksi.', 'error');
    $redirectBack();
}
// Batas aman agar satu request tak memproses ratusan order sekaligus.
if (count($orderIds) > 100) $orderIds = array_slice($orderIds, 0, 100);

try {
    $pdo = getDB();
    $ph  = implode(',', array_fill(0, count($orderIds), '?'));

    // ── HAPUS MASSAL: hanya order berstatus 'cancelled' ──────────────────
    // Guard server-side keras: paid & pending TAK AKAN pernah terhapus lewat
    // aksi massal, apa pun id yang dikirim. Relasi dependent (order_items,
    // payments, commissions, coupon_usages, member_access) ikut terhapus via
    // FK ON DELETE CASCADE — sama seperti delete-transaction.php.
    if ($action === 'delete') {
        $sel = $pdo->prepare("SELECT id FROM orders WHERE id IN ($ph) AND status = 'cancelled'");
        $sel->execute($orderIds);
        $delIds = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));

        $done = 0;
        if ($delIds) {
            $dph = implode(',', array_fill(0, count($delIds), '?'));
            $del = $pdo->prepare("DELETE FROM orders WHERE id IN ($dph)");
            $del->execute($delIds);
            $done = $del->rowCount();
        }
        $skipped = count($orderIds) - $done;

        logActivity('admin', 'order.bulk_delete', 'order', 0,
            "Hapus massal transaksi dibatalkan: $done dihapus, $skipped dilewati (bukan cancelled).");

        $msg = "$done transaksi dibatalkan dihapus permanen.";
        if ($skipped > 0) $msg .= " $skipped dilewati (bukan berstatus dibatalkan).";
        flash('success', $msg, 'success');
        $redirectBack();
    }

    $newStatus = $action === 'confirm' ? 'paid' : 'cancelled';
    // Ambil hanya order PENDING (aksi massal khusus pending — cegah menyentuh
    // yang sudah paid/cancelled). GROUP_CONCAT product_ids agar kompatibel dgn
    // konsumen $order['product_ids'] (spt onOrderPaid via update-transaction).
    $stmt = $pdo->prepare(
        "SELECT o.*, GROUP_CONCAT(oi.product_id) AS product_ids
         FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id
         WHERE o.id IN ($ph) AND o.status = 'pending'
         GROUP BY o.id"
    );
    $stmt->execute($orderIds);
    $orders = $stmt->fetchAll();

    $done = 0; $skipped = 0; $failed = 0;
    foreach ($orders as $order) {
        $res = changeOrderStatus($pdo, $order, $newStatus);
        if ($res['ok'] && $res['changed'])      $done++;
        elseif ($res['ok'] && !$res['changed']) $skipped++;
        else                                    $failed++;
    }
    // Order yang diminta tapi bukan pending (sudah berubah status) → dilewati.
    $notPending = count($orderIds) - count($orders);
    $skipped   += max(0, $notPending);

    logActivity('admin', 'order.bulk_' . $action, 'order', 0,
        "Aksi massal $action: $done berhasil, $skipped dilewati, $failed gagal");

    $verb = $action === 'confirm' ? 'dikonfirmasi LUNAS' : 'dibatalkan';
    $msg  = "$done transaksi $verb.";
    if ($skipped > 0) $msg .= " $skipped dilewati (bukan pending).";
    if ($failed  > 0) $msg .= " $failed gagal.";
    flash($failed > 0 ? 'error' : 'success', $msg, $failed > 0 ? 'error' : 'success');
} catch (Throwable $e) {
    error_log('bulk-transaction error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat memproses aksi massal.', 'error');
}

$redirectBack();

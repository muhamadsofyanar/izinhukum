<?php
// ════════════════════════════════════════════════════════════════
// Averion — Action: Kirim Ulang Notifikasi Order (pilih WhatsApp / Email)
// POST + CSRF. Admin/staff ber-izin 'transactions'. Hanya order 'paid'.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('transactions')) {
    flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error');
    redirect('/admin');
}

$orderNumber = preg_replace('/[^A-Za-z0-9_-]/', '', trim($_POST['order_number'] ?? ''));
$backUrl     = $orderNumber !== '' ? ('/admin/transactions/' . $orderNumber) : '/admin/transactions';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect($backUrl);
}

$channel = strtolower(trim($_POST['channel'] ?? ''));
if (!in_array($channel, ['whatsapp', 'email'], true)) {
    flash('error', 'Pilih channel WhatsApp atau Email.', 'error');
    redirect($backUrl);
}

try {
    $res = resendOrderNotification($orderNumber, $channel);
    if ($res['ok']) {
        logActivity('admin', 'notification.resend', 'order', 0,
            'Kirim ulang notifikasi ' . $channel . ' order ' . $orderNumber . ' → ' . ($res['recipient'] ?? '') . ' (sukses)');
        flash('success', $res['message']);
    } else {
        flash('error', $res['message'], 'error');
    }
} catch (Throwable $e) {
    error_log('resend-order-notification error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat mengirim ulang notifikasi.', 'error');
}

redirect($backUrl);

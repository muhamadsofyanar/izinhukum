<?php
// Jalankan proses expiry: cancel order pending yang sudah melewati batas waktu.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/transactions');
}

$expiryHours = (int) getSetting('order_expiry_hours', '0');

if ($expiryHours <= 0) {
    flash('info', 'Fitur order expiry tidak aktif. Atur jam expiry di Pengaturan.', 'warning');
    redirect('/admin/transactions');
}

$count = processExpiredOrders();
if ($count > 0) {
    flash('success', "$count order pending berhasil di-cancel dan notifikasi WhatsApp telah dikirimkan ke pembeli.");
} else {
    flash('info', 'Tidak ada order yang perlu di-cancel.', 'info');
}

redirect('/admin/transactions');

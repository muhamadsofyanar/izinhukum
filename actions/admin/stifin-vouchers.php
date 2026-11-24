<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/voucher-tes');
}
if (!validateCSRF($_POST['csrf_token'] ?? '') || stifinVoucherProductId() === 0) {
    flash('error', 'Permintaan tidak valid atau fitur voucher belum diaktifkan.', 'error');
    redirect('/admin/voucher-tes');
}

$action = $_POST['action'] ?? '';
$added = null;
try {
    $db = getDB();
    if ($action === 'transfer' && stifinVoucherTransferEnabled()) {
        stifinProcessVoucherTransfers($db);
        flash('success', 'Antrean transfer diperiksa. Lihat hasil pada daftar transfer.');
        redirect('/admin/voucher-tes');
    }
    if ($action === 'import') {
        $raw = $_POST['codes'] ?? '';
        if (!is_string($raw) || strlen($raw) > 65000) {
            throw new InvalidArgumentException('Ukuran daftar voucher melebihi batas.');
        }
        $added = stifinImportVoucherCodes($db, $raw);
    } elseif ($action !== 'distribute') {
        throw new InvalidArgumentException('Tindakan tidak tersedia.');
    }
    stifinDistributeVouchers($db);
    flash('success', $added === null ? 'Antrean voucher diperiksa.' : "{$added} kode baru disimpan. Antrean voucher diperiksa.");
} catch (InvalidArgumentException $error) {
    flash('error', $error->getMessage(), 'error');
} catch (Throwable $error) {
    error_log('[stifin-voucher] Operasi admin gagal.');
    $message = $added === null ? 'Operasi voucher gagal. Periksa instalasi modul.'
        : "{$added} kode disimpan, tetapi distribusi tertunda. Jalankan distribusi lagi.";
    flash('error', $message, 'error');
}
redirect('/admin/voucher-tes');

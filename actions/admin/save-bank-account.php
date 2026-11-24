<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/bank-accounts');
}

$pdo = getDB();
$id  = (int) ($_POST['id'] ?? 0);

// Toggle aktif/nonaktif cepat (dari badge status di tabel)
if (!empty($_POST['toggle_only']) && $id > 0) {
    $pdo->prepare("UPDATE bank_accounts SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ?")->execute([$id]);
    flash('success', 'Status rekening diperbarui.');
    redirect('/admin/bank-accounts');
}

$channelType   = ($_POST['channel_type'] ?? 'bank') === 'ewallet' ? 'ewallet' : 'bank';
$bankName      = trim($_POST['bank_name'] ?? '');
$accountNumber = preg_replace('/[^0-9\-]/', '', $_POST['account_number'] ?? '');
$accountName   = trim($_POST['account_name'] ?? '');
$instructions  = trim($_POST['instructions'] ?? '');
$isActive      = isset($_POST['is_active']) ? 1 : 0;
$sortOrder     = (int) ($_POST['sort_order'] ?? 0);

if ($bankName === '' || $accountNumber === '' || $accountName === '') {
    $msg = $channelType === 'ewallet'
        ? 'Provider, nomor HP, dan atas nama wajib diisi.'
        : 'Nama bank, nomor rekening, dan atas nama wajib diisi.';
    flash('error', $msg, 'error');
    redirect('/admin/bank-accounts');
}

try {
    if ($id > 0) {
        $pdo->prepare(
            "UPDATE bank_accounts SET bank_name=?, channel_type=?, account_number=?, account_name=?, instructions=?, is_active=?, sort_order=?, updated_at=NOW() WHERE id=?"
        )->execute([$bankName, $channelType, $accountNumber, $accountName, $instructions ?: null, $isActive, $sortOrder, $id]);
        logActivity('admin', 'bank_account.update', 'bank_account', $id, 'Update channel pembayaran: ' . $bankName);
        flash('success', 'Channel pembayaran berhasil diperbarui.');
    } else {
        $pdo->prepare(
            "INSERT INTO bank_accounts (bank_name, channel_type, account_number, account_name, instructions, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$bankName, $channelType, $accountNumber, $accountName, $instructions ?: null, $isActive, $sortOrder]);
        logActivity('admin', 'bank_account.create', 'bank_account', (int) $pdo->lastInsertId(), 'Tambah channel pembayaran: ' . $bankName);
        flash('success', 'Channel pembayaran berhasil ditambahkan.');
    }
} catch (Exception $e) {
    error_log('save-bank-account error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan channel pembayaran.', 'error');
}

redirect('/admin/bank-accounts');

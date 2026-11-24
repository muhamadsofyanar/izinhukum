<?php
// POST /actions/user/save-affiliate-bank — simpan rekening pencairan affiliate.
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/affiliate');
}

$pdo = getDB();
$aff = $pdo->prepare("SELECT id FROM affiliates WHERE user_id = ? LIMIT 1");
$aff->execute([(int) $_SESSION['user_id']]);
$affiliateId = (int) $aff->fetchColumn();
if (!$affiliateId) {
    flash('error', 'Akun affiliate tidak ditemukan.', 'error');
    redirect('/affiliate');
}

$bankName = trim($_POST['bank_name'] ?? '');
$bankNo   = trim($_POST['bank_account_no'] ?? '');
$bankAcc  = trim($_POST['bank_account_name'] ?? '');

if ($bankName === '' || $bankNo === '' || $bankAcc === '') {
    flash('error', 'Lengkapi semua data rekening.', 'error');
    redirect('/affiliate');
}
if (!preg_match('/^[0-9]{5,30}$/', preg_replace('/[\s-]/', '', $bankNo))) {
    flash('error', 'Nomor rekening tidak valid (hanya angka, 5–30 digit).', 'error');
    redirect('/affiliate');
}

$pdo->prepare(
    "UPDATE affiliates SET bank_name = ?, bank_account_no = ?, bank_account_name = ? WHERE id = ?"
)->execute([
    mb_substr($bankName, 0, 100), mb_substr($bankNo, 0, 50), mb_substr($bankAcc, 0, 100), $affiliateId,
]);

flash('success', 'Rekening pencairan disimpan.');
redirect('/affiliate');

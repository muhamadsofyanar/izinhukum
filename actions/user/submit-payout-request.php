<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/affiliate'); }

$bankName = trim($_POST['bank_name'] ?? '');
$bankNo   = trim($_POST['bank_account_no'] ?? '');
$bankAcc  = trim($_POST['bank_account_name'] ?? '');
$note     = trim($_POST['note'] ?? '');
$minPayout = (float) getSetting('min_payout_amount', '50000');

$pdo = getDB();
$pdo->beginTransaction();
try {
    // Kunci baris affiliate → serialize pengajuan bersamaan dari user yang sama
    // (cegah double-submit cepat menghasilkan 2 request pending sekaligus).
    $aff = $pdo->prepare("SELECT id FROM affiliates WHERE user_id = ? LIMIT 1 FOR UPDATE");
    $aff->execute([(int) $_SESSION['user_id']]);
    $affiliateId = (int) $aff->fetchColumn();
    if (!$affiliateId) { $pdo->rollBack(); flash('error', 'Akun affiliate tidak ditemukan.', 'error'); redirect('/affiliate'); }

    $ex = $pdo->prepare("SELECT id FROM payout_requests WHERE affiliate_id = ? AND status IN ('pending','processing') LIMIT 1");
    $ex->execute([$affiliateId]);
    if ($ex->fetchColumn()) { $pdo->rollBack(); flash('error', 'Kamu masih punya request pencairan yang belum selesai.', 'error'); redirect('/affiliate'); }

    $bal = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE affiliate_id = ? AND status = 'approved'");
    $bal->execute([$affiliateId]);
    $approvedBalance = (float) $bal->fetchColumn();

    // Pencairan SELALU sebesar seluruh saldo komisi approved (bukan jumlah parsial dari user).
    // Alasan: mark-payout-paid menandai komisi approved (pada/sebelum waktu request) menjadi
    // 'paid'. Mengunci ke saldo penuh menjamin jumlah cair = total komisi yang ditandai paid.
    $amount = $approvedBalance;
    if ($amount <= 0)                  { $pdo->rollBack(); flash('error', 'Tidak ada saldo komisi yang bisa dicairkan.', 'error'); redirect('/affiliate'); }
    if ($amount < $minPayout)          { $pdo->rollBack(); flash('error', 'Saldo belum mencapai minimal pencairan ' . formatRupiah($minPayout) . '.', 'error'); redirect('/affiliate'); }
    if ($bankName === '' || $bankNo === '' || $bankAcc === '') { $pdo->rollBack(); flash('error', 'Lengkapi data rekening bank.', 'error'); redirect('/affiliate'); }

    $pdo->prepare("INSERT INTO payout_requests (affiliate_id, amount, bank_name, bank_account_no, bank_account_name, note, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')")
        ->execute([$affiliateId, $amount, $bankName, $bankNo, $bankAcc, $note]);
    $payoutId = (int) $pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('submit-payout-request error: ' . $e->getMessage());
    flash('error', 'Gagal mengajukan pencairan. Coba lagi.', 'error');
    redirect('/affiliate');
}

// Ingat rekening terakhir di profil affiliate → prefill payout berikutnya (best-effort,
// di luar transaksi: gagal di sini tak boleh membatalkan request yang sudah tercatat).
try {
    $pdo->prepare("UPDATE affiliates SET bank_name = ?, bank_account_no = ?, bank_account_name = ? WHERE id = ?")
        ->execute([$bankName, $bankNo, $bankAcc, $affiliateId]);
} catch (Throwable $e) {
    error_log('payout save bank to affiliate: ' . $e->getMessage()); // kolom belum ada (pra-migrasi)
}

logActivity('user', 'payout.request', 'payout_request', $payoutId, 'Ajukan pencairan ' . formatRupiah($amount));
flash('success', 'Request pencairan diajukan. Admin memproses dalam 1-3 hari kerja.');
redirect('/affiliate');

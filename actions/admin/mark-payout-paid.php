<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('affiliates')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/payouts'); }

$payoutId  = (int) ($_POST['payout_id'] ?? 0);
$adminNote = trim($_POST['admin_note'] ?? '');

$pdo = getDB();
$stmt = $pdo->prepare("SELECT pr.*, a.id AS affiliate_id, u.name, u.email, u.phone FROM payout_requests pr JOIN affiliates a ON a.id=pr.affiliate_id JOIN users u ON u.id=a.user_id WHERE pr.id=? AND pr.status IN ('pending','processing')");
$stmt->execute([$payoutId]);
$payout = $stmt->fetch();
if (!$payout) { flash('error', 'Payout tidak ditemukan / sudah diproses.', 'error'); redirect('/admin/payouts'); }

// Upload bukti (opsional)
$proofFilename = null; $proofPath = null;
if (!empty($_FILES['proof']['tmp_name']) && ($_FILES['proof']['error'] ?? 1) === UPLOAD_ERR_OK) {
    $file = $_FILES['proof'];
    if ($file['size'] > 5 * 1024 * 1024) { flash('error', 'Ukuran bukti maks 5MB.', 'error'); redirect('/admin/payouts'); }
    $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
    if (!in_array($mime, ['image/jpeg','image/png','image/webp','application/pdf'], true)) { flash('error', 'Format bukti: JPG, PNG, PDF.', 'error'); redirect('/admin/payouts'); }
    $ext = strtolower(preg_replace('/[^a-z0-9]/', '', pathinfo($file['name'], PATHINFO_EXTENSION)));
    $proofFilename = 'payout_' . $payoutId . '_' . time() . ($ext ? '.' . $ext : '');
    $dir = UPLOAD_PATH . 'payouts/';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!move_uploaded_file($file['tmp_name'], $dir . $proofFilename)) { flash('error', 'Gagal simpan bukti.', 'error'); redirect('/admin/payouts'); }
    compressUploadedImage($dir . $proofFilename); // PDF dilewati otomatis
    $proofPath = 'payouts/' . $proofFilename;
}

try {
    $pdo->beginTransaction();
    // Transisi status atomik: hanya SATU request yang menang (cegah race double-proses
    // → total_withdrawn dobel + notifikasi "payout dibayar" ganda).
    $upd = $pdo->prepare("UPDATE payout_requests SET status='paid', admin_note=?, proof_filename=?, proof_path=?, processed_by=?, processed_at=NOW(), paid_at=NOW() WHERE id=? AND status IN ('pending','processing')");
    $upd->execute([$adminNote !== '' ? $adminNote : null, $proofFilename, $proofPath, (int) $_SESSION['user_id'], $payoutId]);
    if ($upd->rowCount() !== 1) {
        $pdo->rollBack();
        flash('info', 'Payout sudah diproses oleh request lain.', 'info');
        redirect('/admin/payouts');
    }

    // Mark approved commissions affiliate ini → paid.
    // HANYA komisi yang sudah approved pada/sebelum waktu request dibuat
    // (payout.created_at = T0). Komisi yang baru di-approve SETELAH request (penjualan
    // baru selama window proses 1-3 hari) TIDAK ikut tersapu → tak hangus, tetap bisa
    // dicairkan di payout berikutnya. amount payout = snapshot SUM(approved) di T0, jadi
    // himpunan yang disapu persis = nominal yang dibayar. approved_at NULL (mis. komisi
    // order gratis / data lama yang tak mengisi approved_at) → pakai created_at sebagai
    // proksi waktu approve.
    $reqAt    = (string) $payout['created_at'];
    $approved = $pdo->prepare(
        "SELECT id FROM commissions
         WHERE affiliate_id = ? AND status = 'approved'
           AND (approved_at <= ? OR (approved_at IS NULL AND created_at <= ?))"
    );
    $approved->execute([(int) $payout['affiliate_id'], $reqAt, $reqAt]);
    $cids = $approved->fetchAll(PDO::FETCH_COLUMN);
    if ($cids) {
        $ph = implode(',', array_fill(0, count($cids), '?'));
        $pdo->prepare("UPDATE commissions SET status='paid', paid_at=NOW() WHERE id IN ($ph)")->execute($cids);
    }
    $pdo->prepare("UPDATE affiliates SET total_withdrawn = total_withdrawn + ? WHERE id = ?")->execute([(float) $payout['amount'], (int) $payout['affiliate_id']]);
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('mark-payout-paid error: ' . $e->getMessage());
    flash('error', 'Gagal memproses payout.', 'error'); redirect('/admin/payouts');
}

logActivity('admin', 'payout.paid', 'payout_request', $payoutId, 'Mark paid payout ' . $payout['name'] . ': ' . formatRupiah((float) $payout['amount']));
require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
NotificationDispatcher::trigger('payout_paid', [
    'name'          => $payout['name'],
    'email'         => $payout['email'],
    'amount'        => formatRupiah((float) $payout['amount']),
    'bank_name'     => $payout['bank_name'] ?? '',
    'affiliate_url' => baseUrl('affiliate'),
], $payout['email'], $payout['phone'] ?? '');

flash('success', 'Payout ditandai sebagai dibayar.');
redirect('/admin/payouts');

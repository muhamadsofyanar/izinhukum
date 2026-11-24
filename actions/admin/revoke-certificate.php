<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('certificates')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/certificates');
}

$certId = (int) ($_POST['cert_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

$pdo = getDB();
$stmt = $pdo->prepare("SELECT id, certificate_no FROM certificates WHERE id = ? AND status = 'active'");
$stmt->execute([$certId]);
$cert = $stmt->fetch();
if (!$cert) { flash('error', 'Sertifikat tidak ditemukan.', 'error'); redirect('/admin/certificates'); }

$pdo->prepare("UPDATE certificates SET status = 'revoked', revoked_at = NOW(), revoked_reason = ? WHERE id = ?")
    ->execute([$reason !== '' ? $reason : null, $certId]);

logActivity('admin', 'certificate.revoke', 'certificate', $certId, "Cabut sertifikat {$cert['certificate_no']}. Alasan: $reason");
flash('success', 'Sertifikat berhasil dicabut.');
redirect('/admin/certificates');

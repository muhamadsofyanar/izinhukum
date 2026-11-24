<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('certificates')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/certificates');
}

$userId    = (int) ($_POST['user_id'] ?? 0);
$productId = (int) ($_POST['product_id'] ?? 0);
if (!$userId || !$productId) { flash('error', 'Data tidak lengkap.', 'error'); redirect('/admin/certificates'); }

$pdo = getDB();
$u = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND status = 'active'"); $u->execute([$userId]); $user = $u->fetch();
if (!$user) { flash('error', 'User tidak ditemukan/aktif.', 'error'); redirect('/admin/certificates'); }
$p = $pdo->prepare("SELECT id, name, certificate_enabled FROM products WHERE id = ?"); $p->execute([$productId]); $product = $p->fetch();
if (!$product || !$product['certificate_enabled']) { flash('error', 'Produk tidak mengaktifkan sertifikat.', 'error'); redirect('/admin/certificates'); }

$chk = $pdo->prepare("SELECT certificate_no FROM certificates WHERE user_id = ? AND product_id = ? AND status = 'active' LIMIT 1");
$chk->execute([$userId, $productId]);
if ($no = $chk->fetchColumn()) { flash('warning', 'User sudah punya sertifikat aktif: ' . $no, 'warning'); redirect('/admin/certificates'); }

$certId = issueCertificate($userId, $productId, (int) $_SESSION['user_id']);
if (!$certId) { flash('error', 'Gagal menerbitkan sertifikat.', 'error'); redirect('/admin/certificates'); }

$certNo = $pdo->query("SELECT certificate_no FROM certificates WHERE id = " . (int) $certId)->fetchColumn();
logActivity('admin', 'certificate.issue', 'certificate', $certId, "Issue sertifikat {$user['name']} — {$product['name']}");
if (!hasAccess($userId, $productId)) {
    flash('warning', 'Sertifikat diterbitkan: ' . $certNo . ' (catatan: user belum punya akses produk ini).', 'warning');
} else {
    flash('success', 'Sertifikat berhasil diterbitkan: ' . $certNo);
}
redirect('/admin/certificates');

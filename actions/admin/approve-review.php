<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/reviews'); }

$reviewId = (int) ($_POST['review_id'] ?? 0);
$action   = $_POST['action'] ?? '';
$note     = trim($_POST['admin_note'] ?? '');
if (!$reviewId || !in_array($action, ['approve', 'reject'], true)) { flash('error', 'Data tidak valid.', 'error'); redirect('/admin/reviews'); }

$pdo = getDB();
$stmt = $pdo->prepare("SELECT r.*, p.name AS product_name, u.name AS reviewer_name FROM product_reviews r JOIN products p ON p.id=r.product_id JOIN users u ON u.id=r.user_id WHERE r.id=? AND r.status='pending'");
$stmt->execute([$reviewId]);
$review = $stmt->fetch();
if (!$review) { flash('error', 'Review tidak ditemukan / sudah diproses.', 'error'); redirect('/admin/reviews'); }

if ($action === 'approve') {
    $pdo->prepare("UPDATE product_reviews SET status='approved', approved_at=NOW(), approved_by=?, admin_note=NULL WHERE id=?")->execute([(int) $_SESSION['user_id'], $reviewId]);
    logActivity('admin', 'review.approve', 'review', $reviewId, "Approve review {$review['reviewer_name']} → {$review['product_name']}");
    flash('success', 'Review disetujui dan tampil di halaman produk.');
} else {
    $pdo->prepare("UPDATE product_reviews SET status='rejected', admin_note=? WHERE id=?")->execute([$note !== '' ? $note : null, $reviewId]);
    logActivity('admin', 'review.reject', 'review', $reviewId, "Reject review {$review['reviewer_name']} → {$review['product_name']}");
    flash('success', 'Review ditolak.');
}
redirect('/admin/reviews');

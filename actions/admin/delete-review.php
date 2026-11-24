<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/reviews'); }

$reviewId = (int) ($_POST['review_id'] ?? 0);
getDB()->prepare("DELETE FROM product_reviews WHERE id = ?")->execute([$reviewId]);
logActivity('admin', 'review.delete', 'review', $reviewId, 'Hapus review ID: ' . $reviewId);
flash('success', 'Review berhasil dihapus.');
redirect('/admin/reviews');

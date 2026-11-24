<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/products'); }

$productId  = (int) ($_POST['product_id'] ?? 0);
$rating     = (int) ($_POST['rating'] ?? 0);
$reviewText = trim($_POST['review_text'] ?? '');

$pdo = getDB();
$pStmt = $pdo->prepare("SELECT id, name, slug, reviews_enabled FROM products WHERE id = ? AND status = 'active'");
$pStmt->execute([$productId]);
$product = $pStmt->fetch();
$slug = $product['slug'] ?? '';
$back = $slug ? '/products/' . $slug : '/products';

if (getSetting('reviews_enabled', '1') !== '1') { flash('error', 'Fitur review tidak aktif.', 'error'); redirect($back); }
if (!$product || !$product['reviews_enabled']) { flash('error', 'Review tidak diaktifkan untuk produk ini.', 'error'); redirect($back); }
if (getSetting('reviews_require_access', '1') === '1' && !hasAccess((int) $_SESSION['user_id'], $productId)) {
    flash('error', 'Kamu harus memiliki produk ini untuk memberi ulasan.', 'error'); redirect($back);
}
if ($rating < 1 || $rating > 5) { flash('error', 'Rating harus 1–5 bintang.', 'error'); redirect($back); }
$reviewText = mb_substr($reviewText, 0, 1000);

$ex = $pdo->prepare("SELECT id, status FROM product_reviews WHERE user_id = ? AND product_id = ?");
$ex->execute([(int) $_SESSION['user_id'], $productId]);
$existing = $ex->fetch();
if ($existing) {
    if ($existing['status'] === 'pending')  { flash('warning', 'Ulasanmu sedang menunggu persetujuan.', 'warning'); redirect($back); }
    if ($existing['status'] === 'approved') { flash('warning', 'Kamu sudah memberi ulasan untuk produk ini.', 'warning'); redirect($back); }
    // rejected → boleh kirim ulang
    $pdo->prepare("UPDATE product_reviews SET rating=?, review_text=?, status='pending', admin_note=NULL, approved_at=NULL, approved_by=NULL, updated_at=NOW() WHERE id=?")
        ->execute([$rating, $reviewText !== '' ? $reviewText : null, $existing['id']]);
    flash('success', 'Ulasanmu dikirim ulang dan menunggu persetujuan.');
    redirect($back);
}

$pdo->prepare("INSERT INTO product_reviews (product_id, user_id, rating, review_text, status) VALUES (?, ?, ?, ?, 'pending')")
    ->execute([$productId, (int) $_SESSION['user_id'], $rating, $reviewText !== '' ? $reviewText : null]);
logActivity('user', 'review.submit', 'review', (int) $pdo->lastInsertId(), "Review produk {$product['name']} — {$rating} bintang");
flash('success', 'Terima kasih! Ulasanmu menunggu persetujuan.');
redirect($back);

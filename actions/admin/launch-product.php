<?php
// Launch produk coming_soon SEKARANG: aktifkan + kirim batch pertama notif waitlist
// (sisanya dilanjutkan otomatis oleh Auto-Cron, idempoten via notified_at).
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/products');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/products');
}

$productId = (int) ($_POST['product_id'] ?? 0);

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT id, name FROM products WHERE id = ? AND status = 'coming_soon' LIMIT 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    if (!$product) {
        flash('error', 'Produk tidak ditemukan atau bukan berstatus Coming Soon.', 'error');
        redirect('/admin/products');
    }

    $pdo->prepare("UPDATE products SET status = 'active', launch_at = COALESCE(launch_at, NOW()) WHERE id = ?")
        ->execute([$productId]);
    logActivity('admin', 'product.launch', 'product', $productId, 'Launch produk: ' . $product['name']);

    // Batch pertama notif langsung dikirim (tak menunggu Auto-Cron).
    $res  = processWaitlistLaunches(50);
    $sent = (int) ($res['sent'] ?? 0);

    $sisa = 0;
    try {
        $c = $pdo->prepare("SELECT COUNT(*) FROM product_waitlist WHERE product_id = ? AND status = 'waiting' AND notified_at IS NULL");
        $c->execute([$productId]);
        $sisa = (int) $c->fetchColumn();
    } catch (Throwable $e) {}

    $msg = 'Produk "' . $product['name'] . '" berhasil di-launch! Notifikasi terkirim ke ' . $sent . ' pendaftar waitlist';
    $msg .= $sisa > 0 ? ' — ' . $sisa . ' sisanya dilanjutkan otomatis di latar belakang.' : '.';
    flash('success', $msg);
} catch (Throwable $e) {
    error_log('launch-product error: ' . $e->getMessage());
    flash('error', 'Gagal me-launch produk.', 'error');
}

redirect('/admin/products');

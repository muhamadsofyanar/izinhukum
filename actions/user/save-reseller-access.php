<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/reseller');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/reseller');
}

$userId    = (int) $_SESSION['user_id'];
$productId = (int) ($_POST['product_id'] ?? 0);
$passRaw   = trim((string) ($_POST['access_password'] ?? ''));
$pass      = $passRaw !== '' ? mb_substr($passRaw, 0, 100) : null;

try {
    $pdo = getDB();

    // Guard: reseller HARUS memiliki produk RESELL (R) tertaut, & produk P punya
    // halaman akses aktif.
    $accOn = $pdo->prepare("SELECT 1 FROM products WHERE id = ? AND access_page_enabled = 1 AND status = 'active' LIMIT 1");
    $accOn->execute([$productId]);
    if (!resellerCanManageSalespage($userId, $productId) || !$accOn->fetchColumn()) {
        flash('error', 'Produk tidak valid.', 'error');
        redirect('/reseller');
    }

    ensureResellerSalespageTable($pdo);
    // UPSERT hanya kolom access_password (jangan sentuh wa_template/resell_enabled).
    $pdo->prepare(
        "INSERT INTO reseller_salespage (user_id, product_id, access_password)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE access_password = VALUES(access_password), updated_at = NOW()"
    )->execute([$userId, $productId, $pass]);

    flash('success', 'Password akses berhasil disimpan.');
} catch (Exception $e) {
    error_log('save-reseller-access error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan password. Silakan coba lagi.', 'error');
}

redirect('/reseller');

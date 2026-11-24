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

$waTplRaw  = trim((string) ($_POST['wa_template'] ?? ''));
$waTpl     = $waTplRaw !== '' ? mb_substr($waTplRaw, 0, 500) : null;
$resell    = ((int) ($_POST['resell_enabled'] ?? 0) === 1) ? 1 : 0;

try {
    $pdo = getDB();

    // Guard: reseller HARUS memiliki produk RESELL (R) yang ditautkan ke produk P ini.
    if (!resellerCanManageSalespage($userId, $productId)) {
        flash('error', 'Produk tidak valid.', 'error');
        redirect('/reseller');
    }

    ensureResellerSalespageTable($pdo);
    $pdo->prepare(
        "INSERT INTO reseller_salespage (user_id, product_id, wa_template, resell_enabled)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE wa_template = VALUES(wa_template), resell_enabled = VALUES(resell_enabled), updated_at = NOW()"
    )->execute([$userId, $productId, $waTpl, $resell]);

    flash('success', 'Pengaturan salespage berhasil disimpan.');
} catch (Exception $e) {
    error_log('save-reseller-salespage error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan pengaturan. Silakan coba lagi.', 'error');
}

redirect('/reseller');

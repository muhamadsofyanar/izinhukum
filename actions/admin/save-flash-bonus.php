<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/flash-bonus');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/flash-bonus');
}

$offerId         = (int) ($_POST['offer_id']         ?? 0);
$mainProductId   = (int) ($_POST['main_product_id']  ?? 0);
$bonusProductId  = (int) ($_POST['bonus_product_id'] ?? 0);
$redirectProduct = (int) ($_POST['redirect_product_id'] ?? $mainProductId);
$headline        = trim($_POST['headline']    ?? '');
$description      = trim($_POST['description'] ?? '');
$status          = $_POST['status'] ?? 'active';
$sortOrder       = max(0, (int) ($_POST['sort_order'] ?? 0));

$isEdit = $offerId > 0;

$errors = [];

if ($mainProductId <= 0)  $errors[] = 'Produk utama tidak valid.';
if ($bonusProductId <= 0) $errors[] = 'Pilih produk bonus.';
if ($mainProductId > 0 && $mainProductId === $bonusProductId) $errors[] = 'Produk bonus tidak boleh sama dengan produk utama.';
if (!in_array($status, ['active', 'inactive'], true)) $errors[] = 'Status tidak valid.';

if (!empty($errors)) {
    flash('error', implode(' ', $errors), 'error');
    redirect('/admin/flash-bonus?product_id=' . $redirectProduct);
}

try {
    $pdo = getDB();

    // Pastikan kedua produk exist
    $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE id = ?");
    $check->execute([$mainProductId]);
    if ((int)$check->fetchColumn() === 0) {
        flash('error', 'Produk utama tidak ditemukan.', 'error');
        redirect('/admin/flash-bonus');
    }
    $check->execute([$bonusProductId]);
    if ((int)$check->fetchColumn() === 0) {
        flash('error', 'Produk bonus tidak ditemukan.', 'error');
        redirect('/admin/flash-bonus?product_id=' . $redirectProduct);
    }

    if ($isEdit) {
        $stmt = $pdo->prepare(
            "UPDATE flash_bonus_offers
             SET bonus_product_id = ?, headline = ?, description = ?, status = ?, sort_order = ?
             WHERE id = ? AND main_product_id = ?"
        );
        $stmt->execute([
            $bonusProductId, $headline ?: null, $description ?: null,
            $status, $sortOrder, $offerId, $mainProductId,
        ]);
        flash('success', 'Bonus berhasil diperbarui.');
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO flash_bonus_offers
             (main_product_id, bonus_product_id, headline, description, status, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $mainProductId, $bonusProductId, $headline ?: null,
            $description ?: null, $status, $sortOrder,
        ]);
        flash('success', 'Bonus berhasil ditambahkan.');
    }

    redirect('/admin/flash-bonus?product_id=' . $redirectProduct);

} catch (Exception $e) {
    error_log('Save flash bonus error: ' . $e->getMessage());
    if (str_contains($e->getMessage(), 'Duplicate entry')) {
        flash('error', 'Produk ini sudah menjadi bonus untuk produk utama tersebut.', 'error');
    } else {
        flash('error', 'Terjadi kesalahan saat menyimpan bonus.', 'error');
    }
    redirect('/admin/flash-bonus?product_id=' . $redirectProduct);
}

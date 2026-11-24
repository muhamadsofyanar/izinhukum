<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/product-bumps');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/product-bumps');
}

$bumpId          = (int) ($_POST['bump_id']          ?? 0);
$mainProductId   = (int) ($_POST['main_product_id']  ?? 0);
$bumpProductId   = (int) ($_POST['bump_product_id']  ?? 0);
$redirectProduct = (int) ($_POST['redirect_product_id'] ?? $mainProductId);
$bumpPriceRaw    = trim($_POST['bump_price']  ?? '');
$bumpPrice       = ($bumpPriceRaw !== '') ? max(0, (float)$bumpPriceRaw) : null;
$headline        = trim($_POST['headline']    ?? '');
$description     = trim($_POST['description'] ?? '');
$status          = $_POST['status'] ?? 'active';
$sortOrder       = max(0, (int) ($_POST['sort_order'] ?? 0));

$isEdit = $bumpId > 0;

$errors = [];

if ($mainProductId <= 0)  $errors[] = 'Produk utama tidak valid.';
if ($bumpProductId <= 0)  $errors[] = 'Pilih produk bump.';
if ($mainProductId > 0 && $mainProductId === $bumpProductId) $errors[] = 'Produk bump tidak boleh sama dengan produk utama.';
if (!in_array($status, ['active', 'inactive'], true)) $errors[] = 'Status tidak valid.';

if (!empty($errors)) {
    flash('error', implode(' ', $errors), 'error');
    redirect('/admin/product-bumps?product_id=' . $redirectProduct);
}

try {
    $pdo = getDB();

    // Pastikan kedua produk exist
    $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE id = ?");
    $check->execute([$mainProductId]);
    if ((int)$check->fetchColumn() === 0) {
        flash('error', 'Produk utama tidak ditemukan.', 'error');
        redirect('/admin/product-bumps');
    }
    $check->execute([$bumpProductId]);
    if ((int)$check->fetchColumn() === 0) {
        flash('error', 'Produk bump tidak ditemukan.', 'error');
        redirect('/admin/product-bumps?product_id=' . $redirectProduct);
    }

    if ($isEdit) {
        $stmt = $pdo->prepare(
            "UPDATE product_bumps
             SET bump_product_id = ?, bump_price = ?, headline = ?, description = ?,
                 status = ?, sort_order = ?
             WHERE id = ? AND main_product_id = ?"
        );
        $stmt->execute([
            $bumpProductId, $bumpPrice, $headline ?: null, $description ?: null,
            $status, $sortOrder, $bumpId, $mainProductId,
        ]);
        flash('success', 'Bump berhasil diperbarui.');
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO product_bumps
             (main_product_id, bump_product_id, bump_price, headline, description, status, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $mainProductId, $bumpProductId, $bumpPrice, $headline ?: null,
            $description ?: null, $status, $sortOrder,
        ]);
        $bumpId = (int)$pdo->lastInsertId();
        flash('success', 'Bump berhasil ditambahkan.');
    }

    redirect('/admin/product-bumps?product_id=' . $redirectProduct);

} catch (Exception $e) {
    error_log('Save product bump error: ' . $e->getMessage());
    if (str_contains($e->getMessage(), 'Duplicate entry')) {
        flash('error', 'Produk ini sudah menjadi bump untuk produk utama tersebut.', 'error');
    } else {
        flash('error', 'Terjadi kesalahan saat menyimpan bump.', 'error');
    }
    redirect('/admin/product-bumps?product_id=' . $redirectProduct);
}

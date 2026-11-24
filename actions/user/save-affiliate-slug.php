<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/affiliate');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/affiliate');
}

$slug   = strtolower(trim($_POST['custom_slug'] ?? ''));
$slug   = preg_replace('/[^a-z0-9-]/', '', $slug);
$userId = (int) $_SESSION['user_id'];

if (strlen($slug) < 3) {
    flash('error', 'Slug minimal 3 karakter.', 'error');
    redirect('/affiliate');
}

if (strlen($slug) > 50) {
    flash('error', 'Slug maksimal 50 karakter.', 'error');
    redirect('/affiliate');
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare('SELECT id FROM affiliates WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) {
        flash('error', 'Akun affiliate tidak ditemukan.', 'error');
        redirect('/affiliate');
    }

    // Cek duplikat (kecuali milik sendiri)
    $stmt = $pdo->prepare('SELECT id FROM affiliates WHERE custom_slug = ? AND user_id != ? LIMIT 1');
    $stmt->execute([$slug, $userId]);
    if ($stmt->fetch()) {
        flash('error', 'Slug ini sudah dipakai, coba yang lain.', 'error');
        redirect('/affiliate');
    }

    $stmt = $pdo->prepare('UPDATE affiliates SET custom_slug = ? WHERE user_id = ?');
    $stmt->execute([$slug, $userId]);

    flash('success', 'Link afiliasi custom berhasil disimpan!');
} catch (Exception $e) {
    error_log('Save affiliate slug error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan slug.', 'error');
}

redirect('/affiliate');

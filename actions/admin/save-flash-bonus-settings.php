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

$mainProductId = (int) ($_POST['main_product_id'] ?? 0);
$enabled       = ($_POST['enabled'] ?? '1') === '1' ? 1 : 0;
$duration      = (int) ($_POST['duration_minutes'] ?? 60);
$maxSelect     = (int) ($_POST['max_select'] ?? 1);
$label         = trim($_POST['label'] ?? '');

// Batas wajar: durasi 1–1440 menit, max pilih 1–20, label wajib.
$duration  = max(1, min(1440, $duration));
$maxSelect = max(1, min(20, $maxSelect));
if ($label === '') $label = 'Bonus Gratis Hari Ini';
$label = mb_substr($label, 0, 120);

if ($mainProductId <= 0) {
    flash('error', 'Produk tidak valid.', 'error');
    redirect('/admin/flash-bonus');
}

try {
    $pdo = getDB();

    $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE id = ?");
    $check->execute([$mainProductId]);
    if ((int)$check->fetchColumn() === 0) {
        flash('error', 'Produk tidak ditemukan.', 'error');
        redirect('/admin/flash-bonus');
    }

    // UPSERT baris setting per-produk.
    $stmt = $pdo->prepare(
        "INSERT INTO flash_bonus_settings (main_product_id, enabled, duration_minutes, max_select, label)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), duration_minutes = VALUES(duration_minutes),
                                 max_select = VALUES(max_select), label = VALUES(label)"
    );
    $stmt->execute([$mainProductId, $enabled, $duration, $maxSelect, $label]);

    flash('success', 'Pengaturan Bonus Kilat disimpan.');

} catch (Exception $e) {
    error_log('Save flash bonus settings error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menyimpan pengaturan.', 'error');
}

redirect('/admin/flash-bonus?product_id=' . $mainProductId);

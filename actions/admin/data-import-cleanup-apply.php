<?php
/**
 * POST /actions/admin/data-import-cleanup-apply
 * Terapkan nol-kan nominal order impor untuk product_id terpilih.
 * HANYA menyentuh order payment_gateway='sejoli-migrasi' status 'paid'.
 * Nilai lama di-backup ke JSON (storage/imports/cleanup) → bisa di-undo.
 *
 * Param: keys[] (array string — "p:<id>" produk asli / "n:<rawname>" sub-produk placeholder).
 * Balas JSON {ok, batch, orders, items, omzet}.
 */
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
require_once __DIR__ . '/../../helpers/sejoli-import.php';

header('Content-Type: application/json');

$fail = function (string $msg) {
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    $fail('Permintaan tidak valid.');
}

$keys = (array) ($_POST['keys'] ?? []);
if (!$keys) { $fail('Pilih minimal satu produk.'); }

try {
    $res = sejoli_cleanup_apply(getDB(), $keys, (int) ($_SESSION['user_id'] ?? 0));
    if (($res['orders'] ?? 0) === 0) {
        $fail('Tidak ada order impor yang cocok untuk dibersihkan.');
    }
    logActivity('admin', 'data.import.cleanup', null, null,
        'Nol-kan nominal impor: ' . (int) $res['orders'] . ' order, Rp ' .
        number_format((float) $res['omzet'], 0, ',', '.') . ' (batch ' . $res['batch'] . ').');
    echo json_encode(['ok' => true] + $res);
} catch (Throwable $e) {
    error_log('data-import-cleanup-apply: ' . $e->getMessage());
    $fail('Terjadi kesalahan saat membersihkan data.');
}

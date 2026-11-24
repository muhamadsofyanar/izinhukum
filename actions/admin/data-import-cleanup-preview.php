<?php
/**
 * POST /actions/admin/data-import-cleanup-preview
 * Pratinjau pembersihan omzet impor: berapa order & omzet akan dinol-kan
 * untuk product_id terpilih. DRY-RUN — tidak menulis apa pun.
 *
 * Param: keys[] (array string — "p:<id>" produk asli / "n:<rawname>" sub-produk placeholder).
 * Balas JSON {ok, orders, items, omzet}.
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
    $res = sejoli_cleanup_preview(getDB(), $keys);
    echo json_encode(['ok' => true] + $res);
} catch (Throwable $e) {
    error_log('data-import-cleanup-preview: ' . $e->getMessage());
    $fail('Terjadi kesalahan saat menghitung dampak.');
}

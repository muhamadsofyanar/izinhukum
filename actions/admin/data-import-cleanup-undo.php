<?php
/**
 * POST /actions/admin/data-import-cleanup-undo
 * Batalkan satu batch pembersihan: kembalikan nominal lama dari backup JSON.
 * Idempoten (batch yang sudah di-undo ditolak). Hanya menyentuh order migrasi.
 *
 * Param: batch (string token).
 * Balas JSON {ok, orders}.
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

$batch = trim((string) ($_POST['batch'] ?? ''));
if ($batch === '') { $fail('Batch tidak valid.'); }

try {
    $res = sejoli_cleanup_undo(getDB(), $batch);
    logActivity('admin', 'data.import.cleanup.undo', null, null,
        'Batalkan pembersihan impor: ' . (int) $res['orders'] . ' order dipulihkan (batch ' . $batch . ').');
    echo json_encode(['ok' => true] + $res);
} catch (RuntimeException $e) {
    // Pesan RuntimeException helper sudah ramah-user (cadangan tak ada/rusak/sudah di-undo).
    $fail($e->getMessage());
} catch (Throwable $e) {
    error_log('data-import-cleanup-undo: ' . $e->getMessage());
    $fail('Terjadi kesalahan saat membatalkan.');
}

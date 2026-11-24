<?php
/**
 * POST /actions/admin/data-import-sync
 * Sinkronkan akses produk untuk transaksi hasil MIGRASI, tanpa file CSV.
 *
 * Dipakai saat admin membuat produknya MENYUSUL: nama produk asli sudah
 * tersimpan sebagai snapshot di order_items.product_name saat impor, jadi
 * pencocokan bisa dilakukan langsung dari database (file CSV impor memang
 * dihapus otomatis setelah selesai).
 *
 * Param: apply=0 (pratinjau, tidak menulis) | apply=1 (terapkan), after_id.
 * Balas JSON {ok, counts, last_id, finished}. Dipanggil berulang (chunked).
 *
 * SENYAP: hanya menulis order_items.product_id + member_access — tanpa
 * notifikasi/komisi, sama seperti importer.
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

$apply   = ($_POST['apply'] ?? '0') === '1';
$afterId = max(0, (int) ($_POST['after_id'] ?? 0));
$CHUNK   = 500;

try {
    $pdo = getDB();

    // Placeholder TIDAK dibuat di sini — bila belum ada berarti tak pernah ada
    // transaksi produk-tak-dikenal, dan item placeholder otomatis nihil.
    $st = $pdo->prepare("SELECT id FROM products WHERE slug = ? LIMIT 1");
    $st->execute([SEJOLI_PLACEHOLDER_SLUG]);
    $placeholderId = ($pid = (int) $st->fetchColumn()) > 0 ? $pid : null;

    $prodIdx = sejoli_product_index($pdo);
    $rows    = sejoli_sync_candidates($pdo, $afterId, $CHUNK);

    if (!$rows) {
        echo json_encode([
            'ok'       => true,
            'counts'   => ['scanned' => 0, 'relink' => 0, 'akses_baru' => 0, 'sudah' => 0, 'tak_cocok' => 0],
            'last_id'  => $afterId,
            'finished' => true,
        ]);
        exit;
    }

    if ($apply) {
        $pdo->beginTransaction();
        $res = sejoli_sync_batch($pdo, $rows, $prodIdx, $placeholderId, true);
        $pdo->commit();
    } else {
        $res = sejoli_sync_batch($pdo, $rows, $prodIdx, $placeholderId, false);
    }

    $finished = count($rows) < $CHUNK;
    if ($apply && $finished) {
        logActivity('admin', 'data.import.sync', null, null,
            'Sinkronisasi akses migrasi dijalankan (sampai item #' . $res['last_id'] . ').');
    }

    echo json_encode([
        'ok'       => true,
        'counts'   => $res,
        'last_id'  => $res['last_id'] ?: $afterId,
        'finished' => $finished,
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('data-import-sync: ' . $e->getMessage());
    $fail('Terjadi kesalahan saat sinkronisasi.');
}

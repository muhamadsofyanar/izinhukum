<?php
/**
 * POST /actions/admin/data-import-run
 * Proses satu CHUNK baris dari file tersimpan (token) mulai dari offset.
 * Menulis LANGSUNG & SENYAP ke DB via sejoli_import_row (per-baris transaksi).
 * Balas JSON {ok, counts, next_offset, total, finished}.
 *
 * Dipanggil berulang oleh halaman sampai finished=true (chunked → hindari timeout).
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

$token  = (string) ($_POST['token'] ?? '');
$offset = max(0, (int) ($_POST['offset'] ?? 0));
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    $fail('Token tidak valid.');
}

$path = __DIR__ . '/../../storage/imports/' . $token . '.csv';
if (!is_readable($path)) {
    $fail('Sesi import kedaluwarsa. Unggah ulang file.');
}

$read = sejoli_read_rows($path);
if ($read['error'] !== null) {
    $fail($read['error']);
}

$rows  = $read['rows'];
$total = count($rows);

$opts = [
    'grantAccess'     => ($_POST['grant'] ?? '1') === '1',
    'includeFree'     => ($_POST['free'] ?? '1') === '1',
    'recordUnmatched' => ($_POST['unmatched'] ?? '1') === '1',
    // Dedup konten (user+produk+nominal+tanggal) — default ON, cegah duplikat
    // dari source ber-order_number beda utk transaksi sama.
    'dedupContent'    => ($_POST['dedup'] ?? '1') === '1',
    'productMap'      => [],
];

$CHUNK = 500;
$pdo   = getDB();
$prodIdx = sejoli_product_index($pdo);
$placeholderId = null;

$counts = [
    'imported' => 0, 'access_reimport' => 0, 'skipped_exists' => 0, 'skipped_dup' => 0,
    'skipped_free' => 0, 'skipped_status' => 0, 'skipped_unmatched' => 0, 'skipped_invalid' => 0, 'error' => 0,
];

$end = min($total, $offset + $CHUNK);
for ($i = $offset; $i < $end; $i++) {
    $row = $rows[$i];
    try {
        $pdo->beginTransaction();
        $res = sejoli_import_row($pdo, $row, $opts, $prodIdx, $placeholderId);
        $pdo->commit();
        $code = $res['status'];
        if (isset($counts[$code])) $counts[$code]++;
        else $counts['error']++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $counts['error']++;
        error_log('sejoli-import row ' . $i . ' (' . ($row['order_number'] ?? '?') . '): ' . $e->getMessage());
    }
}

$finished = $end >= $total;

// Selesai → bersihkan file + catat log aktivitas (sekali).
if ($finished) {
    @unlink($path);
    logActivity('admin', 'data.import', null, null,
        'Import Sejoli selesai: ' . $total . ' baris diproses.');
}

echo json_encode([
    'ok'          => true,
    'counts'      => $counts,
    'next_offset' => $end,
    'total'       => $total,
    'finished'    => $finished,
]);

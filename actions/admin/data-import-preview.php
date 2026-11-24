<?php
/**
 * POST /actions/admin/data-import-preview
 * Terima upload file → simpan ke storage/imports → hitung pratinjau (DRY-RUN,
 * tidak menulis ke DB) → balas JSON {ok, token, total_rows, summary}.
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
if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $fail('File gagal diunggah.');
}

// Simpan file ke storage/imports dengan token acak (hindari path traversal).
$dir = __DIR__ . '/../../storage/imports';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
// .htaccess: tolak akses langsung ke file import (data pribadi member).
$ht = $dir . '/.htaccess';
if (!file_exists($ht)) { @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"); }

$token = bin2hex(random_bytes(16));
$dest  = $dir . '/' . $token . '.csv';

if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
    $fail('Gagal menyimpan file di server.');
}

$read = sejoli_read_rows($dest);
if ($read['error'] !== null) {
    @unlink($dest);
    $fail($read['error']);
}

$opts = [
    'grantAccess'     => ($_POST['grant'] ?? '1') === '1',
    'includeFree'     => ($_POST['free'] ?? '1') === '1',
    'recordUnmatched' => ($_POST['unmatched'] ?? '1') === '1',
    'productMap'      => [],
];

$summary = sejoli_preview(getDB(), $read['rows'], $opts);

echo json_encode([
    'ok'         => true,
    'token'      => $token,
    'total_rows' => $read['total'],
    'summary'    => $summary,
]);

<?php
// Perbarui alt text satu gambar Galeri Media. AJAX JSON.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Token keamanan tidak valid.']);
    exit;
}

$id  = (int) ($_POST['id'] ?? 0);
$alt = substr(trim((string) ($_POST['alt'] ?? '')), 0, 255);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Data tidak valid.']);
    exit;
}

try {
    getDB()->prepare('UPDATE media_files SET alt = ? WHERE id = ?')->execute([($alt !== '' ? $alt : null), $id]);
    echo json_encode(['ok' => true, 'alt' => $alt]);
} catch (Exception $e) {
    error_log('update-media-alt error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan alt.']);
}

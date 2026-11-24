<?php
// Hapus satu gambar Galeri Media (file + baris). AJAX JSON.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Metode tidak diizinkan.']);
    exit;
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Token keamanan tidak valid.']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Data tidak valid.']);
    exit;
}

try {
    $pdo = getDB();
    $st = $pdo->prepare('SELECT filename FROM media_files WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $fname = $st->fetchColumn();
    if ($fname === false) {
        echo json_encode(['ok' => false, 'message' => 'Gambar tidak ditemukan.']);
        exit;
    }
    $pdo->prepare('DELETE FROM media_files WHERE id = ?')->execute([$id]);

    // Hapus file fisik — hanya basename di folder media (cegah path traversal).
    $safe = basename((string) $fname);
    $abs  = rtrim(UPLOAD_PATH, '/\\') . '/media/' . $safe;
    if (is_file($abs)) @unlink($abs);

    if (function_exists('logActivity')) {
        logActivity('admin', 'media.deleted', 'media_files', $id, 'Gambar dihapus dari Galeri Media.');
    }
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    error_log('delete-media error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Gagal menghapus gambar.']);
}

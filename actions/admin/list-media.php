<?php
// Daftar gambar Galeri Media (JSON) — dipakai modal picker (Canvas dll).
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$mediaBaseUrl = rtrim(UPLOAD_URL, '/') . '/media/';

try {
    $pdo = getDB();
    if ($q !== '') {
        $st = $pdo->prepare("SELECT id, filename, alt, width, height, size FROM media_files
                             WHERE original_name LIKE ? OR alt LIKE ? ORDER BY created_at DESC LIMIT 200");
        $like = '%' . $q . '%';
        $st->execute([$like, $like]);
    } else {
        $st = $pdo->query("SELECT id, filename, alt, width, height, size FROM media_files ORDER BY created_at DESC LIMIT 200");
    }
    $out = [];
    foreach ($st->fetchAll() as $m) {
        $out[] = [
            'id'     => (int) $m['id'],
            'url'    => $mediaBaseUrl . rawurlencode($m['filename']),
            'alt'    => (string) ($m['alt'] ?? ''),
            'width'  => (int) ($m['width'] ?? 0),
            'height' => (int) ($m['height'] ?? 0),
            'size'   => (int) ($m['size'] ?? 0),
        ];
    }
    echo json_encode(['ok' => true, 'items' => $out]);
} catch (Exception $e) {
    error_log('list-media error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'items' => []]);
}

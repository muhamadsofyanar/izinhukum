<?php
// Grup Media (AJAX JSON): buat/hapus grup, pindahkan gambar ke grup, ambil semua URL
// satu grup untuk disalin sekaligus. Dipakai /admin/media.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

function mgOut(array $data, int $code = 200): void { http_response_code($code); echo json_encode($data); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') mgOut(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
if (!validateCSRF($_POST['csrf_token'] ?? '')) mgOut(['ok' => false, 'message' => 'Sesi kedaluwarsa. Muat ulang halaman.'], 403);

$action = (string) ($_POST['action'] ?? '');
$pdo = getDB();

try {
    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) mgOut(['ok' => false, 'message' => 'Nama grup wajib (maks 100 karakter).'], 422);
        $pdo->prepare('INSERT INTO media_groups (name) VALUES (?)')->execute([$name]);
        $id = (int) $pdo->lastInsertId();
        if (function_exists('logActivity')) logActivity('admin', 'media.group_create', 'media_groups', $id, 'Grup media dibuat: ' . $name);
        mgOut(['ok' => true, 'group' => ['id' => $id, 'name' => $name]]);
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) mgOut(['ok' => false, 'message' => 'Grup tidak valid.'], 422);
        // Lepas gambar dari grup dulu (tanpa FK) → gambar TIDAK ikut terhapus, hanya
        // kembali ke "Tanpa grup". Lalu hapus baris grup.
        $pdo->prepare('UPDATE media_files SET group_id = NULL WHERE group_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM media_groups WHERE id = ?')->execute([$id]);
        if (function_exists('logActivity')) logActivity('admin', 'media.group_delete', 'media_groups', $id, 'Grup media dihapus (gambar dilepas ke Tanpa grup).');
        mgOut(['ok' => true]);
    }

    if ($action === 'assign') {
        $mediaId = (int) ($_POST['media_id'] ?? 0);
        $groupId = (int) ($_POST['group_id'] ?? 0); // 0 = Tanpa grup
        if ($mediaId <= 0) mgOut(['ok' => false, 'message' => 'Gambar tidak valid.'], 422);
        if ($groupId > 0) {
            $exists = $pdo->prepare('SELECT 1 FROM media_groups WHERE id = ? LIMIT 1');
            $exists->execute([$groupId]);
            if (!$exists->fetchColumn()) mgOut(['ok' => false, 'message' => 'Grup tidak ditemukan.'], 404);
        }
        $pdo->prepare('UPDATE media_files SET group_id = ? WHERE id = ?')->execute([$groupId > 0 ? $groupId : null, $mediaId]);
        mgOut(['ok' => true]);
    }

    if ($action === 'urls') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        if ($groupId <= 0) mgOut(['ok' => false, 'message' => 'Grup tidak valid.'], 422);
        $st = $pdo->prepare('SELECT filename FROM media_files WHERE group_id = ? ORDER BY created_at ASC, id ASC');
        $st->execute([$groupId]);
        $baseUrl = rtrim(UPLOAD_URL, '/') . '/media/';
        $urls = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $fn) { $urls[] = $baseUrl . rawurlencode((string) $fn); }
        mgOut(['ok' => true, 'count' => count($urls), 'urls' => $urls, 'text' => implode("\n", $urls)]);
    }

    mgOut(['ok' => false, 'message' => 'Aksi tidak dikenal.'], 400);
} catch (Throwable $e) {
    error_log('media-group error: ' . $e->getMessage());
    mgOut(['ok' => false, 'message' => 'Gagal memproses grup media. Pastikan migrasi dijalankan.'], 500);
}

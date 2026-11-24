<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/lead-magnets');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/lead-magnets');
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'ID lead magnet tidak valid.', 'error');
    redirect('/admin/lead-magnets');
}

try {
    $pdo = getDB();

    // Ambil path thumbnail dulu (sebelum baris dihapus) untuk dibersihkan dari disk.
    $thumb = '';
    try {
        $t = $pdo->prepare('SELECT thumbnail FROM lead_magnets WHERE id = ? LIMIT 1');
        $t->execute([$id]);
        $thumb = (string) ($t->fetchColumn() ?: '');
    } catch (Exception $e) {
        $thumb = '';
    }

    // leads ikut terhapus via FK ON DELETE CASCADE.
    $stmt = $pdo->prepare('DELETE FROM lead_magnets WHERE id = ?');
    $stmt->execute([$id]);

    // Hapus file thumbnail dari disk — hanya bila benar-benar berada di dalam
    // folder upload lead-magnets (cegah path traversal / hapus file di luar).
    if ($thumb !== '') {
        $root  = realpath(__DIR__ . '/../../');
        $lmDir = realpath(rtrim(UPLOAD_PATH, '/\\') . '/lead-magnets');
        $abs   = $root ? realpath($root . '/' . ltrim($thumb, '/')) : false;
        if ($abs && $lmDir && str_starts_with($abs, $lmDir) && is_file($abs)) {
            @unlink($abs);
        }
    }

    if (function_exists('logActivity')) {
        logActivity('admin', 'lead_magnet.deleted', 'lead_magnets', $id, 'Lead magnet #' . $id . ' dihapus.');
    }
    flash('success', 'Lead magnet beserta leads-nya berhasil dihapus.');
} catch (Exception $e) {
    error_log('Delete lead magnet error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus lead magnet.', 'error');
}

redirect('/admin/lead-magnets');

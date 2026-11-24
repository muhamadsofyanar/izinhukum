<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../helpers/darmutiara-updates.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit;
}

try {
    darmutiaraQueueUpdate((string) ($_POST['version'] ?? ''));
    flash('success', 'Permintaan deploy diterima Coolify. Buka ulang halaman ini setelah deploy selesai.');
} catch (RuntimeException $error) {
    flash('error', $error->getMessage(), 'error');
}
redirect('/admin/update');

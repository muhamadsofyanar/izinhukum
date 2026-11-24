<?php
// POST /actions/admin/toggle-affiliate-display — aktif/nonaktif cepat dari list.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/affiliate-displays');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/affiliate-displays');
}

$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    try {
        getDB()->prepare(
            "UPDATE affiliate_displays SET status = IF(status='active','inactive','active'), updated_at = NOW() WHERE id = ?"
        )->execute([$id]);
    } catch (Exception $e) {
        error_log('toggle-affiliate-display error: ' . $e->getMessage());
    }
}
redirect('/admin/affiliate-displays');

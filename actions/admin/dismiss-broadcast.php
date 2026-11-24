<?php
// POST /actions/admin/dismiss-broadcast
// Tutup broadcast vendor di lonceng admin (per-id). Broadcast BARU (id beda)
// tetap muncul lagi. Hanya admin/staff.
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false]);
    exit;
}

$id = (int) ($_POST['broadcast_id'] ?? 0);
if ($id > 0) {
    try { setSetting('vendor_broadcast_dismissed_id', (string) $id); } catch (Throwable $e) {}
}
echo json_encode(['success' => true]);

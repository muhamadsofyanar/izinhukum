<?php
// ─── Estimasi jumlah penerima broadcast (read-only, AJAX) ──────────
// Memakai resolver ASLI broadcastRecipientRows() agar angka = daftar kirim
// sebenarnya (exclude produk & skip email gagal ikut diperhitungkan).
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('broadcast')) { http_response_code(403); echo json_encode(['error' => 'Akses ditolak.']); exit; }

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['error' => 'Permintaan tidak valid.']);
    exit;
}

$channel = trim($_POST['channel'] ?? '');
$segment = trim($_POST['target_segment'] ?? 'all_users');
if (!in_array($channel, ['email', 'whatsapp'], true)) { echo json_encode(['error' => 'Channel tidak valid.']); exit; }
if (in_array($segment, ['email_openers', 'email_clickers'], true) && $channel !== 'email') {
    echo json_encode(['error' => 'Segmen ini hanya untuk channel Email.']); exit;
}

$excludeProductIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['exclude_products'] ?? [])), fn($v) => $v > 0)));
$targetProductIds  = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['target_products'] ?? [])), fn($v) => $v > 0)));
$engagementDays    = in_array((int) ($_POST['engagement_days'] ?? 0), [30, 60, 90], true) ? (int) $_POST['engagement_days'] : null;

// Campaign sintetis (tanpa disimpan) untuk resolver.
$campaign = [
    'id'                         => 0,
    'channel'                    => $channel,
    'target_segment'             => $segment,
    'target_membership_level_id' => !empty($_POST['target_membership_level_id']) ? (int) $_POST['target_membership_level_id'] : null,
    'target_lead_magnet_id'      => !empty($_POST['target_lead_magnet_id']) ? (int) $_POST['target_lead_magnet_id'] : null,
    'target_product_ids'         => $targetProductIds ? implode(',', $targetProductIds) : null,
    'exclude_product_ids'        => $excludeProductIds ? implode(',', $excludeProductIds) : null,
    'skip_failed_emails'         => !empty($_POST['skip_failed_emails']) ? 1 : 0,
    'engagement_days'            => $engagementDays,
];

try {
    $rows  = broadcastRecipientRows(getDB(), $campaign);
    $valid = 0;
    foreach ($rows as $r) { if (($r[4] ?? '') === 'pending') $valid++; }
    echo json_encode(['valid' => $valid, 'total' => count($rows)]);
} catch (Exception $e) {
    error_log('broadcast-estimate error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Gagal menghitung penerima.']);
}
exit;

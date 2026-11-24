<?php
/**
 * Broadcast — START endpoint (AJAX/JSON).
 *
 * Tidak lagi mengirim sinkron. Tugasnya: validasi, susun daftar penerima ke
 * broadcast_recipients (status 'pending'), tandai campaign 'sending', lalu
 * kembalikan broadcast_id + total agar frontend memulai SSE stream.
 *
 * Pengiriman sebenarnya dilakukan oleh actions/admin/broadcast-stream.php.
 */

require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();

header('Content-Type: application/json; charset=utf-8');

function bc_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!staffCan('broadcast')) {
    bc_json(['success' => false, 'message' => 'Kamu tidak memiliki akses untuk aksi ini.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bc_json(['success' => false, 'message' => 'Metode tidak valid.'], 405);
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    bc_json(['success' => false, 'message' => 'Token keamanan tidak valid.'], 403);
}

// Lepas lock sesi setelah auth/CSRF — build worklist (bisa ribuan baris) tak boleh
// menahan sesi & memblokir request lain dari admin yang sama.
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
@set_time_limit(0);

$campaignId = (int) ($_POST['campaign_id'] ?? 0);
if ($campaignId <= 0) {
    bc_json(['success' => false, 'message' => 'ID campaign tidak valid.']);
}

$pdo = getDB();

$stmt = $pdo->prepare("SELECT * FROM broadcast_campaigns WHERE id = ? LIMIT 1");
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch();

if (!$campaign) {
    bc_json(['success' => false, 'message' => 'Campaign tidak ditemukan.']);
}
if ($campaign['status'] === 'sending') {
    bc_json(['success' => false, 'message' => 'Campaign sedang dalam proses pengiriman.']);
}

$channel      = $campaign['channel'];
$providerName = $campaign['provider'];

// ── Validasi provider sebelum susun penerima ──
require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';

if ($channel === 'whatsapp' && getSetting($providerName . '_enabled', '0') !== '1') {
    bc_json(['success' => false, 'message' => 'Provider "' . $providerName . '" sedang dinonaktifkan. Aktifkan dulu di Integrasi.']);
}
if (!NotificationDispatcher::getAdapterPublic($providerName)) {
    bc_json(['success' => false, 'message' => 'Provider "' . $providerName . '" tidak dikenali atau tidak aktif.']);
}

// ── Susun worklist penerima (segmen + kecualikan produk + skip email gagal) ──
// Logika terpusat di helper agar sama dg jalur scheduled/cron.
try {
    $res = startBroadcastCampaign($pdo, $campaign);
} catch (Throwable $e) {
    error_log('Send broadcast prepare error: ' . $e->getMessage());
    bc_json(['success' => false, 'message' => 'Gagal menyiapkan antrian pengiriman.']);
}

if (($res['valid'] ?? 0) <= 0) {
    // Tak ada penerima valid → kembalikan status draft agar tak nyangkut 'sending'.
    $pdo->prepare("UPDATE broadcast_campaigns SET status='draft' WHERE id=?")->execute([$campaignId]);
    bc_json(['success' => false, 'message' => 'Tidak ada penerima valid (mungkin semua dikecualikan / alamat kosong).']);
}

if (function_exists('logActivity')) {
    logActivity('admin', 'broadcast.start', 'broadcast_campaigns', $campaignId,
        'Broadcast "' . $campaign['name'] . '" dimulai (' . $res['valid'] . ' penerima).');
}

bc_json([
    'success'     => true,
    'campaign_id' => $campaignId,
    'total'       => $res['valid'],
    'total_all'   => $res['total'],
]);

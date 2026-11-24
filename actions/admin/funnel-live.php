<?php
// Pengunjung aktif funnel (live) — dipanggil via fetch untuk auto-refresh kartu.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');
$win  = (int) ($_GET['window'] ?? 60);
$live = funnelLivePages($win);
echo json_encode([
    'ok'         => true,
    'window'     => max(30, min(3600, $win)),
    'total'      => array_sum(array_column($live, 'total')),
    'pages'      => $live,
    'updated_at' => date('H:i:s'),
], JSON_UNESCAPED_UNICODE);

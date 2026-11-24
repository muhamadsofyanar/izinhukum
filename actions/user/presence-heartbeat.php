<?php
// Heartbeat presence MEMBER — dipanggil tiap ~20 dtk dari area member (footer).
// Mencatat "siapa yang SEDANG membuka area member" ke tabel user_presence →
// dipakai indikator "sedang online" di kartu Active Members dashboard admin.
// user_id dibaca dari sesi server (bukan dari request). Balas 204 tanpa body.
// Fail-soft: error tak boleh mengganggu member.
//
// TANPA CSRF — sengaja, pola sama dengan funnel-heartbeat publik: ini ping
// presence self-scoped (hanya menyentuh user_id milik sesi sendiri), tak ada
// mutasi state sensitif. Guard: wajib login & role 'user'.
require_once __DIR__ . '/../../bootstrap.php';

http_response_code(204);

// Hanya member (role 'user'). Admin/staff bukan "member online".
if (!isLoggedIn()) { exit; }
if (function_exists('isAdminOrStaff') && isAdminOrStaff()) { exit; }
if (($_SESSION['role'] ?? '') !== 'user') { exit; }

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { exit; }

try {
    $pdo = getDB();
    $pdo->prepare(
        "INSERT INTO user_presence (user_id, last_seen)
         VALUES (?, NOW())
         ON DUPLICATE KEY UPDATE last_seen = NOW()"
    )->execute([$uid]);

    // Prune sesekali (≈2%): buang presence basi >1 jam agar tabel ramping.
    if (random_int(1, 50) === 1) {
        $pdo->exec("DELETE FROM user_presence WHERE last_seen < (NOW() - INTERVAL 1 HOUR)");
    }
} catch (Throwable $e) {
    error_log('presence-heartbeat: ' . $e->getMessage());
}
exit;

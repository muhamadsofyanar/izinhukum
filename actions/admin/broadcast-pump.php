<?php
/**
 * Broadcast — PUMP endpoint (AJAX/JSON).
 * Memproses SATU batch penerima (lock-guarded) lalu kembalikan progress.
 * Dipanggil berulang oleh halaman broadcast (realtime) & pumper global admin.
 * Pengiriman latar sebenarnya juga dijalankan cron (processBroadcastQueue).
 *
 * METHOD: POST {campaign_id?, limit?}
 */
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();

header('Content-Type: application/json; charset=utf-8');
function bcpump_json(array $p, int $c = 200): void {
    http_response_code($c);
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!staffCan('broadcast')) bcpump_json(['success' => false, 'message' => 'Tanpa akses.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bcpump_json(['success' => false, 'message' => 'Metode tidak valid.'], 405);
if (!validateCSRF($_POST['csrf_token'] ?? '')) bcpump_json(['success' => false, 'message' => 'Token keamanan tidak valid.'], 403);

// PENTING: lepas lock file-sesi PHP setelah auth/CSRF. Tanpa ini, request pump yang
// berjalan ~4,5 detik (batch × jeda) menahan sesi → SEMUA request lain dari admin
// yang sama (pindah menu, aset) ikut ANTRE → halaman terasa berat. Auth sudah selesai.
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
@set_time_limit(0);
@ignore_user_abort(true); // lanjut proses batch ini walau tab pindah/tutup

$cid   = (int) ($_POST['campaign_id'] ?? 0) ?: null;
$limit = max(1, min(50, (int) ($_POST['limit'] ?? 15)));

$r = processBroadcastQueue($limit, $cid);

// Bila campaign target sudah tak 'sending' (selesai/failed) → ambil status final agar
// halaman tahu berhenti polling.
if ($cid && empty($r['campaign_id'])) {
    try {
        $pdo = getDB();
        $c = $pdo->prepare("SELECT status, total_sent, total_failed, total_recipients FROM broadcast_campaigns WHERE id=? LIMIT 1");
        $c->execute([$cid]);
        if ($row = $c->fetch()) {
            $r['campaign_id'] = $cid;
            $r['status']      = $row['status'];
            $r['sent']        = (int) $row['total_sent'];
            $r['failed']      = (int) $row['total_failed'];
            $r['total']       = (int) $row['total_recipients'];
            $r['pending']     = max(0, $r['total'] - $r['sent'] - $r['failed']);
        }
    } catch (Throwable $e) { /* abaikan */ }
}

$done  = (int) ($r['sent'] ?? 0) + (int) ($r['failed'] ?? 0);
$total = (int) ($r['total'] ?? 0);
$r['success'] = true;
$r['percent'] = $total > 0 ? (int) round($done / $total * 100) : (($r['status'] ?? '') === 'sent' ? 100 : 0);

bcpump_json($r);

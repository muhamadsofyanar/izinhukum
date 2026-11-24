<?php
/**
 * Kompres massal gambar Galeri Media yang masih > 400 KB (AJAX, JSON).
 *
 * Diproses per-batch ber-CURSOR (after_id) agar tahan timeout & TIDAK infinite-loop:
 * file yang tak bisa turun ke bawah target (atau GIF) dilewati sekali & cursor maju,
 * tak akan dipilih ulang. Frontend memanggil berulang sampai done=true.
 *
 * METHOD: POST {csrf_token, after_id?, limit?}
 */
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

function cmb_json(array $p, int $c = 200): void {
    http_response_code($c);
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cmb_json(['ok' => false, 'message' => 'Metode tidak valid.'], 405);
if (!validateCSRF($_POST['csrf_token'] ?? '')) cmb_json(['ok' => false, 'message' => 'Sesi kedaluwarsa. Muat ulang halaman.'], 403);

// Lepas lock sesi — pemrosesan GD bisa panjang, jangan tahan request lain admin.
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
@set_time_limit(0);

const CMB_TARGET = 400 * 1024; // 400 KB
const CMB_MIMES  = "('image/jpeg','image/png','image/webp')"; // GIF dilewati (tak bisa dikompres aman)

$afterId = max(0, (int) ($_POST['after_id'] ?? 0));
$limit   = max(1, min(8, (int) ($_POST['limit'] ?? 3)));

$pdo    = getDB();
$dirAbs = rtrim(UPLOAD_PATH, '/\\') . '/media';

// Total kandidat (gambar > target yang BISA dikompres) — utk progress di frontend.
$totalLarge = (int) $pdo->query("SELECT COUNT(*) FROM media_files WHERE size > " . CMB_TARGET . " AND mime IN " . CMB_MIMES)->fetchColumn();

$sel = $pdo->prepare(
    "SELECT id, filename, mime, size FROM media_files
     WHERE id > ? AND size > ? AND mime IN " . CMB_MIMES . "
     ORDER BY id ASC LIMIT ?"
);
$sel->bindValue(1, $afterId, PDO::PARAM_INT);
$sel->bindValue(2, CMB_TARGET, PDO::PARAM_INT);
$sel->bindValue(3, $limit, PDO::PARAM_INT);
$sel->execute();
$rows = $sel->fetchAll();

$upd = $pdo->prepare("UPDATE media_files SET size = ?, width = ?, height = ? WHERE id = ?");

$processed = 0; $compressed = 0; $saved = 0; $lastId = $afterId;

foreach ($rows as $m) {
    $lastId = (int) $m['id'];
    $path = $dirAbs . '/' . basename((string) $m['filename']);
    if (!is_file($path)) {
        // Berkas hilang → set size 0 agar tak terus terhitung "besar". Lewati.
        $upd->execute([0, null, null, (int) $m['id']]);
        continue;
    }
    $res = compressImageToTarget($path, CMB_TARGET);
    $newSize = (int) @filesize($path);
    $w = $h = null;
    $gi = @getimagesize($path);
    if ($gi) { $w = (int) $gi[0]; $h = (int) $gi[1]; }
    $upd->execute([$newSize, $w, $h, (int) $m['id']]);

    $processed++;
    if (!empty($res['compressed'])) {
        $compressed++;
        $saved += max(0, (int) $res['before'] - (int) $res['after']);
    }
}

cmb_json([
    'ok'         => true,
    'processed'  => $processed,
    'compressed' => $compressed,
    'saved'      => $saved,
    'last_id'    => $lastId,
    'total'      => $totalLarge,   // kandidat tersisa saat batch ini dimulai
    'done'       => count($rows) === 0,
]);

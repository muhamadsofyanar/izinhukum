<?php
require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) { echo json_encode(['success' => false, 'message' => 'Silakan login.']); exit; }
if (!validateCSRF($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'CSRF tidak valid.']); exit; }

$downloadId = (int) ($_POST['download_id'] ?? 0);
if (!$downloadId) { echo json_encode(['success' => false, 'message' => 'Download tidak ditemukan.']); exit; }

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT pd.id, pd.product_id FROM product_downloads pd WHERE pd.id = ? AND pd.status = 'active' LIMIT 1");
    $stmt->execute([$downloadId]);
    $download = $stmt->fetch();
    if (!$download) { echo json_encode(['success' => false, 'message' => 'File tidak ditemukan atau tidak aktif.']); exit; }

    // Admin/staff lolos (dipakai mode preview materi); member lewat canAccessDownload:
    // akses produk pemilik file ATAU akses materi yang menaut file ini.
    if (!isAdminOrStaff() && !canAccessDownload((int) $_SESSION['user_id'], $downloadId)) {
        echo json_encode(['success' => false, 'message' => 'Kamu tidak memiliki akses ke file ini.']); exit;
    }

    $token = generateDownloadToken($downloadId, (int) $_SESSION['user_id']);
    echo json_encode(['success' => true, 'token' => $token]);
} catch (Exception $e) {
    error_log('request-download error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan server.']);
}
exit;

<?php
// POST /actions/admin/fetch-mailketing-lists
// Ambil daftar list Mailketing (viewlist) untuk dropdown pengaturan, lalu cache.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method tidak diizinkan.']);
    exit;
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman.']);
    exit;
}

try {
    require_once __DIR__ . '/../../helpers/MailketingClient.php';
    $res = MailketingClient::viewList();
    if (!empty($res['ok'])) {
        // Cache untuk render dropdown setelah reload halaman.
        setSetting('mailketing_lists_cache', json_encode($res['lists'], JSON_UNESCAPED_UNICODE), 'integrations');
        echo json_encode(['success' => true, 'lists' => $res['lists']]);
    } else {
        echo json_encode(['success' => false, 'message' => (string) ($res['message'] ?? 'Gagal mengambil daftar list.')]);
    }
} catch (Throwable $e) {
    error_log('fetch-mailketing-lists error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat mengambil daftar list.']);
}

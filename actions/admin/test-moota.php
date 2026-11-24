<?php
// POST /actions/admin/test-moota
// Tes koneksi ke API Moota memakai Server Key tersimpan (dipanggil via AJAX).
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permintaan tidak valid.']);
    exit;
}

require_once __DIR__ . '/../../payment_gateways/MootaGateway.php';

try {
    $gw  = new MootaGateway();
    $res = $gw->fetchBankAccounts();

    if (!$res['success']) {
        echo json_encode(['success' => false, 'message' => $res['message']]);
        exit;
    }

    // Info Rekening diambil OTOMATIS dari Moota → simpan ke config_json['bank_info']
    // (sumber tunggal; tidak lagi diketik manual admin). Kolom lain di config dipertahankan.
    $bankInfo = MootaGateway::formatBankInfo($res['accounts']);
    $pdo      = getDB();
    $row      = $pdo->query("SELECT config_json FROM payment_gateways WHERE provider='moota' LIMIT 1")->fetch();
    $cfg      = ($row && !empty($row['config_json'])) ? (json_decode($row['config_json'], true) ?: []) : [];
    $cfg['bank_info'] = $bankInfo;
    $pdo->prepare("UPDATE payment_gateways SET config_json = ?, updated_at = NOW() WHERE provider='moota'")
        ->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE)]);

    $note = $bankInfo === ''
        ? ' Namun belum ada rekening terdaftar di Moota — tambahkan rekening dulu di dashboard Moota, lalu tes ulang.'
        : '';
    echo json_encode(['success' => true, 'message' => $res['message'] . $note, 'bank_info' => $bankInfo]);
} catch (Throwable $e) {
    error_log('test-moota error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat menguji koneksi.']);
}

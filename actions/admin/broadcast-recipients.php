<?php
/**
 * Broadcast — daftar penerima (log) per campaign untuk modal Detail. JSON.
 * METHOD: GET  ·  URL: /actions/admin/broadcast-recipients?id={campaign_id}
 */

require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();

header('Content-Type: application/json; charset=utf-8');

if (!staffCan('broadcast')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Tidak memiliki akses.']);
    exit;
}

$campaignId = (int) ($_GET['id'] ?? 0);
if ($campaignId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
    exit;
}

try {
    $pdo = getDB();

    $cStmt = $pdo->prepare("SELECT id, name, channel, status, total_sent, total_failed FROM broadcast_campaigns WHERE id = ? LIMIT 1");
    $cStmt->execute([$campaignId]);
    $campaign = $cStmt->fetch();
    if (!$campaign) {
        echo json_encode(['success' => false, 'message' => 'Campaign tidak ditemukan.']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT br.recipient, br.status, br.error_message, br.sent_at,
                COALESCE(u.name, l.name, '') AS name
         FROM broadcast_recipients br
         LEFT JOIN users u ON u.id = br.user_id
         LEFT JOIN leads l ON l.id = br.lead_id
         WHERE br.campaign_id = ?
         ORDER BY (br.status = 'failed') DESC, br.id ASC
         LIMIT 2000"
    );
    $stmt->execute([$campaignId]);
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'     => true,
        'campaign'    => [
            'name'         => $campaign['name'],
            'channel'      => $campaign['channel'],
            'status'       => $campaign['status'],
            'total_sent'   => (int) $campaign['total_sent'],
            'total_failed' => (int) $campaign['total_failed'],
        ],
        'recipients'  => $recipients,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    error_log('broadcast-recipients error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Gagal memuat data.']);
}

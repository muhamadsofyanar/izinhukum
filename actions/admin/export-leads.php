<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$filterMagnet = (int) ($_GET['magnet'] ?? 0);
$q = trim($_GET['q'] ?? '');

try {
    $pdo = getDB();

    $where  = '1=1';
    $params = [];
    if ($filterMagnet > 0) { $where .= ' AND l.lead_magnet_id = ?'; $params[] = $filterMagnet; }
    if ($q !== '') {
        $where .= ' AND (l.name LIKE ? OR l.whatsapp LIKE ? OR l.email LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }

    $stmt = $pdo->prepare(
        "SELECT l.id, l.name, l.whatsapp, COALESCE(l.email,'') AS email,
                lm.title AS magnet_title, COALESCE(l.source,'') AS source,
                l.promoted_user_id,
                CASE
                    WHEN l.promoted_user_id IS NOT NULL
                         AND EXISTS (SELECT 1 FROM orders o WHERE o.user_id = l.promoted_user_id AND o.status='paid')
                    THEN 'Customer'
                    WHEN l.promoted_user_id IS NOT NULL THEN 'User'
                    ELSE 'Lead'
                END AS tahap,
                l.created_at,
                COALESCE(au.name,'') AS affiliate_name
         FROM leads l
         INNER JOIN lead_magnets lm ON lm.id = l.lead_magnet_id
         LEFT JOIN affiliates a ON a.id = l.affiliate_id
         LEFT JOIN users au ON au.id = a.user_id
         WHERE {$where}
         ORDER BY l.created_at DESC"
    );
    $stmt->execute($params);

    $filename = 'leads_export_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM utf-8 utk Excel
    fputcsv($output, ['ID', 'Nama', 'WhatsApp', 'Email', 'Lead Magnet', 'Affiliate', 'Sumber', 'Tahap', 'Tanggal']);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['name'],
            $row['whatsapp'],
            $row['email'],
            $row['magnet_title'],
            $row['affiliate_name'],
            $row['source'],
            $row['tahap'],
            $row['created_at'],
        ]);
    }
    fclose($output);
    exit;

} catch (Exception $e) {
    error_log('Export leads error: ' . $e->getMessage());
    flash('error', 'Gagal mengekspor leads.', 'error');
    redirect('/admin/lead-magnets?tab=leads');
}

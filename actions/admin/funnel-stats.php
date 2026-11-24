<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

$range     = $_GET['range'] ?? '7days';
$rawProd   = (string) ($_GET['product_id'] ?? '0');
$productId = 0;
$bundleId  = 0;

if (str_starts_with($rawProd, 'b_')) {
    $bundleId = (int) substr($rawProd, 2);
} else {
    $productId = (int) $rawProd;
}

// Rentang hari untuk chart (max 30 titik)
$chartDays = 7;

// Semua nilai $dateCondition berasal dari whitelist switch — tidak ada user input langsung
switch ($range) {
    case 'today':
        $dateCondition = "DATE(created_at) = CURDATE()";
        $chartDays     = 1;
        break;
    case 'yesterday':
        $dateCondition = "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        $chartDays     = 1;
        break;
    case 'week':
        $dateCondition = "created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $chartDays     = 7;
        break;
    case '30days':
    case 'month':
        $dateCondition = "created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $chartDays     = 30;
        $range         = '30days';
        break;
    case 'lifetime':
        $dateCondition = "1=1";
        $chartDays     = 30;
        break;
    case '7days':
    default:
        $dateCondition = "created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $chartDays     = 7;
        $range         = '7days';
        break;
}

try {
    $pdo = getDB();

    if ($bundleId > 0) {
        // Stat cards — filter per bundle
        $stmtLead = $pdo->prepare("SELECT COUNT(DISTINCT o.id) FROM orders o WHERE $dateCondition AND EXISTS (SELECT 1 FROM order_items oib WHERE oib.order_id = o.id AND oib.bundle_id = ?)");
        $stmtLead->execute([$bundleId]);
        $totalLead = (int) $stmtLead->fetchColumn();

        $stmtSales = $pdo->prepare("SELECT COUNT(DISTINCT o.id) FROM orders o WHERE o.status='paid' AND $dateCondition AND EXISTS (SELECT 1 FROM order_items oib WHERE oib.order_id = o.id AND oib.bundle_id = ?)");
        $stmtSales->execute([$bundleId]);
        $totalSales = (int) $stmtSales->fetchColumn();

        $stmtOmzet = $pdo->prepare("SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE o.status='paid' AND $dateCondition AND EXISTS (SELECT 1 FROM order_items oib WHERE oib.order_id = o.id AND oib.bundle_id = ?)");
        $stmtOmzet->execute([$bundleId]);
        $totalOmzet = (float) $stmtOmzet->fetchColumn();

        // Chart data — per bundle
        $stmtChart = $pdo->prepare(
            "SELECT DATE(o.created_at) AS date, COUNT(DISTINCT o.id) AS count
             FROM orders o
             WHERE EXISTS (SELECT 1 FROM order_items oib WHERE oib.order_id = o.id AND oib.bundle_id = ?)
               AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(o.created_at)
             ORDER BY date ASC"
        );
        $stmtChart->execute([$bundleId, $chartDays]);
        $rawChart = $stmtChart->fetchAll(PDO::FETCH_KEY_PAIR);

    } elseif ($productId > 0) {
        // Stat cards — filter per produk
        $stmtLead = $pdo->prepare("SELECT COUNT(DISTINCT o.id) FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE $dateCondition AND oi.product_id = ?");
        $stmtLead->execute([$productId]);
        $totalLead = (int) $stmtLead->fetchColumn();

        $stmtSales = $pdo->prepare("SELECT COUNT(DISTINCT o.id) FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.status='paid' AND $dateCondition AND oi.product_id = ?");
        $stmtSales->execute([$productId]);
        $totalSales = (int) $stmtSales->fetchColumn();

        $stmtOmzet = $pdo->prepare("SELECT COALESCE(SUM(oi.price * oi.quantity),0) FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.status='paid' AND $dateCondition AND oi.product_id = ?");
        $stmtOmzet->execute([$productId]);
        $totalOmzet = (float) $stmtOmzet->fetchColumn();

        // Chart data — per produk
        $stmtChart = $pdo->prepare(
            "SELECT DATE(o.created_at) AS date, COUNT(DISTINCT o.id) AS count
             FROM orders o
             JOIN order_items oi ON oi.order_id = o.id
             WHERE oi.product_id = ? AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(o.created_at)
             ORDER BY date ASC"
        );
        $stmtChart->execute([$productId, $chartDays]);
        $rawChart = $stmtChart->fetchAll(PDO::FETCH_KEY_PAIR);
    } else {
        // Stat cards — query global
        $totalLead  = (int)   $pdo->query("SELECT COUNT(*) FROM orders WHERE $dateCondition")->fetchColumn();
        $totalSales = (int)   $pdo->query("SELECT COUNT(*) FROM orders WHERE status='paid' AND $dateCondition")->fetchColumn();
        $totalOmzet = (float) $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid' AND $dateCondition")->fetchColumn();

        // Chart data — global
        $stmt = $pdo->prepare(
            "SELECT DATE(created_at) AS date, COUNT(*) AS count
             FROM orders
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC"
        );
        $stmt->execute([$chartDays]);
        $rawChart = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // Isi tanggal kosong dengan 0 supaya chart kontinu
    $chartData = [];
    for ($i = max(0, $chartDays - 1); $i >= 0; $i--) {
        $d           = date('Y-m-d', strtotime("-{$i} days"));
        $chartData[] = [
            'date'      => date('d M', strtotime($d)),
            'full_date' => $d,
            'count'     => (int) ($rawChart[$d] ?? 0),
        ];
    }

    // Member yang sedang online (global, lepas dari filter range/produk) — untuk
    // indikator live di kartu Active Members. Auto-refresh ikut poller 30 dtk.
    $membersOnline = function_exists('countMembersOnline') ? countMembersOnline() : 0;

    echo json_encode([
        'success' => true,
        'stats'   => [
            'total_lead'            => $totalLead,
            'total_sales'           => $totalSales,
            'total_omzet'           => (int) $totalOmzet,
            'total_omzet_formatted' => formatRupiah($totalOmzet),
            'members_online'        => $membersOnline,
        ],
        'chart' => $chartData,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('Funnel stats error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Gagal memuat statistik']);
}


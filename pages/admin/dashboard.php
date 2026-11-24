<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('dashboard')) { redirect('/admin/no-access'); }

$pageTitle   = 'Dashboard';
$topbarTitle = 'Dashboard';

// ─── Filter Produk / Bundle ──────────────────────────────────
$rawProductFilter  = (string) ($_GET['product_id'] ?? '0');
$selectedProductId = 0;
$selectedBundleId  = 0;

if (str_starts_with($rawProductFilter, 'b_')) {
    $selectedBundleId = (int) substr($rawProductFilter, 2);
} else {
    $selectedProductId = (int) $rawProductFilter;
}

$allProducts = [];
$allBundles  = [];

try {
    $pdo = getDB();
    $allProducts = $pdo->query("SELECT id, name, type FROM products ORDER BY name ASC")->fetchAll();
    $allBundles  = $pdo->query("SELECT id, name FROM product_bundles ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    error_log('Dashboard fetch products/bundles error: ' . $e->getMessage());
}

// ─── Queries statistik ────────────────────────────────────────
$totalUsers    = 0;
$totalOrders   = 0;
$totalRevenue  = 0.0;
$activeMembers = 0;
$recentOrders  = [];
$topProducts   = [];

// Member yang sedang online (presence realtime, window 120 dtk) — indikator live
// di kartu Active Members. Global (lepas dari filter bundle/produk). Fail-soft → 0.
$membersOnline = countMembersOnline();

try {
    $pdo = getDB();

    if ($selectedBundleId > 0) {
        $stmtUsers = $pdo->prepare(
            "SELECT COUNT(DISTINCT o.user_id) 
             FROM orders o 
             JOIN users u ON u.id = o.user_id 
             WHERE u.role = 'user' AND EXISTS (SELECT 1 FROM order_items oib WHERE oib.order_id = o.id AND oib.bundle_id = ?)"
        );
        $stmtUsers->execute([$selectedBundleId]);
        $totalUsers = (int) $stmtUsers->fetchColumn();

        $stmtOrders = $pdo->prepare(
            "SELECT COUNT(DISTINCT o.id) 
             FROM orders o 
             WHERE EXISTS (SELECT 1 FROM order_items oib WHERE oib.order_id = o.id AND oib.bundle_id = ?)"
        );
        $stmtOrders->execute([$selectedBundleId]);
        $totalOrders = (int) $stmtOrders->fetchColumn();

        $stmtRev = $pdo->prepare(
            "SELECT COALESCE(SUM(o.total), 0) 
             FROM orders o 
             WHERE o.status = 'paid' AND EXISTS (SELECT 1 FROM order_items oib WHERE oib.order_id = o.id AND oib.bundle_id = ?)"
        );
        $stmtRev->execute([$selectedBundleId]);
        $totalRevenue = (float) $stmtRev->fetchColumn();

        $stmtMem = $pdo->prepare(
            "SELECT COUNT(DISTINCT ma.user_id) 
             FROM member_access ma 
             JOIN product_bundle_items pbi ON pbi.product_id = ma.product_id 
             WHERE pbi.bundle_id = ? 
               AND ma.status = 'active' 
               AND (ma.expires_at IS NULL OR ma.expires_at > NOW())"
        );
        $stmtMem->execute([$selectedBundleId]);
        $activeMembers = (int) $stmtMem->fetchColumn();

    } elseif ($selectedProductId > 0) {
        $stmtUsers = $pdo->prepare(
            "SELECT COUNT(DISTINCT o.user_id) 
             FROM orders o 
             JOIN order_items oi ON oi.order_id = o.id 
             JOIN users u ON u.id = o.user_id 
             WHERE u.role = 'user' AND oi.product_id = ?"
        );
        $stmtUsers->execute([$selectedProductId]);
        $totalUsers = (int) $stmtUsers->fetchColumn();

        $stmtOrders = $pdo->prepare(
            "SELECT COUNT(DISTINCT o.id) 
             FROM orders o 
             JOIN order_items oi ON oi.order_id = o.id 
             WHERE oi.product_id = ?"
        );
        $stmtOrders->execute([$selectedProductId]);
        $totalOrders = (int) $stmtOrders->fetchColumn();

        $stmtRev = $pdo->prepare(
            "SELECT COALESCE(SUM(oi.price * oi.quantity), 0) 
             FROM order_items oi 
             JOIN orders o ON oi.order_id = o.id 
             WHERE o.status = 'paid' AND oi.product_id = ?"
        );
        $stmtRev->execute([$selectedProductId]);
        $totalRevenue = (float) $stmtRev->fetchColumn();

        $stmtMem = $pdo->prepare(
            "SELECT COUNT(DISTINCT user_id) 
             FROM member_access 
             WHERE status = 'active' 
               AND (expires_at IS NULL OR expires_at > NOW()) 
               AND product_id = ?"
        );
        $stmtMem->execute([$selectedProductId]);
        $activeMembers = (int) $stmtMem->fetchColumn();
    } else {
        $totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();

        $totalOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();

        $totalRevenue = (float) $pdo->query(
            "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status = 'paid'"
        )->fetchColumn();

        $activeMembers = (int) $pdo->query(
            "SELECT COUNT(*) FROM member_access
             WHERE status = 'active'
               AND (expires_at IS NULL OR expires_at > NOW())"
        )->fetchColumn();
    }

} catch (Exception $e) {
    error_log('Dashboard stats error: ' . $e->getMessage());
}

try {
    $pdo = getDB();
    if ($selectedBundleId > 0) {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT o.order_number, o.total, o.status, o.created_at,
                    u.name AS user_name
             FROM orders o
             JOIN users  u ON o.user_id = u.id
             WHERE EXISTS (SELECT 1 FROM order_items oib WHERE oib.order_id = o.id AND oib.bundle_id = ?)
             ORDER BY o.created_at DESC
             LIMIT 10"
        );
        $stmt->execute([$selectedBundleId]);
    } elseif ($selectedProductId > 0) {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT o.order_number, o.total, o.status, o.created_at,
                    u.name AS user_name
             FROM orders o
             JOIN users  u ON o.user_id = u.id
             JOIN order_items oi ON oi.order_id = o.id
             WHERE oi.product_id = ?
             ORDER BY o.created_at DESC
             LIMIT 10"
        );
        $stmt->execute([$selectedProductId]);
    } else {
        $stmt = $pdo->query(
            "SELECT o.order_number, o.total, o.status, o.created_at,
                    u.name AS user_name
             FROM orders o
             JOIN users  u ON o.user_id = u.id
             ORDER BY o.created_at DESC
             LIMIT 10"
        );
    }
    $recentOrders = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Dashboard recent orders error: ' . $e->getMessage());
}

try {
    $pdo = getDB();
    if ($selectedBundleId > 0) {
        // BUG LAMA (jangan diulang): 1 order bundle = BANYAK baris order_items (satu
        // per produk dalam bundle, lihat process-bundle.php) → JOIN order_items bikin
        // fan-out, SUM(o.total) TANPA dedup menjumlah o.total SEKALI PER ITEM, bukan
        // per order → revenue bundle ber-N produk overcount N kali lipat. Fix: dedup
        // per order.id dulu via subquery, baru SUM tanpa fan-out.
        $stmt = $pdo->prepare(
            "SELECT pb.name, pb.slug, 'bundle' AS type,
                    t.sold_count, t.revenue
             FROM product_bundles pb
             JOIN (
                 SELECT COUNT(*) AS sold_count, COALESCE(SUM(total), 0) AS revenue
                 FROM (
                     SELECT DISTINCT o.id, o.total
                     FROM orders o
                     JOIN order_items oi ON oi.order_id = o.id
                     WHERE o.status = 'paid' AND oi.bundle_id = ?
                 ) x
             ) t ON 1=1
             WHERE pb.id = ?"
        );
        $stmt->execute([$selectedBundleId, $selectedBundleId]);
    } elseif ($selectedProductId > 0) {
        $stmt = $pdo->prepare(
            "SELECT p.name, p.slug, p.type,
                    COUNT(oi.id)                      AS sold_count,
                    SUM(oi.price * oi.quantity)       AS revenue
             FROM order_items oi
             JOIN products p ON oi.product_id = p.id
             JOIN orders   o ON oi.order_id   = o.id
             WHERE o.status = 'paid' AND p.id = ?
             GROUP BY p.id, p.name, p.slug, p.type"
        );
        $stmt->execute([$selectedProductId]);
    } else {
        $stmt = $pdo->query(
            "SELECT p.name, p.slug, p.type,
                    COUNT(oi.id)                      AS sold_count,
                    SUM(oi.price * oi.quantity)       AS revenue
             FROM order_items oi
             JOIN products p ON oi.product_id = p.id
             JOIN orders   o ON oi.order_id   = o.id
             WHERE o.status = 'paid'
             GROUP BY p.id, p.name, p.slug, p.type
             ORDER BY sold_count DESC
             LIMIT 5"
        );
    }
    $topProducts = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Dashboard top products error: ' . $e->getMessage());
}

// ─── Fase 2: queries statistik lanjutan ──────────────────────
$monthlyRevenue      = [];
$revTotal6           = 0.0;
$revTrendPct         = null;
$totalCommPaid       = 0.0;
$totalCommPending    = 0.0;
$couponUsedCount     = 0;
$couponTotalDiscount = 0.0;
$levelDistribution   = [];

try {
    $pdo = getDB();
    if ($selectedBundleId > 0) {
        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(o.created_at, '%Y-%m') AS month, SUM(o.total) AS revenue, COUNT(DISTINCT o.id) AS orders
             FROM orders o
             WHERE o.status = 'paid' AND EXISTS (SELECT 1 FROM order_items oib WHERE oib.order_id = o.id AND oib.bundle_id = ?) AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
             GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
             ORDER BY month ASC"
        );
        $stmt->execute([$selectedBundleId]);
    } elseif ($selectedProductId > 0) {
        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(o.created_at, '%Y-%m') AS month, SUM(oi.price * oi.quantity) AS revenue, COUNT(DISTINCT o.id) AS orders
             FROM order_items oi
             JOIN orders o ON oi.order_id = o.id
             WHERE o.status = 'paid' AND oi.product_id = ? AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
             GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
             ORDER BY month ASC"
        );
        $stmt->execute([$selectedProductId]);
    } else {
        $stmt = $pdo->query(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, SUM(total) AS revenue, COUNT(*) AS orders
             FROM orders
             WHERE status = 'paid' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
             GROUP BY DATE_FORMAT(created_at, '%Y-%m')
             ORDER BY month ASC"
        );
    }
    $rawRevenue = []; $rawOrders = [];
    foreach ($stmt->fetchAll() as $row) {
        $rawRevenue[$row['month']] = (float) $row['revenue'];
        $rawOrders[$row['month']]  = (int) $row['orders'];
    }
    // Isi 6 slot bulan — bulan kosong tetap tampil dengan value 0
    $monthlyRevenue = [];
    for ($i = 5; $i >= 0; $i--) {
        $key              = date('Y-m', strtotime("-{$i} months"));
        $monthlyRevenue[] = ['month' => $key, 'revenue' => $rawRevenue[$key] ?? 0.0, 'orders' => $rawOrders[$key] ?? 0];
    }
    // Total 6 bulan + tren vs 6 bulan sebelumnya (untuk headline metrik).
    $revTotal6 = (float) array_sum(array_column($monthlyRevenue, 'revenue'));
    if ($selectedBundleId > 0) {
        $stmtPrev = $pdo->prepare(
            "SELECT COALESCE(SUM(o.total), 0) FROM orders o
             WHERE o.status = 'paid'
               AND EXISTS (SELECT 1 FROM order_items oib WHERE oib.order_id = o.id AND oib.bundle_id = ?)
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
               AND o.created_at <  DATE_SUB(NOW(), INTERVAL 6 MONTH)"
        );
        $stmtPrev->execute([$selectedBundleId]);
        $revPrev6 = (float) $stmtPrev->fetchColumn();
    } elseif ($selectedProductId > 0) {
        $stmtPrev = $pdo->prepare(
            "SELECT COALESCE(SUM(oi.price * oi.quantity), 0) FROM order_items oi
             JOIN orders o ON oi.order_id = o.id
             WHERE o.status = 'paid'
               AND oi.product_id = ?
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
               AND o.created_at <  DATE_SUB(NOW(), INTERVAL 6 MONTH)"
        );
        $stmtPrev->execute([$selectedProductId]);
        $revPrev6 = (float) $stmtPrev->fetchColumn();
    } else {
        $revPrev6 = (float) $pdo->query(
            "SELECT COALESCE(SUM(total), 0) FROM orders
             WHERE status = 'paid'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
               AND created_at <  DATE_SUB(NOW(), INTERVAL 6 MONTH)"
        )->fetchColumn();
    }
    $revTrendPct = $revPrev6 > 0 ? (int) round(($revTotal6 - $revPrev6) / $revPrev6 * 100) : null;
} catch (Exception $e) {
    error_log('Dashboard monthly revenue error: ' . $e->getMessage());
    $monthlyRevenue = [];
}

try {
    $pdo = getDB();
    if ($selectedBundleId > 0) {
        $stmtComm = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN c.status='paid'    THEN c.amount ELSE 0 END), 0) AS paid,
                COALESCE(SUM(CASE WHEN c.status='pending' THEN c.amount ELSE 0 END), 0) AS pending
             FROM commissions c
             JOIN orders o ON c.order_id = o.id
             WHERE EXISTS (SELECT 1 FROM order_items oib WHERE oib.order_id = o.id AND oib.bundle_id = ?)"
        );
        $stmtComm->execute([$selectedBundleId]);
        $commRow = $stmtComm->fetch(PDO::FETCH_ASSOC);
    } elseif ($selectedProductId > 0) {
        $stmtComm = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN c.status='paid'    THEN c.amount ELSE 0 END), 0) AS paid,
                COALESCE(SUM(CASE WHEN c.status='pending' THEN c.amount ELSE 0 END), 0) AS pending
             FROM commissions c
             JOIN orders o ON c.order_id = o.id
             JOIN order_items oi ON oi.order_id = o.id
             WHERE oi.product_id = ?"
        );
        $stmtComm->execute([$selectedProductId]);
        $commRow = $stmtComm->fetch(PDO::FETCH_ASSOC);
    } else {
        $commRow = $pdo->query(
            "SELECT
                COALESCE(SUM(CASE WHEN status='paid'    THEN amount ELSE 0 END), 0) AS paid,
                COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END), 0) AS pending
             FROM commissions"
        )->fetch(PDO::FETCH_ASSOC);
    }
    $totalCommPaid    = (float) ($commRow['paid']    ?? 0);
    $totalCommPending = (float) ($commRow['pending'] ?? 0);
} catch (Exception $e) {
    error_log('Dashboard commission stats error: ' . $e->getMessage());
}

try {
    $pdo = getDB();
    if ($selectedBundleId > 0) {
        $stmtCoup = $pdo->prepare(
            "SELECT COUNT(DISTINCT cu.id), COALESCE(SUM(cu.discount_amount), 0) 
             FROM coupon_usages cu
             JOIN orders o ON cu.order_id = o.id
             WHERE EXISTS (SELECT 1 FROM order_items oib WHERE oib.order_id = o.id AND oib.bundle_id = ?)"
        );
        $stmtCoup->execute([$selectedBundleId]);
        $row = $stmtCoup->fetch(PDO::FETCH_NUM);
    } elseif ($selectedProductId > 0) {
        $stmtCoup = $pdo->prepare(
            "SELECT COUNT(DISTINCT cu.id), COALESCE(SUM(cu.discount_amount), 0) 
             FROM coupon_usages cu
             JOIN orders o ON cu.order_id = o.id
             JOIN order_items oi ON oi.order_id = o.id
             WHERE oi.product_id = ?"
        );
        $stmtCoup->execute([$selectedProductId]);
        $row = $stmtCoup->fetch(PDO::FETCH_NUM);
    } else {
        $row = $pdo->query("SELECT COUNT(*), COALESCE(SUM(discount_amount),0) FROM coupon_usages")->fetch(PDO::FETCH_NUM);
    }
    $couponUsedCount     = (int)   ($row[0] ?? 0);
    $couponTotalDiscount = (float) ($row[1] ?? 0);
} catch (Exception $e) {
    error_log('Dashboard coupon stats error: ' . $e->getMessage());
}

try {
    $pdo  = getDB();
    $stmt = $pdo->query(
        "SELECT ml.id, ml.name, ml.color, COUNT(u.id) AS user_count
         FROM membership_levels ml
         LEFT JOIN users u ON u.membership_level_id = ml.id
         GROUP BY ml.id, ml.name, ml.color
         ORDER BY ml.sort_order ASC"
    );
    $levelDistribution = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Dashboard level distribution error: ' . $e->getMessage());
}

// ─── Onboarding Checklist Setup Data ─────────────────────────
$onboardingSteps = [];
try {
    $pdo = getDB();

    // Cek keberadaan transaksi/order sebagai fallback (toko yang sudah beroperasi pasti sudah selesai setup)
    $hasOrders = (int) ($pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn() ?? 0) > 0;

    $hasSiteName   = !empty(getSetting('site_name')) || $hasOrders;
    $hasBranding   = !empty(getSetting('site_logo')) || !empty(getSetting('favicon')) || !empty(getSetting('brand_color')) || $hasOrders;
    $hasGateways   = (int) ($pdo->query("SELECT COUNT(*) FROM payment_gateways WHERE is_active = 1")->fetchColumn() ?? 0) > 0
                     || (int) ($pdo->query("SELECT COUNT(*) FROM bank_accounts WHERE is_active = 1")->fetchColumn() ?? 0) > 0
                     || $hasOrders;
    $hasNotif      = (int) ($pdo->query("SELECT COUNT(*) FROM notification_templates WHERE is_active = 1")->fetchColumn() ?? 0) > 0
                     || !empty(getSetting('fonte_api_key')) || !empty(getSetting('starsender_api_key'))
                     || !empty(getSetting('onesender_api_key')) || !empty(getSetting('kirimdev_api_key'))
                     || $hasOrders;
    $hasProducts   = (int) ($pdo->query("SELECT COUNT(*) FROM products")->fetchColumn() ?? 0) > 0
                     || (int) ($pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn() ?? 0) > 0
                     || (int) ($pdo->query("SELECT COUNT(*) FROM membership_plans")->fetchColumn() ?? 0) > 0
                     || $hasOrders;
    $hasCanvas     = (int) ($pdo->query("SELECT COUNT(*) FROM canvas_pages")->fetchColumn() ?? 0) > 0
                     || $hasOrders;

    $onboardingSteps = [
        [
            'id'    => 'settings',
            'title' => 'Pengaturan Umum',
            'url'   => APP_URL . '/admin/settings',
            'done'  => $hasSiteName,
            'desc'  => 'Informasi toko & mata uang',
            'icon'  => '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        ],
        [
            'id'    => 'branding',
            'title' => 'Pengaturan Branding',
            'url'   => APP_URL . '/admin/branding',
            'done'  => $hasBranding,
            'desc'  => 'Logo, favicon & identitas visual',
            'icon'  => '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21a9 9 0 1 1 0-18c2.4 0 4.6.9 6.2 2.5a8.8 8.8 0 0 1 2.3 6.1c0 3-2.5 5.4-5.5 5.4H13a1 1 0 0 0-1 1v1.5a1.5 1.5 0 0 1-1.5 1.5Z"/><circle cx="7.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="12" cy="7.5" r=".5" fill="currentColor"/><circle cx="16.5" cy="10.5" r=".5" fill="currentColor"/></svg>',
        ],
        [
            'id'    => 'gateways',
            'title' => 'Payment Gateway',
            'url'   => APP_URL . '/admin/integrations',
            'done'  => $hasGateways,
            'desc'  => 'Pembayaran otomatis & manual',
            'icon'  => '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
        ],
        [
            'id'    => 'notifications',
            'title' => 'Pengaturan Notifikasi',
            'url'   => APP_URL . '/admin/notifications',
            'done'  => $hasNotif,
            'desc'  => 'Template pesan WA & Email',
            'icon'  => '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
        ],
        [
            'id'    => 'products',
            'title' => 'Buat Produk',
            'url'   => APP_URL . '/admin/products',
            'done'  => $hasProducts,
            'desc'  => 'Kelas, produk digital, membership',
            'icon'  => '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
        ],
        [
            'id'    => 'canvas',
            'title' => 'Canvas Landing Page',
            'url'   => APP_URL . '/admin/canvas',
            'done'  => $hasCanvas,
            'desc'  => 'Sales page & checkout page',
            'icon'  => '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
        ],
    ];
} catch (Exception $e) {
    error_log('Onboarding steps error: ' . $e->getMessage());
}

$completedCount = count(array_filter($onboardingSteps, fn($s) => $s['done']));
$totalSteps     = count($onboardingSteps);
$progressPct    = $totalSteps > 0 ? (int) round(($completedCount / $totalSteps) * 100) : 0;
$isAllDone      = ($completedCount > 0 && $completedCount === $totalSteps);

$operations = [];
if (staffCan('transactions')) {
    require_once __DIR__ . '/../../helpers/transaction-filter.php';
    $filter = buildTransactionFilters(['status' => 'awaiting_manual', 'period' => 'lifetime']);
    $operations[] = [
        'label' => 'Bukti transfer menunggu verifikasi',
        'url' => '/admin/transactions?status=awaiting_manual&period=lifetime',
        'query' => 'SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id WHERE ' . $filter['where'],
        'params' => $filter['params'],
    ];
}
if (staffCan('users')) {
    $operations[] = [
        'label' => 'Anggota dengan akses aktif',
        'url' => '/admin/users?access=active',
        'query' => "SELECT COUNT(DISTINCT user_id) FROM member_access
            WHERE status = 'active' AND (expires_at IS NULL OR expires_at > NOW())",
        'params' => [],
    ];
}
if (staffCan('content')) {
    $operations[] = [
        'label' => 'Kajian mendatang yang sudah terbit',
        'url' => '/admin/kajian?status=published&when=upcoming',
        'query' => "SELECT COUNT(*) FROM darmutiara_kajian
            WHERE published = 1 AND starts_at >= ? AND LOWER(title) NOT LIKE '[demo]%'",
        'params' => [date('Y-m-d H:i:s')],
    ];
}
foreach ($operations as &$operation) {
    $operation['count'] = null;
    try {
        $stmt = getDB()->prepare($operation['query']);
        $stmt->execute($operation['params']);
        $operation['count'] = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Dashboard operasional: ' . $e->getMessage());
    }
}
unset($operation);

$typeBadge = function(string $type): string {
    return match ($type) {
        'class'      => 'inline-flex px-1.5 py-0.5 rounded text-xs font-medium bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400',
        'membership' => 'inline-flex px-1.5 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
        default      => 'inline-flex px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    };
};
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <!-- Main area -->
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-4 sm:p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <?php
      // ── Kesehatan Sistem: kegagalan senyap jadi kelihatan ──
      // Dirender sebagai TOAST ringkas kanan-bawah (lihat akhir file), bukan
      // banner merah full-width di atas konten: isinya biasanya ringan (mis.
      // "1 notifikasi gagal") sehingga banner besar terasa berlebihan, dan
      // menu sidebar "Kesehatan Sistem" SUDAH punya badge merah "!" sebagai
      // penanda persisten — banner jadi peringatan dobel.
      $healthIssues = [];
      if (staffCan('notifications')) {
          try {
              $hs = systemHealthSnapshot();
              if ($hs['cron']['level'] === 'danger') {
                  $healthIssues[] = $hs['cron']['age'] < 0
                      ? 'proses otomatis (cron) belum pernah tercatat berjalan'
                      : 'proses otomatis (cron) tidak berjalan sejak ' . date('d M H:i', $hs['cron']['last']);
              }
              if ($hs['notif']['failed_24h'] > 0) {
                  $healthIssues[] = $hs['notif']['failed_24h'] . ' notifikasi gagal terkirim dalam 24 jam terakhir';
              }
          } catch (Throwable $e) {}
      }
      ?>

      <section class="dmi-hero mb-6" aria-labelledby="admin-darmutiara-heading">
        <div>
          <h2 id="admin-darmutiara-heading">Kantor Virtual Cabang</h2>
          <?php if (staffCan('content')): ?>
          <p>Kelola kegiatan, promotor, peserta tes, voucher dan transaksi cabang.</p>
          <?php endif; ?>
        </div>
        <?php if (isAdmin()): ?>
        <a class="dmi-cta" href="<?= e(APP_URL) ?>/admin/kantor-cabang">Buka Kantor Cabang <span aria-hidden="true">→</span></a>
        <?php elseif (staffCan('content')): ?>
        <a class="dmi-cta" href="<?= e(APP_URL) ?>/admin/kajian">Kelola Kajian <span aria-hidden="true">→</span></a>
        <?php endif; ?>
      </section>

      <?php if ($operations): ?>
      <section class="mb-6" aria-labelledby="admin-operations-heading">
        <h2 id="admin-operations-heading" class="mb-3 text-lg font-bold text-gray-900 dark:text-white">Operasional</h2>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <?php foreach ($operations as $operation): ?>
            <a href="<?= e(APP_URL . $operation['url']) ?>" class="rounded-xl border border-gray-200 bg-white p-4 hover:border-[var(--brand-color)] dark:border-gray-800 dark:bg-gray-900">
              <p class="text-sm font-medium text-gray-600 dark:text-gray-300"><?= e($operation['label']) ?></p>
              <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?= $operation['count'] === null ? 'Belum tersedia' : number_format($operation['count'], 0, ',', '.') ?></p>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <!-- Page header -->
      <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2 flex-wrap">
            Ringkasan
            <?php if ($selectedBundleId > 0): ?>
              <?php
                $selectedBunObj = array_filter($allBundles, fn($b) => (int)$b['id'] === $selectedBundleId);
                $selectedBunName = !empty($selectedBunObj) ? reset($selectedBunObj)['name'] : '';
              ?>
              <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-purple-50 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 border border-purple-200 dark:border-purple-800">
                [BUNDLE] <?= e($selectedBunName) ?>
              </span>
            <?php elseif ($selectedProductId > 0): ?>
              <?php
                $selectedProdObj = array_filter($allProducts, fn($p) => (int)$p['id'] === $selectedProductId);
                $selectedProdName = !empty($selectedProdObj) ? reset($selectedProdObj)['name'] : '';
              ?>
              <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                <?= e($selectedProdName) ?>
              </span>
            <?php endif; ?>
          </h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            <?= e(date('l, d F Y')) ?>
          </p>
        </div>

        <!-- Filter Per Produk / Bundle (Posisi Asli Kanan) -->
        <form method="GET" action="" class="flex items-center gap-2">
          <div class="relative min-w-[220px]">
            <select name="product_id" id="product_id_filter" onchange="this.form.submit()"
                    class="w-full appearance-none bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-gray-800 dark:text-gray-200 text-xs rounded-lg px-3 py-2 pr-8 font-medium shadow-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition-colors cursor-pointer">
              <option value="0">Semua Produk & Bundle (Global)</option>
              <?php if (!empty($allProducts)): ?>
              <optgroup label="Produk Digital & Kelas">
                <?php foreach ($allProducts as $prodItem): ?>
                <option value="<?= $prodItem['id'] ?>" <?= $rawProductFilter === (string) $prodItem['id'] ? 'selected' : '' ?>>
                  <?= e($prodItem['name']) ?> (<?= e(strtoupper($prodItem['type'])) ?>)
                </option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
              <?php if (!empty($allBundles)): ?>
              <optgroup label="Product Bundle">
                <?php foreach ($allBundles as $bundleItem): ?>
                <option value="b_<?= $bundleItem['id'] ?>" <?= $rawProductFilter === 'b_' . $bundleItem['id'] ? 'selected' : '' ?>>
                  <?= e($bundleItem['name']) ?> (BUNDLE)
                </option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
            </select>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-400">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </div>
          </div>

          <?php if ($selectedProductId > 0 || $selectedBundleId > 0): ?>
          <a href="<?= e(APP_URL) ?>/admin"
             class="px-2.5 py-2 text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 rounded-lg flex items-center gap-1 transition-colors shrink-0"
             title="Reset Filter Produk">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
            Reset
          </a>
          <?php endif; ?>
        </form>
      </div>

      <!-- ═══ PANDUAN PENYIAPAN AVERION (ONBOARDING SETUP CHECKLIST) ═══ -->
      <?php if (!empty($onboardingSteps) && !$isAllDone): ?>
      <div id="averion-onboarding-card" class="mb-6 bg-white dark:bg-gray-900 rounded-2xl border border-gray-200/80 dark:border-gray-800 p-5 shadow-sm transition-all duration-300 relative overflow-hidden">
        <!-- Accent Glow Line -->
        <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-emerald-500 via-teal-500 to-indigo-500"></div>

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-gray-100 dark:border-gray-800">
          <div>
            <div class="flex items-center gap-2 flex-wrap">
              <span class="p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                  <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
              </span>
              <h3 class="text-sm font-bold text-gray-900 dark:text-white">Panduan Penyiapan Averion</h3>
              <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                <?= $completedCount ?>/<?= $totalSteps ?> Selesai (<?= $progressPct ?>%)
              </span>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
              Yang perlu diatur di awal oleh pengguna untuk penggunaan AVERION adalah sebagai berikut:
            </p>
          </div>

          <div class="flex items-center gap-3 shrink-0">
            <!-- Progress Bar -->
            <div class="w-32 hidden md:block">
              <div class="w-full h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div class="h-full bg-emerald-500 rounded-full transition-all duration-500" style="width: <?= $progressPct ?>%"></div>
              </div>
            </div>

            <!-- Close Button -->
            <button type="button" onclick="closeOnboardingCard()" title="Tutup Panduan"
                    class="px-2.5 py-1 rounded-lg text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition inline-flex items-center gap-1">
              <span>Tutup</span>
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>
        </div>

        <!-- 6 Steps Grid -->
        <div id="onboarding-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 pt-4">
          <?php foreach ($onboardingSteps as $step): ?>
          <a href="<?= e($step['url']) ?>"
             class="group p-3 rounded-xl border transition-all duration-200 flex items-start gap-3 relative overflow-hidden <?= $step['done'] ? 'bg-emerald-50/40 dark:bg-emerald-950/20 border-emerald-200/80 dark:border-emerald-900/60 hover:border-emerald-300' : 'bg-gray-50/70 dark:bg-gray-800/30 border-gray-200 dark:border-gray-800 hover:bg-white dark:hover:bg-gray-800 hover:border-[var(--brand-color)] hover:shadow-sm' ?>">
            <div class="shrink-0 p-2 rounded-lg <?= $step['done'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300' : 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 group-hover:text-[var(--brand-color)]' ?>">
              <?= $step['icon'] ?>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between gap-1">
                <h4 class="text-xs font-bold truncate text-gray-900 dark:text-white group-hover:text-[var(--brand-color)] transition-colors">
                  <?= e($step['title']) ?>
                </h4>
                <?php if ($step['done']): ?>
                  <span class="w-4 h-4 rounded-full bg-emerald-500 text-white flex items-center justify-center shrink-0" title="Sudah Selesai">
                    <svg class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                  </span>
                <?php else: ?>
                  <svg class="w-3.5 h-3.5 text-gray-400 opacity-0 group-hover:opacity-100 transition-opacity shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                <?php endif; ?>
              </div>
              <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5 line-clamp-1">
                <?= e($step['desc']) ?>
              </p>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <script>
      function closeOnboardingCard() {
          const card = document.getElementById('averion-onboarding-card');
          if (card) {
              card.classList.add('hidden');
          if (card) {
              card.classList.remove('hidden');
          }
      }
      </script>
      <?php endif; ?>

      <!-- ═══ STATISTIK CARDS ═══ -->
      <?php
      // UI Theme: kelas kartu statistik mengikuti card-style theme aktif.
      $cardStyle = ($themeConfig['--card-style'] ?? (function_exists('getThemeConfig') ? getThemeConfig()['--card-style'] : 'flat'));
      $grad      = ($cardStyle === 'gradient');
      $cardCls   = fn(int $n) => $grad
          ? "card-base stat-card stat-card-$n p-5"
          : "card-base bg-white dark:bg-gray-900 p-5 border border-gray-200 dark:border-gray-800";
      $iconCls   = 'w-10 h-10 rounded-lg flex items-center justify-center shrink-0' . ($grad ? ' stat-icon' : '');
      ?>
      <div class="kpi-accent grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

        <!-- Total Users -->
        <div class="<?= $cardCls(1) ?>">
          <div class="flex items-start justify-between">
            <div class="min-w-0">
              <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Users</p>
              <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white" data-count="<?= (int) $totalUsers ?>"><?= number_format($totalUsers) ?></p>
            </div>
            <div class="<?= $iconCls ?> bg-indigo-50 dark:bg-indigo-900/20">
              <svg class="w-5 h-5 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
              </svg>
            </div>
          </div>
          <a href="<?= e(APP_URL) ?>/admin/users"
             class="mt-3 text-xs font-medium whitespace-nowrap flex items-center gap-1 text-indigo-500 hover:text-indigo-600 transition-colors">
            Lihat semua
            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="9 18 15 12 9 6"/>
            </svg>
          </a>
        </div>

        <!-- Total Orders -->
        <div class="<?= $cardCls(2) ?>">
          <div class="flex items-start justify-between">
            <div class="min-w-0">
              <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Orders</p>
              <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white" data-count="<?= (int) $totalOrders ?>"><?= number_format($totalOrders) ?></p>
            </div>
            <div class="<?= $iconCls ?> bg-blue-50 dark:bg-blue-900/20">
              <svg class="w-5 h-5 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="1" y="4" width="22" height="16" rx="2"/>
                <line x1="1" y1="10" x2="23" y2="10"/>
              </svg>
            </div>
          </div>
          <a href="<?= e(APP_URL) ?>/admin/transactions"
             class="mt-3 text-xs font-medium whitespace-nowrap flex items-center gap-1 text-blue-500 hover:text-blue-600 transition-colors">
            Lihat semua
            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="9 18 15 12 9 6"/>
            </svg>
          </a>
        </div>

        <!-- Total Revenue -->
        <div class="<?= $cardCls(3) ?>">
          <div class="flex items-start justify-between">
            <div class="min-w-0">
              <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Revenue</p>
              <p class="mt-2 text-xl font-bold text-gray-900 dark:text-white truncate" data-count="<?= (float) $totalRevenue ?>">
                <?= e(formatRupiah($totalRevenue)) ?>
              </p>
            </div>
            <div class="<?= $iconCls ?> bg-green-50 dark:bg-green-900/20">
              <svg class="w-5 h-5 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="1" x2="12" y2="23"/>
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
              </svg>
            </div>
          </div>
          <p class="mt-3 text-xs text-gray-400 dark:text-gray-600">Dari transaksi paid</p>
        </div>

        <!-- Active Members -->
        <div class="<?= $cardCls(4) ?>">
          <div class="flex items-start justify-between">
            <div class="min-w-0">
              <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Active Members</p>
              <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white" data-count="<?= (int) $activeMembers ?>"><?= number_format($activeMembers) ?></p>
            </div>
            <div class="<?= $iconCls ?> bg-purple-50 dark:bg-purple-900/20">
              <svg class="w-5 h-5 text-purple-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                <polyline points="9 12 11 14 15 10"/>
              </svg>
            </div>
          </div>
          <style>
            /* Titik "live" menyala hijau dengan ring glow yang mengembang — terlihat
               hidup (napas), bukan sekadar animate-pulse yang bikin redup. CSS lokal
               per-halaman (tak butuh regen Tailwind). */
            /* margin-right di sini (bukan Tailwind gap-1.5 yang belum ter-compile). */
            .live-dot{position:relative;width:8px;height:8px;margin-right:6px;border-radius:9999px;background:#22c55e;flex:none;box-shadow:0 0 6px rgba(34,197,94,.9)}
            .live-dot::after{content:"";position:absolute;inset:0;border-radius:9999px;background:#22c55e;animation:liveDotPulse 1.8s ease-out infinite}
            @keyframes liveDotPulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.55);opacity:1}70%{box-shadow:0 0 0 7px rgba(34,197,94,0);opacity:.55}100%{box-shadow:0 0 0 0 rgba(34,197,94,0);opacity:1}}
            @media (prefers-reduced-motion: reduce){.live-dot::after{animation:none}}
          </style>
          <p class="mt-3 text-xs flex items-center text-green-600 dark:text-green-400" title="Member yang sedang membuka area member (realtime, 2 menit terakhir)">
            <span class="live-dot" aria-hidden="true"></span>
            <span><span id="members-online-count" data-count="<?= (int) $membersOnline ?>"><?= number_format($membersOnline) ?></span> sedang online</span>
          </p>
        </div>

      </div>

      <!-- ═══ LIVE FUNNEL MONITORING ═══ -->
      <!-- LIVE FUNNEL MONITORING WIDGET -->
      <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-4 sm:p-5 mb-6">
        <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
          <div>
            <h3 class="font-semibold text-gray-900 dark:text-white flex items-center gap-2">
              Live Funnel Monitoring
              <span class="inline-flex items-center gap-1 text-xs font-normal text-green-600 dark:text-green-400">
                <span class="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse"></span> LIVE
              </span>
            </h3>
            <p class="text-xs text-gray-400 mt-0.5">Auto refresh setiap 30 detik</p>
          </div>

          <div class="flex items-center gap-1 bg-gray-50 dark:bg-gray-900 rounded-lg p-1 w-full sm:w-auto overflow-x-auto" id="funnel-range-filter">
            <button data-range="today"     class="funnel-range-btn shrink-0 whitespace-nowrap px-3 py-1.5 text-xs rounded-md text-gray-500 hover:bg-white dark:hover:bg-gray-800 transition-colors">Hari Ini</button>
            <button data-range="yesterday" class="funnel-range-btn shrink-0 whitespace-nowrap px-3 py-1.5 text-xs rounded-md text-gray-500 hover:bg-white dark:hover:bg-gray-800 transition-colors">Kemarin</button>
            <button data-range="week"      class="funnel-range-btn shrink-0 whitespace-nowrap px-3 py-1.5 text-xs rounded-md text-gray-500 hover:bg-white dark:hover:bg-gray-800 transition-colors">Minggu Ini</button>
            <button data-range="7days"     class="funnel-range-btn shrink-0 whitespace-nowrap px-3 py-1.5 text-xs rounded-md bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-medium shadow-sm transition-colors">7 Hari</button>
            <button data-range="30days"    class="funnel-range-btn shrink-0 whitespace-nowrap px-3 py-1.5 text-xs rounded-md text-gray-500 hover:bg-white dark:hover:bg-gray-800 transition-colors">30 Hari</button>
            <button data-range="lifetime"  class="funnel-range-btn shrink-0 whitespace-nowrap px-3 py-1.5 text-xs rounded-md text-gray-500 hover:bg-white dark:hover:bg-gray-800 transition-colors">Lifetime</button>
          </div>
        </div>

        <div class="kpi-accent grid grid-cols-2 sm:grid-cols-3 gap-3 mb-5">
          <div class="bg-blue-50/50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 rounded-lg p-3.5">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
              <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Total Lead</span>
              <div class="w-7 h-7 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
              </div>
            </div>
            <p class="text-2xl font-bold text-gray-900 dark:text-white" id="funnel-total-lead">—</p>
          </div>

          <div class="bg-green-50/50 dark:bg-green-900/10 border border-green-100 dark:border-green-900/30 rounded-lg p-3.5">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
              <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Total Sales</span>
              <div class="w-7 h-7 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
              </div>
            </div>
            <p class="text-2xl font-bold text-gray-900 dark:text-white" id="funnel-total-sales">—</p>
          </div>

          <div class="bg-indigo-50/50 dark:bg-indigo-900/10 border border-indigo-100 dark:border-indigo-900/30 rounded-lg p-3.5 col-span-2 sm:col-span-1">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
              <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Total Omzet</span>
              <div class="w-7 h-7 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                </svg>
              </div>
            </div>
            <p class="text-2xl font-bold text-gray-900 dark:text-white" id="funnel-total-omzet">—</p>
          </div>
        </div>

        <div class="relative" style="height: 180px;">
          <svg id="funnel-chart-svg" viewBox="0 0 800 180" class="w-full h-full" preserveAspectRatio="none"></svg>
          <div id="funnel-dots-overlay" class="absolute inset-0 pointer-events-none"></div>
          <div id="funnel-tooltip" class="hidden absolute bg-gray-900 text-white text-xs rounded-lg px-3 py-2 pointer-events-none shadow-lg z-10"></div>
        </div>
        <div class="flex justify-between text-xs text-gray-400 mt-2" id="funnel-chart-labels"></div>
      </div>
      <!-- /Live Funnel Monitoring -->

      <!-- ═══ CONTENT AREA ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        <!-- Transaksi Terbaru (2/3 kiri) -->
        <div class="lg:col-span-2 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm truncate">Transaksi Terbaru</h3>
            <a href="<?= e(APP_URL) ?>/admin/transactions"
               class="shrink-0 whitespace-nowrap text-xs font-medium text-[var(--brand-color)] hover:underline flex items-center gap-1">
              Lihat Semua
              <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <polyline points="9 18 15 12 9 6"/>
              </svg>
            </a>
          </div>

          <?php if (empty($recentOrders)): ?>
          <div class="py-14 text-center">
            <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
            </svg>
            <p class="text-sm text-gray-400 dark:text-gray-600">Belum ada transaksi</p>
          </div>
          <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="bg-gray-50 dark:bg-gray-800/50">
                  <th class="hidden sm:table-cell text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">No. Order</th>
                  <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">User</th>
                  <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total</th>
                  <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                  <th class="hidden md:table-cell text-right px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Tanggal</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                <?php foreach ($recentOrders as $order): ?>
                <tr class="table-row-hover transition-colors">
                  <td class="hidden sm:table-cell px-5 py-3.5">
                    <a href="<?= e(APP_URL) ?>/admin/transactions/<?= e($order['order_number']) ?>"
                       class="font-mono text-xs font-medium text-[var(--brand-color)] hover:underline">
                      <?= e($order['order_number']) ?>
                    </a>
                  </td>
                  <td class="px-4 py-3.5">
                    <span class="block text-gray-700 dark:text-gray-300 font-medium max-w-[9rem] sm:max-w-none truncate"><?= e($order['user_name']) ?></span>
                    <!-- No. Order tampil di sini saat kolomnya tersembunyi (mobile) — tetap bisa dibuka -->
                    <a href="<?= e(APP_URL) ?>/admin/transactions/<?= e($order['order_number']) ?>"
                       class="sm:hidden block font-mono text-[11px] text-[var(--brand-color)] hover:underline mt-0.5"><?= e($order['order_number']) ?></a>
                  </td>
                  <td class="px-4 py-3.5 text-right">
                    <span class="font-semibold text-gray-900 dark:text-white"><?= e(formatRupiah((float) $order['total'])) ?></span>
                  </td>
                  <td class="px-4 py-3.5 text-center">
                    <?= renderStatusBadge($order['status'], 'order') ?>
                  </td>
                  <td class="hidden md:table-cell px-5 py-3.5 text-right">
                    <span class="text-xs text-gray-400 dark:text-gray-500">
                      <?= e(date('d M Y', strtotime($order['created_at']))) ?>
                    </span>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

        <!-- Panel Kanan (1/3) -->
        <div class="space-y-5">

          <!-- Quick Actions -->
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <div class="px-4 sm:px-5 py-4 border-b border-gray-100 dark:border-gray-800">
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Quick Actions</h3>
            </div>
            <div class="kpi-accent p-3 space-y-1">

              <a href="<?= e(APP_URL) ?>/admin/products"
                 class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors group">
                <div class="w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-900/20 flex items-center justify-center shrink-0">
                  <svg class="w-4 h-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                  </svg>
                </div>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-white">Tambah Produk</span>
              </a>

              <a href="<?= e(APP_URL) ?>/admin/users"
                 class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors group">
                <div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center shrink-0">
                  <svg class="w-4 h-4 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>
                  </svg>
                </div>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-white">Tambah User</span>
              </a>

              <a href="<?= e(APP_URL) ?>/admin/content"
                 class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors group">
                <div class="w-8 h-8 rounded-lg bg-teal-50 dark:bg-teal-900/20 flex items-center justify-center shrink-0">
                  <svg class="w-4 h-4 text-teal-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
                    <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
                  </svg>
                </div>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-white">Kelola Konten</span>
              </a>

              <a href="<?= e(APP_URL) ?>/admin/transactions"
                 class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors group">
                <div class="w-8 h-8 rounded-lg bg-green-50 dark:bg-green-900/20 flex items-center justify-center shrink-0">
                  <svg class="w-4 h-4 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                  </svg>
                </div>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-white">Lihat Laporan</span>
              </a>

            </div>
          </div>

          <!-- Top Products -->
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-5 py-4 border-b border-gray-100 dark:border-gray-800">
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Produk Terlaris</h3>
              <a href="<?= e(APP_URL) ?>/admin/products"
                 class="text-xs font-medium text-[var(--brand-color)] hover:underline">Semua</a>
            </div>

            <?php if (empty($topProducts)): ?>
            <div class="py-10 text-center">
              <p class="text-sm text-gray-400 dark:text-gray-600">Belum ada data penjualan</p>
            </div>
            <?php else: ?>
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($topProducts as $i => $prod): ?>
              <div class="flex items-center gap-3 px-4 sm:px-5 py-3.5">
                <span class="text-xs font-bold text-gray-400 dark:text-gray-600 w-4 shrink-0"><?= $i + 1 ?></span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">
                    <?= e($prod['name']) ?>
                  </p>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">
                    <?= (int) $prod['sold_count'] ?> terjual
                  </p>
                </div>
                <div class="text-right shrink-0">
                  <p class="text-xs font-semibold text-gray-700 dark:text-gray-300">
                    <?= e(formatRupiah((float) $prod['revenue'])) ?>
                  </p>
                  <span class="<?= $typeBadge($prod['type']) ?> mt-1">
                    <?= e($prod['type']) ?>
                  </span>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>

        </div>
        <!-- /Panel Kanan -->

      </div>
      <!-- /Content Area -->

      <!-- ═══ FASE 2: STATISTIK LANJUTAN ═══ -->
      <div class="mt-6 space-y-5">

        <!-- Revenue Chart -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <div class="min-w-0">
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Revenue 6 Bulan Terakhir</h3>
              <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Dari transaksi berstatus paid</p>
            </div>
            <a href="<?= e(APP_URL) ?>/admin/transactions"
               class="shrink-0 whitespace-nowrap text-xs font-medium text-[var(--brand-color)] hover:underline">Lihat Transaksi →</a>
          </div>

          <?php
            $maxRev = 0;
            foreach ($monthlyRevenue as $m) { if ((float)$m['revenue'] > $maxRev) $maxRev = (float)$m['revenue']; }
            $nMonths = max(count($monthlyRevenue), 1);
            $avgRev  = $revTotal6 / $nMonths;
            $avgPct  = $maxRev > 0 ? min($avgRev / $maxRev * 100, 100) : 0;
            $lastIdx = count($monthlyRevenue) - 1;
          ?>
          <div class="p-4 sm:p-5">
            <!-- Headline metrik: total 6 bulan + tren -->
            <div class="flex items-end justify-between gap-3 mb-5">
              <div class="min-w-0">
                <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none truncate"><?= e(formatRupiah($revTotal6)) ?></p>
                <p class="text-[11px] text-gray-400 dark:text-gray-600 mt-1.5 uppercase tracking-wider">Total 6 bulan</p>
              </div>
              <?php if ($revTrendPct !== null): $up = $revTrendPct >= 0; ?>
              <span class="shrink-0 inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs font-bold <?= $up ? 'text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20' : 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20' ?>">
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $up ? 'M6 15l6-6 6 6' : 'M6 9l6 6 6-6' ?>"/></svg>
                <?= abs($revTrendPct) ?>% <span class="font-medium text-[10px] opacity-70">vs 6 bln lalu</span>
              </span>
              <?php endif; ?>
            </div>

            <!-- Bar chart -->
            <div class="relative" style="height:200px;">
              <?php if ($avgPct > 0): ?>
              <div class="absolute left-0 right-0 border-t border-dashed border-gray-300 dark:border-gray-600 opacity-60 pointer-events-none" style="bottom:<?= number_format($avgPct, 2, '.', '') ?>%">
                <span class="absolute right-0 -top-4 text-[9px] text-gray-400 dark:text-gray-500 bg-white dark:bg-gray-900 px-1">rata² <?= e(formatRupiahShort($avgRev)) ?></span>
              </div>
              <?php endif; ?>
              <div class="absolute inset-0 flex items-end justify-between gap-2">
                <?php foreach ($monthlyRevenue as $idx => $m): ?>
                <?php
                  $rev    = (float)$m['revenue'];
                  $hasVal = $rev > 0;
                  $pct    = $maxRev > 0 ? max($rev / $maxRev * 100, $hasVal ? 6 : 2) : 2;
                  $isLive = ($idx === $lastIdx);
                  $isPeak = ($hasVal && $rev >= $maxRev);
                  $ord    = (int) ($m['orders'] ?? 0);
                  $avgOrd = $ord > 0 ? $rev / $ord : 0;
                ?>
                <div class="revcol flex-1 flex flex-col items-center justify-end h-full max-w-[3rem] group cursor-default"
                     data-month="<?= e(date('M Y', strtotime($m['month'] . '-01'))) ?>"
                     data-rev="<?= e(formatRupiah($rev)) ?>"
                     data-orders="<?= $ord ?>"
                     data-avgord="<?= e(formatRupiahShort($avgOrd)) ?>"
                     data-live="<?= $isLive ? '1' : '0' ?>">
                  <?php if ($isLive): ?>
                  <span class="text-[8px] font-bold text-[var(--brand-color)] mb-0.5 px-1 rounded leading-none" style="background:color-mix(in srgb,var(--brand-color) 14%,transparent)">berjalan</span>
                  <?php endif; ?>
                  <span class="text-[10px] font-semibold mb-1 leading-none <?= $hasVal ? 'text-gray-600 dark:text-gray-300' : 'text-gray-300 dark:text-gray-700 opacity-0 group-hover:opacity-100' ?>">
                    <?= $hasVal ? e(formatRupiahShort($rev)) : '0' ?>
                  </span>
                  <div class="w-full rounded-t-md transition-all duration-500 group-hover:opacity-80"
                       style="height:<?= number_format($pct, 2, '.', '') ?>%;
                              <?php if ($isLive): ?>background:repeating-linear-gradient(135deg,color-mix(in srgb,var(--brand-color) 42%,transparent) 0 5px,color-mix(in srgb,var(--brand-color) 18%,transparent) 5px 10px);border:1px solid color-mix(in srgb,var(--brand-color) 45%,transparent);<?php elseif ($hasVal): ?>background-color:var(--brand-color);<?php else: ?>background-color:rgb(209 213 219);<?php endif; ?>
                              <?= $isPeak ? 'box-shadow:0 0 0 3px color-mix(in srgb,var(--brand-color) 22%,transparent);' : '' ?>">
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <!-- Month labels -->
            <div class="flex justify-between gap-2 mt-2 pt-2 border-t border-gray-100 dark:border-gray-800">
              <?php foreach ($monthlyRevenue as $m): ?>
              <div class="flex-1 max-w-[3rem] text-center">
                <span class="text-[11px] text-gray-400 dark:text-gray-600"><?= e(date('M', strtotime($m['month'] . '-01'))) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <!-- Tooltip revenue -->
          <div id="revTooltip" class="fixed z-50 hidden pointer-events-none rounded-lg bg-gray-900 text-white px-2.5 py-2 shadow-xl" style="min-width:120px"></div>
          <script>
          (function(){
            var tt=document.getElementById('revTooltip'); if(!tt) return;
            document.querySelectorAll('.revcol').forEach(function(c){
              c.addEventListener('mousemove',function(e){
                tt.classList.remove('hidden');
                tt.style.left=Math.min(e.clientX+14,window.innerWidth-150)+'px';
                tt.style.top=(e.clientY-8)+'px';
                var live=c.dataset.live==='1', o=parseInt(c.dataset.orders,10)||0;
                tt.innerHTML='<div class="text-xs font-bold">'+c.dataset.month+(live?' · berjalan':'')+'</div>'+
                  '<div class="text-sm font-extrabold" style="margin:2px 0">'+c.dataset.rev+'</div>'+
                  '<div class="text-gray-400 text-[10px]">'+o+' order'+(o>0?' · avg '+c.dataset.avgord:'')+'</div>';
              });
              c.addEventListener('mouseleave',function(){tt.classList.add('hidden');});
            });
          })();
          </script>
        </div>

        <!-- Stat Cards: Komisi + Kupon (compact, seluruh kartu clickable) -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

          <!-- Komisi Dibayar -->
          <a href="<?= e(APP_URL) ?>/admin/affiliate-finance?tab=commissions&status=paid"
             class="group flex items-center gap-3 bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-200 dark:border-gray-800 shadow-sm transition-all hover:shadow-md hover:border-gray-300 dark:hover:border-gray-700">
            <span class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0 bg-green-50 dark:bg-green-900/20 text-green-500">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </span>
            <div class="min-w-0 flex-1">
              <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider truncate">Komisi Dibayar</p>
              <p class="text-lg font-bold text-gray-900 dark:text-white truncate leading-tight mt-0.5"><?= e(formatRupiah($totalCommPaid)) ?></p>
            </div>
            <svg class="w-4 h-4 shrink-0 text-gray-300 dark:text-gray-600 group-hover:text-gray-400 transition-colors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
          </a>

          <!-- Komisi Pending -->
          <a href="<?= e(APP_URL) ?>/admin/affiliate-finance?tab=commissions&status=pending"
             class="group flex items-center gap-3 bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-200 dark:border-gray-800 shadow-sm transition-all hover:shadow-md hover:border-gray-300 dark:hover:border-gray-700">
            <span class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0 bg-yellow-50 dark:bg-yellow-900/20 text-yellow-500">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </span>
            <div class="min-w-0 flex-1">
              <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider truncate">Komisi Pending</p>
              <p class="text-lg font-bold text-yellow-600 dark:text-yellow-400 truncate leading-tight mt-0.5"><?= e(formatRupiah($totalCommPending)) ?></p>
            </div>
            <svg class="w-4 h-4 shrink-0 text-gray-300 dark:text-gray-600 group-hover:text-gray-400 transition-colors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
          </a>

          <!-- Kupon Terpakai -->
          <a href="<?= e(APP_URL) ?>/admin/coupons"
             class="group flex items-center gap-3 bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-200 dark:border-gray-800 shadow-sm transition-all hover:shadow-md hover:border-gray-300 dark:hover:border-gray-700">
            <span class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0 bg-orange-50 dark:bg-orange-900/20 text-orange-500">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
            </span>
            <div class="min-w-0 flex-1">
              <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider truncate">Kupon Terpakai</p>
              <p class="text-lg font-bold text-gray-900 dark:text-white truncate leading-tight mt-0.5"><?= number_format($couponUsedCount) ?></p>
              <p class="text-[11px] text-gray-400 dark:text-gray-600 truncate mt-0.5">Total diskon <?= e(formatRupiah($couponTotalDiscount)) ?></p>
            </div>
            <svg class="w-4 h-4 shrink-0 text-gray-300 dark:text-gray-600 group-hover:text-gray-400 transition-colors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
          </a>

        </div>

        <!-- Distribusi Membership Level -->
        <?php if (!empty($levelDistribution)): ?>
        <?php
          $totalLevelUsers = array_sum(array_column($levelDistribution, 'user_count'));
          $nLv = count($levelDistribution);
          // Brand monochrome ramp: index rendah = teal muda, index tinggi = teal tua.
          $brandRamp = function (int $i, int $n): string {
              $f = $n > 1 ? $i / ($n - 1) : 0.5;
              if ($f < 0.5) {
                  $p = (int) round(35 + ($f / 0.5) * 65);          // 35→100% brand (sisanya putih)
                  return 'color-mix(in srgb, var(--brand-color) ' . $p . '%, #ffffff)';
              }
              $d = (int) round((($f - 0.5) / 0.5) * 45);            // 0→45% hitam
              return 'color-mix(in srgb, var(--brand-color) ' . (100 - $d) . '%, #000000)';
          };
          // Level dominan (jumlah user terbanyak) untuk kalimat ringkas.
          $domIdx = 0; $domCount = -1;
          foreach ($levelDistribution as $di => $dl) { if ((int)$dl['user_count'] > $domCount) { $domCount = (int)$dl['user_count']; $domIdx = $di; } }
        ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <div class="min-w-0">
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Distribusi Membership Level</h3>
              <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5"><?= number_format($totalLevelUsers) ?> user dengan level aktif</p>
            </div>
            <a href="<?= e(APP_URL) ?>/admin/membership-levels"
               class="shrink-0 whitespace-nowrap text-xs font-medium text-[var(--brand-color)] hover:underline">Kelola Level →</a>
          </div>
          <div class="p-4 sm:p-5">
            <!-- Bar tersegmen 100% (distribusi dalam sekali lihat) -->
            <div class="flex h-6 rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-800 mb-3.5">
              <?php $segShown = 0; foreach ($levelDistribution as $i => $lv): ?>
                <?php $c = (int) $lv['user_count']; $wp = $totalLevelUsers > 0 ? $c / $totalLevelUsers * 100 : 0; if ($wp <= 0) continue; ?>
                <div class="h-full <?= $segShown > 0 ? 'border-l-2 border-white dark:border-gray-900' : '' ?>"
                     style="width:<?= number_format($wp, 3, '.', '') ?>%;background:<?= $brandRamp($i, $nLv) ?>"
                     title="<?= e($lv['name']) ?> · <?= number_format($c) ?> (<?= round($wp) ?>%)"></div>
                <?php $segShown++; endforeach; ?>
            </div>
            <?php if ($totalLevelUsers > 0): ?>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Dominan: <span class="font-semibold text-gray-800 dark:text-gray-200"><?= e($levelDistribution[$domIdx]['name']) ?></span> · <?= round($domCount / $totalLevelUsers * 100) ?>% dari total user</p>
            <?php endif; ?>
            <!-- Legend per level -->
            <div class="space-y-2.5">
              <?php foreach ($levelDistribution as $i => $lv): ?>
              <?php $c = (int) $lv['user_count']; $p = $totalLevelUsers > 0 ? round($c / $totalLevelUsers * 100) : 0; ?>
              <div class="flex items-center gap-3">
                <span class="w-3 h-3 rounded shrink-0" style="background:<?= $brandRamp($i, $nLv) ?>"></span>
                <span class="text-sm font-medium text-gray-800 dark:text-gray-200 flex-1 min-w-0 truncate"><?= e($lv['name']) ?></span>
                <span class="text-xs text-gray-400 dark:text-gray-600 w-9 text-right"><?= $p ?>%</span>
                <span class="text-sm font-bold text-gray-900 dark:text-white w-6 text-right"><?= number_format($c) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div>
      <!-- /Fase 2 Statistik Lanjutan -->

    </main>
  </div>
</div>
<script>
(function() {
  let currentRange = '7days';
  let refreshInterval = null;
  let funnelAbortCtrl = null;

  // Count-up halus: angka "hidup" saat dimuat/refresh (≤600ms, respek
  // prefers-reduced-motion). isRupiah → format ulang id-ID dengan prefix Rp.
  function countUp(el, target, isRupiah) {
    if (!el) return;
    const fmt = v => isRupiah ? window.fmtMoney(v) : Math.round(v).toLocaleString('id-ID');
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || target === 0) {
      el.textContent = fmt(target); return;
    }
    const dur = 600, t0 = performance.now();
    (function tick(now) {
      const p = Math.min((now - t0) / dur, 1);
      el.textContent = fmt(target * (1 - Math.pow(1 - p, 3))); // ease-out cubic
      if (p < 1) requestAnimationFrame(tick);
    })(t0);
  }
  function parseNumeric(text) {
    var c = window.APP_CURRENCY || { thousand: '.', decimal: ',' };
    var s = String(text).split(c.thousand).join('');          // buang pemisah ribuan
    if (c.decimal !== '.') s = s.split(c.decimal).join('.');  // desimal → titik
    return parseFloat(s.replace(/[^0-9.\-]/g, '')) || 0;
  }

  const funnelEls = ['funnel-total-lead', 'funnel-total-sales', 'funnel-total-omzet']
    .map(id => document.getElementById(id));

  async function loadFunnelStats(range) {
    if (funnelAbortCtrl) funnelAbortCtrl.abort();
    funnelAbortCtrl = new AbortController();
    // Skeleton ringan: redupkan angka selama fetch (bukan mengosongkan —
    // nilai lama tetap terbaca, tidak ada layout shift).
    funnelEls.forEach(el => { if (el) { el.style.transition = 'opacity .2s'; el.style.opacity = '.4'; } });
    try {
      const productId = document.getElementById('product_id_filter')?.value || '0';
      const res = await fetch(
        '<?= rtrim(e(APP_URL), '/') ?>/actions/admin/funnel-stats?range=' + range + '&product_id=' + productId,
        { signal: funnelAbortCtrl.signal }
      );
      const data = await res.json();
      if (!data.success) return;

      countUp(funnelEls[0], parseInt(data.stats.total_lead, 10) || 0, false);
      countUp(funnelEls[1], parseInt(data.stats.total_sales, 10) || 0, false);
      countUp(funnelEls[2], parseNumeric(data.stats.total_omzet_formatted), true);

      // Indikator "sedang online" (angka volatile → set langsung, tanpa count-up).
      var onlineEl = document.getElementById('members-online-count');
      if (onlineEl && data.stats.members_online != null) {
        onlineEl.textContent = (parseInt(data.stats.members_online, 10) || 0).toLocaleString('id-ID');
      }

      renderChart(data.chart);
    } catch (e) {
      if (e.name !== 'AbortError') console.error('Funnel stats fetch error:', e);
    } finally {
      funnelEls.forEach(el => { if (el) el.style.opacity = '1'; });
    }
  }

  function smoothLinePath(points) {
    if (points.length < 2) return '';
    if (points.length === 2) {
      return `M${points[0].x},${points[0].y} L${points[1].x},${points[1].y}`;
    }
    let path = `M${points[0].x},${points[0].y}`;
    for (let i = 0; i < points.length - 1; i++) {
      const p0 = points[i === 0 ? 0 : i - 1];
      const p1 = points[i];
      const p2 = points[i + 1];
      const p3 = points[i + 2 < points.length ? i + 2 : i + 1];
      const cp1x = p1.x + (p2.x - p0.x) / 6;
      const cp1y = p1.y + (p2.y - p0.y) / 6;
      const cp2x = p2.x - (p3.x - p1.x) / 6;
      const cp2y = p2.y - (p3.y - p1.y) / 6;
      path += ` C${cp1x},${cp1y} ${cp2x},${cp2y} ${p2.x},${p2.y}`;
    }
    return path;
  }

  function renderChart(chartData) {
    const svg      = document.getElementById('funnel-chart-svg');
    const labelsEl = document.getElementById('funnel-chart-labels');
    const width = 800, height = 180, padding = 10;

    if (!chartData || chartData.length === 0) {
      svg.innerHTML = '';
      labelsEl.innerHTML = '';
      return;
    }

    // Empty-state cerdas: semua titik nol → jangan biarkan area chart yang
    // mahal kosong tanpa pesan; beri ajakan aksi di tengahnya.
    const allZero = chartData.every(d => (parseInt(d.count, 10) || 0) === 0);
    const emptyMsg = allZero
      ? '<text x="' + (width / 2) + '" y="' + (height / 2 - 14) + '" text-anchor="middle" fill="#9ca3af" font-size="13" font-weight="600">Belum ada aktivitas di rentang ini</text>'
      + '<text x="' + (width / 2) + '" y="' + (height / 2 + 6) + '" text-anchor="middle" fill="#b6bcc6" font-size="11.5">Bagikan link produk atau lead magnet kamu untuk mulai mengisi funnel</text>'
      : '';

    const maxVal = Math.max(...chartData.map(d => d.count), 5);
    const stepX  = (width - padding * 2) / Math.max(chartData.length - 1, 1);

    const points = chartData.map((d, i) => {
      const x = padding + i * stepX;
      const y = height - padding - ((d.count / maxVal) * (height - padding * 2));
      return { x, y, ...d };
    });

    // Grid horizontal — 3 garis reference
    let gridLines = '';
    for (let i = 0; i <= 2; i++) {
      const gy = padding + (i * (height - padding * 2) / 2);
      gridLines += `<line x1="${padding}" y1="${gy}" x2="${width - padding}" y2="${gy}"
                     stroke="currentColor" stroke-width="1" stroke-dasharray="4,4"
                     class="text-gray-200 dark:text-gray-700" opacity="0.6"/>`;
    }

    const linePath = smoothLinePath(points);
    const areaPath = linePath + ` L${points[points.length - 1].x},${height} L${points[0].x},${height} Z`;

    let svgContent = gridLines + `
      <defs>
        <linearGradient id="funnelGradient" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="var(--brand-color, #6366f1)" stop-opacity="0.25"/>
          <stop offset="100%" stop-color="var(--brand-color, #6366f1)" stop-opacity="0"/>
        </linearGradient>
      </defs>
      <path d="${areaPath}" fill="url(#funnelGradient)" />
      <path d="${linePath}" fill="none" stroke="var(--brand-color, #6366f1)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
    ` + emptyMsg;

    svg.innerHTML = svgContent;

    // Render dots as HTML overlay — immune to SVG preserveAspectRatio="none" distortion
    const dotsOverlay = document.getElementById('funnel-dots-overlay');
    dotsOverlay.innerHTML = '';
    points.forEach((p, i) => {
      const isLast   = i === points.length - 1;
      const leftPct  = (p.x / width) * 100;
      const topPct   = (p.y / height) * 100;
      const dot      = document.createElement('div');
      dot.className  = 'absolute rounded-full funnel-point';
      dot.dataset.index = i;
      if (isLast) {
        dot.style.cssText = `width:10px;height:10px;left:${leftPct}%;top:${topPct}%;` +
          `transform:translate(-50%,-50%);background:var(--brand-color,#6366f1);` +
          `border:2px solid white;box-shadow:0 0 0 4px color-mix(in srgb,var(--brand-color,#6366f1) 20%,transparent);` +
          `pointer-events:auto;cursor:pointer;`;
      } else {
        dot.style.cssText = `width:8px;height:8px;left:${leftPct}%;top:${topPct}%;` +
          `transform:translate(-50%,-50%);background:white;` +
          `border:1.5px solid var(--brand-color,#6366f1);` +
          `pointer-events:auto;cursor:pointer;`;
      }
      dotsOverlay.appendChild(dot);
    });

    const labelStep = Math.ceil(chartData.length / 7);
    labelsEl.innerHTML = chartData
      .filter((_, i) => i % labelStep === 0 || i === chartData.length - 1)
      .map(d => `<span>${d.date}</span>`)
      .join('');

    const tooltip = document.getElementById('funnel-tooltip');
    dotsOverlay.querySelectorAll('.funnel-point').forEach(function(dot) {
      dot.addEventListener('mouseenter', function() {
        const idx = parseInt(this.dataset.index);
        const d   = chartData[idx];
        const p   = points[idx];
        tooltip.innerHTML = `<div class="font-medium">${d.date}</div><div class="text-gray-300">Jumlah Transaksi: ${d.count}</div>`;
        tooltip.classList.remove('hidden');
        tooltip.style.left      = (p.x / width * 100) + '%';
        tooltip.style.top       = `calc(${p.y / height * 100}% - 50px)`;
        tooltip.style.transform = 'translateX(-50%)';
      });
      dot.addEventListener('mouseleave', function() {
        tooltip.classList.add('hidden');
      });
    });
  }

  document.querySelectorAll('.funnel-range-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      currentRange = this.dataset.range;
      document.querySelectorAll('.funnel-range-btn').forEach(b => {
        b.classList.remove('bg-white', 'dark:bg-gray-800', 'text-gray-900', 'dark:text-white', 'font-medium', 'shadow-sm');
        b.classList.add('text-gray-500');
      });
      this.classList.add('bg-white', 'dark:bg-gray-800', 'text-gray-900', 'dark:text-white', 'font-medium', 'shadow-sm');
      this.classList.remove('text-gray-500');
      loadFunnelStats(currentRange);
    });
  });

  loadFunnelStats(currentRange);

  // Count-up KPI atas saat halaman dimuat (nilai server-rendered dianimasikan;
  // format Rupiah dikenali dari prefix teksnya).
  document.querySelectorAll('.kpi-accent .text-2xl, .kpi-accent .text-xl').forEach(function (el) {
    const raw = el.textContent.trim();
    const sym = (window.APP_CURRENCY && window.APP_CURRENCY.symbol) || 'Rp';
    const isRp = raw.indexOf(sym) === 0;
    // Utamakan nilai mentah dari data-count (hindari salah baca pemisah ribuan/desimal
    // saat teks server-rendered pakai format berbeda dari APP_CURRENCY). Fallback: parse teks.
    const val  = (el.dataset.count !== undefined && el.dataset.count !== '')
                   ? parseFloat(el.dataset.count)
                   : parseNumeric(raw);
    if (val > 0) countUp(el, val, isRp);
  });

  refreshInterval = setInterval(function() {
    loadFunnelStats(currentRange);
  }, 30000);

  document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
      clearInterval(refreshInterval);
    } else {
      loadFunnelStats(currentRange);
      refreshInterval = setInterval(function() { loadFunnelStats(currentRange); }, 30000);
    }
  });
})();
</script>

<?php if ($healthIssues):
    // Tanda-tangan isu: dipakai sebagai kunci dismiss. Isu BARU (mis. cron
    // ikut mati) mengubah tanda-tangan → toast muncul lagi walau yang lama
    // sudah ditutup. Badge "!" di sidebar tetap ada sebagai pengingat
    // persisten, jadi menutup toast tak menghilangkan jejak masalah.
    $healthSig = substr(md5(implode('|', $healthIssues)), 0, 10);
?>
<div id="health-toast" class="hidden fixed bottom-6 right-6 z-[60] w-72 max-w-xs">
  <div class="relative rounded-xl border border-amber-200 dark:border-amber-800/50 bg-white dark:bg-gray-900 shadow-lg">
    <a href="<?= e(APP_URL) ?>/admin/system-health" class="flex items-start gap-2.5 p-3 pr-8 group">
      <span class="w-7 h-7 rounded-lg bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center shrink-0">
        <svg class="w-4 h-4 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
      </span>
      <span class="min-w-0">
        <span class="block text-xs font-semibold text-gray-800 dark:text-gray-100">Kesehatan sistem perlu perhatian</span>
        <span class="block text-[11px] text-gray-500 dark:text-gray-400 leading-snug mt-0.5"><?= e(ucfirst(implode('; ', $healthIssues))) ?>.</span>
        <span class="block text-[11px] font-semibold text-amber-600 dark:text-amber-400 mt-1">Lihat detail →</span>
      </span>
    </a>
    <button type="button" id="health-toast-close" aria-label="Tutup"
            class="absolute top-2 right-2 w-6 h-6 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
</div>
<script>
(function () {
  var KEY  = 'averion_health_dismiss';
  var SIG  = <?= json_encode($healthSig) ?>;
  var el   = document.getElementById('health-toast');
  if (!el) return;
  try { if (localStorage.getItem(KEY) === SIG) return; } catch (e) {}
  el.classList.remove('hidden');
  document.getElementById('health-toast-close').addEventListener('click', function () {
    el.classList.add('hidden');
    try { localStorage.setItem(KEY, SIG); } catch (e) {}
  });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

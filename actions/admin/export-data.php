<?php
require_once __DIR__ . '/../../bootstrap.php';

$type         = $_GET['type'] ?? '';
$allowedTypes = ['users', 'transactions', 'commissions'];

// Guard: export Transaksi boleh diakses staff ber-izin (selaras halaman
// /admin/transactions); export users/commissions tetap admin-only (PII lebih luas).
if ($type === 'transactions') {
    requireAdminOrStaff();
    if (!staffCan('transactions')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }
} else {
    requireAdmin();
}

if (!in_array($type, $allowedTypes, true)) {
    flash('error', 'Tipe export tidak valid.', 'error');
    redirect('/admin');
}

try {
    $pdo = getDB();

    switch ($type) {
        case 'users':
            $filename = 'users_export_' . date('Ymd_His') . '.csv';
            $stmt     = $pdo->query(
                "SELECT u.id, u.name, u.email, COALESCE(u.phone,'') AS phone,
                        u.role, u.status, COALESCE(u.affiliate_code,'') AS affiliate_code,
                        u.created_at,
                        COALESCE(SUM(CASE WHEN o.status='paid' THEN o.total ELSE 0 END), 0) AS total_spent,
                        COALESCE((SELECT ml.name FROM membership_levels ml WHERE ml.id = u.membership_level_id LIMIT 1),'') AS level
                 FROM users u
                 LEFT JOIN orders o ON o.user_id = u.id
                 GROUP BY u.id
                 ORDER BY u.created_at DESC"
            );
            $headers = ['ID', 'Nama', 'Email', 'Telepon', 'Role', 'Status', 'Kode Affiliate', 'Terdaftar', 'Total Belanja', 'Level'];
            break;

        case 'transactions':
            // Filter-aware: ikut pencarian/produk/status/periode yang aktif di halaman
            // (helper yang sama dgn /admin/transactions) → export = yang sedang dilihat.
            require_once __DIR__ . '/../../helpers/transaction-filter.php';
            $tf       = buildTransactionFilters($_GET);
            $whereSQL = $tf['where'] !== '' ? 'WHERE ' . $tf['where'] : '';
            $filename = 'transactions_export_' . date('Ymd_His') . '.csv';
            $stmt     = $pdo->prepare(
                "SELECT o.order_number, u.name AS user_name,
                        COALESCE(u.phone,'') AS phone, u.email,
                        COALESCE((SELECT pb.name FROM order_items oib JOIN product_bundles pb ON pb.id = oib.bundle_id
                                  WHERE oib.order_id = o.id AND oib.is_bundle = 1 AND oib.bundle_id IS NOT NULL
                                  ORDER BY oib.id LIMIT 1), '') AS bundle_name,
                        COALESCE((SELECT GROUP_CONCAT(oi.product_name ORDER BY oi.id SEPARATOR ' | ')
                                  FROM order_items oi WHERE oi.order_id = o.id), '') AS product_names,
                        o.total, o.status,
                        COALESCE((SELECT p.method FROM payments p WHERE p.order_id = o.id ORDER BY p.id DESC LIMIT 1), '') AS pay_method,
                        COALESCE(o.referral_code,'') AS referral_code,
                        COALESCE(o.coupon_code,'') AS coupon_code,
                        o.created_at,
                        COALESCE(o.paid_at,'') AS paid_at
                 FROM orders o
                 JOIN users u ON o.user_id = u.id
                 $whereSQL
                 ORDER BY o.created_at DESC"
            );
            $stmt->execute($tf['params']);
            $headers = ['No. Order', 'Nama Pembeli', 'WhatsApp', 'Email', 'Bundle', 'Produk', 'Total', 'Status', 'Metode Bayar', 'Kode Referral', 'Kode Kupon', 'Tanggal Order', 'Tanggal Bayar'];
            break;

        case 'commissions':
            $filename = 'commissions_export_' . date('Ymd_His') . '.csv';
            $stmt     = $pdo->query(
                "SELECT u.name AS affiliate_name, u.email, o.order_number,
                        c.amount, c.rate, c.status, c.created_at,
                        COALESCE(c.approved_at,'') AS approved_at,
                        COALESCE(c.paid_at,'') AS paid_at
                 FROM commissions c
                 JOIN affiliates a ON c.affiliate_id = a.id
                 JOIN users u ON a.user_id = u.id
                 JOIN orders o ON c.order_id = o.id
                 ORDER BY c.created_at DESC"
            );
            $headers = ['Nama Affiliate', 'Email', 'No. Order', 'Nominal Komisi', 'Rate (%)', 'Status', 'Tanggal', 'Disetujui', 'Dibayar'];
            break;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');

    // BOM agar Excel baca UTF-8 dengan benar
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, $headers);

    // Netralisasi CSV formula injection: sel string yang diawali = + - @ (atau tab/CR)
    // di-prefix apostrof agar Excel/Sheets tak mengeksekusinya sebagai formula.
    $csvSafe = static function ($v): string {
        $s = (string) $v;
        return ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) ? "'" . $s : $s;
    };

    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        fputcsv($output, array_map($csvSafe, $row));
    }

    fclose($output);
    exit;

} catch (Exception $e) {
    error_log('Export data error: ' . $e->getMessage());
    flash('error', 'Gagal mengekspor data: ' . $e->getMessage(), 'error');
    redirect('/admin/' . $type);
}

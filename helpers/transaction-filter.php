<?php
/**
 * Filter bersama untuk /admin/transactions & Export CSV — agar keduanya
 * memfilter data yang PERSIS SAMA (pencarian, produk/bundle, status, periode).
 * Mengasumsikan alias tabel: `orders o` JOIN `users u`.
 *
 * Return:
 *   where          : string klausa gabungan (tanpa kata WHERE), '' bila kosong
 *   params         : parameter untuk where
 *   where_revenue  : klausa utk kartu Revenue (periode+produk+cari, status DIPAKSA 'paid')
 *   params_revenue : parameter untuk where_revenue
 *   period, from, to, statusFilter, itemFilter, search : nilai ternormalisasi (utk UI)
 */
function buildTransactionFilters(array $g): array
{
    $statuses = ['pending', 'paid', 'failed', 'cancelled', 'expired'];

    // ── Pencarian (no order / nama / email / WA / produk / bundle) ──
    $searchClause = ''; $searchParams = [];
    $search = trim((string) ($g['q'] ?? ''));
    if ($search !== '') {
        $searchClause = '(o.order_number LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?'
            . ' OR EXISTS (SELECT 1 FROM order_items oix WHERE oix.order_id = o.id AND oix.product_name LIKE ?)'
            . ' OR EXISTS (SELECT 1 FROM order_items oib JOIN product_bundles pb ON pb.id = oib.bundle_id WHERE oib.order_id = o.id AND pb.name LIKE ?))';
        $like = '%' . $search . '%';
        $searchParams = [$like, $like, $like, $like, $like, $like];
    }

    // ── Filter Produk / Bundle ──
    $itemClause = ''; $itemParams = [];
    $itemFilter = trim((string) ($g['item'] ?? ''));
    if ($itemFilter !== '') {
        if (str_starts_with($itemFilter, 'b_')) {
            $bundleId = (int) substr($itemFilter, 2);
            if ($bundleId > 0) {
                $itemClause = 'EXISTS (SELECT 1 FROM order_items oib WHERE oib.order_id = o.id AND oib.bundle_id = ?)';
                $itemParams = [$bundleId];
            } else { $itemFilter = ''; }
        } else {
            $productId = (int) preg_replace('/[^0-9]/', '', $itemFilter);
            if ($productId > 0) {
                $itemClause = 'EXISTS (SELECT 1 FROM order_items oip WHERE oip.order_id = o.id AND oip.product_id = ?)';
                $itemParams = [$productId];
            } else { $itemFilter = ''; }
        }
    }

    // ── Filter Status ──
    $statusClause = ''; $statusParams = [];
    $statusFilter = (string) ($g['status'] ?? '');
    if ($statusFilter === 'awaiting_manual') {
        $statusClause = "o.status = 'pending' AND EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id AND p.method = 'manual_transfer' AND p.proof_file IS NOT NULL)";
    } elseif ($statusFilter !== '' && in_array($statusFilter, $statuses, true)) {
        $statusClause = 'o.status = ?';
        $statusParams = [$statusFilter];
    } else {
        $statusFilter = '';
    }

    // ── Periode (default: 7 hari terakhir). custom pakai from/to (YYYY-MM-DD). ──
    $dateClause = ''; $dateParams = [];
    $period = (string) ($g['period'] ?? '7');
    $from   = trim((string) ($g['from'] ?? ''));
    $to     = trim((string) ($g['to'] ?? ''));
    $isDate = fn(string $s): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1;

    if ($period === 'custom' && ($isDate($from) || $isDate($to))) {
        $parts = [];
        if ($isDate($from)) { $parts[] = 'DATE(o.created_at) >= ?'; $dateParams[] = $from; }
        if ($isDate($to))   { $parts[] = 'DATE(o.created_at) <= ?'; $dateParams[] = $to; }
        $dateClause = implode(' AND ', $parts);
    } else {
        switch ($period) {
            case 'yesterday': $dateClause = 'DATE(o.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)'; break;
            case '7':         $dateClause = 'DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)'; break;
            case '30':        $dateClause = 'DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)'; break;
            case 'lifetime':  $dateClause = ''; break;
            case 'today':     $dateClause = 'DATE(o.created_at) = CURDATE()'; break;
            default:          $period = '7'; $dateClause = 'DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)';
        }
        $from = ''; $to = '';
    }

    // Susun WHERE gabungan (list) & WHERE khusus revenue (tanpa status dropdown, paksa paid).
    $glue = function (array $clauses): string {
        return implode(' AND ', array_filter($clauses, fn($c) => $c !== ''));
    };
    $where = $glue([$searchClause, $itemClause, $statusClause, $dateClause]);
    $params = array_merge($searchParams, $itemParams, $statusParams, $dateParams);

    $whereRevenue = $glue([$searchClause, $itemClause, $dateClause, "o.status = 'paid'"]);
    $paramsRevenue = array_merge($searchParams, $itemParams, $dateParams);

    // WHERE tanpa filter status (cari+produk+periode) — utk ringkasan jumlah per status
    // pada periode & filter aktif (chip ringkasan di halaman transaksi).
    $whereNoStatus  = $glue([$searchClause, $itemClause, $dateClause]);
    $paramsNoStatus = array_merge($searchParams, $itemParams, $dateParams);

    return [
        'where'           => $where,
        'params'          => $params,
        'where_revenue'   => $whereRevenue,
        'params_revenue'  => $paramsRevenue,
        'where_nostatus'  => $whereNoStatus,
        'params_nostatus' => $paramsNoStatus,
        'search'         => $search,
        'itemFilter'     => $itemFilter,
        'statusFilter'   => $statusFilter,
        'period'         => $period,
        'from'           => $from,
        'to'             => $to,
    ];
}

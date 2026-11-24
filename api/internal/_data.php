<?php
/**
 * Logic query bersama untuk Integrasi Claude (MCP) — dipakai OLEH KEDUA:
 *   1. endpoint REST api/internal/*.php (Fase 1)
 *   2. handler protokol MCP api/mcp/server.php (Fase 2, tools/call)
 * Satu sumber kebenaran → tak ada duplikasi SQL (konstitusi §19). Tiap fungsi
 * MURNI: terima PDO + parameter tervalidasi, kembalikan array; TIDAK meng-echo,
 * TIDAK exit, TIDAK auth (caller yang urus auth & respons).
 *
 * Aturan omzet [[sum-distinct-omzet-bug]]: SUM(total) langsung dari orders (tanpa
 * JOIN order_items → tak fan-out); SUM(oi.price*oi.quantity) per item (aman tanpa
 * dedup). NOL SUM(DISTINCT).
 */
require_once __DIR__ . '/_auth.php'; // helper: mcpDateCondition, mcpClampLimit, mcpMask*

/** KPI ringkas periode. */
function mcpDataDashboard(PDO $pdo, string $periodRaw): array
{
    $dc = mcpDateCondition($periodRaw, 'created_at', '7d');

    $revenue = (float) $pdo->query(
        "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status = 'paid' AND {$dc['sql']}"
    )->fetchColumn();
    $ordersPaid = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders WHERE status = 'paid' AND {$dc['sql']}"
    )->fetchColumn();
    $ordersPending = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders WHERE status = 'pending' AND {$dc['sql']}"
    )->fetchColumn();
    $newUsers = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role = 'user' AND {$dc['sql']}"
    )->fetchColumn();
    $activeMembers = (int) $pdo->query(
        "SELECT COUNT(*) FROM member_access WHERE status = 'active' AND (expires_at IS NULL OR expires_at > NOW())"
    )->fetchColumn();

    return [
        'period'         => $dc['period'],
        'revenue'        => $revenue,
        'orders_paid'    => $ordersPaid,
        'orders_pending' => $ordersPending,
        'new_users'      => $newUsers,
        'active_members' => $activeMembers,
    ];
}

/** Deret omzet + perbandingan periode sebelumnya. */
function mcpDataRevenue(PDO $pdo, string $rangeRaw, string $groupRaw): array
{
    $range = in_array($rangeRaw, ['30d', '90d', '365d'], true) ? $rangeRaw : '30d';
    $days  = ['30d' => 30, '90d' => 90, '365d' => 365][$range];
    $groupBy = $groupRaw === 'month' ? 'month' : 'day';

    $total = (float) $pdo->query(
        "SELECT COALESCE(SUM(total), 0) FROM orders
         WHERE status = 'paid' AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)"
    )->fetchColumn();
    $prevTotal = (float) $pdo->query(
        "SELECT COALESCE(SUM(total), 0) FROM orders
         WHERE status = 'paid'
           AND created_at >= DATE_SUB(CURDATE(), INTERVAL " . ($days * 2) . " DAY)
           AND created_at <  DATE_SUB(CURDATE(), INTERVAL {$days} DAY)"
    )->fetchColumn();
    $deltaPct = $prevTotal > 0 ? round((($total - $prevTotal) / $prevTotal) * 100, 1) : null;

    $fmt  = $groupBy === 'month' ? '%Y-%m' : '%Y-%m-%d';
    $rows = $pdo->query(
        "SELECT DATE_FORMAT(created_at, '{$fmt}') AS bucket, COALESCE(SUM(total), 0) AS revenue
         FROM orders
         WHERE status = 'paid' AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
         GROUP BY bucket ORDER BY bucket ASC"
    )->fetchAll();
    $series = [];
    foreach ($rows as $r) {
        $series[] = ['date' => (string) $r['bucket'], 'revenue' => (float) $r['revenue']];
    }

    return [
        'range'      => $range,
        'group_by'   => $groupBy,
        'total'      => $total,
        'prev_total' => $prevTotal,
        'delta_pct'  => $deltaPct,
        'series'     => $series,
    ];
}

/** Produk terlaris + omzet per produk. */
function mcpDataProductSales(PDO $pdo, string $rangeRaw, $limitRaw): array
{
    $dc    = mcpDateCondition($rangeRaw, 'o.created_at', '30d');
    $limit = mcpClampLimit($limitRaw, 10, 50);

    // sold = jumlah baris item (dipertahankan utk kompatibilitas pemakai lama);
    // units = jumlah unit sebenarnya (SUM quantity); buyers = pembeli unik;
    // orders_count = jumlah order memuat produk. Omzet DEFINISI SAMA (nol query paralel).
    $stmt = $pdo->prepare(
        "SELECT p.name,
                COUNT(oi.id)                           AS sold,
                COALESCE(SUM(oi.quantity), 0)          AS units,
                COUNT(DISTINCT o.user_id)              AS buyers,
                COUNT(DISTINCT oi.order_id)            AS orders_count,
                COALESCE(SUM(oi.price * oi.quantity), 0) AS revenue
         FROM order_items oi
         JOIN products p ON p.id = oi.product_id
         JOIN orders   o ON o.id = oi.order_id
         WHERE o.status = 'paid' AND {$dc['sql']}
         GROUP BY p.id, p.name
         ORDER BY revenue DESC
         LIMIT {$limit}"
    );
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'product'      => (string) $r['name'],
            'sold'         => (int) $r['sold'],
            'units'        => (int) $r['units'],
            'buyers'       => (int) $r['buyers'],
            'orders_count' => (int) $r['orders_count'],
            'revenue'      => (float) $r['revenue'],
        ];
    }
    return ['range' => $dc['period'], 'items' => $items];
}

/**
 * Ringkasan email sequence — agregat per sequence (TANPA PII). Status sequence,
 * trigger, jumlah step, enrollment per status (aktif/selesai/berhenti), email
 * terkirim/gagal. Sumber: email_sequences + email_sequence_steps/enrollments/sends.
 */
function mcpDataEmailSequenceSummary(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT s.id, s.name, s.trigger_event, s.status, s.created_at,
                (SELECT COUNT(*) FROM email_sequence_steps st WHERE st.sequence_id = s.id) AS steps,
                (SELECT COUNT(*) FROM email_sequence_enrollments e WHERE e.sequence_id = s.id) AS enrolled,
                (SELECT COUNT(*) FROM email_sequence_enrollments e WHERE e.sequence_id = s.id AND e.status = 'active') AS enrolled_active,
                (SELECT COUNT(*) FROM email_sequence_enrollments e WHERE e.sequence_id = s.id AND e.status = 'completed') AS enrolled_completed,
                (SELECT COUNT(*) FROM email_sequence_enrollments e WHERE e.sequence_id = s.id AND e.status = 'unsubscribed') AS enrolled_unsubscribed,
                (SELECT COUNT(*) FROM email_sequence_sends snd
                   JOIN email_sequence_enrollments e2 ON e2.id = snd.enrollment_id
                  WHERE e2.sequence_id = s.id AND snd.status = 'sent') AS emails_sent,
                (SELECT COUNT(*) FROM email_sequence_sends snd
                   JOIN email_sequence_enrollments e2 ON e2.id = snd.enrollment_id
                  WHERE e2.sequence_id = s.id AND snd.status = 'failed') AS emails_failed
         FROM email_sequences s
         ORDER BY s.status ASC, s.updated_at DESC, s.id DESC"
    )->fetchAll();

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id'                    => (int) $r['id'],
            'name'                  => (string) $r['name'],
            'trigger'               => (string) $r['trigger_event'],
            'status'                => (string) $r['status'],
            'steps'                 => (int) $r['steps'],
            'enrolled'              => (int) $r['enrolled'],
            'enrolled_active'       => (int) $r['enrolled_active'],
            'enrolled_completed'    => (int) $r['enrolled_completed'],
            'enrolled_unsubscribed' => (int) $r['enrolled_unsubscribed'],
            'emails_sent'           => (int) $r['emails_sent'],
            'emails_failed'         => (int) $r['emails_failed'],
        ];
    }
    return ['sequences' => $items];
}

/**
 * Pembelian lanjutan antarproduk (cross-sell): untuk pembeli produk acuan, produk
 * LAIN apa yang paling sering juga mereka beli (order lunas). Basis rekomendasi.
 * Agregat per produk (jumlah pembeli bersama), TANPA PII. Atribusi ke produk UTAMA
 * (is_bump=0), definisi "lunas" sama dgn metrik lain (orders.status='paid').
 * Melempar InvalidArgumentException bila produk acuan tak ditemukan.
 */
function mcpDataCrossSell(PDO $pdo, string $productRef, string $periodRaw, $limitRaw): array
{
    $productRef = trim($productRef);
    if ($productRef === '') {
        throw new InvalidArgumentException('Sebutkan produk acuan (nama atau ID).');
    }
    if (ctype_digit($productRef)) {
        $p = $pdo->prepare("SELECT id, name FROM products WHERE id = ? LIMIT 1");
        $p->execute([(int) $productRef]);
    } else {
        $p = $pdo->prepare("SELECT id, name FROM products WHERE name LIKE ? ORDER BY (name = ?) DESC, sort_order ASC LIMIT 1");
        $p->execute(['%' . $productRef . '%', $productRef]);
    }
    $product = $p->fetch();
    if (!$product) {
        throw new InvalidArgumentException("Produk \"{$productRef}\" tidak ditemukan.");
    }
    $pid   = (int) $product['id'];
    $dc    = mcpDateCondition($periodRaw, 'o.created_at', '90d');
    $limit = mcpClampLimit($limitRaw, 10, 50);

    // Pembeli (user) yang pernah beli produk acuan (lunas, item utama).
    $base = $pdo->prepare(
        "SELECT DISTINCT o.user_id
         FROM orders o JOIN order_items oi ON oi.order_id = o.id
         WHERE oi.product_id = ? AND oi.is_bump = 0 AND o.status = 'paid'"
    );
    $base->execute([$pid]);
    $buyerIds = array_map('intval', $base->fetchAll(PDO::FETCH_COLUMN));
    $baseCount = count($buyerIds);
    if ($baseCount === 0) {
        return ['product' => (string) $product['name'], 'product_id' => $pid, 'period' => $dc['period'], 'base_buyers' => 0, 'items' => []];
    }

    // Produk LAIN yang dibeli pembeli-pembeli itu (lunas, periode), diurut jumlah pembeli bersama.
    $ph = implode(',', array_fill(0, $baseCount, '?'));
    $sql =
        "SELECT oi.product_id, p.name,
                COUNT(DISTINCT o.user_id) AS buyers,
                COUNT(DISTINCT o.id)      AS orders_count
         FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         JOIN products p     ON p.id = oi.product_id
         WHERE o.user_id IN ($ph)
           AND oi.product_id <> ?
           AND oi.is_bump = 0
           AND o.status = 'paid'
           AND {$dc['sql']}
         GROUP BY oi.product_id, p.name
         ORDER BY buyers DESC, orders_count DESC
         LIMIT {$limit}";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($buyerIds, [$pid]));

    $items = [];
    foreach ($st->fetchAll() as $r) {
        $buyers = (int) $r['buyers'];
        $items[] = [
            'product'          => (string) $r['name'],
            'product_id'       => (int) $r['product_id'],
            'buyers'           => $buyers,
            'orders_count'     => (int) $r['orders_count'],
            'attach_rate_pct'  => $baseCount > 0 ? round($buyers / $baseCount * 100, 2) : null,
        ];
    }
    return [
        'product'     => (string) $product['name'],
        'product_id'  => $pid,
        'period'      => $dc['period'],
        'base_buyers' => $baseCount,
        'items'       => $items,
    ];
}

/** Order menunggu bayar, tertua dulu. */
function mcpDataPending(PDO $pdo, $limitRaw, $olderThanRaw): array
{
    $limit     = mcpClampLimit($limitRaw, 20, 100);
    $olderCond = '';
    if ($olderThanRaw !== null && (int) $olderThanRaw > 0) {
        $h = min(24 * 30, (int) $olderThanRaw);
        $olderCond = " AND o.created_at <= DATE_SUB(NOW(), INTERVAL {$h} HOUR)";
    }

    $stmt = $pdo->prepare(
        "SELECT o.order_number, o.total, o.payment_gateway, o.created_at,
                u.name AS buyer_name,
                (SELECT oi.product_name FROM order_items oi
                  WHERE oi.order_id = o.id AND oi.is_bump = 0
                  ORDER BY oi.id ASC LIMIT 1) AS product_name
         FROM orders o
         JOIN users u ON u.id = o.user_id
         WHERE o.status = 'pending'{$olderCond}
         ORDER BY o.created_at ASC
         LIMIT {$limit}"
    );
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'order'     => (string) $r['order_number'],
            'product'   => (string) ($r['product_name'] ?? '-'),
            'amount'    => (float) $r['total'],
            'gateway'   => (string) ($r['payment_gateway'] ?? '-'),
            'age_hours' => (int) round((time() - strtotime((string) $r['created_at'])) / 3600),
            'buyer'     => (string) $r['buyer_name'],
        ];
    }
    return ['items' => $items];
}

/** Leaderboard affiliate: komisi per status. */
function mcpDataLeaderboard(PDO $pdo, string $rangeRaw, $limitRaw): array
{
    $dc    = mcpDateCondition($rangeRaw, 'c.created_at', '30d');
    $limit = mcpClampLimit($limitRaw, 10, 50);

    $stmt = $pdo->prepare(
        "SELECT u.name, a.code, COUNT(c.id) AS conversions,
                COALESCE(SUM(CASE WHEN c.status = 'approved' THEN c.amount ELSE 0 END), 0) AS commission_approved,
                COALESCE(SUM(CASE WHEN c.status = 'pending'  THEN c.amount ELSE 0 END), 0) AS commission_pending
         FROM commissions c
         JOIN affiliates a ON a.id = c.affiliate_id
         JOIN users u      ON u.id = a.user_id
         WHERE c.status <> 'rejected' AND {$dc['sql']}
         GROUP BY a.id, u.name, a.code
         ORDER BY commission_approved DESC
         LIMIT {$limit}"
    );
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'name'                => (string) $r['name'],
            'code'                => (string) $r['code'],
            'conversions'         => (int) $r['conversions'],
            'commission_approved' => (float) $r['commission_approved'],
            'commission_pending'  => (float) $r['commission_pending'],
        ];
    }
    return ['range' => $dc['period'], 'items' => $items];
}

/**
 * Cari member + ringkas belanja. Melempar InvalidArgumentException bila q<2 char
 * (caller memetakan ke 400 / JSON-RPC invalid params). Email/WA di-mask sesuai
 * setting mcp_mask_pii.
 */
function mcpDataMembers(PDO $pdo, string $q, $limitRaw): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) {
        throw new InvalidArgumentException('Parameter q minimal 2 karakter.');
    }
    $limit = mcpClampLimit($limitRaw, 10, 50);
    $like  = '%' . $q . '%';

    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, u.email, u.phone,
                COALESCE((SELECT COUNT(*) FROM orders WHERE user_id = u.id AND status = 'paid'), 0) AS orders_paid,
                COALESCE((SELECT SUM(total) FROM orders WHERE user_id = u.id AND status = 'paid'), 0) AS total_spent,
                COALESCE((SELECT COUNT(*) FROM member_access WHERE user_id = u.id AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())), 0) AS active_access,
                COALESCE(ml.name, ml_calc.name, '') AS level_name
         FROM users u
         LEFT JOIN membership_levels ml ON ml.id = u.membership_level_id
         LEFT JOIN membership_levels ml_calc ON ml_calc.id = (
             SELECT id FROM membership_levels
             WHERE min_spend <= COALESCE((SELECT SUM(total) FROM orders WHERE user_id = u.id AND status = 'paid'), 0)
             ORDER BY min_spend DESC LIMIT 1
         )
         WHERE u.role = 'user' AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)
         ORDER BY total_spent DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$like, $like, $like]);

    $items = [];
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'id'            => (int) $r['id'],
            'name'          => (string) $r['name'],
            'email'         => mcpMaskEmail((string) ($r['email'] ?? '')),
            'whatsapp'      => mcpMaskPhone((string) ($r['phone'] ?? '')),
            'total_spent'   => (float) $r['total_spent'],
            'orders_paid'   => (int) $r['orders_paid'],
            'level'         => (string) ($r['level_name'] ?: '-'),
            'active_access' => (int) $r['active_access'],
        ];
    }
    return ['query' => $q, 'masked' => mcpMaskEnabled(), 'items' => $items];
}

/**
 * Daftar tiket support — default status 'open'. Field needs_reply = balasan
 * terakhir dari user & admin belum melihat (butuh dibalas). Untuk Claude memilih
 * tiket mana yang perlu dijawab, lalu tarik detailnya via mcpDataTicketDetail.
 */
function mcpDataTickets(PDO $pdo, string $statusRaw, $limitRaw): array
{
    $status = in_array($statusRaw, ['open', 'answered', 'closed', 'all'], true) ? $statusRaw : 'open';
    $limit  = mcpClampLimit($limitRaw, 20, 100);

    $where  = $status === 'all' ? '1=1' : 't.status = ?';
    $params = $status === 'all' ? [] : [$status];

    $stmt = $pdo->prepare(
        "SELECT t.ticket_number, t.subject, t.status, t.last_reply_at, t.last_reply_by, t.created_at,
                u.name AS user_name,
                (t.last_reply_by = 'user' AND (t.admin_seen_at IS NULL OR t.admin_seen_at < t.last_reply_at)) AS needs_reply
         FROM support_tickets t
         JOIN users u ON u.id = t.user_id
         WHERE {$where}
         ORDER BY t.last_reply_at DESC, t.created_at DESC
         LIMIT {$limit}"
    );
    $stmt->execute($params);

    $items = [];
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'ticket'        => (string) $r['ticket_number'],
            'subject'       => (string) $r['subject'],
            'user'          => (string) $r['user_name'],
            'status'        => (string) $r['status'],
            'needs_reply'   => (bool) $r['needs_reply'],
            'last_reply_by' => (string) ($r['last_reply_by'] ?? '-'),
            'last_reply_at' => (string) ($r['last_reply_at'] ?? $r['created_at']),
        ];
    }
    return ['status' => $status, 'items' => $items];
}

/**
 * Detail 1 tiket + seluruh utas percakapan (pesan awal & balasan ada di
 * support_ticket_replies; tabel tiket tak punya kolom body). from='user'/'admin'.
 * Melempar InvalidArgumentException bila nomor kosong / tak ditemukan.
 */
function mcpDataTicketDetail(PDO $pdo, string $ticketNumber): array
{
    $ticketNumber = trim($ticketNumber);
    if ($ticketNumber === '') {
        throw new InvalidArgumentException('Nomor tiket wajib diisi.');
    }
    $t = $pdo->prepare(
        "SELECT t.id, t.ticket_number, t.subject, t.status, t.created_at, u.name AS user_name
         FROM support_tickets t
         JOIN users u ON u.id = t.user_id
         WHERE t.ticket_number = ? LIMIT 1"
    );
    $t->execute([$ticketNumber]);
    $ticket = $t->fetch();
    if (!$ticket) {
        throw new InvalidArgumentException("Tiket {$ticketNumber} tidak ditemukan.");
    }

    $r = $pdo->prepare(
        "SELECT sender_type, message, created_at
         FROM support_ticket_replies WHERE ticket_id = ? ORDER BY id ASC"
    );
    $r->execute([(int) $ticket['id']]);
    $messages = [];
    foreach ($r->fetchAll() as $m) {
        $messages[] = [
            'from'    => (string) $m['sender_type'], // user | admin
            'message' => (string) $m['message'],
            'at'      => (string) $m['created_at'],
        ];
    }

    return [
        'ticket'     => (string) $ticket['ticket_number'],
        'subject'    => (string) $ticket['subject'],
        'user'       => (string) $ticket['user_name'],
        'status'     => (string) $ticket['status'],
        'created_at' => (string) $ticket['created_at'],
        'messages'   => $messages,
    ];
}

/**
 * Funnel 1 produk: visitor (lp_view) → klik checkout → bayar + conversion rate tiap
 * tahap. Sumber sama halaman Funnel Analytics; dedup omzet per order.id (BUKAN
 * SUM(DISTINCT) — [[sum-distinct-omzet-bug]]). productRef = ID atau nama (LIKE).
 * lp_view sudah ter-dedup per sesi saat insert. Melempar InvalidArgumentException
 * bila produk tak ditemukan.
 */
function mcpDataFunnel(PDO $pdo, string $productRef, string $periodRaw): array
{
    $productRef = trim($productRef);
    if ($productRef === '') {
        throw new InvalidArgumentException('Sebutkan produk (nama atau ID).');
    }
    if (ctype_digit($productRef)) {
        $p = $pdo->prepare("SELECT id, name FROM products WHERE id = ? LIMIT 1");
        $p->execute([(int) $productRef]);
    } else {
        // Prioritas kecocokan persis, lalu urutan tampil.
        $p = $pdo->prepare("SELECT id, name FROM products WHERE name LIKE ? ORDER BY (name = ?) DESC, sort_order ASC LIMIT 1");
        $p->execute(['%' . $productRef . '%', $productRef]);
    }
    $product = $p->fetch();
    if (!$product) {
        throw new InvalidArgumentException("Produk \"{$productRef}\" tidak ditemukan.");
    }
    $pid = (int) $product['id'];

    $dcF = mcpDateCondition($periodRaw, 'created_at', '30d');    // funnel_events
    $dcO = mcpDateCondition($dcF['period'], 'o.created_at', $dcF['period']); // orders

    $st = $pdo->prepare("SELECT COUNT(*) FROM funnel_events WHERE product_id = ? AND event_type = 'lp_view' AND {$dcF['sql']}");
    $st->execute([$pid]);
    $views = (int) $st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM funnel_events WHERE product_id = ? AND event_type = 'checkout_click' AND {$dcF['sql']}");
    $st->execute([$pid]);
    $clicks = (int) $st->fetchColumn();

    $st = $pdo->prepare(
        "SELECT COUNT(*), COALESCE(SUM(total), 0) FROM (
            SELECT DISTINCT o.id, o.total
            FROM orders o JOIN order_items oi ON oi.order_id = o.id
            WHERE oi.product_id = ? AND oi.is_bump = 0 AND o.status = 'paid' AND {$dcO['sql']}
         ) t"
    );
    $st->execute([$pid]);
    [$paid, $revenue] = array_values($st->fetch(PDO::FETCH_NUM));
    $paid = (int) $paid; $revenue = (float) $revenue;

    $rate = fn(int $n, int $d): ?float => $d > 0 ? round($n / $d * 100, 2) : null;

    return [
        'product'                 => (string) $product['name'],
        'product_id'              => $pid,
        'period'                  => $dcF['period'],
        'views'                   => $views,
        'checkout_clicks'         => $clicks,
        'paid'                    => $paid,
        'revenue'                 => $revenue,
        'cr_view_to_checkout_pct' => $rate($clicks, $views),
        'cr_checkout_to_paid_pct' => $rate($paid, $clicks),
        'cr_view_to_paid_pct'     => $rate($paid, $views),
    ];
}

/**
 * Ringkasan traffic lintas produk: views, klik checkout, paid, conversion rate per
 * produk (diurut views terbanyak) + total keseluruhan. Untuk membandingkan "produk
 * mana banyak traffic tapi sepi konversi". 2 query agregat + 1 nama (tanpa N+1).
 */
function mcpDataTrafficSummary(PDO $pdo, string $periodRaw, $limitRaw): array
{
    $dcF   = mcpDateCondition($periodRaw, 'created_at', '30d');
    $dcO   = mcpDateCondition($dcF['period'], 'o.created_at', $dcF['period']);
    $limit = mcpClampLimit($limitRaw, 10, 50);

    // views + clicks per produk (dari funnel_events)
    $ev = $pdo->query(
        "SELECT product_id,
                SUM(event_type = 'lp_view') AS views,
                SUM(event_type = 'checkout_click') AS clicks
         FROM funnel_events
         WHERE product_id > 0 AND {$dcF['sql']}
         GROUP BY product_id"
    )->fetchAll();

    // paid per produk (dedup order, is_bump=0)
    $paidBy = [];
    foreach ($pdo->query(
        "SELECT oi.product_id, COUNT(DISTINCT o.id) AS paid
         FROM orders o JOIN order_items oi ON oi.order_id = o.id
         WHERE oi.is_bump = 0 AND o.status = 'paid' AND {$dcO['sql']}
         GROUP BY oi.product_id"
    )->fetchAll() as $r) {
        $paidBy[(int) $r['product_id']] = (int) $r['paid'];
    }

    // nama produk (satu query IN)
    $names = [];
    $ids = array_map(fn($r) => (int) $r['product_id'], $ev);
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $ns = $pdo->prepare("SELECT id, name FROM products WHERE id IN ($ph)");
        $ns->execute($ids);
        foreach ($ns->fetchAll() as $r) { $names[(int) $r['id']] = (string) $r['name']; }
    }

    $items = [];
    $totViews = 0; $totClicks = 0; $totPaid = 0;
    foreach ($ev as $r) {
        $pid = (int) $r['product_id'];
        $v = (int) $r['views']; $c = (int) $r['clicks']; $pd = $paidBy[$pid] ?? 0;
        $totViews += $v; $totClicks += $c; $totPaid += $pd;
        $items[] = [
            'product_id'      => $pid,
            'product'         => $names[$pid] ?? ('#' . $pid),
            'views'           => $v,
            'checkout_clicks' => $c,
            'paid'            => $pd,
            'conversion_pct'  => $v > 0 ? round($pd / $v * 100, 2) : null,
        ];
    }
    usort($items, fn($a, $b) => $b['views'] <=> $a['views']);
    $items = array_slice($items, 0, $limit);

    return [
        'period'  => $dcF['period'],
        'overall' => [
            'views'           => $totViews,
            'checkout_clicks' => $totClicks,
            'paid'            => $totPaid,
            'conversion_pct'  => $totViews > 0 ? round($totPaid / $totViews * 100, 2) : null,
        ],
        'items' => $items,
    ];
}

/**
 * Performa A/B canvas rotator (split test). rotatorRef kosong = ringkasan SEMUA
 * rotator (per-rotator impression/click/conversion + CR). rotatorRef diisi (nama/ID)
 * = rincian PER-VARIAN + tandai varian unggul. Rumus MENIRU pages/admin/canvas-
 * rotator-stats.php (bukan diturunkan ulang): CR = checkout_click / impression;
 * "unggulan" = CR tertinggi setelah minimal 20 impresi (hindari noise data kecil).
 * Rotator = canvas_pages page_type='rotator'; varian = canvas_rotator_variants.
 * Melempar InvalidArgumentException bila rotator yg disebut tak ditemukan.
 */
function mcpDataCanvasAbTest(PDO $pdo, string $rotatorRef, string $periodRaw): array
{
    $MIN_IMPR = 20; // ambang varian "unggulan" — sama dgn halaman admin.
    $dc     = mcpDateCondition($periodRaw, 'created_at', '30d');
    $period = $dc['period'];
    $dcE    = mcpDateCondition($period, 'e.created_at', $period)['sql']; // utk subquery beralias e
    $pct    = fn(int $n, int $d): ?float => $d > 0 ? round($n / $d * 100, 2) : null;

    $rotatorRef = trim($rotatorRef);

    // ── Mode ringkasan: semua rotator ──
    if ($rotatorRef === '') {
        $rotators = $pdo->query("SELECT id, title FROM canvas_pages WHERE page_type = 'rotator' ORDER BY id DESC")->fetchAll();
        // Agregat event per rotator + tipe dalam SATU query (tanpa N+1).
        $agg = [];
        foreach ($pdo->query(
            "SELECT rotator_id,
                    SUM(event_type = 'impression')     AS impressions,
                    SUM(event_type = 'checkout_click') AS clicks,
                    SUM(event_type = 'conversion')     AS conversions
             FROM canvas_rotator_events
             WHERE {$dc['sql']}
             GROUP BY rotator_id"
        )->fetchAll() as $r) {
            $agg[(int) $r['rotator_id']] = $r;
        }
        $items = [];
        foreach ($rotators as $ro) {
            $rid = (int) $ro['id'];
            $a = $agg[$rid] ?? ['impressions' => 0, 'clicks' => 0, 'conversions' => 0];
            $imp = (int) $a['impressions']; $clk = (int) $a['clicks']; $cnv = (int) $a['conversions'];
            $items[] = [
                'rotator_id'         => $rid,
                'rotator'            => (string) $ro['title'],
                'impressions'        => $imp,
                'checkout_clicks'    => $clk,
                'conversions'        => $cnv,
                'cr_checkout_pct'    => $pct($clk, $imp),
                'cr_conversion_pct'  => $pct($cnv, $imp),
            ];
        }
        usort($items, fn($a, $b) => $b['impressions'] <=> $a['impressions']);
        return ['period' => $period, 'rotators' => $items];
    }

    // ── Mode rincian: satu rotator ──
    if (ctype_digit($rotatorRef)) {
        $rs = $pdo->prepare("SELECT id, title, slug FROM canvas_pages WHERE id = ? AND page_type = 'rotator' LIMIT 1");
        $rs->execute([(int) $rotatorRef]);
    } else {
        $rs = $pdo->prepare("SELECT id, title, slug FROM canvas_pages WHERE page_type = 'rotator' AND title LIKE ? ORDER BY (title = ?) DESC, id DESC LIMIT 1");
        $rs->execute(['%' . $rotatorRef . '%', $rotatorRef]);
    }
    $rotator = $rs->fetch();
    if (!$rotator) {
        throw new InvalidArgumentException("Rotator \"{$rotatorRef}\" tidak ditemukan.");
    }
    $rid = (int) $rotator['id'];

    // Per varian — subquery COUNT per tipe (pola halaman admin, + filter periode + conversion).
    $vs = $pdo->prepare(
        "SELECT rv.id, rv.label, rv.is_active, cp.title AS canvas_title, cp.slug AS canvas_slug,
                (SELECT COUNT(*) FROM canvas_rotator_events e WHERE e.variant_id = rv.id AND e.event_type = 'impression'     AND {$dcE}) AS impressions,
                (SELECT COUNT(*) FROM canvas_rotator_events e WHERE e.variant_id = rv.id AND e.event_type = 'checkout_click' AND {$dcE}) AS clicks,
                (SELECT COUNT(*) FROM canvas_rotator_events e WHERE e.variant_id = rv.id AND e.event_type = 'conversion'     AND {$dcE}) AS conversions
         FROM canvas_rotator_variants rv
         LEFT JOIN canvas_pages cp ON cp.id = rv.variant_canvas_id
         WHERE rv.rotator_id = ?
         ORDER BY rv.sort_order ASC, rv.id ASC"
    );
    $vs->execute([$rid]);
    $rows = $vs->fetchAll();

    // Varian unggul = CR checkout tertinggi dengan minimal MIN_IMPR impresi.
    $leadId = 0; $leadCr = -1.0;
    foreach ($rows as $v) {
        $imp = (int) $v['impressions'];
        if ($imp < $MIN_IMPR) continue;
        $cr = $imp > 0 ? ((int) $v['clicks'] / $imp) : 0;
        if ($cr > $leadCr) { $leadCr = $cr; $leadId = (int) $v['id']; }
    }

    $variants = [];
    $totImp = 0; $totClk = 0; $totCnv = 0;
    foreach ($rows as $v) {
        $vid = (int) $v['id'];
        $imp = (int) $v['impressions']; $clk = (int) $v['clicks']; $cnv = (int) $v['conversions'];
        $totImp += $imp; $totClk += $clk; $totCnv += $cnv;
        $variants[] = [
            'variant_id'        => $vid,
            'variant'           => ($v['label'] !== '' && $v['label'] !== null) ? (string) $v['label'] : ('Varian #' . $vid),
            'canvas'            => (string) ($v['canvas_title'] ?? ''),
            'is_active'         => (int) $v['is_active'] === 1,
            'impressions'       => $imp,
            'checkout_clicks'   => $clk,
            'conversions'       => $cnv,
            'cr_checkout_pct'   => $pct($clk, $imp),
            'cr_conversion_pct' => $pct($cnv, $imp),
            'is_leading'        => $vid === $leadId,
        ];
    }

    return [
        'rotator'            => (string) $rotator['title'],
        'rotator_id'         => $rid,
        'period'             => $period,
        'min_impressions'    => $MIN_IMPR,
        'leading_variant_id' => $leadId ?: null,
        'totals' => [
            'impressions'       => $totImp,
            'checkout_clicks'   => $totClk,
            'conversions'       => $totCnv,
            'cr_checkout_pct'   => $pct($totClk, $totImp),
            'cr_conversion_pct' => $pct($totCnv, $totImp),
        ],
        'variants' => $variants,
    ];
}

/**
 * Performa broadcast email/WA — AGREGAT per campaign (TAK PERNAH per-orang: kolom
 * recipient = email/WA = PII). campaignRef kosong = daftar broadcast terbaru dlm
 * periode (diurut terbaru). campaignRef diisi (nama/ID) = metrik satu campaign.
 * Rumus MENIRU pages/admin/broadcast.php: uniq_opens/uniq_clicks = COUNT recipient
 * dgn opened_at/clicked_at IS NOT NULL; sent/failed dari kolom campaign. open/click
 * rate = uniq ÷ total_sent. Melempar InvalidArgumentException bila campaign tak ada.
 */
function mcpDataBroadcastEngagement(PDO $pdo, string $campaignRef, $limitRaw, string $periodRaw): array
{
    $pct = fn(int $n, int $d): ?float => $d > 0 ? round($n / $d * 100, 2) : null;
    $row = function (array $bc) use ($pct): array {
        $recipients = (int) $bc['total_recipients'];
        $sent       = (int) $bc['total_sent'];
        $failed     = (int) $bc['total_failed'];
        $opens      = (int) $bc['uniq_opens'];
        $clicks     = (int) $bc['uniq_clicks'];
        return [
            'campaign_id'    => (int) $bc['id'],
            'name'           => (string) $bc['name'],
            'channel'        => (string) $bc['channel'],
            'subject'        => (string) ($bc['subject'] ?? ''),
            'status'         => (string) $bc['status'],
            'recipients'     => $recipients,
            'sent'           => $sent,
            'failed'         => $failed,
            'pending'        => max(0, $recipients - $sent - $failed),
            'unique_opens'   => $opens,
            'unique_clicks'  => $clicks,
            'open_rate_pct'  => $pct($opens, $sent),
            'click_rate_pct' => $pct($clicks, $sent),
            'sent_at'        => $bc['sent_at'] !== null ? (string) $bc['sent_at'] : null,
            'created_at'     => (string) $bc['created_at'],
        ];
    };
    $select =
        "SELECT bc.id, bc.name, bc.channel, bc.subject, bc.status, bc.total_recipients,
                bc.total_sent, bc.total_failed, bc.sent_at, bc.created_at,
                (SELECT COUNT(*) FROM broadcast_recipients r WHERE r.campaign_id = bc.id AND r.opened_at IS NOT NULL)  AS uniq_opens,
                (SELECT COUNT(*) FROM broadcast_recipients r WHERE r.campaign_id = bc.id AND r.clicked_at IS NOT NULL) AS uniq_clicks
         FROM broadcast_campaigns bc";

    $campaignRef = trim($campaignRef);

    // Cache ~45 detik: subquery COUNT open/click per-campaign berat bila penerima
    // banyak. Dibaca AI (MCP get_broadcast_engagement / insights). Fail-soft.
    $__bcKey = 'mcpd_bcast_' . md5($campaignRef . '|' . (string) $limitRaw . '|' . $periodRaw);
    if (function_exists('cacheGet')) {
        $__bcHit = cacheGet($__bcKey);
        if (is_array($__bcHit)) {
            return $__bcHit;
        }
    }

    if ($campaignRef !== '') {
        if (ctype_digit($campaignRef)) {
            $st = $pdo->prepare($select . " WHERE bc.id = ? LIMIT 1");
            $st->execute([(int) $campaignRef]);
        } else {
            $st = $pdo->prepare($select . " WHERE bc.name LIKE ? ORDER BY (bc.name = ?) DESC, bc.created_at DESC LIMIT 1");
            $st->execute(['%' . $campaignRef . '%', $campaignRef]);
        }
        $bc = $st->fetch();
        if (!$bc) {
            throw new InvalidArgumentException("Broadcast \"{$campaignRef}\" tidak ditemukan.");
        }
        $out = $row($bc);
        if (function_exists('cacheSet')) {
            cacheSet($__bcKey, $out, 45);
        }
        return $out;
    }

    $dc    = mcpDateCondition($periodRaw, 'bc.created_at', '30d');
    $limit = mcpClampLimit($limitRaw, 10, 50);
    $st = $pdo->query($select . " WHERE {$dc['sql']} ORDER BY bc.created_at DESC LIMIT {$limit}");
    $items = array_map($row, $st->fetchAll());
    $out = ['period' => $dc['period'], 'campaigns' => $items];
    if (function_exists('cacheSet')) {
        cacheSet($__bcKey, $out, 45);
    }
    return $out;
}

/**
 * Kesehatan PENGIRIMAN event server-side (Meta CAPI outbound queue) — antrian event
 * yang Averion KIRIM ke platform, BUKAN hit pixel di browser pengunjung. Sumber:
 * capi_event_queue (satu-satunya tabel; tak ada data pixel inbound). Metrik: jumlah
 * per status, failed rate, umur pending tertua, distribusi attempts, per platform.
 * (Analitik pixel penuh = connector Meta terpisah, di luar scope.)
 */
function mcpDataPixelHealth(PDO $pdo, string $periodRaw): array
{
    $dc  = mcpDateCondition($periodRaw, 'created_at', '30d');
    $pct = fn(int $n, int $d): ?float => $d > 0 ? round($n / $d * 100, 2) : null;

    $o = $pdo->query(
        "SELECT SUM(status = 'pending') pending, SUM(status = 'sent') sent,
                SUM(status = 'failed') failed, COUNT(*) total
         FROM capi_event_queue WHERE {$dc['sql']}"
    )->fetch();
    $pending = (int) $o['pending']; $sent = (int) $o['sent'];
    $failed  = (int) $o['failed'];  $total = (int) $o['total'];

    // Pending tertua (umur antrian yang belum terkirim).
    $op = $pdo->query(
        "SELECT MIN(created_at) oldest, TIMESTAMPDIFF(MINUTE, MIN(created_at), NOW()) age_min
         FROM capi_event_queue WHERE status = 'pending' AND {$dc['sql']}"
    )->fetch();
    $oldestPending = null;
    if ($op && $op['oldest'] !== null) {
        $ageMin = (int) $op['age_min'];
        $oldestPending = [
            'created_at'  => (string) $op['oldest'],
            'age_minutes' => $ageMin,
            'age_hours'   => round($ageMin / 60, 1),
        ];
    }

    // Distribusi attempts (berapa event per jumlah percobaan).
    $attempts = [];
    foreach ($pdo->query(
        "SELECT attempts, COUNT(*) c FROM capi_event_queue WHERE {$dc['sql']} GROUP BY attempts ORDER BY attempts ASC"
    )->fetchAll() as $r) {
        $attempts[] = ['attempts' => (int) $r['attempts'], 'count' => (int) $r['c']];
    }

    // Per platform (Meta, TikTok, dst).
    $byPlatform = [];
    foreach ($pdo->query(
        "SELECT platform, SUM(status = 'pending') pending, SUM(status = 'sent') sent,
                SUM(status = 'failed') failed, COUNT(*) total
         FROM capi_event_queue WHERE {$dc['sql']} GROUP BY platform ORDER BY total DESC"
    )->fetchAll() as $r) {
        $byPlatform[] = [
            'platform' => (string) $r['platform'],
            'pending'  => (int) $r['pending'],
            'sent'     => (int) $r['sent'],
            'failed'   => (int) $r['failed'],
            'total'    => (int) $r['total'],
        ];
    }

    return [
        'period'                => $dc['period'],
        'note'                  => 'Kesehatan pengiriman event server-side yang Averion KIRIM ke platform (Meta CAPI outbound), bukan hit pixel di browser pengunjung.',
        'overall'               => ['pending' => $pending, 'sent' => $sent, 'failed' => $failed, 'total' => $total],
        'failed_rate_pct'       => $pct($failed, $total),
        'oldest_pending'        => $oldestPending,
        'attempts_distribution' => $attempts,
        'by_platform'           => $byPlatform,
    ];
}

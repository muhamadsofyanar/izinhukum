<?php
/**
 * Endpoint protokol MCP (Model Context Protocol) — Integrasi Claude, FASE 2.
 * URL: /api/mcp  (route di index.php → file ini). Transport: Streamable HTTP
 * (spec 2025-06-18): SATU endpoint, client POST JSON-RPC 2.0, server balas
 * application/json (tanpa SSE — cukup untuk server tool-only, jalan di shared
 * hosting). Stateless: TANPA Mcp-Session-Id (opsional per spec).
 *
 * Auth: Bearer token statik (dikonfigurasi pembeli di "Request headers" konektor
 * Claude.ai — OAuth TIDAK diperlukan untuk single-admin; lihat dok konektor).
 * Reuse mcpTokenValid()/mcpEnabled() + fungsi data _data.php (satu logic, tak
 * duplikat dgn endpoint REST Fase 1).
 *
 * Method MCP ditangani: initialize, notifications/initialized (202), tools/list,
 * tools/call, ping.
 */
require_once __DIR__ . '/internal/_data.php';    // read: pulls _auth.php + bootstrap
require_once __DIR__ . '/internal/_actions.php'; // write (Fase 5): fungsi aksi ber-gate

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ─── Balas JSON-RPC ────────────────────────────────────────────────
function mcpRpcSend(int $http, array $payload): void {
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function mcpRpcResult($id, array|object $result): void {
    // object → emit {} (mis. ping/initialize.capabilities); array → list/objek biasa.
    mcpRpcSend(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}
function mcpRpcError($id, int $code, string $message, int $http = 200): void {
    mcpRpcSend($http, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
}

// ─── Method HTTP ───────────────────────────────────────────────────
$httpMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($httpMethod === 'GET' || $httpMethod === 'DELETE') {
    // Kami tak menawarkan SSE (GET) maupun terminasi sesi (DELETE) → 405.
    header('Allow: POST');
    mcpRpcError(null, -32600, 'Endpoint MCP ini hanya menerima POST (tanpa SSE).', 405);
}
if ($httpMethod !== 'POST') {
    header('Allow: POST');
    mcpRpcError(null, -32600, 'Method tidak diizinkan.', 405);
}

// ─── Auth: Bearer + toggle aktif (sebelum parsing) ─────────────────
$token = mcpExtractBearer();
if ($token === '' || !mcpTokenValid($token)) {
    // SENGAJA TANPA header WWW-Authenticate: header itu menandai server sbg
    // "OAuth-protected resource" → konektor Claude auto-deteksi OAuth ("Selalu
    // diperlukan") lalu mencoba alur OAuth yang tak kita punya → gagal connect.
    // Server ini pakai token header statik (auth "Tidak ada" di UI konektor).
    mcpRpcError(null, -32001, 'Token tidak valid atau tidak ada.', 401);
}
if (!mcpEnabled()) {
    mcpRpcError(null, -32002, 'Integrasi Claude (MCP) belum diaktifkan admin instalasi ini.', 403);
}

// ─── Rate limit (bersama dgn endpoint REST) ────────────────────────
$ip = client_real_ip();
if (!checkIpRateLimit('mcp_api', $ip, 60, 1)) {
    mcpRpcError(null, -32003, 'Terlalu banyak permintaan. Coba lagi sebentar.', 429);
}
recordIpAction('mcp_api', $ip);

// ─── Parse JSON-RPC ────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$msg = json_decode((string) $raw, true);
if (!is_array($msg) || ($msg['jsonrpc'] ?? '') !== '2.0' || !isset($msg['method'])) {
    mcpRpcError(null, -32700, 'JSON-RPC tidak valid.', 400);
}

$rpcMethod = (string) $msg['method'];
$params    = is_array($msg['params'] ?? null) ? $msg['params'] : [];

// Notifikasi (tanpa "id", mis. notifications/initialized) → 202 tanpa body.
if (!array_key_exists('id', $msg)) {
    http_response_code(202);
    exit;
}
$id = $msg['id'];

// ─── Registry tool: nama MCP → deskripsi, schema input, pemanggil data ──
$pdo = getDB();
$tools = [
    'get_business_dashboard' => [
        'description' => 'Ringkasan KPI bisnis (omzet, jumlah order lunas & pending, pengguna baru, member aktif) untuk periode tertentu.',
        'schema' => ['type' => 'object', 'properties' => [
            'period' => ['type' => 'string', 'enum' => ['today', '7d', '30d', '90d', 'all'], 'description' => 'Rentang waktu. Default 7d.'],
        ]],
        'call' => fn($a) => mcpDataDashboard($pdo, (string) ($a['period'] ?? '7d')),
    ],
    'get_revenue_summary' => [
        'description' => 'Tren omzet (order lunas) sebagai deret per hari/bulan + total & perbandingan persen dengan periode sebelumnya yang sama panjang.',
        'schema' => ['type' => 'object', 'properties' => [
            'range'    => ['type' => 'string', 'enum' => ['30d', '90d', '365d'], 'description' => 'Rentang. Default 30d.'],
            'group_by' => ['type' => 'string', 'enum' => ['day', 'month'], 'description' => 'Kelompokkan per hari atau bulan. Default day.'],
        ]],
        'call' => fn($a) => mcpDataRevenue($pdo, (string) ($a['range'] ?? '30d'), (string) ($a['group_by'] ?? 'day')),
    ],
    'get_product_sales' => [
        'description' => 'Produk terlaris beserta jumlah terjual dan omzet per produk, diurut omzet tertinggi.',
        'schema' => ['type' => 'object', 'properties' => [
            'range' => ['type' => 'string', 'enum' => ['today', '7d', '30d', '90d', '365d', 'all'], 'description' => 'Rentang. Default 30d.'],
            'limit' => ['type' => 'integer', 'description' => 'Jumlah produk (maks 50). Default 10.'],
        ]],
        'call' => fn($a) => mcpDataProductSales($pdo, (string) ($a['range'] ?? '30d'), $a['limit'] ?? null),
    ],
    'get_pending_transactions' => [
        'description' => 'Daftar order yang menunggu pembayaran (belum lunas), diurut tertua dulu, beserta umur order dalam jam dan gateway pembayaran.',
        'schema' => ['type' => 'object', 'properties' => [
            'limit'            => ['type' => 'integer', 'description' => 'Jumlah order (maks 100). Default 20.'],
            'older_than_hours' => ['type' => 'integer', 'description' => 'Hanya order yang lebih tua dari sekian jam (opsional).'],
        ]],
        'call' => fn($a) => mcpDataPending($pdo, $a['limit'] ?? null, $a['older_than_hours'] ?? null),
    ],
    'get_top_affiliates' => [
        'description' => 'Peringkat affiliate berdasarkan komisi: jumlah konversi, komisi disetujui, dan komisi pending. Diurut komisi disetujui tertinggi.',
        'schema' => ['type' => 'object', 'properties' => [
            'range' => ['type' => 'string', 'enum' => ['today', '7d', '30d', '90d', '365d', 'all'], 'description' => 'Rentang. Default 30d.'],
            'limit' => ['type' => 'integer', 'description' => 'Jumlah affiliate (maks 50). Default 10.'],
        ]],
        'call' => fn($a) => mcpDataLeaderboard($pdo, (string) ($a['range'] ?? '30d'), $a['limit'] ?? null),
    ],
    'search_members' => [
        'description' => 'Cari member berdasarkan nama, email, atau nomor WhatsApp; kembalikan total belanja, jumlah order lunas, level, dan akses aktif. Email & WA bisa tersamar sesuai pengaturan privasi instalasi.',
        'schema' => ['type' => 'object', 'properties' => [
            'q'     => ['type' => 'string', 'description' => 'Kata kunci (min 2 karakter): nama / email / nomor WA.'],
            'limit' => ['type' => 'integer', 'description' => 'Jumlah hasil (maks 50). Default 10.'],
        ], 'required' => ['q']],
        'call' => fn($a) => mcpDataMembers($pdo, (string) ($a['q'] ?? ''), $a['limit'] ?? null),
    ],
    'get_support_tickets' => [
        'description' => 'Daftar tiket support. Default menampilkan yang berstatus "open"; field needs_reply menandai tiket yang balasan terakhirnya dari pelanggan & belum dijawab admin. Pakai nomor tiket dari sini untuk membuka detail lewat get_ticket.',
        'schema' => ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['open', 'answered', 'closed', 'all'], 'description' => 'Filter status. Default open.'],
            'limit'  => ['type' => 'integer', 'description' => 'Jumlah tiket (maks 100). Default 20.'],
        ]],
        'call' => fn($a) => mcpDataTickets($pdo, (string) ($a['status'] ?? 'open'), $a['limit'] ?? null),
    ],
    'get_ticket' => [
        'description' => 'Detail satu tiket support beserta seluruh utas percakapannya (pesan pelanggan & balasan admin, berurutan). Gunakan untuk membaca konteks lalu menyusun DRAFT balasan — draft tidak dikirim, admin yang menyalin/mengirim sendiri.',
        'schema' => ['type' => 'object', 'properties' => [
            'ticket' => ['type' => 'string', 'description' => 'Nomor tiket (mis. dari get_support_tickets).'],
        ], 'required' => ['ticket']],
        'call' => fn($a) => mcpDataTicketDetail($pdo, (string) ($a['ticket'] ?? '')),
    ],
    'get_traffic_summary' => [
        'description' => 'Ringkasan traffic lintas produk: jumlah kunjungan halaman (views), klik checkout, pembelian (paid), dan conversion rate (paid/views) per produk, diurut views terbanyak, + total keseluruhan. Untuk membandingkan produk mana banyak traffic tapi sepi konversi. Traffic dari pelacakan funnel Averion sendiri.',
        'schema' => ['type' => 'object', 'properties' => [
            'period' => ['type' => 'string', 'enum' => ['today', '7d', '30d', '90d', '365d', 'all'], 'description' => 'Rentang. Default 30d.'],
            'limit'  => ['type' => 'integer', 'description' => 'Jumlah produk (maks 50). Default 10.'],
        ]],
        'call' => fn($a) => mcpDataTrafficSummary($pdo, (string) ($a['period'] ?? '30d'), $a['limit'] ?? null),
    ],
    'get_funnel_analytics' => [
        'description' => 'Funnel satu produk: kunjungan halaman (views) → klik checkout → bayar, beserta conversion rate tiap tahap dan omzet. Sebutkan produk lewat nama atau product_id (mis. dari get_traffic_summary/get_product_sales). Untuk analisis "di tahap mana pengunjung berhenti".',
        'schema' => ['type' => 'object', 'properties' => [
            'product' => ['type' => 'string', 'description' => 'Nama produk (dicocokkan) atau product_id.'],
            'period'  => ['type' => 'string', 'enum' => ['today', '7d', '30d', '90d', '365d', 'all'], 'description' => 'Rentang. Default 30d.'],
        ], 'required' => ['product']],
        'call' => fn($a) => mcpDataFunnel($pdo, (string) ($a['product'] ?? ''), (string) ($a['period'] ?? '30d')),
    ],
    'get_canvas_ab_test' => [
        'description' => 'Performa split test (A/B) Canvas Rotator: per varian jumlah impression, checkout_click, conversion + conversion rate, dan penanda varian unggul (CR checkout tertinggi setelah minimal 20 impresi). Sebutkan rotator lewat nama/ID untuk rincian per-varian; kosongkan untuk ringkasan semua rotator. Untuk pertanyaan "varian/angle mana yang menang".',
        'schema' => ['type' => 'object', 'properties' => [
            'rotator' => ['type' => 'string', 'description' => 'Nama rotator (dicocokkan) atau ID. Kosong = ringkasan semua rotator.'],
            'period'  => ['type' => 'string', 'enum' => ['today', '7d', '30d', '90d', '365d', 'all'], 'description' => 'Rentang. Default 30d.'],
        ]],
        'call' => fn($a) => mcpDataCanvasAbTest($pdo, (string) ($a['rotator'] ?? ''), (string) ($a['period'] ?? '30d')),
    ],
    'get_broadcast_engagement' => [
        'description' => 'Performa broadcast email/WhatsApp per campaign: jumlah penerima, terkirim, gagal, open rate, dan click rate (agregat — tanpa data penerima per-orang). Sebutkan campaign lewat nama/ID untuk metrik satu campaign; kosongkan untuk daftar broadcast terbaru beserta metriknya.',
        'schema' => ['type' => 'object', 'properties' => [
            'campaign' => ['type' => 'string', 'description' => 'Nama campaign (dicocokkan) atau ID. Kosong = daftar broadcast terbaru.'],
            'period'   => ['type' => 'string', 'enum' => ['today', '7d', '30d', '90d', '365d', 'all'], 'description' => 'Rentang (untuk daftar terbaru). Default 30d.'],
            'limit'    => ['type' => 'integer', 'description' => 'Jumlah broadcast di daftar (maks 50). Default 10.'],
        ]],
        'call' => fn($a) => mcpDataBroadcastEngagement($pdo, (string) ($a['campaign'] ?? ''), $a['limit'] ?? null, (string) ($a['period'] ?? '30d')),
    ],
    'get_pixel_delivery_health' => [
        'description' => 'Kesehatan PENGIRIMAN event server-side (antrian Meta CAPI outbound) yang Averion KIRIM ke platform — BUKAN hit pixel di browser pengunjung. Metrik: jumlah per status (pending/sent/failed), failed rate, umur pending tertua, distribusi attempts, dan rincian per platform. Untuk memeriksa apakah ada event tracking yang gagal terkirim. (Analitik pixel penuh butuh connector Meta terpisah, di luar cakupan.)',
        'schema' => ['type' => 'object', 'properties' => [
            'period' => ['type' => 'string', 'enum' => ['today', '7d', '30d', '90d', '365d', 'all'], 'description' => 'Rentang. Default 30d.'],
        ]],
        'call' => fn($a) => mcpDataPixelHealth($pdo, (string) ($a['period'] ?? '30d')),
    ],

    // ── AKSI TULIS (Fase 5) — hanya jalan bila mcpWriteEnabled() + persetujuan user ──
    'send_member_message' => [
        'write' => true,
        'description' => 'Kirim pesan WhatsApp atau email ke SATU member (sebutkan member_id atau member_email). Untuk banyak orang, panggil berulang. Memakai provider notifikasi instalasi ini. Butuh "Aksi Tulis" diaktifkan admin + persetujuan pengguna.',
        'schema' => ['type' => 'object', 'properties' => [
            'channel'      => ['type' => 'string', 'enum' => ['whatsapp', 'email'], 'description' => 'Kanal pengiriman.'],
            'member_id'    => ['type' => 'integer', 'description' => 'ID member tujuan (mis. dari search_members). Pakai ini ATAU member_email.'],
            'member_email' => ['type' => 'string', 'description' => 'Email member tujuan (alternatif member_id).'],
            'subject'      => ['type' => 'string', 'description' => 'Judul (hanya untuk email; opsional).'],
            'message'      => ['type' => 'string', 'description' => 'Isi pesan (wajib).'],
        ], 'required' => ['channel', 'message']],
        'call' => fn($a) => mcpActionSendMessage($pdo, $a),
    ],
    'send_admin_telegram' => [
        'write' => true,
        'description' => 'Kirim notifikasi/alert ke channel Telegram admin (INTERNAL — bukan ke pelanggan). Butuh "Aksi Tulis" diaktifkan admin + Telegram dikonfigurasi + persetujuan pengguna.',
        'schema' => ['type' => 'object', 'properties' => [
            'message' => ['type' => 'string', 'description' => 'Isi alert (wajib).'],
        ], 'required' => ['message']],
        'call' => fn($a) => mcpActionTelegramAlert((string) ($a['message'] ?? '')),
    ],
];

// ─── Dispatch ──────────────────────────────────────────────────────
switch ($rpcMethod) {
    case 'initialize':
        $clientVer = (string) ($params['protocolVersion'] ?? '2025-06-18');
        $supported = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
        $ver = in_array($clientVer, $supported, true) ? $clientVer : '2025-06-18';
        mcpRpcResult($id, [
            'protocolVersion' => $ver,
            'capabilities'    => ['tools' => (object) []], // tools tanpa listChanged
            'serverInfo'      => [
                'name'    => (string) getSetting('app_name', 'STIFIn Mulia') . ' — Integrasi Claude',
                'version' => defined('APP_VERSION') ? APP_VERSION : '1.0',
            ],
        ]);
        // no fallthrough (mcpRpcResult exit)

    case 'ping':
        mcpRpcResult($id, (object) []);

    case 'tools/list':
        $list = [];
        foreach ($tools as $name => $t) {
            $schema = $t['schema'];
            if (empty($schema['properties'])) { $schema['properties'] = (object) []; }
            $list[] = ['name' => $name, 'description' => $t['description'], 'inputSchema' => $schema];
        }
        mcpRpcResult($id, ['tools' => $list]);

    case 'tools/call':
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if (!isset($tools[$name])) {
            mcpRpcError($id, -32602, "Tool tidak dikenal: {$name}");
        }
        // Gerbang AKSI TULIS: tool ber-tanda write hanya jalan bila admin mengaktifkan
        // "Izinkan Aksi Tulis" (default MATI). Read tak terpengaruh. Hasil isError
        // (bukan error protokol) supaya Claude bisa menjelaskan ke user.
        if (!empty($tools[$name]['write']) && !mcpWriteEnabled()) {
            mcpRpcResult($id, [
                'content' => [['type' => 'text', 'text' => 'Aksi tulis (kirim pesan/alert) belum diaktifkan admin. Nyalakan "Izinkan Aksi Tulis" di Admin > Integrasi Claude dulu.']],
                'isError' => true,
            ]);
        }
        try {
            $data = ($tools[$name]['call'])($args);
            logActivity('admin', 'mcp.tool_call', 'mcp', null, "tool={$name} ip={$ip}");
            mcpRpcResult($id, [
                'content'           => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT)]],
                'structuredContent' => $data,
                'isError'           => false,
            ]);
        } catch (InvalidArgumentException $e) {
            // Error INPUT tool → hasil tool ber-isError (bukan error protokol JSON-RPC).
            mcpRpcResult($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
        } catch (Throwable $e) {
            error_log('api/mcp tools/call error [' . $name . ']: ' . $e->getMessage());
            mcpRpcResult($id, ['content' => [['type' => 'text', 'text' => 'Terjadi kesalahan server saat menjalankan tool.']], 'isError' => true]);
        }

    default:
        mcpRpcError($id, -32601, "Method tidak ditemukan: {$rpcMethod}");
}

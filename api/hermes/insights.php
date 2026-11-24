<?php
// ════════════════════════════════════════════════════════════════════════
// Hermes Agent — Baca Data Bisnis (insights)  GET /api/hermes/insights?metric=<m>
//
// READ-ONLY, agregat, TANPA PII. Untuk Hermes menganalisis bisnis. Satu endpoint
// dispatcher by `metric` (whitelist dari hermesInsightsRegistry) → memakai ULANG
// fungsi agregasi di api/internal/_data.php (nol query/omzet paralel). TIDAK ada
// aksi tulis/kirim di sini.
//
// Auth: Bearer token + hermes_enabled (master) + hermes_read_enabled (tier baca).
//   Tier baca TERPISAH dari tier manajemen/tulis — default MATI, opt-in admin.
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../helpers/hermes.php';
require_once __DIR__ . '/../../api/internal/_data.php'; // fungsi agregasi bersama + helper mcpDateCondition/mcpClampLimit

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    hermesApiSend(405, ['ok' => false, 'error' => 'Metode tidak diizinkan. Hanya GET (read-only).']);
}
hermesInsightsAuthorize(); // token valid + hermes_enabled + hermes_read_enabled

$registry = hermesInsightsRegistry();
$metric   = trim((string) ($_GET['metric'] ?? ''));
if ($metric === '' || !isset($registry[$metric])) {
    hermesApiSend(400, [
        'ok'               => false,
        'error'            => $metric === '' ? 'Parameter metric wajib.' : 'Metric tidak dikenal: ' . $metric,
        'available_metrics'=> array_keys($registry),
    ]);
}

$pdo    = getDB();
$period = (string) ($_GET['period'] ?? '');

// Cache agregat read-only (dibaca AI/polling) — boleh basi ~45 detik. Key = metric
// + semua parameter query (token ada di header, tak ikut). Fail-soft: bila cache
// bermasalah, komputasi tetap jalan. Mengurangi beban DB saat Hermes menembak live.
$insightsCacheKey = 'hermes_insights_' . md5($metric . '|' . json_encode($_GET));
$data = function_exists('cacheGet') ? cacheGet($insightsCacheKey) : null;

if (!is_array($data)) {
try {
    switch ($metric) {
        case 'business_summary':
            $data = mcpDataDashboard($pdo, $period);
            break;

        case 'revenue_trend':
            $data = mcpDataRevenue($pdo, (string) ($_GET['range'] ?? '30d'), (string) ($_GET['group'] ?? 'day'));
            break;

        case 'product_sales':
            $data = mcpDataProductSales($pdo, $period, $_GET['limit'] ?? null);
            break;

        case 'traffic_summary':
            $data = mcpDataTrafficSummary($pdo, $period, $_GET['limit'] ?? null);
            break;

        case 'product_funnel':
            $data = mcpDataFunnel($pdo, (string) ($_GET['product'] ?? ''), $period);
            break;

        case 'cross_sell':
            $data = mcpDataCrossSell($pdo, (string) ($_GET['product'] ?? ''), $period, $_GET['limit'] ?? null);
            break;

        case 'affiliate_performance':
            // Strip PII: buang nama affiliate, sisakan kode (publik) + metrik.
            $raw = mcpDataLeaderboard($pdo, $period, $_GET['limit'] ?? null);
            $items = [];
            foreach ($raw['items'] as $it) {
                $items[] = [
                    'code'                => $it['code'],
                    'conversions'         => $it['conversions'],
                    'commission_approved' => $it['commission_approved'],
                    'commission_pending'  => $it['commission_pending'],
                ];
            }
            $data = ['range' => $raw['range'], 'items' => $items];
            break;

        case 'email_sequence_summary':
            $data = mcpDataEmailSequenceSummary($pdo);
            break;

        case 'broadcast_summary':
            $data = mcpDataBroadcastEngagement($pdo, (string) ($_GET['campaign'] ?? ''), $_GET['limit'] ?? null, $period);
            break;

        default:
            // Tak tercapai (metric sudah divalidasi ke registry), tapi fail-safe.
            hermesApiSend(400, ['ok' => false, 'error' => 'Metric belum diimplementasi: ' . $metric]);
    }
} catch (InvalidArgumentException $e) {
    // Input tak valid (mis. produk tak ditemukan / wajib) → 422, aman ditampilkan.
    hermesApiSend(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    hermesLog('500 insights metric=' . $metric . ' : ' . $e->getMessage());
    hermesApiSend(500, ['ok' => false, 'error' => 'Gagal mengambil data.']);
}
    if (function_exists('cacheSet') && is_array($data)) {
        cacheSet($insightsCacheKey, $data, 45);
    }
}

hermesLog('insights metric=' . $metric . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-'));

hermesApiSend(200, [
    'ok'          => true,
    'metric'      => $metric,
    'data'        => $data,
    'definitions' => hermesInsightsDefinitions(),
    'generated_at'=> date('c'),
]);

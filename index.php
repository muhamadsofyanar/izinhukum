<?php
// ─── Redirect ke installer jika belum dikonfigurasi ──────────
if (!file_exists(__DIR__ . '/config.php')) {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    header('Location: ' . $base . '/installer/');
    exit;
}

require_once __DIR__ . '/bootstrap.php';

// ─── Hitung base path dari APP_URL ───────────────────────────
// Contoh: APP_URL = 'http://localhost/averion' → basePath = '/averion'
//         APP_URL = 'https://mysite.com'       → basePath = ''
$basePath    = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?? '', '/');
$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
    $requestPath = substr($requestPath, strlen($basePath));
}
$requestPath = rtrim($requestPath, '/') ?: '/';

// ─── Routing table ────────────────────────────────────────────
// Format: pattern => [file_template, [param_name => match_group_index]]
// File template boleh pakai {1},{2} untuk capture group dinamis.
$routes = [

    // ── Root ──────────────────────────────────────────────────
    '#^/$#' => ['pages/public/layanan.php', []],
    '#^/layanan$#' => ['pages/public/layanan.php', []],
    '#^/layanan/([a-z0-9-]+)$#' => ['pages/public/layanan.php', ['slug' => 1]],

    // ── PWA (Progressive Web App) ─────────────────────────────
    // Manifest dinamis (white-label) + ikon di-generate GD. Gate pwa_enabled di file.
    // PENTING: ikon pakai path TANPA ekstensi .png — di server nginx (CloudPanel) &
    // sebagian LiteSpeed, request berekstensi statis (.png) dilayani langsung oleh
    // web server (404 bila file fisik tak ada) & TAK PERNAH masuk ke PHP front controller,
    // sehingga route .png gagal. Path tanpa ekstensi selalu diteruskan ke index.php.
    '#^/manifest\.webmanifest$#' => ['pages/public/pwa-manifest.php', []],
    '#^/pwa-icon/(\d+)$#'        => ['pages/public/pwa-icon.php', ['size' => 1]],
    // Back-compat: route lama berekstensi (jalan di Apache) — dipertahankan agar
    // manifest yang sudah ter-cache di browser lama tetap dapat ikon di Apache.
    '#^/pwa-icon-(\d+)\.png$#'   => ['pages/public/pwa-icon.php', ['size' => 1]],

    // ── Affiliate & Referral ──────────────────────────────────
    '#^/go/([A-Za-z0-9]+)/([a-z0-9-]+)$#'       => ['pages/public/affiliate-landing-redirect.php', ['code' => 1, 'slug' => 2]],
    '#^/r/([a-z0-9-]+)$#'                         => ['pages/public/affiliate-redirect.php',         ['slug' => 1]],
    // Averion Partner — storefront per-member (slug/kode). Dua segmen → tak bentrok
    // dgn Canvas landing `/partner` (yang tetap di-resolve oleh resolver standalone).
    '#^/partner/([A-Za-z0-9-]+)$#'                => ['pages/public/partner.php',                   ['code' => 1]],
    // Broadcast open/click tracking (pixel + link-wrap milik Averion).
    '#^/bt/o/([A-Za-z0-9._-]+)$#'                 => ['tracking/broadcast-open.php',  ['tok' => 1]],
    '#^/bt/c/([A-Za-z0-9._-]+)$#'                 => ['tracking/broadcast-click.php', ['tok' => 1]],

    // ── License Expired (bypass enforcement) — juga dipakai utk status suspend ─
    '#^/license-expired$#'                         => ['pages/public/license-expired.php', []],

    // ── Halaman Publik ────────────────────────────────────────
    '#^/login$#'                                   => ['pages/public/login.php',          []],
    '#^/login-otp$#'                               => ['pages/public/login-otp.php',      []],
    '#^/login/2fa$#'                               => ['pages/public/login-2fa.php',      []],
    '#^/register$#'                                => ['pages/public/register.php',        []],
    '#^/forgot-password$#'                         => ['pages/public/forgot-password.php', []],
    '#^/reset-password$#'                          => ['pages/public/reset-password.php',  []],
    '#^/products$#'                                => ['pages/public/products.php',        []],
    '#^/products/([a-z0-9-]+)$#'                  => ['pages/public/product-detail.php',  ['slug' => 1]],
    '#^/checkout$#'                                => ['pages/public/checkout.php',        []],
    '#^/order/([a-z0-9-]+)$#'                      => ['pages/public/public-checkout.php', ['slug' => 1]], // checkout publik (kanonik pendek)
    '#^/on/([a-z0-9-]+)$#'                         => ['pages/public/salespage.php',       ['slug' => 1]], // salespage siap-pakai reseller
    '#^/akses/([a-z0-9-]+)$#'                      => ['pages/public/product-access.php',  ['slug' => 1]], // halaman akses produk berpassword
    '#^/unsubscribe/([A-Za-z0-9._-]+)$#'           => ['pages/public/unsubscribe.php',     ['token' => 1]], // berhenti berlangganan email promosi
    '#^/akses-file/([0-9]+)$#'                     => ['pages/public/access-download.php',  ['id' => 1]],   // unduh file dari halaman akses
    '#^/akses-cepat/([A-Za-z0-9]+)$#'              => ['pages/public/magic-login.php',      ['token' => 1]], // magic-login akses instan pasca-bayar (akun lama)
    '#^/public-checkout/([a-z0-9-]+)$#'           => ['pages/public/public-checkout.php', ['slug' => 1]], // alias lama (backward-compat)
    // Form order embeddable (iframe di situs eksternal) — reuse public-checkout via flag.
    '#^/embed/checkout/([a-z0-9-]+)$#'            => ['pages/public/embed-checkout.php', ['slug' => 1]],
    '#^/public-thank-you/([A-Za-z0-9_-]+)$#'      => ['pages/public/public-thank-you.php', ['order_number' => 1]],

    // ── Invoice PDF (standalone, login wajib) ─────────────────
    '#^/invoice/([A-Za-z0-9_-]+)$#'               => ['pages/public/invoice-print.php', ['order_number' => 1]],

    // ── Lead Magnet — Opt-in Publik ───────────────────────────
    '#^/lp/([a-z0-9-]+)$#'                        => ['pages/public/lead-landing.php', ['slug' => 1]],

    // ── Funnel Gate — Form Kualifikasi Publik ─────────────────
    '#^/apply/([a-z0-9-]+)$#'                     => ['pages/public/funnel-gate-form.php', ['slug' => 1]],

    // ── Product Bundle (publik) ───────────────────────────────
    '#^/bundles/([a-z0-9-]+)$#'                   => ['pages/public/bundle-detail.php', ['slug' => 1]],

    // ── Google OAuth (publik, tanpa requireLogin) ─────────────
    '#^/auth/google$#'                             => ['auth/google.php',                  []],
    '#^/auth/google/callback$#'                    => ['auth/google-callback.php',         []],

    // ── Area User ─────────────────────────────────────────────
    '#^/dashboard$#'                               => ['pages/user/dashboard.php',         []],
    '#^/transactions$#'                            => ['pages/user/transactions.php',       []],
    '#^/katalog$#'                                 => ['pages/user/catalog.php',            []], // etalase produk di dashboard member
    '#^/rewards$#'                                 => ['pages/user/rewards.php',            []], // Poin Reward member
    '#^/penawaran$#'                               => ['pages/user/offers.php',            []], // Penawaran upsell bertarget di dashboard member
    '#^/transactions/([A-Za-z0-9_-]+)$#'          => ['pages/user/transaction-detail.php', ['order_number' => 1]],
    '#^/member$#'                                  => ['pages/user/member-area.php',        []],
    '#^/member/voucher-tes$#'                       => ['pages/user/stifin-vouchers.php',     []],
    '#^/admin/promotor-cabang$#'                   => ['pages/admin/stifin-promoters.php',   []],
    '#^/admin/kantor-cabang$#'                     => ['pages/admin/stifin-office.php',      []],
    '#^/admin/peserta-tes$#'                       => ['pages/admin/stifin-participants.php', []],
    '#^/admin/data-resmi-cabang$#'                 => ['pages/admin/stifin-official-reports.php', []],
    '#^/admin/laporan-cabang$#'                    => ['pages/admin/stifin-reports.php', []],
    '#^/member/stifin-saya$#'                      => ['pages/user/stifin-office.php', []],
    '#^/admin/voucher-tes$#'                        => ['pages/admin/stifin-vouchers.php',    []],
    '#^/member/my-licenses$#'                      => ['pages/user/my-licenses.php',        []],
    '#^/agency-seo$#'                              => ['pages/user/agency-seo.php',         []], // Portal Agency SEO (vendor-only)
    '#^/member/lm/([0-9]+)/material/([0-9]+)$#'   => ['pages/user/material-view.php',      ['lead_magnet_id' => 1, 'material_id' => 2]],
    '#^/member/lm/([0-9]+)$#'                      => ['pages/user/class-detail.php',       ['lead_magnet_id' => 1]],
    '#^/member/([0-9]+)/material/([0-9]+)$#'      => ['pages/user/material-view.php',      ['product_id' => 1, 'material_id' => 2]],
    '#^/member/([0-9]+)$#'                         => ['pages/user/class-detail.php',       ['product_id' => 1]],
    '#^/profile$#'                                 => ['pages/user/profile.php',            []],
    '#^/profile/2fa$#'                             => ['pages/user/profile-2fa.php',        []],
    '#^/affiliate$#'                               => ['pages/user/affiliate.php',          []],
    '#^/reseller$#'                                => ['pages/user/reseller.php',           []],
    '#^/jv-earnings$#'                              => ['pages/user/jv-earnings.php',        []],
    '#^/downloads$#'                               => ['pages/user/downloads.php',          []],
    '#^/download/([a-f0-9]+)$#'                    => ['pages/public/serve-download.php',   ['token' => 1]],
    '#^/certificates$#'                            => ['pages/user/certificates.php',       []],
    '#^/certificate/([a-f0-9\-]+)$#'              => ['pages/public/certificate.php',      ['uuid' => 1]],
    '#^/bantuan$#'                                 => ['pages/user/tickets.php',            []],
    '#^/bantuan/([0-9]+)$#'                        => ['pages/user/ticket-detail.php',      ['id' => 1]],
    '#^/panduan$#'                                 => ['pages/user/docs.php',               []],

    // ── Area Admin ────────────────────────────────────────────
    '#^/admin$#'                                   => ['pages/admin/dashboard.php',           []],
    '#^/admin/dashboard$#'                         => ['pages/admin/dashboard.php',           []],
    '#^/admin/users$#'                             => ['pages/admin/users.php',               []],
    '#^/admin/subscriptions$#'                     => ['pages/admin/subscriptions.php',       []],
    '#^/admin/staff$#'                             => ['pages/admin/staff.php',               []],
    '#^/admin/no-access$#'                          => ['pages/admin/no-access.php',           []],
    '#^/admin/products$#'                          => ['pages/admin/products.php',            []],
    '#^/admin/affiliate-displays$#'                => ['pages/admin/affiliate-displays.php',  []],
    '#^/admin/canvas$#'                            => ['pages/admin/canvas.php',              []],
    '#^/admin/canvas/stats$#'                      => ['pages/admin/canvas-rotator-stats.php', []],
    '#^/admin/downloads$#'                         => ['pages/admin/downloads.php',          []],
    '#^/admin/certificates$#'                      => ['pages/admin/certificates.php',       []],
    '#^/admin/reviews$#'                            => ['pages/admin/reviews.php',             []],
    '#^/admin/bundles$#'                            => ['pages/admin/bundles.php',             []],
    '#^/admin/bundles/edit$#'                       => ['pages/admin/bundle-form.php',         []],
    '#^/admin/email-sequences$#'                   => ['pages/admin/email-sequences.php',     []],
    '#^/admin/email-sequence-steps$#'              => ['pages/admin/email-sequence-steps.php', []],
    '#^/admin/abandoned-carts$#'                   => ['pages/admin/abandoned-carts.php',      []],
    '#^/admin/transactions$#'                      => ['pages/admin/transactions.php',        []],
    '#^/admin/transactions/([A-Za-z0-9_-]+)$#'     => ['pages/admin/transaction-detail.php',  ['order_number' => 1]],
    '#^/admin/coupons$#'                           => ['pages/admin/coupons.php',             []],
    '#^/admin/membership-levels$#'                 => ['pages/admin/membership-levels.php',   []],
    '#^/admin/leaderboard$#'                       => ['pages/admin/leaderboard.php',         []],
    '#^/admin/affiliate-finance$#'                 => ['pages/admin/affiliate-finance.php',   []],
    '#^/admin/commissions$#'                       => ['pages/admin/commissions.php',         []],
    '#^/admin/payouts$#'                            => ['pages/admin/payouts.php',             []],
    '#^/admin/jv-partner$#'                         => ['pages/admin/jv-partner.php',           []],
    '#^/admin/commission-rules$#'                  => ['pages/admin/commission-rules.php',    []],
    '#^/admin/product-bumps$#'                     => ['pages/admin/product-bumps.php',       []],
    '#^/admin/flash-bonus$#'                       => ['pages/admin/flash-bonus.php',         []],
    '#^/admin/upsells$#'                           => ['pages/admin/upsells.php',             []],
    '#^/admin/content$#'                           => ['pages/admin/content.php',             []],
    '#^/admin/kajian$#' => ['pages/admin/kajian.php', []],
    '#^/kajian$#' => ['pages/user/kajian.php', []],
    // Preview konten (admin/staff): render template member asli, read-only.
    '#^/admin/content/(preview)/lm/([0-9]+)/material/([0-9]+)$#' => ['pages/user/material-view.php', ['preview' => 1, 'lead_magnet_id' => 2, 'material_id' => 3]],
    '#^/admin/content/(preview)/lm/([0-9]+)$#'                   => ['pages/user/class-detail.php',  ['preview' => 1, 'lead_magnet_id' => 2]],
    '#^/admin/content/(preview)/([0-9]+)/material/([0-9]+)$#'    => ['pages/user/material-view.php', ['preview' => 1, 'product_id' => 2, 'material_id' => 3]],
    '#^/admin/content/(preview)/([0-9]+)$#'                      => ['pages/user/class-detail.php',  ['preview' => 1, 'product_id' => 2]],
    '#^/admin/class-categories$#'                  => ['pages/admin/class-categories.php',    []],
    '#^/admin/media$#'                             => ['pages/admin/media.php',               []],
    '#^/admin/checkout-templates$#'                => ['pages/admin/checkout-templates.php',  []],
    '#^/admin/notifications$#'                     => ['pages/admin/notifications.php',       []],
    '#^/admin/branding$#'                          => ['pages/admin/branding.php',            []],
    '#^/admin/settings$#'                          => ['pages/admin/settings.php',            []],
    '#^/admin/integrations$#'                      => ['pages/admin/integrations.php',        []],
    '#^/admin/hermes-agent$#'                      => ['pages/admin/hermes-agent.php',        []],
    '#^/admin/webhooks$#'                          => ['pages/admin/webhooks.php',            []],
    '#^/admin/studio-integration$#'                => ['pages/admin/studio-integration.php',  []],
    '#^/admin/mcp-integration$#'                   => ['pages/admin/mcp-integration.php',     []],
    // Antrean Lisensi Vendor — halamannya sendiri menolak bila bukan instalasi vendor.
    '#^/admin/license-fulfillments$#'              => ['pages/admin/license-fulfillments.php', []],
    '#^/admin/ai-chat$#'                           => ['pages/admin/ai-chat.php',             []],
    '#^/admin/bank-accounts$#'                     => ['pages/admin/bank-accounts.php',       []],
    '#^/admin/tracking-pixels$#'                   => ['pages/admin/tracking-pixels.php',     []],
    '#^/admin/social-proof$#'                      => ['pages/admin/social-proof.php',        []],
    '#^/admin/support-page$#'                      => ['pages/admin/support-page.php',        []],
    '#^/admin/tickets$#'                           => ['pages/admin/tickets.php',             []],
    '#^/admin/tickets/([0-9]+)$#'                  => ['pages/admin/ticket-detail.php',       ['id' => 1]],
    '#^/admin/info-member$#'                       => ['pages/admin/info-member.php',         []],
    '#^/admin/api-keys-overview$#'                 => ['pages/admin/api-keys-overview.php',   []],
    '#^/admin/broadcast$#'                         => ['pages/admin/broadcast.php',           []],
    '#^/admin/lead-magnets$#'                      => ['pages/admin/lead-magnets.php',        []],
    '#^/admin/funnel-gate$#'                       => ['pages/admin/funnel-gate.php',         []],
    '#^/admin/funnel-gate/edit$#'                  => ['pages/admin/funnel-gate-edit.php',    []],
    '#^/admin/funnel-gate/submissions$#'           => ['pages/admin/funnel-gate-submissions.php', []],
    '#^/admin/chat-leads$#'                        => ['pages/admin/chat-leads.php',          []],
    '#^/admin/waitlist$#'                          => ['pages/admin/waitlist.php',            []],
    '#^/admin/activity-logs$#'                     => ['pages/admin/activity-logs.php',       []],
    '#^/admin/funnel-analytics$#'                  => ['pages/admin/funnel-analytics.php',    []],
    '#^/admin/license$#'                           => ['pages/admin/license.php',             []],
    '#^/admin/license-activate$#'                  => ['pages/admin/license-activate.php',    []],
    '#^/admin/licenses$#'                          => ['pages/admin/licenses.php',            []],
    '#^/admin/licenses/([0-9]+)$#'                 => ['pages/admin/license-detail.php',      ['id' => 1]],
    '#^/admin/update$#'                            => ['pages/admin/update.php',              []],
    '#^/admin/changelog$#'                         => ['pages/admin/changelog.php',           []],
    '#^/admin/backups$#'                           => ['pages/admin/backups.php',             []],
    '#^/admin/data-import$#'                        => ['pages/admin/data-import.php',         []],
    '#^/admin/system-health$#'                     => ['pages/admin/system-health.php',       []],
    '#^/admin/docs$#'                              => ['pages/admin/docs.php',                []],

    // ── Actions: Auth (eksplisit) ─────────────────────────────
    '#^/actions/auth/login$#'                      => ['actions/auth/login.php',              []],
    '#^/actions/auth/register$#'                   => ['actions/auth/register.php',           []],
    '#^/actions/auth/forgot-password$#'            => ['actions/auth/forgot-password.php',    []],
    '#^/actions/auth/reset-password$#'             => ['actions/auth/reset-password.php',     []],
    '#^/actions/auth/logout$#'                     => ['actions/auth/logout.php',             []],
    '#^/actions/auth/request-otp$#'               => ['actions/auth/request-otp.php',        []],
    '#^/actions/auth/verify-otp$#'                => ['actions/auth/verify-otp.php',         []],
    '#^/actions/auth/verify-2fa$#'                => ['actions/auth/verify-2fa.php',         []],

    // ── Actions: Checkout (eksplisit) ─────────────────────────
    '#^/actions/checkout/process$#'               => ['actions/checkout/process.php',         []],
    '#^/actions/checkout/validate-coupon$#'       => ['actions/checkout/validate-coupon.php', []],
    '#^/actions/checkout/create-payment$#'        => ['actions/checkout/create-payment.php',  []],
    '#^/actions/checkout/paypal-return$#'         => ['actions/checkout/paypal-return.php',    []],

    // ── Actions: AI Chat (publik, rate-limited, TANPA CSRF) ───
    '#^/actions/chat/send$#'                      => ['actions/chat/send.php',                []],
    '#^/actions/chat/stream$#'                    => ['actions/chat/stream.php',              []],
    '#^/actions/chat/lead$#'                      => ['actions/chat/lead.php',                []],

    // ── Actions: Admin (dinamis) — nama file dari URL ─────────
    '#^/actions/admin/([a-z][a-z0-9-]*)$#'        => ['actions/admin/{1}.php', []],

    // ── Actions: User (dinamis) ───────────────────────────────
    '#^/actions/user/([a-z][a-z0-9-]*)$#'         => ['actions/user/{1}.php',  []],

    // ── Actions: Public (dinamis) ─────────────────────────────
    '#^/actions/public/([a-z][a-z0-9-]*)$#'       => ['actions/public/{1}.php', []],

    // ── Webhooks (dinamis) ────────────────────────────────────
    '#^/webhooks/([a-z][a-z0-9-]*)$#'             => ['webhooks/{1}.php', []],

    // ── Cron (token-protected) ────────────────────────────────
    '#^/cron/process$#'                            => ['cron/process.php', []],

    // ── Public API: License (TANPA login & TANPA CSRF) ────────
    // Dipanggil oleh software pihak ketiga milik buyer untuk
    // aktivasi/validasi/deaktivasi license key produk digital.
    '#^/api/license/activate$#'                    => ['api/license/activate.php',   []],
    '#^/api/license/validate$#'                    => ['api/license/validate.php',   []],
    '#^/api/license/deactivate$#'                  => ['api/license/deactivate.php', []],

    // ── Public API: Averion Studio (Bearer token, TANPA login & TANPA CSRF) ──
    // Dipanggil Averion Studio untuk membaca daftar produk aktif (read-only).
    '#^/api/studio/products$#'                     => ['api/studio/products.php', []],

    // ── Integrasi Claude (MCP) — endpoint protokol Streamable HTTP (Bearer token,
    //    TANPA login/CSRF). Konektor Claude.ai POST JSON-RPC ke sini. ──
    '#^/api/mcp$#'                                 => ['api/mcp.php', []],

    // ── Hermes Agent: Ingest API (Bearer token, TANPA login/CSRF) ──
    '#^/api/hermes/capabilities$#'                 => ['api/hermes/capabilities.php', []],
    '#^/api/hermes/email-sequences$#'              => ['api/hermes/email-sequences.php', []],
    '#^/api/hermes/funnel-gate$#'                  => ['api/hermes/funnel-gate.php', []],
    '#^/api/hermes/offers$#'                       => ['api/hermes/offers.php', []],
    '#^/api/hermes/broadcast$#'                    => ['api/hermes/broadcast.php', []],
    '#^/api/hermes/insights$#'                     => ['api/hermes/insights.php', []],
];

// ── CANVAS ROUTING: Homepage override ─────────────────────────
// Jika ada Canvas yang ditandai homepage & aktif, ia menggantikan
// halaman produk default di '/'. renderCanvas() echo lalu exit().
if ($requestPath === '/' || $requestPath === '') {
    try {
        $st = getDB()->prepare(
            "SELECT * FROM canvas_pages WHERE is_homepage = 1 AND is_active = 1 LIMIT 1"
        );
        $st->execute();
        $homepageCanvas = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $homepageCanvas = null; // tabel belum ada / DB error → lanjut ke default
    }
    if ($homepageCanvas) {
        require_once __DIR__ . '/pages/public/canvas-render.php';
        renderCanvas($homepageCanvas); // exit di dalam
    }
}

// ─── Dispatch ─────────────────────────────────────────────────
foreach ($routes as $pattern => [$fileTemplate, $params]) {
    if (!preg_match($pattern, $requestPath, $m)) {
        continue;
    }

    // Selesaikan {1}, {2}, … dalam file template (untuk route dinamis)
    $file = preg_replace_callback(
        '/\{(\d+)\}/',
        fn($x) => $m[(int) $x[1]] ?? '',
        $fileTemplate
    );

    // Inject URL params ke $_GET
    foreach ($params as $key => $idx) {
        $_GET[$key] = $m[$idx];
    }

    $fullPath = __DIR__ . '/' . $file;
    if (file_exists($fullPath)) {
        require $fullPath;
    } else {
        http_response_code(404);
        echo '404 — Halaman tidak ditemukan.';
    }
    exit;
}

// ── CANVAS ROUTING: Standalone slug ───────────────────────────
// Tidak ada route inti yang cocok. Coba resolusikan segmen pertama
// sebagai slug Canvas (mis. /sales-page). Core route sudah di-handle
// di atas, jadi di sini hanya slug bebas yang sampai.
$firstSegment = strtolower(explode('/', ltrim($requestPath, '/'))[0] ?? '');
$coreRoutes   = ['admin', 'dashboard', 'login', 'register', 'logout', 'checkout',
                 'products', 'member', 'affiliate', 'api', 'assets', 'actions',
                 'pages', 'uploads', 'webhook', 'webhooks', 'cron', 'go', 'r', 'bt',
                 'auth', 'transactions', 'profile', 'login-otp', 'license-expired', 'lp',
                 'apply', 'bundles', 'invoice', 'certificate', 'certificates', 'downloads', 'download', 'bantuan', 'order', 'on', 'reseller', 'akses', 'akses-file', 'akses-cepat', 'unsubscribe',
                 'katalog', 'rewards', 'agency-seo', 'panduan'];

// ── HALAMAN DUKUNG (Bayar Fleksibel Fase 2) — slug configurable ─
// Halaman support turnkey milik admin (satu per situs). Diaktifkan via setting
// support_page_enabled; slug diatur support_page_slug (default 'dukung'). Dicek
// sebelum resolusi Canvas standalone agar diprioritaskan & tak bentrok reserved.
if (getSetting('support_page_enabled', '0') === '1') {
    $supSlug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string) getSetting('support_page_slug', 'dukung')))) ?: 'dukung';
    if ($firstSegment === $supSlug && $requestPath === '/' . $supSlug && !in_array($supSlug, $coreRoutes, true)) {
        require __DIR__ . '/pages/public/support-page.php';
        exit;
    }
}

if ($firstSegment !== '' && !in_array($firstSegment, $coreRoutes, true)) {
    try {
        $st = getDB()->prepare(
            "SELECT * FROM canvas_pages WHERE slug = ? LIMIT 1"
        );
        $st->execute([$firstSegment]);
        $canvasPage = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $canvasPage = null;
    }

    if ($canvasPage) {
        if ((int)($canvasPage['is_active'] ?? 0) === 0) {
            // Canvas ditemukan tapi dinonaktifkan → redirect ke homepage.
            header('Location: ' . APP_URL . '/', true, 302);
            exit;
        }
        require_once __DIR__ . '/pages/public/canvas-render.php';
        renderCanvas($canvasPage); // exit di dalam
    }
}

// ─── Tidak ada route yang cocok → 404 ────────────────────────
http_response_code(404);
$page404 = __DIR__ . '/pages/public/404.php';
file_exists($page404) ? require $page404 : print('404 — Halaman tidak ditemukan.');

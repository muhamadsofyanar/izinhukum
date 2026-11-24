<?php
require_once __DIR__ . '/../../bootstrap.php';

// Sanitasi input
$affiliateCode = preg_replace('/[^A-Z0-9]/', '', strtoupper($_GET['code'] ?? ''));
$productSlug   = preg_replace('/[^a-z0-9-]/', '', strtolower($_GET['slug'] ?? ''));

// Tanpa slug produk yang valid, tidak ada tujuan jelas → kembali ke katalog.
if ($productSlug === '') {
    redirect('/products');
}

// Guard: kode afiliasi kosong/tidak valid (mis. user dengan affiliate_code
// kosong menghasilkan link /go//slug). Langsung ke halaman produk TANPA query DB.
if (empty($affiliateCode) || !preg_match('/^[A-Z0-9]{4,20}$/i', $affiliateCode)) {
    redirect('/products/' . $productSlug);
    exit;
}

try {
    $pdo = getDB();

    // Validasi affiliate aktif
    $stmt = $pdo->prepare("SELECT id, code FROM affiliates WHERE code = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$affiliateCode]);
    $affiliate = $stmt->fetch();

    // Ambil data produk
    $stmt = $pdo->prepare("SELECT id, slug, landing_page_url, lp_type, canvas_id, status FROM products WHERE slug = ? LIMIT 1");
    $stmt->execute([$productSlug]);
    $product = $stmt->fetch();

    if (!$product || $product['status'] !== 'active') {
        // Produk tidak ditemukan/non-aktif — simpan referral jika valid, redirect ke katalog
        if ($affiliate) {
            setAffiliateCookie($affiliate['code']);
        }
        flash('info', 'Produk tidak ditemukan. Berikut katalog produk kami.');
        redirect('/products');
    }

    // Simpan referral ke session + cookie (tanpa expose kode di URL eksternal)
    if ($affiliate) {
        setAffiliateCookie($affiliate['code']);

        // Catat klik untuk analytics (opsional, gagal tidak bloking)
        try {
            $clickStmt = $pdo->prepare(
                "INSERT INTO referrals (affiliate_id, referred_user_id, status, created_at)
                 SELECT ?, NULL, 'click', NOW()
                 FROM DUAL
                 WHERE (SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'referrals'
                          AND COLUMN_NAME = 'referred_user_id'
                          AND IS_NULLABLE = 'YES') > 0"
            );
            $clickStmt->execute([$affiliate['id']]);
        } catch (Exception $ignored) {
            // Click tracking adalah fitur opsional, tidak bloking redirect
        }
    }

    $lpType = $product['lp_type'] ?? 'none';

    // ── lp_type = canvas → resolve slug Canvas lalu redirect ke /slug/ ─
    if ($lpType === 'canvas' && !empty($product['canvas_id'])) {
        $canvasStmt = $pdo->prepare(
            "SELECT slug FROM canvas_pages WHERE id = ? AND is_active = 1 LIMIT 1"
        );
        $canvasStmt->execute([(int) $product['canvas_id']]);
        $canvas = $canvasStmt->fetch();
        if ($canvas) {
            // Funnel tracking ditangani saat Canvas product_landing dirender.
            header('Location: ' . rtrim(APP_URL, '/') . '/' . $canvas['slug'] . '/', true, 302);
            exit;
        }
        // Canvas tidak aktif/tidak ada → fallback ke halaman produk.
        redirect('/products/' . $product['slug']);
    }

    // ── lp_type = external → redirect ke URL eksternal (behaviour lama) ─
    if ($lpType === 'external' && !empty($product['landing_page_url'])) {
        $url    = $product['landing_page_url'];
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (filter_var($url, FILTER_VALIDATE_URL) && in_array($scheme, ['http', 'https'], true)) {
            // Funnel tracking: lp_view untuk produk dengan LP eksternal
            // Dilakukan SEBELUM redirect — ini proxy representatif "niat masuk LP"
            // Admin/staff & bot di-exclude — kunjungan sendiri/non-manusia bukan visitor.
            if (!isAdminOrStaff() && !isBotUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '')) try {
                $sid       = session_id();
                $ua        = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
                $chkStmt   = $pdo->prepare(
                    "SELECT 1 FROM funnel_events
                     WHERE product_id = ? AND event_type = 'lp_view' AND session_id = ?
                     AND DATE(created_at) = CURDATE() LIMIT 1"
                );
                $chkStmt->execute([(int)$product['id'], $sid]);
                if (!$chkStmt->fetch()) {
                    $pdo->prepare(
                        "INSERT INTO funnel_events (product_id, event_type, session_id, user_agent) VALUES (?, 'lp_view', ?, ?)"
                    )->execute([(int)$product['id'], $sid, $ua]);
                }
            } catch (Exception $ignored) {}

            // Meta CAPI corong: ViewContent. Di LP eksternal browser TAK bisa
            // memasang pixel (halaman bukan milik kita) — CAPI satu-satunya jalan
            // Meta melihat tahap ini. Di-antre → auto-cron (tak menahan redirect).
            if (!isAdminOrStaff()) {
                require_once __DIR__ . '/../../tracking/PixelHelper.php';
                PixelHelper::trackFunnelServerSide('view', (int) $product['id'], [
                    'value'      => (float) ($product['price'] ?? 0),
                    'source_url' => $url,
                ]);
            }

            header('Location: ' . $url, true, 302);
            exit;
        }
    }

    // Fallback: redirect ke halaman produk internal
    redirect('/products/' . $product['slug']);

} catch (Exception $e) {
    error_log('Affiliate landing redirect error: ' . $e->getMessage());
    redirect('/products');
}

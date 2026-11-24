<?php
// ════════════════════════════════════════════════════════════════
// Averion — Canvas Renderer
// PENTING: file ini TIDAK meng-include partial apapun. Ia meng-echo
// raw HTML lalu exit. Dipanggil dari index.php (routing Canvas).
//
// renderCanvas() bertanggung jawab:
//   1. Funnel tracking (hanya product_landing + linked_product_id)
//   2. Inject pixel per-halaman (Meta / TikTok) ke dalam <head>
//   3. Serve konten — mode 'embed' (dari DB) atau 'upload' (dari disk)
// ════════════════════════════════════════════════════════════════

// APP_ROOT = folder root averion/ (pages/public/ → naik 2 level)
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}

if (!function_exists('renderCanvas')) {

/**
 * Kirim HTML Canvas dengan cache + kompresi, lalu exit().
 *
 * Latar: session_start() membuat PHP mengirim "Cache-Control: no-store" +
 * "Expires: 1981" — landing page statis jadi diunduh penuh di SETIAP kunjungan.
 * Dan mod_deflate tidak bisa diandalkan di hosting buyer, jadi HTML 50KB+
 * terkirim tanpa kompresi. Fungsi ini membereskan keduanya di level PHP
 * (jalan di hosting mana pun):
 *   1. ETag + Last-Modified → kunjungan ulang cukup 304 (nol byte body)
 *   2. Cache-Control public 5 menit (edit dari admin tetap cepat terlihat)
 *   3. gzip via ob_gzhandler (browser tanpa dukungan tetap dapat plain)
 */
function canvasOutput(string $html, int $lastModTs, bool $noStore = false): void
{
    header('Content-Type: text/html; charset=utf-8');

    // Jika halaman baru saja di-update (dalam 60 detik terakhir) atau diakses admin/staff,
    // atau di-bypass via ?nocache=1, paksa no-store / no-cache agar perubahan instan terlihat.
    $isRecentlyUpdated = (time() - $lastModTs < 60);
    $forceFresh        = $noStore || $isRecentlyUpdated || isAdminOrStaff() || isset($_GET['nocache']);

    if ($forceFresh) {
        // Rotator/Split Test atau update baru / admin preview:
        // WAJIB no-store — hindari cache stale browser/CDN/proxy.
        header('Cache-Control: private, no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    } else {
        $etag = '"cv-' . md5($html) . '"';
        // Timpa header anti-cache bawaan session.
        header('Cache-Control: public, max-age=300');
        header('Pragma: cache');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 300) . ' GMT');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModTs) . ' GMT');

        // Kunjungan ulang: konten belum berubah → 304 tanpa body.
        $ifNone = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        $ifMod  = trim($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
        if (($ifNone !== '' && $ifNone === $etag)
            || ($ifNone === '' && $ifMod !== '' && strtotime($ifMod) >= $lastModTs)) {
            http_response_code(304);
            exit;
        }
    }

    // Kompresi PHP-level via gzencode langsung — tidak bergantung state
    // output-buffering (php.ini output_buffering membuat ob_gzhandler tak
    // bisa dipakai andal). Server yang ikut kompres (mod_deflate/nginx)
    // melewati respons yang sudah ber-Content-Encoding — tidak dobel.
    header('Vary: Accept-Encoding');
    $acceptsGzip = stripos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false;
    if ($acceptsGzip && extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
        while (ob_get_level() > 0) { ob_end_clean(); } // buang buffer default php.ini
        $gz = gzencode($html, 6);
        if ($gz !== false) {
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($gz));
            echo $gz;
            exit;
        }
    }

    echo $html;
    exit;
}

/**
 * Inject parameter cache-busting (?v=timestamp) ke aset relatif/lokal
 * (<link rel="stylesheet">, <script src="...">, <img>) dalam HTML Canvas.
 */
/**
 * Suntik potongan HTML (mis. widget AI Chat) tepat sebelum </body>. Fallback:
 * sebelum </html>, lalu append. Dipakai semua mode Canvas (embed/builder/upload).
 */
function canvasInjectBeforeBody(string $html, string $snippet): string
{
    if ($snippet === '') return $html;
    if (stripos($html, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $snippet . '</body>', $html, 1);
    }
    if (stripos($html, '</html>') !== false) {
        return preg_replace('/<\/html>/i', $snippet . '</html>', $html, 1);
    }
    return $html . $snippet;
}

/**
 * Bangun HTML widget AI Chat untuk sebuah Canvas yang mengaktifkannya per-halaman.
 * Mengembalikan '' bila canvas tak mengaktifkan atau AI Chat global mati. $focusPid
 * = produk tertaut halaman (0 = tak ada → widget pakai katalog umum).
 */
function canvasBuildChatWidget(array $page, int $focusPid): string
{
    if (empty($page['ai_chat_enabled'])) return '';
    if (getSetting('ai_chat_enabled') !== '1') return '';   // master switch global
    $GLOBALS['__av_chat_force']     = true;
    $GLOBALS['__av_chat_focus_pid'] = $focusPid;
    ob_start();
    include __DIR__ . '/../../partials/ai-chat-widget.php';
    $html = (string) ob_get_clean();
    $GLOBALS['__av_chat_force']     = false;
    $GLOBALS['__av_chat_focus_pid'] = 0;
    return $html;
}

function canvasInjectCacheBuster(string $html, int $version): string
{
    if ($version <= 0 || trim($html) === '') {
        return $html;
    }

    return preg_replace_callback(
        '/(href|src)=["\'](?!(?:https?:|\/\/|data:|#|javascript:))([^"\']+)["\']/i',
        function ($matches) use ($version) {
            $attr = $matches[1];
            $url  = $matches[2];

            // Jangan sentuh jika sudah ada ?v= atau &v=
            if (preg_match('/[?&]v=/i', $url)) {
                return $matches[0];
            }

            $sep    = (strpos($url, '?') !== false) ? '&' : '?';
            $newUrl = $url . $sep . 'v=' . $version;
            return $attr . '="' . $newUrl . '"';
        },
        $html
    ) ?? $html;
}

/**
 * Parse daftar ID pixel dipisah koma → array ID valid (unik, tervalidasi).
 * Dipakai untuk multi-pixel per-halaman (Meta / TikTok).
 */
function canvasPixelIds(string $raw): array
{
    $ids = array_map('trim', explode(',', $raw));
    $ids = array_filter($ids, fn($x) => $x !== '' && preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $x));
    return array_values(array_unique($ids));
}

/**
 * Bangun skrip pixel per-halaman (Meta + TikTok) dari baris canvas.
 * Dipakai bersama render normal & interstitial redirect.
 *
 * @param array $vc Konteks ViewContent (opsional). Bila berisi ['event_id','product_id','value']
 *   → selain PageView, pixel juga memicu event ViewContent untuk produk tertaut (canvas sales
 *   page). event_id STABIL (skema funnelEventId) agar bisa di-dedup dgn CAPI. Kosong = PageView
 *   saja (mis. canvas redirect/non-produk) — perilaku lama tak berubah.
 */
function canvasPixelScripts(array $page, array $vc = []): string
{
    // Rakit event ViewContent (Meta & TikTok) bila ada produk tertaut.
    $metaVc = ''; $ttVc = '';
    if (!empty($vc['event_id']) && !empty($vc['product_id'])) {
        $pidJson = json_encode((string) $vc['product_id']);
        $eidJson = json_encode((string) $vc['event_id']);
        $val     = (float) ($vc['value'] ?? 0);
        // Meta ViewContent: content_ids + (opsional) value/currency; eventID utk dedup CAPI.
        $mcd = "{content_type:'product',content_ids:[{$pidJson}]";
        if ($val > 0) $mcd .= ",value:" . json_encode($val) . ",currency:'IDR'";
        $mcd .= "}";
        $metaVc = "fbq('track','ViewContent',{$mcd},{eventID:{$eidJson}});";
        // TikTok ViewContent: contents[] + (opsional) value/currency; event_id utk dedup CAPI.
        $tcd = "{contents:[{content_id:{$pidJson},content_type:'product'}]";
        if ($val > 0) $tcd .= ",value:" . json_encode($val) . ",currency:'IDR'";
        $tcd .= "}";
        $ttVc = "ttq.track('ViewContent',{$tcd},{event_id:{$eidJson}});";
    }

    $pixelScripts = '';
    // Meta: dukung >1 pixel ID (dipisah koma) → satu loader, banyak fbq('init').
    $metaIds = canvasPixelIds((string) ($page['meta_pixel_id'] ?? ''));
    if ($metaIds) {
        $initJs = ''; $noscript = '';
        foreach ($metaIds as $mid) {
            $e = htmlspecialchars($mid, ENT_QUOTES);
            $initJs   .= "fbq('init','{$e}');";
            $noscript .= '<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=' . $e . '&ev=PageView&noscript=1"/></noscript>';
        }
        $pixelScripts .= <<<HTML
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
document,'script','https://connect.facebook.net/en_US/fbevents.js');
{$initJs}fbq('track','PageView');{$metaVc}
</script>
{$noscript}
HTML;
    }
    // TikTok: dukung >1 pixel ID (dipisah koma) → satu loader, banyak ttq.load().
    $tiktokIds = canvasPixelIds((string) ($page['tiktok_pixel_id'] ?? ''));
    if ($tiktokIds) {
        $loadJs = '';
        foreach ($tiktokIds as $tid) { $loadJs .= "ttq.load('" . htmlspecialchars($tid, ENT_QUOTES) . "');"; }
        $pixelScripts .= <<<HTML
<script>
!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];
ttq.methods=['page','track','identify','instances','debug','on','off','once',
'ready','alias','group','enableCookie','disableCookie'];
ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(
Array.prototype.slice.call(arguments,0)))}};
for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);
ttq.load=function(e,n){var i='https://analytics.tiktok.com/i18n/pixel/events.js';
ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},
ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};
var o=document.createElement('script');o.type='text/javascript',o.async=!0,
o.src=i+'?sdkid='+e+'&lib='+t;
var a=document.getElementsByTagName('script')[0];
a.parentNode.insertBefore(o,a)};
{$loadJs}ttq.page();{$ttVc}
}(window,document,'ttq');
</script>
HTML;
    }
    return $pixelScripts;
}

/**
 * Validasi & normalisasi URL target redirect. Hanya http(s). Return '' bila invalid.
 */
function canvasRedirectTarget(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) return '';
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return '';
    return $url;
}

/**
 * Canvas tipe 'redirect': teruskan pengunjung ke redirect_url. Bila ada pixel →
 * render interstitial (fire pixel) lalu redirect via JS; bila tidak → 302 langsung.
 * Selalu exit().
 */
function renderCanvasRedirect(array $page): void
{
    $target = canvasRedirectTarget((string) ($page['redirect_url'] ?? ''));

    // Belum dikonfigurasi / target invalid → halaman info (bukan redirect).
    if ($target === '') {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Belum dikonfigurasi</title>'
           . '<style>body{font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;color:#111827;display:flex;min-height:100vh;margin:0;align-items:center;justify-content:center}.b{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:28px 32px;max-width:420px;text-align:center}</style>'
           . '</head><body><div class="b"><h1 style="font-size:18px;margin:0 0 8px">Halaman belum dikonfigurasi</h1>'
           . '<p style="font-size:14px;color:#6b7280;margin:0">URL tujuan redirect belum diisi. Silakan atur di panel admin.</p></div></body></html>';
        exit;
    }

    // Teruskan query string pengunjung (opsional) → gabung pintar dengan query target.
    if ((int) ($page['redirect_keep_query'] ?? 1) === 1) {
        $incoming = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($incoming !== '') {
            $target .= (strpos($target, '?') !== false ? '&' : '?') . $incoming;
        }
    }

    $pixels = canvasPixelScripts($page);

    // Tanpa pixel → redirect server 302 langsung (paling cepat, tanpa render).
    if (trim($pixels) === '') {
        header('Location: ' . $target, true, 302);
        header('Referrer-Policy: no-referrer-when-downgrade');
        exit;
    }

    // Dengan pixel → interstitial kilat: fire pixel lalu redirect (JS + noscript fallback).
    $targetJs   = json_encode($target, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
    $targetAttr = htmlspecialchars($target, ENT_QUOTES);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Mengalihkan…</title>
{$pixels}
<noscript><meta http-equiv="refresh" content="0;url={$targetAttr}"></noscript>
<style>body{font-family:Arial,Helvetica,sans-serif;background:#f8fafc;color:#64748b;display:flex;min-height:100vh;margin:0;align-items:center;justify-content:center;font-size:14px}</style>
</head><body>
<p>Mengalihkan…</p>
<script>setTimeout(function(){location.replace({$targetJs});},80);</script>
</body></html>
HTML;
    exit;
}

/**
 * Render satu Canvas page ke output lalu exit().
 *
 * @param array $page Baris canvas_pages (FETCH_ASSOC).
 */
function renderCanvas(array $page, bool $noStore = false): void
{
    // ─── Redirect: teruskan ke URL lain (short-link/cloaking) ─────
    if (($page['page_type'] ?? '') === 'redirect') {
        renderCanvasRedirect($page);
        return; // renderCanvasRedirect() sudah exit.
    }

    // ─── Rotator / Split Test ─────────────────────────────────────
    // Tipe halaman 'rotator' tak punya konten sendiri — ia memilih 1 varian
    // (sticky per pengunjung) lalu me-render konten varian itu. Delegasi &
    // exit di dalam renderRotator().
    if (($page['page_type'] ?? '') === 'rotator') {
        renderRotator($page);
        return; // renderRotator() sudah exit; return = jaga-jaga.
    }

    // ─── Funnel tracking ──────────────────────────────────────────
    // Hanya untuk Canvas bertipe product_landing yang tertaut ke produk.
    // Gagal tracking TIDAK boleh menghentikan render halaman.
    // Admin/staff di-exclude dari funnel — kunjungan sendiri bukan visitor.
    $hbProductId = 0; // produk utama utk heartbeat presence (0 = tak ada / admin / bot)
    // Bot/crawler/preview di-exclude dari funnel & presence — bukan visitor manusia.
    if (($page['page_type'] ?? '') === 'product_landing' && !isAdminOrStaff()
        && !isBotUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        try {
            $pdo       = getDB();
            $sessionId = session_id() ?: bin2hex(random_bytes(8));
            $ua        = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

            // Semua produk tertaut (junction). Fallback ke kolom lama
            // linked_product_id bila junction belum ada / kosong (data pra-migrasi).
            $productIds = [];
            try {
                $ps = $pdo->prepare("SELECT product_id FROM canvas_page_products WHERE canvas_page_id = ?");
                $ps->execute([(int) $page['id']]);
                $productIds = array_map('intval', array_column($ps->fetchAll(), 'product_id'));
            } catch (Throwable $e) { /* tabel belum ada → pakai fallback */ }
            if (!$productIds && !empty($page['linked_product_id'])) {
                $productIds = [(int) $page['linked_product_id']];
            }
            $hbProductId = (int) ($productIds[0] ?? 0);

            if ($productIds) {
                // Dedup 1 view / sesi / hari PER PRODUK — pola SAMA dengan
                // product-detail.php & affiliate-landing-redirect.php.
                $check = $pdo->prepare(
                    "SELECT 1 FROM funnel_events
                     WHERE product_id = ? AND event_type = 'lp_view' AND session_id = ?
                     AND DATE(created_at) = CURDATE() LIMIT 1"
                );
                $insert = $pdo->prepare(
                    "INSERT INTO funnel_events (product_id, event_type, session_id, user_agent) VALUES (?, 'lp_view', ?, ?)"
                );
                foreach ($productIds as $productId) {
                    if ($productId <= 0) continue;
                    $check->execute([$productId, $sessionId]);
                    if (!$check->fetch()) $insert->execute([$productId, $sessionId, $ua]);
                }
            }
        } catch (Throwable $e) {
            error_log('Canvas funnel tracking error: ' . $e->getMessage());
            // Lanjut render — tracking opsional.
        }
    }

    // ─── Build pixel scripts (per-halaman, beda dari pixel global) ─
    // Canvas sales page dgn produk tertaut → pixel juga memicu ViewContent
    // (sebelumnya HANYA PageView). event_id stabil (skema funnelEventId) agar
    // bisa di-dedup dgn event CAPI. hbProductId sudah 0 utk admin/non-produk.
    $pixelVc = [];
    if ($hbProductId > 0) {
        $vcValue = 0.0;
        try {
            $vp = getDB()->prepare('SELECT price FROM products WHERE id = ?');
            $vp->execute([$hbProductId]);
            $vcValue = (float) $vp->fetchColumn();
        } catch (Throwable $e) { /* value opsional, ViewContent tetap jalan */ }
        $vcEventId = (class_exists('PixelHelper') || @include_once __DIR__ . '/../../tracking/PixelHelper.php')
            ? PixelHelper::funnelEventId('view', $hbProductId)
            : 'view_' . $hbProductId . '_' . substr(sha1(session_id() ?: 'nosess'), 0, 12) . '_' . date('Ymd');
        $pixelVc = ['event_id' => $vcEventId, 'product_id' => $hbProductId, 'value' => $vcValue];
    }
    $pixelScripts = canvasPixelScripts($page, $pixelVc);

    // Opsi A — cadangan CAPI server-side utk ViewContent Canvas (tahan ad-blocker,
    // menutup ~30-40% loss browser di iOS/adblock). Hanya jalan bila pixel Canvas
    // juga terdaftar (punya token) di Settings global & master CAPI aktif. event_id
    // SAMA dgn ViewContent browser ($pixelVc) → Meta dedup, tak dobel.
    if ($hbProductId > 0 && class_exists('PixelHelper')) {
        try {
            $canvasSrcUrl = rtrim(APP_URL, '/') . (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
            PixelHelper::trackCanvasViewServerSide(
                canvasPixelIds((string) ($page['meta_pixel_id'] ?? '')),
                $hbProductId,
                ['value' => $vcValue, 'source_url' => $canvasSrcUrl]
            );
        } catch (Throwable $e) { error_log('canvas CAPI view error: ' . $e->getMessage()); }
    }

    // Heartbeat presence: sales page canvas (produk utama tertaut) → ikut
    // disuntik lewat mekanisme $pixelScripts (head/body) sama seperti pixel.
    if ($hbProductId > 0) {
        $pixelScripts .= funnelHeartbeatScript('product', $hbProductId, 'salespage');
    }

    // ─── Widget AI Chat (opsional, per-canvas) ────────────────────
    // Produk fokus = produk tertaut halaman (prioritas junction via $hbProductId,
    // fallback kolom linked_product_id untuk tipe non-product_landing). 0 = katalog
    // umum. Widget disuntik sebelum </body> di tiap mode di bawah.
    $chatFocusPid = $hbProductId > 0 ? $hbProductId : (int) ($page['linked_product_id'] ?? 0);
    $chatWidget   = canvasBuildChatWidget($page, $chatFocusPid);

    // ─── Lepas lock sesi lebih awal (render publik non-form) ──────
    // session_start() menahan lock file-sesi sampai script selesai → request
    // paralel dari pengunjung yang SAMA (heartbeat presence, aset yang kebetulan
    // lewat PHP) ikut antre. Untuk render yang TIDAK menulis sesi, tutup sesi di
    // sini: semua nilai turunan-sesi (session_id utk funnel/pixel event_id) sudah
    // dibaca di atas; membaca $_SESSION tetap bisa setelah close. PENGECUALIAN:
    // builder dengan form lead menulis CSRF ke sesi di avLbRenderPage → jangan
    // ditutup (akan hilang token). Pola sama: memori ai-load-session-lock-cache.
    $needsSession = (($page['mode'] ?? '') === 'builder'
        && function_exists('avLbHasLeadForm') && avLbHasLeadForm($page));
    if (!$needsSession && session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }

    $updatedTs = strtotime((string) ($page['updated_at'] ?? '')) ?: time();

    // ─── Mode embed: konten dari kolom DB ─────────────────────────
    if (($page['mode'] ?? '') === 'embed') {
        $html = (string) ($page['content'] ?? '');
        $html = canvasInjectCacheBuster($html, $updatedTs);
        if ($pixelScripts !== '') {
            if (stripos($html, '</head>') !== false) {
                $html = str_ireplace('</head>', $pixelScripts . '</head>', $html);
            } elseif (stripos($html, '<body') !== false) {
                $html = preg_replace('/<body([^>]*)>/i', '<body$1>' . $pixelScripts, $html, 1);
            } else {
                $html = $pixelScripts . $html;
            }
        }
        $html = canvasInjectBeforeBody($html, $chatWidget);
        canvasOutput($html, $updatedTs, $noStore);
    }

    // ─── Mode builder: landing visual (block engine) dari JSON DB ──
    if (($page['mode'] ?? '') === 'builder' && function_exists('avLbRenderPage')) {
        // Blok form lead menyuntik token CSRF per-sesi → jangan di-cache publik
        // (token tiap pengunjung beda; cache = CSRF gagal / bocor antar-sesi).
        $builderNoStore = $noStore || (function_exists('avLbHasLeadForm') && avLbHasLeadForm($page));
        $html = avLbRenderPage($page);
        $html = canvasInjectCacheBuster($html, $updatedTs);
        if ($pixelScripts !== '') {
            if (stripos($html, '</head>') !== false) {
                $html = str_ireplace('</head>', $pixelScripts . '</head>', $html);
            } elseif (stripos($html, '<body') !== false) {
                $html = preg_replace('/<body([^>]*)>/i', '<body$1>' . $pixelScripts, $html, 1);
            } else {
                $html = $pixelScripts . $html;
            }
        }
        $html = canvasInjectBeforeBody($html, $chatWidget);
        canvasOutput($html, $updatedTs, $builderNoStore);
    }

    // ─── Mode upload: serve index.html dari disk ──────────────────
    $baseUpload = rtrim(APP_ROOT, '/\\') . '/assets/uploads/canvas/';
    $slug       = (string) ($page['slug'] ?? '');

    $candidates = [
        $baseUpload . $slug . '/index.html',
        $baseUpload . $slug . '/index.htm',
    ];
    $indexFile = null;
    foreach ($candidates as $c) {
        if (file_exists($c)) { $indexFile = $c; break; }
    }
    if (!$indexFile) {
        http_response_code(404);
        echo '404 Not Found';
        exit;
    }

    // Guard path traversal: file harus berada di dalam base upload canvas.
    $realBase = realpath($baseUpload);
    $realFile = realpath($indexFile);
    if (!$realBase || !$realFile || strpos($realFile, $realBase) !== 0) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }

    $html = file_get_contents($realFile);
    if ($html === false) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($realFile); // fallback bila gagal baca file
        exit;
    }

    // Hitung timestamp rilis/update (gabungan mtime file + DB updated_at)
    $fileModTs = filemtime($realFile) ?: time();
    $updatedTs = max($updatedTs, $fileModTs);

    // Auto Asset Cache-Busting (?v=timestamp) ke aset relatif/lokal
    $html = canvasInjectCacheBuster($html, $updatedTs);

    // Inject <base> agar aset RELATIF (CSS, JS, gambar, video, font) resolve ke
    // folder canvas — bukan URL slug (tanpa ini "style.css" di /{slug} → 404).
    // Slug tervalidasi [a-z0-9_-] saat publish, aman untuk atribut href.
    // EFEK SAMPING <base>: link #anchor ikut resolve ke URL base (jelek, mis.
    // /assets/uploads/canvas/{slug}/#pricing). Skrip kecil di bawah mengembalikan
    // link #anchor ke URL halaman yang bersih (mis. /{slug}#pricing) saat DOM siap.
    $baseTag  = '<base href="' . htmlspecialchars(rtrim(APP_URL, '/') . '/assets/uploads/canvas/' . $slug . '/', ENT_QUOTES) . '">';
    $baseTag .= '<script>addEventListener("DOMContentLoaded",function(){var p=location.pathname+location.search;document.querySelectorAll(\'a[href^="#"]\').forEach(function(a){var h=a.getAttribute("href");if(h&&h.charAt(0)==="#"&&h.length>1)a.setAttribute("href",p+h);});});</script>';
    if (preg_match('/<head[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos  = $m[0][1] + strlen($m[0][0]);
        $html = substr($html, 0, $pos) . $baseTag . substr($html, $pos);
    } else {
        $html = $baseTag . $html;
    }

    // Pixel per-halaman (Meta/TikTok) tepat sebelum </head>.
    if ($pixelScripts !== '') {
        if (stripos($html, '</head>') !== false) {
            $html = str_ireplace('</head>', $pixelScripts . '</head>', $html);
        } else {
            $html = $pixelScripts . $html;
        }
    }

    $html = canvasInjectBeforeBody($html, $chatWidget);
    canvasOutput($html, $updatedTs, $noStore);
}

/**
 * Render Canvas bertipe 'rotator' (Split Test): pilih 1 varian sticky per
 * pengunjung (cookie, weighted), catat impression, lalu render konten varian
 * itu via renderCanvas(..., noStore:true). Selalu exit().
 *
 * @param array $rotator Baris canvas_pages page_type='rotator'.
 */
function renderRotator(array $rotator): void
{
    $rotatorId = (int) ($rotator['id'] ?? 0);
    $pdo       = getDB();

    // Varian aktif yang canvas-nya juga ada & aktif & BUKAN rotator (hindari
    // rekursi). Urut sort_order untuk round-robin/tampilan stabil.
    $variants = [];
    try {
        $vs = $pdo->prepare(
            "SELECT rv.id, rv.variant_canvas_id, rv.weight, rv.label
             FROM canvas_rotator_variants rv
             JOIN canvas_pages cp ON cp.id = rv.variant_canvas_id
             WHERE rv.rotator_id = ? AND rv.is_active = 1
               AND cp.is_active = 1 AND cp.page_type <> 'rotator'
             ORDER BY rv.sort_order ASC, rv.id ASC"
        );
        $vs->execute([$rotatorId]);
        $variants = $vs->fetchAll();
    } catch (Throwable $e) {
        error_log('Rotator variants load: ' . $e->getMessage());
    }

    if (!$variants) {
        http_response_code(404);
        echo 'Halaman belum siap.';
        exit;
    }

    // ─── Sticky pick (cookie) ─────────────────────────────────────
    $cookieName = 'av_rot_' . $rotatorId;
    $validIds   = array_map(fn($v) => (int) $v['id'], $variants);
    $chosen     = null;

    $cookieVal = isset($_COOKIE[$cookieName]) ? (int) $_COOKIE[$cookieName] : 0;
    if ($cookieVal > 0 && in_array($cookieVal, $validIds, true)) {
        foreach ($variants as $v) {
            if ((int) $v['id'] === $cookieVal) { $chosen = $v; break; }
        }
    }

    // Belum ada / cookie tak valid → undi berdasarkan bobot, lalu set cookie 30 hari.
    if ($chosen === null) {
        $totalWeight = 0;
        foreach ($variants as $v) { $totalWeight += max(1, (int) $v['weight']); }
        // random_int aman & tersedia; hindari Math rand yang diblok di sebagian env.
        $roll = random_int(1, max(1, $totalWeight));
        $acc  = 0;
        foreach ($variants as $v) {
            $acc += max(1, (int) $v['weight']);
            if ($roll <= $acc) { $chosen = $v; break; }
        }
        if ($chosen === null) $chosen = $variants[0];

        // Cookie sticky (tanpa httponly agar konsisten dgn kebutuhan atribusi; nilai
        // hanya id varian, bukan data sensitif). SameSite=Lax cukup untuk halaman ini.
        @setcookie($cookieName, (string) (int) $chosen['id'], [
            'expires'  => time() + 60 * 60 * 24 * 30,
            'path'     => '/',
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$cookieName] = (string) (int) $chosen['id'];
    }

    // ─── Catat impression (skip admin/staff; dedup per sesi+varian+hari) ──
    if (!isAdminOrStaff()) {
        try {
            $sid = session_id() ?: bin2hex(random_bytes(8));
            $chk = $pdo->prepare(
                "SELECT 1 FROM canvas_rotator_events
                 WHERE variant_id = ? AND event_type = 'impression' AND session_id = ?
                   AND DATE(created_at) = CURDATE() LIMIT 1"
            );
            $chk->execute([(int) $chosen['id'], $sid]);
            if (!$chk->fetch()) {
                $pdo->prepare(
                    "INSERT INTO canvas_rotator_events (rotator_id, variant_id, event_type, session_id)
                     VALUES (?, ?, 'impression', ?)"
                )->execute([$rotatorId, (int) $chosen['id'], $sid]);
            }
        } catch (Throwable $e) {
            error_log('Rotator impression track: ' . $e->getMessage());
        }
    }

    // ─── Muat canvas varian & render (no-store) ───────────────────
    try {
        $cs = $pdo->prepare("SELECT * FROM canvas_pages WHERE id = ? LIMIT 1");
        $cs->execute([(int) $chosen['variant_canvas_id']]);
        $variantPage = $cs->fetch();
    } catch (Throwable $e) {
        $variantPage = null;
    }
    if (!$variantPage) {
        http_response_code(404);
        echo 'Varian tidak ditemukan.';
        exit;
    }

    // Gabungkan pixel ROTATOR ke pixel varian (dedup via canvasPixelIds saat inject)
    // → tracking level-rotator ikut jalan di semua varian, plus pixel khusus varian.
    $variantPage['meta_pixel_id']   = trim(((string) ($rotator['meta_pixel_id'] ?? '')) . ',' . ((string) ($variantPage['meta_pixel_id'] ?? '')), ', ');
    $variantPage['tiktok_pixel_id'] = trim(((string) ($rotator['tiktok_pixel_id'] ?? '')) . ',' . ((string) ($variantPage['tiktok_pixel_id'] ?? '')), ', ');

    // Render konten varian di bawah URL rotator, TANPA cache (sticky per orang).
    renderCanvas($variantPage, true);
}

} // function_exists guard

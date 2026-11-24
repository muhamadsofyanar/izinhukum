<?php

class PixelHelper {

    // Graph API: v20.0 dihapus Meta 24 Sep 2026. Pakai versi ber-dukungan panjang
    // (v24.0 rilis Okt 2025) — payload CAPI stabil lintas versi ini. Perbarui berkala.
    private const META_GRAPH_VERSION = 'v24.0';

    private const META_EVENT_DEFAULTS = [
        'view'     => 'ViewContent',
        'checkout' => 'AddToCart',
        'invoice'  => 'Lead',
        'paid'     => 'Purchase',
    ];
    private const META_ALLOWED_EVENTS = [
        'PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Lead',
        'Purchase', 'CompleteRegistration', 'Subscribe', 'Contact', 'CustomEvent',
    ];

    // ════════════════ Konfigurasi Meta (multi-pixel) ════════════════

    public static function metaBrowserEnabled(): bool {
        return (string) getSetting('meta_pixel_enabled', '0') === '1';
    }
    public static function metaCapiEnabled(): bool {
        return (string) getSetting('meta_capi_enabled', '0') === '1';
    }
    /**
     * Toggle "corong penuh": kirim ViewContent (sales page) & InitiateCheckout
     * (checkout) ke Meta selain Purchase. OPT-IN (default mati) agar Events
     * Manager buyer lama tak berubah tiba-tiba saat update.
     */
    public static function metaFullFunnelEnabled(): bool {
        return (string) getSetting('meta_full_funnel_enabled', '0') === '1';
    }

    /** Daftar pixel Meta aktif dari settings. $withToken → token didekripsi. */
    public static function metaPixels(bool $withToken = false): array {
        $raw  = (string) getSetting('meta_pixels_json', '');
        $list = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($list)) $list = [];
        $out = [];
        foreach ($list as $r) {
            if (!is_array($r)) continue;
            if ((string) ($r['enabled'] ?? '1') !== '1') continue;
            $pid = preg_replace('/[^0-9]/', '', (string) ($r['pixel_id'] ?? ''));
            if ($pid === '') continue;
            $row = [
                'pixel_id'        => $pid,
                'test_event_code' => (string) ($r['test_event_code'] ?? ''),
            ];
            if ($withToken) $row['token'] = pixel_decrypt((string) ($r['token_enc'] ?? ''));
            $out[] = $row;
        }
        return $out;
    }

    /** Resolusi nama event Meta untuk satu stage funnel (hormati CustomEvent). */
    public static function metaEventName(string $stage): string {
        $def = self::META_EVENT_DEFAULTS[$stage] ?? 'Purchase';
        $v   = (string) getSetting('meta_event_' . $stage, $def);
        if (!in_array($v, self::META_ALLOWED_EVENTS, true)) $v = $def;
        if ($v === 'CustomEvent') {
            $c = preg_replace('/[^A-Za-z0-9_]/', '', (string) getSetting('meta_event_' . $stage . '_custom', ''));
            $v = $c !== '' ? $c : $def;
        }
        return $v;
    }

    // ════════════════ Client-side (browser) ════════════════

    /** Base pixel + PageView untuk semua halaman publik (dipanggil head.php). */
    public static function renderClientScripts(): string {
        $html = '';
        try {
            if (self::metaBrowserEnabled()) {
                $pixels = self::metaPixels();
                if ($pixels) {
                    $html .= self::metaPixelBase($pixels);
                    $html .= "\n<script>fbq('track','PageView');</script>";
                }
            }
            $tt = self::tiktokRow('tiktok_pixel');
            if ($tt && !empty($tt['pixel_id'])) {
                $html .= self::tiktokPixelBase($tt['pixel_id']) . "\n<script>ttq.page();</script>";
            }
        } catch (Exception $e) {
            error_log('PixelHelper renderClientScripts error: ' . $e->getMessage());
        }
        return $html;
    }

    /**
     * Render satu event funnel browser-side (self-contained, aman di halaman
     * standalone). $stage: 'checkout' | 'invoice' | 'paid'.
     * Untuk 'paid', menyertakan eventID utk deduplikasi dgn CAPI.
     *
     * @param array $data ['order_number'=>?, 'total'=>?, 'currency'=>?]
     */
    public static function renderEvent(string $stage, array $data = []): string {
        $html = '';
        try {
            $orderNo  = (string) ($data['order_number'] ?? '');
            $value    = (float) ($data['total'] ?? 0);
            $currency = (string) ($data['currency'] ?? 'IDR');
            $eventId  = $orderNo !== '' ? self::eventId($stage, $orderNo) : ($stage . '_' . bin2hex(random_bytes(6)));

            if (self::metaBrowserEnabled()) {
                $pixels = self::metaPixels();
                if ($pixels) {
                    $name = self::metaEventName($stage);
                    $cd   = $value > 0 ? "{value:" . json_encode($value) . ",currency:" . json_encode($currency) . "}" : "{}";
                    $html .= self::metaPixelBase($pixels);
                    $html .= "\n<script>fbq('track'," . json_encode($name) . "," . $cd . ",{eventID:" . json_encode($eventId) . "});</script>";
                }
            }
            // TikTok hanya untuk paid (CompletePayment).
            if ($stage === 'paid') {
                $tt = self::tiktokRow('tiktok_pixel');
                if ($tt && !empty($tt['pixel_id'])) {
                    $html .= self::tiktokPixelBase($tt['pixel_id']);
                    $html .= "\n<script>ttq.track('CompletePayment',{value:" . json_encode($value) . ",currency:" . json_encode($currency) . "},{event_id:" . json_encode($eventId) . "});</script>";
                }
            }
        } catch (Exception $e) {
            error_log('PixelHelper renderEvent error: ' . $e->getMessage());
        }
        return $html;
    }

    /** Kompat: Purchase browser = renderEvent('paid'). */
    public static function renderPurchaseEvent(array $orderData): string {
        return self::renderEvent('paid', $orderData);
    }

    /**
     * ID event funnel yang STABIL & deterministik (bukan acak) → browser & CAPI
     * menghitung nilai yang sama sehingga Meta men-dedup (event_name + event_id).
     * Kunci: stage + produk + sesi + tanggal (1 event ViewContent per sesi/hari).
     */
    public static function funnelEventId(string $stage, int $productId): string {
        $sid = function_exists('session_id') ? (session_id() ?: 'nosess') : 'nosess';
        return $stage . '_' . $productId . '_' . substr(sha1($sid), 0, 12) . '_' . date('Ymd');
    }

    /**
     * Render event funnel browser-side (Meta) utk tahap 'view'|'checkout'.
     * Hanya jalan bila corong-penuh + pixel browser aktif. eventID stabil agar
     * dedup dgn CAPI. Self-contained (base pixel diikutkan, tanpa PageView ulang).
     */
    public static function renderFunnelBrowser(string $stage, int $productId, array $data = []): string {
        try {
            if (!self::metaFullFunnelEnabled() || !self::metaBrowserEnabled()) return '';
            if ($productId <= 0) return '';
            $pixels = self::metaPixels();
            if (!$pixels) return '';
            $name    = self::metaEventName($stage === 'view' ? 'view' : 'checkout');
            $eventId = self::funnelEventId($stage, $productId);
            $value   = (float) ($data['value'] ?? 0);
            $cd = "{content_type:'product',content_ids:[" . json_encode((string) $productId) . "]";
            if ($value > 0) $cd .= ",value:" . json_encode($value) . ",currency:\"IDR\"";
            $cd .= "}";
            return self::metaPixelBase($pixels)
                 . "\n<script>fbq('track'," . json_encode($name) . "," . $cd . ",{eventID:" . json_encode($eventId) . "});</script>";
        } catch (Exception $e) {
            error_log('PixelHelper renderFunnelBrowser error: ' . $e->getMessage());
            return '';
        }
    }

    // ════════════════ Server-side (CAPI) ════════════════

    /** Kirim event 'paid' ke Meta CAPI (semua pixel) + TikTok CAPI. Dari webhook. */
    public static function trackPurchaseServerSide(array $orderData): void {
        try {
            $enriched = self::enrichOrder($orderData);
            $enriched['event_id'] = self::eventId('paid', (string) ($enriched['order_number'] ?? ''));

            if (self::metaCapiEnabled()) {
                $eventName = self::metaEventName('paid');
                foreach (self::metaPixels(true) as $pixel) {
                    self::sendMetaCapiEvent($pixel, $eventName, $enriched);
                }
            }

            $tt = self::tiktokRow('tiktok_capi');
            if ($tt && !empty($tt['pixel_id']) && (int) ($tt['is_active'] ?? 0) === 1) {
                self::sendTiktokCapiEvent($tt, $enriched);
            }
        } catch (Exception $e) {
            error_log('PixelHelper trackPurchaseServerSide error: ' . $e->getMessage());
        }
    }

    /** Kirim 1 test event ke satu pixel Meta (dari admin). $pixel: ['pixel_id','token','test_event_code']. */
    public static function sendMetaTestEvent(array $pixel): array {
        $data = [
            'order_number' => 'TEST-' . date('YmdHis') . '-' . random_int(100, 999),
            'total'        => 10000,
            'email'        => 'test@example.com',
            'phone'        => '628123456789',
            'name'         => 'Test Connection',
            'user_id'      => 0,
            'client_ip'    => client_real_ip(),
            'client_ua'    => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'fbp'          => (string) ($_COOKIE['_fbp'] ?? ''),
            'fbc'          => (string) ($_COOKIE['_fbc'] ?? ''),
            'event_id'     => 'test-' . bin2hex(random_bytes(8)),
        ];
        return self::sendMetaCapiEvent($pixel, self::metaEventName('paid'), $data, true);
    }

    /**
     * Antre event funnel (ViewContent/InitiateCheckout) utk dikirim ke Meta CAPI
     * lewat auto-cron — TIDAK memblokir page-load pengunjung. Dipanggil di titik
     * tangkap funnel (lp_view / checkout_click). User-data & IP asli diambil SAAT
     * INI (konteks request pengunjung), disnapshot ke payload; token pixel baru
     * di-resolve saat flush (tak disimpan di antrean).
     *
     * $stage: 'view' | 'checkout'. $data: ['value'=>float, 'source_url'=>string].
     */
    public static function trackFunnelServerSide(string $stage, int $productId, array $data = []): void {
        try {
            if (!self::metaFullFunnelEnabled() || !self::metaCapiEnabled()) return;
            if ($productId <= 0) return;
            $eventName = self::metaEventName($stage === 'view' ? 'view' : 'checkout');
            $eventId   = self::funnelEventId($stage, $productId);
            $ud = self::buildMetaUserData([
                'user_id'   => (int) ($_SESSION['user_id'] ?? 0),
                'client_ip' => client_real_ip(),
                'client_ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'fbp'       => (string) ($_COOKIE['_fbp'] ?? ''),
                'fbc'       => function_exists('meta_resolve_fbc') ? meta_resolve_fbc() : (string) ($_COOKIE['_fbc'] ?? ''),
            ]);
            $value = (float) ($data['value'] ?? 0);
            $event = [
                'event_name'       => $eventName,
                'event_time'       => time(),
                'event_id'         => $eventId,
                'action_source'    => 'website',
                'event_source_url' => (string) ($data['source_url'] ?? (rtrim(APP_URL, '/') . '/')),
                'user_data'        => $ud,
                'custom_data'      => array_filter([
                    'currency'     => 'IDR',
                    'value'        => $value > 0 ? $value : null,
                    'content_type' => 'product',
                    'content_ids'  => [(string) $productId],
                ], fn($v) => $v !== null),
            ];
            getDB()->prepare(
                "INSERT INTO capi_event_queue (platform, event_name, event_id, payload)
                 VALUES ('meta', ?, ?, ?)"
            )->execute([$eventName, $eventId, json_encode($event, JSON_UNESCAPED_SLASHES)]);
        } catch (Throwable $e) {
            error_log('PixelHelper trackFunnelServerSide error: ' . $e->getMessage());
        }
    }

    /**
     * Opsi A — CAPI ViewContent khusus halaman Canvas (sales page no-code).
     * Beda dari trackFunnelServerSide(): TIDAK bergantung toggle full-funnel —
     * Canvas = niat pixel eksplisit per-halaman — tapi HANYA mengirim bila:
     *   (a) master CAPI aktif, DAN
     *   (b) minimal satu Pixel ID yang dipasang di Canvas JUGA terdaftar (punya
     *       token) di Settings global — agar event tak terkirim ke pixel tak terkait.
     * Tak ada pixel Canvas yang cocok → lewati (browser-only, seperti sebelumnya).
     * Dedup: event_id = funnelEventId('view', productId), IDENTIK dgn ViewContent
     * browser Canvas → Meta dedup (event_name + event_id, window 48 jam). Payload
     * patuh syarat event web Meta: event_name/time, action_source, event_source_url,
     * client_user_agent (via user_data). Non-blocking: hanya enqueue → flush oleh cron.
     *
     * @param string[] $canvasMetaIds ID pixel Meta di Canvas (hasil canvasPixelIds).
     * @param array    $data ['value'=>float, 'source_url'=>string]
     */
    public static function trackCanvasViewServerSide(array $canvasMetaIds, int $productId, array $data = []): void
    {
        try {
            if (!self::metaCapiEnabled()) return;
            if ($productId <= 0 || !$canvasMetaIds) return;

            // Gerbang kecocokan: pixel Canvas harus terdaftar (punya token) di global.
            $globalIds = array_map(static fn($p) => (string) $p['pixel_id'], self::metaPixels());
            if (!array_intersect($canvasMetaIds, $globalIds)) return;

            $eventName = self::metaEventName('view');
            $eventId   = self::funnelEventId('view', $productId);
            $ud = self::buildMetaUserData([
                'user_id'   => (int) ($_SESSION['user_id'] ?? 0),
                'client_ip' => client_real_ip(),
                'client_ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'fbp'       => (string) ($_COOKIE['_fbp'] ?? ''),
                'fbc'       => function_exists('meta_resolve_fbc') ? meta_resolve_fbc() : (string) ($_COOKIE['_fbc'] ?? ''),
            ]);
            $value = (float) ($data['value'] ?? 0);
            $event = [
                'event_name'       => $eventName,
                'event_time'       => time(),
                'event_id'         => $eventId,
                'action_source'    => 'website',
                'event_source_url' => (string) ($data['source_url'] ?? (rtrim(APP_URL, '/') . '/')),
                'user_data'        => $ud,
                'custom_data'      => array_filter([
                    'currency'     => 'IDR',
                    'value'        => $value > 0 ? $value : null,
                    'content_type' => 'product',
                    'content_ids'  => [(string) $productId],
                ], static fn($v) => $v !== null),
            ];
            getDB()->prepare(
                "INSERT INTO capi_event_queue (platform, event_name, event_id, payload)
                 VALUES ('meta', ?, ?, ?)"
            )->execute([$eventName, $eventId, json_encode($event, JSON_UNESCAPED_SLASHES)]);
        } catch (Throwable $e) {
            error_log('PixelHelper trackCanvasViewServerSide error: ' . $e->getMessage());
        }
    }

    /**
     * Flush antrean event funnel ke Meta CAPI. Dipanggil dari auto-cron (setelah
     * response terkirim → nol dampak page-load). Kirim tiap event ke SEMUA pixel
     * aktif, retry sampai 5x, housekeeping baris tua. Fail-soft menyeluruh.
     */
    public static function processCapiEventQueue(int $limit = 50): void {
        try {
            // Housekeeping: buang antrean basi (>3 hari) apa pun statusnya — Meta
            // menolak event >7 hari; sisa pending saat CAPI dimatikan pun terbuang.
            try { getDB()->exec("DELETE FROM capi_event_queue WHERE created_at < NOW() - INTERVAL 3 DAY"); }
            catch (Throwable $e) {}

            if (!self::metaCapiEnabled()) return;
            $pixels = self::metaPixels(true);
            if (!$pixels) return;

            $limit = max(1, min(200, $limit));
            $rows = getDB()->query(
                "SELECT id, event_name, event_id, payload, attempts
                 FROM capi_event_queue
                 WHERE platform = 'meta' AND status = 'pending'
                 ORDER BY id ASC LIMIT " . $limit
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $event    = json_decode((string) $row['payload'], true);
                $attempts = (int) $row['attempts'] + 1;
                if (!is_array($event)) { self::markCapiRow((int) $row['id'], 'failed', $attempts); continue; }

                $allOk = true;
                foreach ($pixels as $pixel) {
                    $res = self::postMetaEvent($pixel, $event, false, '');
                    if (!$res['ok']) $allOk = false;
                }

                if ($allOk)              self::markCapiRow((int) $row['id'], 'sent',    $attempts);
                elseif ($attempts >= 5)  self::markCapiRow((int) $row['id'], 'failed',  $attempts);
                else                     self::markCapiRow((int) $row['id'], 'pending', $attempts);
            }
        } catch (Throwable $e) {
            error_log('PixelHelper processCapiEventQueue error: ' . $e->getMessage());
        }
    }

    private static function markCapiRow(int $id, string $status, int $attempts): void {
        try {
            $sentAt = $status === 'sent' ? date('Y-m-d H:i:s') : null;
            getDB()->prepare(
                "UPDATE capi_event_queue SET status = ?, attempts = ?, sent_at = ? WHERE id = ?"
            )->execute([$status, $attempts, $sentAt, $id]);
        } catch (Throwable $e) {
            error_log('PixelHelper markCapiRow error: ' . $e->getMessage());
        }
    }

    // ════════════════ Internal ════════════════

    private static function eventId(string $stage, string $orderNumber): string {
        $base = $stage === 'paid' ? 'purchase' : $stage;
        return $base . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $orderNumber);
    }

    /**
     * event_source_url utk Purchase CAPI = halaman thank-you order (tempat Purchase
     * browser fire & pixel termuat) → URL on-domain yang menyamai event browser,
     * mendukung match/dedup Meta. Fallback homepage bila order_number kosong
     * (mis. test event tanpa nomor). Rute: /public-thank-you/{order_number}.
     */
    private static function purchaseSourceUrl(string $orderNumber): string {
        $base = rtrim(APP_URL, '/');
        return $orderNumber !== ''
            ? $base . '/public-thank-you/' . rawurlencode($orderNumber)
            : $base . '/';
    }

    private static function enrichOrder(array $orderData): array {
        $orderNo = (string) ($orderData['order_number'] ?? '');
        if ($orderNo === '') return $orderData;
        try {
            $st = getDB()->prepare(
                "SELECT o.order_number, o.total, o.user_id, o.tracking_meta,
                        u.name AS user_name, u.email AS user_email, u.phone AS user_phone
                 FROM orders o LEFT JOIN users u ON u.id = o.user_id
                 WHERE o.order_number = ? LIMIT 1"
            );
            $st->execute([$orderNo]);
            $row = $st->fetch();
            if (!$row) return $orderData;
            $tm = [];
            if (!empty($row['tracking_meta'])) {
                $tmp = json_decode((string) $row['tracking_meta'], true);
                if (is_array($tmp)) $tm = $tmp;
            }
            return [
                'order_number' => (string) $row['order_number'],
                'total'        => (float) $row['total'],
                'email'        => $orderData['email'] ?? ($row['user_email'] ?? ''),
                'phone'        => $row['user_phone'] ?? '',
                'name'         => $row['user_name'] ?? '',
                'user_id'      => (int) ($row['user_id'] ?? 0),
                'fbp'          => (string) ($tm['fbp'] ?? ''),
                'fbc'          => (string) ($tm['fbc'] ?? ''),
                'client_ip'    => (string) ($tm['ip'] ?? ''),
                'client_ua'    => (string) ($tm['ua'] ?? ''),
            ];
        } catch (Exception $e) {
            return $orderData;
        }
    }

    private static function tiktokRow(string $platform): ?array {
        try {
            $st = getDB()->prepare("SELECT * FROM tracking_pixels WHERE platform = ? AND is_active = 1 LIMIT 1");
            $st->execute([$platform]);
            return $st->fetch() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    private static function metaPixelBase(array $pixels): string {
        $inits = '';
        foreach ($pixels as $p) {
            $inits .= "fbq('init'," . json_encode((string) $p['pixel_id']) . ");";
        }
        return "\n<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
document,'script','https://connect.facebook.net/en_US/fbevents.js');
" . $inits . "</script>";
    }

    private static function tiktokPixelBase(string $pixelId): string {
        return "\n<script>
!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=
['page','track','identify','instances','debug','on','off','once','ready','alias',
'group','enableCookie','disableCookie'],ttq.setAndDefer=function(t,e){t[e]=function(){
t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)
ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<e.length;n++)
ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i='https://analytics.tiktok.com/i18n/pixel/events.js';
ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},
ttq._o[e]=n||{};var o=document.createElement('script');o.type='text/javascript',o.async=!0,o.src=i+'?sdkid='+e+'&lib='+t;
var a=document.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};
ttq.load('" . e($pixelId) . "');
}(window,document,'ttq');</script>";
    }

    private static function buildMetaUserData(array $d): array {
        $ud = [];
        $h  = fn(string $v): string => hash('sha256', strtolower(trim($v)));

        $email = trim((string) ($d['email'] ?? ''));
        if ($email !== '') $ud['em'] = [$h($email)];
        $phone = lead_normalize_wa((string) ($d['phone'] ?? ''));
        if ($phone !== '') $ud['ph'] = [hash('sha256', $phone)];
        $name = trim((string) ($d['name'] ?? ''));
        if ($name !== '') {
            $parts = preg_split('/\s+/', $name);
            if (($parts[0] ?? '') !== '') $ud['fn'] = [$h($parts[0])];
            if (count($parts) > 1) $ud['ln'] = [$h((string) end($parts))];
        }
        $uid = (int) ($d['user_id'] ?? 0);
        if ($uid > 0) $ud['external_id'] = [hash('sha256', (string) $uid)];
        $ip = trim((string) ($d['client_ip'] ?? ''));
        if ($ip !== '') $ud['client_ip_address'] = $ip;
        $ua = trim((string) ($d['client_ua'] ?? ''));
        if ($ua !== '') $ud['client_user_agent'] = substr($ua, 0, 500);
        $fbp = trim((string) ($d['fbp'] ?? ''));
        if ($fbp !== '') $ud['fbp'] = $fbp;
        $fbc = trim((string) ($d['fbc'] ?? ''));
        if ($fbc !== '') $ud['fbc'] = $fbc;
        return $ud;
    }

    private static function sendMetaCapiEvent(array $pixel, string $eventName, array $orderData, bool $isTest = false): array {
        $pixelId = preg_replace('/[^0-9]/', '', (string) ($pixel['pixel_id'] ?? ''));
        $token   = (string) ($pixel['token'] ?? '');
        if ($pixelId === '' || $token === '') {
            return ['ok' => false, 'http' => 0, 'message' => 'Pixel ID atau Access Token kosong.'];
        }

        $event = [
            'event_name'       => $eventName,
            'event_time'       => time(),
            'event_id'         => (string) ($orderData['event_id'] ?? ('purchase_' . ($orderData['order_number'] ?? ''))),
            'action_source'    => 'website',
            // URL halaman thank-you tempat Purchase browser fire (pixel ADA di sana) →
            // on-domain & menyamai event browser utk match/dedup Meta, bukan homepage generik.
            'event_source_url' => self::purchaseSourceUrl((string) ($orderData['order_number'] ?? '')),
            'user_data'        => self::buildMetaUserData($orderData),
            'custom_data'      => [
                'currency'     => 'IDR',
                'value'        => (float) ($orderData['total'] ?? 0),
                'order_id'     => (string) ($orderData['order_number'] ?? ''),
                'content_type' => 'product',
            ],
        ];
        return self::postMetaEvent($pixel, $event, $isTest, (string) ($orderData['order_number'] ?? ''));
    }

    /**
     * POST satu event Meta (payload sudah jadi) ke satu pixel. Dipakai oleh
     * pengiriman langsung (Purchase/test) & flush antrean corong (CAPI funnel).
     */
    private static function postMetaEvent(array $pixel, array $event, bool $isTest = false, string $orderNumber = ''): array {
        $pixelId = preg_replace('/[^0-9]/', '', (string) ($pixel['pixel_id'] ?? ''));
        $token   = (string) ($pixel['token'] ?? '');
        if ($pixelId === '' || $token === '') {
            return ['ok' => false, 'http' => 0, 'message' => 'Pixel ID atau Access Token kosong.'];
        }

        $payload  = ['data' => [$event]];
        $testCode = trim((string) ($pixel['test_event_code'] ?? ''));
        if ($testCode !== '') $payload['test_event_code'] = $testCode;

        $url = 'https://graph.facebook.com/' . self::META_GRAPH_VERSION . '/'
             . rawurlencode($pixelId) . '/events?access_token=' . rawurlencode($token);
        $resp = self::httpPostJson($url, json_encode($payload));
        $ok   = $resp['http'] >= 200 && $resp['http'] < 300;

        $errMsg = '';
        if (!$ok) {
            $j = json_decode((string) $resp['body'], true);
            $errMsg = is_array($j) && isset($j['error']['message']) ? (string) $j['error']['message'] : ($resp['error'] ?: ('HTTP ' . $resp['http']));
        }

        $eventName = (string) ($event['event_name'] ?? '');
        self::logIntegration('meta_capi', $isTest ? 'test_event' : ($eventName === 'Purchase' ? 'purchase_event' : 'funnel_event'),
            ['pixel_id' => $pixelId, 'event' => $eventName, 'order_number' => $orderNumber, 'event_id' => $event['event_id'] ?? ''],
            $resp['body'], $ok ? 'success' : 'failed');

        try {
            setSetting('meta_capi_last_at', date('Y-m-d H:i:s'), 'tracking');
            setSetting('meta_capi_last_http', (string) $resp['http'], 'tracking');
            setSetting('meta_capi_last_event', $eventName, 'tracking');
            setSetting('meta_capi_last_error', $errMsg, 'tracking');
        } catch (Exception $e) {}

        return ['ok' => $ok, 'http' => $resp['http'], 'message' => $ok ? 'Event terkirim.' : $errMsg, 'response' => $resp['body']];
    }

    private static function sendTiktokCapiEvent(array $config, array $orderData): void {
        $pixelId = (string) ($config['pixel_id'] ?? '');
        $token   = pixel_decrypt((string) ($config['access_token'] ?? ''));
        if ($pixelId === '' || $token === '') return;

        $payload = [
            'event_source'    => 'web',
            'event_source_id' => $pixelId,
            'data' => [[
                'event'      => 'CompletePayment',
                'event_time' => time(),
                'event_id'   => (string) ($orderData['event_id'] ?? ''),
                'user'       => [
                    'email'      => hash('sha256', strtolower(trim((string) ($orderData['email'] ?? '')))),
                    'phone'      => ($orderData['phone'] ?? '') !== '' ? hash('sha256', lead_normalize_wa((string) $orderData['phone'])) : '',
                    'ip'         => (string) ($orderData['client_ip'] ?? ''),
                    'user_agent' => (string) ($orderData['client_ua'] ?? ''),
                ],
                'properties' => [
                    'currency' => 'IDR',
                    'value'    => (float) ($orderData['total'] ?? 0),
                    'order_id' => (string) ($orderData['order_number'] ?? ''),
                ],
            ]],
        ];
        $resp = self::httpPostJson(
            'https://business-api.tiktok.com/open_api/v1.3/event/track/',
            json_encode($payload),
            ['Access-Token: ' . $token]
        );
        $ok = $resp['http'] >= 200 && $resp['http'] < 300;
        self::logIntegration('tiktok_capi', 'purchase_event',
            ['order_number' => $orderData['order_number'] ?? '', 'value' => $orderData['total'] ?? 0],
            $resp['body'], $ok ? 'success' : 'failed');
    }

    private static function httpPostJson(string $url, string $body, array $extraHeaders = []): array {
        $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
        $out = ['http' => 0, 'body' => '', 'error' => ''];
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $out['body']  = (string) curl_exec($ch);
            $out['http']  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $out['error'] = (string) curl_error($ch);
            curl_close($ch);
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    private static function logIntegration(string $provider, string $action, array $request, $response, string $status): void {
        try {
            getDB()->prepare(
                "INSERT INTO integration_logs (provider, action, request, response, status, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            )->execute([$provider, $action, json_encode($request), is_string($response) ? substr($response, 0, 2000) : json_encode($response), $status]);
        } catch (Exception $e) {
            error_log('PixelHelper logIntegration error: ' . $e->getMessage());
        }
    }
}

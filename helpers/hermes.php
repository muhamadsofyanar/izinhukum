<?php
// ════════════════════════════════════════════════════════════════════════
// Hermes Agent — Ingest API ber-token untuk sistem eksternal (automation/AI).
// Fondasi terpusat & extensible: satu gerbang auth + registry kapabilitas, agar
// ingest berikutnya (email sequence, follow-up pending payment, dst) tinggal
// menambah baris di registry — bukan bikin surface baru.
//
// POSTUR KEAMANAN (diadopsi dari Ingest API averion-scribe, terbukti):
//   - DEFAULT MATI (opt-in per instalasi pembeli). Fitur PRODUK (semua pembeli).
//   - Token disimpan HANYA sebagai hash sha256 (mentah ditampilkan SEKALI).
//   - Tier terpisah: master (hermes_enabled) vs tulis-LIVE (hermes_manage_enabled).
//   - Eksternal TAK PERNAH mengirim email / memegang kredensial Mailketing.
//   - Konten masuk selalu DRAFT/INACTIVE; aktivasi tetap admin.
//
// Penyimpanan: settings (grup 'hermes'):
//   hermes_enabled        '0'|'1'  (default '0')  master switch API
//   hermes_manage_enabled '0'|'1'  (default '0')  gate aksi tulis (LIVE)
//   hermes_token_hash     sha256(token)           (token mentah TAK disimpan)
// ════════════════════════════════════════════════════════════════════════

/** Fitur Hermes aktif? (default mati) — master switch API. */
function hermesEnabled(): bool
{
    return getSetting('hermes_enabled', '0') === '1';
}

/**
 * Tier manajemen aktif? (default mati). Gate TAMBAHAN untuk aksi TULIS yang
 * berdampak (mis. memasukkan follow-up ke antrean). Dipisah dari ingest draft.
 */
function hermesManageEnabled(): bool
{
    return getSetting('hermes_manage_enabled', '0') === '1';
}

/**
 * Tier BACA data bisnis aktif? (default mati). Gate TERPISAH dari master & tulis —
 * mengaktifkan Hermes untuk ingest email TIDAK otomatis membuka data bisnis. Admin
 * harus opt-in sadar. Endpoint baca (insights) hanya agregat read-only, tanpa PII.
 */
function hermesReadEnabled(): bool
{
    return getSetting('hermes_read_enabled', '0') === '1';
}

/** Hash token tersimpan (kosong bila belum pernah dibuat). */
function hermesTokenHash(): string
{
    return (string) getSetting('hermes_token_hash', '');
}

/** Token sudah pernah dibuat? */
function hermesTokenIsSet(): bool
{
    return hermesTokenHash() !== '';
}

/**
 * Buat token mentah 32-byte hex, simpan HANYA hash-nya, kembalikan token mentah.
 * Token mentah hanya ada di memori panggilan ini — ditampilkan SEKALI ke admin.
 */
function hermesGenerateToken(): string
{
    $raw = bin2hex(random_bytes(32));
    setSetting('hermes_token_hash', hash('sha256', $raw), 'hermes');
    return $raw;
}

/** Verifikasi token bearer terhadap hash tersimpan (timing-safe). */
function hermesVerifyToken(string $token): bool
{
    $hash = hermesTokenHash();
    if ($hash === '' || $token === '') {
        return false;
    }
    return hash_equals($hash, hash('sha256', $token));
}

/** Ambil token dari header Authorization: Bearer (fallback lintas-server). */
function hermesBearerToken(): string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if ($auth === '' && function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        $auth = $h['Authorization'] ?? ($h['authorization'] ?? '');
    }
    return preg_match('/^Bearer\s+(.+)$/i', trim((string) $auth), $m) ? trim($m[1]) : '';
}

/** URL endpoint discovery absolut (untuk ditampilkan + contoh di admin). */
function hermesEndpointUrl(): string
{
    return baseUrl() . '/api/hermes/capabilities';
}

/** Kirim respon JSON lalu berhenti (endpoint mesin, tak bocorkan detail internal). */
function hermesApiSend(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Log satu baris permintaan Hermes (TANPA token) ke storage/logs/hermes.log. */
function hermesLog(string $line): void
{
    $dir = __DIR__ . '/../storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    @file_put_contents(
        $dir . '/hermes.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Rate-limit fixed-window (default 30 permintaan / 60 detik) berbagi satu bucket
 * file. Return true bila diizinkan. Non-fatal: bila FS gagal → izinkan.
 */
function hermesRateLimitOk(int $max = 30, int $windowSec = 60): bool
{
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/hermes-rate.json';
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return true;
    }
    @flock($fp, LOCK_EX);
    $now  = time();
    $data = json_decode((string) stream_get_contents($fp), true);
    if (!is_array($data) || ($now - (int) ($data['start'] ?? 0)) >= $windowSec) {
        $data = ['start' => $now, 'count' => 0];
    }
    $data['count'] = (int) $data['count'] + 1;
    $ok = $data['count'] <= $max;
    @ftruncate($fp, 0);
    @rewind($fp);
    @fwrite($fp, json_encode($data));
    @flock($fp, LOCK_UN);
    @fclose($fp);
    return $ok;
}

/**
 * Guard bersama endpoint Hermes. Cek berurutan lalu exit JSON bila gagal:
 * rate-limit → token → master switch (hermes_enabled) → khusus TULIS butuh
 * hermes_manage_enabled.
 * @param bool $writeAction true bila aksi menulis (butuh tier manajemen).
 */
function hermesApiAuthorize(bool $writeAction): void
{
    if (!hermesRateLimitOk()) {
        hermesApiSend(429, ['ok' => false, 'error' => 'Terlalu banyak permintaan. Coba lagi sebentar.']);
    }
    $token = hermesBearerToken();
    if ($token === '') {
        hermesApiSend(401, ['ok' => false, 'error' => 'Token tidak ada. Sertakan header Authorization: Bearer <token>.']);
    }
    if (!hermesTokenIsSet() || !hermesVerifyToken($token)) {
        hermesLog('401 auth gagal ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
        hermesApiSend(401, ['ok' => false, 'error' => 'Token tidak valid.']);
    }
    if (!hermesEnabled()) {
        hermesApiSend(403, ['ok' => false, 'error' => 'Hermes Agent nonaktif.']);
    }
    if ($writeAction && !hermesManageEnabled()) {
        hermesApiSend(403, ['ok' => false, 'error' => 'Tier manajemen Hermes nonaktif. Aktifkan di Admin → Hermes Agent.']);
    }
}

/**
 * Guard khusus endpoint BACA data bisnis (insights). Cek berurutan lalu exit JSON:
 * rate-limit → token → master (hermes_enabled) → tier baca (hermes_read_enabled).
 * TERPISAH dari hermesApiAuthorize agar tier baca tak terikat ke tier tulis/ingest.
 * Hanya read-only agregat; tak pernah menyentuh tier manajemen.
 */
function hermesInsightsAuthorize(): void
{
    if (!hermesRateLimitOk()) {
        hermesApiSend(429, ['ok' => false, 'error' => 'Terlalu banyak permintaan. Coba lagi sebentar.']);
    }
    $token = hermesBearerToken();
    if ($token === '') {
        hermesApiSend(401, ['ok' => false, 'error' => 'Token tidak ada. Sertakan header Authorization: Bearer <token>.']);
    }
    if (!hermesTokenIsSet() || !hermesVerifyToken($token)) {
        hermesLog('401 insights auth gagal ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
        hermesApiSend(401, ['ok' => false, 'error' => 'Token tidak valid.']);
    }
    if (!hermesEnabled()) {
        hermesApiSend(403, ['ok' => false, 'error' => 'Hermes Agent nonaktif.']);
    }
    if (!hermesReadEnabled()) {
        hermesApiSend(403, ['ok' => false, 'error' => 'Tier baca data bisnis Hermes nonaktif. Aktifkan di Admin → Hermes Agent.']);
    }
}

/**
 * Registry metrik BACA (insights) — SATU sumber kebenaran dipakai: dispatcher
 * endpoint, discovery payload, dan halaman admin. Tiap metrik read-only, agregat,
 * TANPA PII. Kolom `params` mendokumentasikan query string agar Hermes tak menebak.
 * Semua agregasi memakai ulang fungsi di api/internal/_data.php (nol query paralel).
 *
 * @return array<string,array{title:string,params:array<string,string>,description:string}>
 */
function hermesInsightsRegistry(): array
{
    $period = 'period: today|7d|30d|90d|365d|all (default 30d)';
    return [
        'business_summary' => [
            'title'       => 'Ringkasan bisnis',
            'params'      => ['period' => $period],
            'description' => 'KPI periode: omzet (order lunas), jumlah order lunas & pending, user baru, member aktif.',
        ],
        'revenue_trend' => [
            'title'       => 'Tren omzet',
            'params'      => ['range' => 'range: 30d|90d|365d (default 30d)', 'group' => 'group: day|month (default day)'],
            'description' => 'Deret omzet order lunas per hari/bulan + total & perbandingan periode sebelumnya (delta %).',
        ],
        'product_sales' => [
            'title'       => 'Penjualan per produk',
            'params'      => [$period, 'limit' => 'limit: 1-50 (default 10)'],
            'description' => 'Per produk: omzet, jumlah unit sebenarnya (SUM quantity), jumlah pembeli unik, jumlah order. Dari order lunas.',
        ],
        'traffic_summary' => [
            'title'       => 'Traffic & konversi',
            'params'      => [$period, 'limit' => 'limit: 1-50 (default 10)'],
            'description' => 'Per produk: views halaman + klik checkout + order lunas + conversion%. Hanya bila Funnel Analytics aktif.',
        ],
        'product_funnel' => [
            'title'       => 'Funnel satu produk',
            'params'      => ['product' => 'product: nama atau ID (wajib)', $period],
            'description' => 'Tahap views → klik checkout → lunas + conversion rate tiap tahap + omzet produk tsb.',
        ],
        'cross_sell' => [
            'title'       => 'Pembelian lanjutan antarproduk',
            'params'      => ['product' => 'product: nama atau ID (wajib)', $period, 'limit' => 'limit: 1-50 (default 10)'],
            'description' => 'Produk lain yang paling sering juga dibeli (lunas) oleh pembeli produk acuan — basis rekomendasi cross-sell.',
        ],
        'affiliate_performance' => [
            'title'       => 'Performa affiliate',
            'params'      => [$period, 'limit' => 'limit: 1-50 (default 10)'],
            'description' => 'Per affiliate (kode saja, tanpa nama/PII): jumlah konversi + komisi approved & pending.',
        ],
        'email_sequence_summary' => [
            'title'       => 'Ringkasan email sequence',
            'params'      => [],
            'description' => 'Per sequence: status, trigger, jumlah step, enrollment (aktif/selesai/berhenti), email terkirim/gagal.',
        ],
        'broadcast_summary' => [
            'title'       => 'Ringkasan broadcast',
            'params'      => [$period, 'limit' => 'limit: 1-50 (default 10)', 'campaign' => 'campaign: nama/ID (opsional, 1 campaign)'],
            'description' => 'Per campaign (agregat, tanpa penerima): penerima/terkirim/gagal + open & click rate.',
        ],
    ];
}

/** Definisi/metadata yang menyertai respons insights agar Hermes tak salah tafsir. */
function hermesInsightsDefinitions(): array
{
    return [
        'timezone'       => date_default_timezone_get(),
        'currency'       => (string) getSetting('currency', 'IDR'),
        'order_status'   => "Omzet & 'lunas' = order status 'paid'. Status order: pending|paid|failed|cancelled|expired.",
        'refund'         => 'Averion TIDAK melacak refund di level status order (tidak ada status refunded). Omzet = order paid; refund TIDAK dikurangi dari angka ini.',
        'order_bump'     => "Omzet total (business_summary/revenue_trend) termasuk order bump (bagian dari orders.total). Metrik per-produk product_sales menghitung SEMUA item termasuk bump. traffic_summary/product_funnel/cross_sell mengaitkan ke produk UTAMA saja (is_bump=0).",
        'ads_profit'     => 'Averion TIDAK menyimpan belanja iklan, profit, atau atribusi iklan. Angka pixel/CAPI = event yang Averion KIRIM, bukan analitik dari platform iklan.',
        'privacy'        => 'Semua respons agregat. Tidak mengembalikan email, telepon, alamat, isi pesan, kredensial, atau data pembayaran pelanggan.',
    ];
}

/**
 * Registry kapabilitas ingest. SATU sumber kebenaran — dipakai endpoint discovery
 * (GET /api/hermes/capabilities) DAN halaman admin. `available` menandai apakah
 * endpoint-nya sudah ter-ship (fase berikutnya tinggal menyalakannya + menambah
 * entri 'endpoints'). `requires_manage` = butuh tier tulis.
 *
 * @return array<string,array{title:string,available:bool,requires_manage:bool,description:string}>
 */
function hermesCapabilityRegistry(): array
{
    return [
        'email_sequence_ingest' => [
            'title'           => 'Ingest Email Sequence',
            'available'       => true,  // Fase 2
            'requires_manage' => false, // masuk selalu sebagai draft/inactive
            'requires_read'   => false,
            'description'     => 'Hermes menulis draft email sequence (selalu inactive; aktivasi tetap admin).',
        ],
        'funnel_gate_ingest' => [
            'title'           => 'Ingest Funnel Gate',
            'available'       => true,
            'requires_manage' => false, // masuk selalu sebagai draft
            'requires_read'   => false,
            'description'     => 'Hermes menyusun Funnel Gate (form kualifikasi prospek) lengkap dengan pertanyaan, bobot skor, dan aturan redirect by-skor — selalu sebagai draft; publikasi tetap admin.',
        ],
        'upsell_ingest' => [
            'title'           => 'Ingest Upsell',
            'available'       => true,
            'requires_manage' => false, // masuk selalu inactive
            'requires_read'   => false,
            'description'     => 'Hermes menautkan penawaran upsell (produk pemicu → produk upsell + harga spesial + copy) — selalu inactive; aktivasi tetap admin. Produk harus sudah ada.',
        ],
        'product_bump_ingest' => [
            'title'           => 'Ingest Product Bump',
            'available'       => true,
            'requires_manage' => false,
            'requires_read'   => false,
            'description'     => 'Hermes menautkan order bump (produk pemicu → produk bump + harga opsional + copy) di checkout — selalu inactive; aktivasi tetap admin. Produk harus sudah ada.',
        ],
        'flash_bonus_ingest' => [
            'title'           => 'Ingest Bonus Kilat',
            'available'       => true,
            'requires_manage' => false,
            'requires_read'   => false,
            'description'     => 'Hermes menautkan Bonus Kilat (produk pemicu → produk bonus gratis + copy) di checkout — selalu inactive; aktivasi tetap admin. Produk harus sudah ada.',
        ],
        'broadcast_ingest' => [
            'title'           => 'Ingest Broadcast',
            'available'       => true,
            'requires_manage' => false, // masuk selalu draft; kirim/jadwal tetap admin
            'requires_read'   => false,
            'description'     => 'Hermes menyusun konten broadcast (email/WhatsApp) siap-kirim ke /admin/broadcast sebagai draft — pengiriman & penjadwalan tetap tombol admin. Dipakai tiap kali admin ingin menyiapkan pemberitahuan.',
        ],
        'business_insights_read' => [
            'title'           => 'Baca Data Bisnis (analisis)',
            'available'       => true,
            'requires_manage' => false,
            'requires_read'   => true, // tier baca terpisah, default mati
            'description'     => 'Hermes membaca agregat bisnis (omzet, produk, traffic, affiliate, email/broadcast) untuk analisis — read-only, tanpa PII, tanpa aksi.',
        ],
    ];
}

/**
 * Skema payload ingest Funnel Gate — SATU sumber kebenaran untuk discovery agar
 * Hermes menyusun form kualifikasi tanpa menebak bentuk data. Dipakai di payload
 * capabilities. Endpoint sebenarnya memvalidasi ulang (sumber kebenaran tetap di
 * api/hermes/funnel-gate.php); ini dokumentasi kontrak, bukan validator.
 */
function hermesFunnelGateSchema(): array
{
    return [
        'endpoint'    => '/api/hermes/funnel-gate',
        'methods'     => ['GET (list / ?ref=<external_ref>)', 'POST (upsert by external_ref)'],
        'status'      => 'draft_only', // form masuk selalu draft; publikasi tetap admin
        'idempotency' => 'external_ref UNIQUE — kirim ulang dengan ref sama = update (ditolak bila form sudah published).',
        'concept'     => 'Funnel Gate = Decision Engine: menyaring/mengkualifikasi prospek via pertanyaan berbobot sebelum masuk funnel utama. Kontak (nama+WA) selalu di awal agar draft bisa di-follow-up.',
        'payload'     => [
            'external_ref'         => 'string wajib ≤191 (kunci idempotensi).',
            'title'                => 'string wajib ≤200.',
            'slug'                 => 'string opsional (default dari title) — URL publik /apply/<slug>.',
            'intro'                => 'string opsional (teks pembuka).',
            'submit_text'          => 'string opsional ≤100 (label tombol kirim).',
            'success_message'      => 'string opsional (pesan setelah submit bila tak ada redirect cocok).',
            'score_enabled'        => 'bool (hitung skor dari jawaban).',
            'contact'              => '{name_label, whatsapp_label, email_enabled:bool, email_required:bool, email_label} — kontak selalu di awal.',
            'form_mode'            => "'single' | 'multi' (wizard berlangkah).",
            'steps'                => 'array (mode multi): [{cid, title, description}] — cid = id klien untuk memetakan pertanyaan ke langkah.',
            'questions'            => "array ≤100: [{type, label, required:bool, help?, placeholder?, step_cid?, options?, weight?, rating_max?}].",
            'redirect_rules'       => 'array ≤30: [{min?, max?, url, label?}] dievaluasi urut; cocok pertama menang (redirect by-skor).',
            'default_redirect_url' => 'string opsional (relatif /… atau https://…) bila tak ada rule cocok.',
            'mailketing_list_id'   => 'string opsional ≤40 (submission di-approve → masuk list ini).',
            'dry_run'              => 'bool — validasi + preview + confirm_hash tanpa menulis.',
            'confirm_hash'         => 'string opsional — bila dikirim wajib cocok dengan hash payload (preview==apply).',
        ],
        'question_types' => [
            'text|textarea' => 'isian bebas (tak berskor).',
            'select|radio|checkbox' => 'pilihan: options=[{label, score}] — skor per opsi yang dipilih.',
            'number' => 'angka: skor = nilai × weight.',
            'rating' => 'rating 1..rating_max (2-10, default 5): skor = nilai × weight.',
        ],
        'scoring' => 'Skor total = jumlah skor opsi terpilih + (nilai number/rating × weight). weight & score rentang ±9999.99.',
    ];
}

/**
 * Katalog produk ringkas untuk discovery — Hermes butuh product_id agar bisa
 * menautkan offer (upsell/bump/bonus) ke produk nyata. Bukan PII (katalog
 * semi-publik lewat storefront). Hanya produk 'active' (yang sah jadi offer),
 * dibatasi agar payload discovery tak membengkak.
 *
 * @return array{items:array<int,array{id:int,name:string,price:float,type:string}>,count:int,truncated:bool,limit:int}
 */
function hermesProductCatalog(int $limit = 500): array
{
    $items = []; $truncated = false;
    try {
        $pdo = getDB();
        $total = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();
        $st = $pdo->prepare("SELECT id, name, price, type FROM products WHERE status = 'active' ORDER BY id ASC LIMIT " . (int) $limit);
        $st->execute();
        foreach ($st->fetchAll() as $p) {
            $items[] = ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'price' => (float) $p['price'], 'type' => (string) $p['type']];
        }
        $truncated = $total > $limit;
    } catch (Throwable $e) {
        // Non-fatal: discovery tetap jalan tanpa katalog bila DB bermasalah.
    }
    return ['items' => $items, 'count' => count($items), 'truncated' => $truncated, 'limit' => $limit];
}

/**
 * Skema payload ingest Offers (upsell/bump/flash_bonus) — satu sumber kebenaran
 * discovery. Endpoint memvalidasi ulang (sumber kebenaran di api/hermes/offers.php).
 */
function hermesOffersSchema(): array
{
    return [
        'endpoint'    => '/api/hermes/offers',
        'methods'     => ['GET (list, ?type=, ?main=<id>)', 'POST (upsert by pasangan produk)'],
        'status'      => 'inactive_only', // offer masuk selalu inactive; aktivasi admin
        'idempotency' => 'Kunci ALAMI (tanpa external_ref): upsell unik per main_product_id; bump & flash_bonus unik per pasangan (main_product_id, offer_product_id). Kirim ulang = update (ditolak bila sudah active).',
        'note'        => 'Hermes HANYA menautkan produk yang SUDAH ADA (lihat blok products untuk id) + menulis harga/copy. Tak bisa membuat produk baru.',
        'types'       => [
            'upsell'      => 'Penawaran upsell pasca-pilih (1 per produk pemicu). price WAJIB (harga spesial > 0).',
            'bump'        => 'Order bump di checkout. price OPSIONAL (override harga; kosong = harga produk).',
            'flash_bonus' => 'Bonus gratis berbatas waktu di checkout. TANPA price.',
        ],
        'payload'     => [
            'type'             => "wajib: 'upsell' | 'bump' | 'flash_bonus'.",
            'main_product_id'  => 'int wajib — produk pemicu (harus active).',
            'offer_product_id' => 'int wajib — produk yang ditawarkan (harus active, ≠ main).',
            'price'            => 'number — wajib upsell (>0), opsional bump, dilarang flash_bonus.',
            'headline'         => 'string opsional ≤200.',
            'description'      => 'string opsional ≤1000.',
            'sort_order'       => 'int opsional (bump/flash_bonus; urutan tampil).',
            'dry_run'          => 'bool — validasi + preview + confirm_hash tanpa menulis.',
            'confirm_hash'     => 'string opsional — bila dikirim wajib cocok (preview==apply).',
        ],
    ];
}

/**
 * Skema payload ingest Broadcast — satu sumber kebenaran discovery. Endpoint
 * memvalidasi ulang (sumber kebenaran di api/hermes/broadcast.php).
 */
function hermesBroadcastSchema(): array
{
    return [
        'endpoint'    => '/api/hermes/broadcast',
        'methods'     => ['GET (list / ?ref=<external_ref>)', 'POST (upsert by external_ref)'],
        'status'      => 'draft_only', // campaign masuk selalu draft; kirim/jadwal tetap admin
        'idempotency' => 'external_ref UNIQUE — kirim ulang dengan ref sama = update (ditolak bila campaign sudah lepas-draft: sending/scheduled/paused/sent/failed).',
        'concept'     => 'Hermes menyusun KONTEN pemberitahuan (nama, subjek, isi, segmen target) → muncul di /admin/broadcast sebagai draft dengan UI yang sama. Admin meninjau, memilih provider bila perlu, lalu menekan Kirim/Jadwalkan. Hermes TAK pernah mengirim.',
        'segments'    => [
            'all_users'      => 'Semua user terdaftar.',
            'active_members' => 'Member dengan akses aktif.',
            'leads_all'      => 'Semua lead (prospek).',
            'product_buyers' => 'Pembeli produk tertentu — wajib target_product_ids.',
            'email_openers'  => 'Pernah buka email (channel email) — opsional engagement_days 30/60/90.',
            'email_clickers' => 'Pernah klik email (channel email) — opsional engagement_days 30/60/90.',
        ],
        'payload'     => [
            'external_ref'        => 'string wajib ≤191 (kunci idempotensi).',
            'name'                => 'string wajib ≤150 (nama campaign internal).',
            'channel'             => "wajib: 'email' | 'whatsapp'.",
            'subject'             => 'string ≤200 — hanya email (diabaikan untuk WhatsApp).',
            'body'                => 'string wajib ≤60000 — isi pesan. Placeholder {{name}} = nama penerima.',
            'body_format'         => "'text' | 'html' — html hanya untuk email; WhatsApp selalu text.",
            'target_segment'      => 'string opsional (default all_users) — lihat blok segments.',
            'target_product_ids'  => 'array id produk — wajib untuk product_buyers (produk harus active).',
            'exclude_product_ids' => 'array id produk opsional — kecualikan pembeli produk ini.',
            'skip_failed_emails'  => 'bool opsional (email) — lewati alamat yang pernah gagal.',
            'engagement_days'     => 'int opsional 30|60|90 — hanya email_openers/email_clickers.',
            'dry_run'             => 'bool — validasi + preview + confirm_hash tanpa menulis.',
            'confirm_hash'        => 'string opsional — bila dikirim wajib cocok (preview==apply).',
        ],
        'note'        => 'Placeholder personalisasi memakai {{name}} (konsisten mesin broadcast). Provider pengirim dipilih server bila ada yang aktif, hanya sebagai nilai awal draft.',
    ];
}

/**
 * Payload discovery untuk GET /api/hermes/capabilities. Agen (Hermes) membaca ini
 * untuk menyetel diri tanpa hardcode, sekaligus health-check token.
 */
function hermesCapabilitiesPayload(): array
{
    $manage = hermesManageEnabled();
    $read   = hermesReadEnabled();
    $caps   = [];
    $endpoints = ['capabilities' => '/api/hermes/capabilities'];
    foreach (hermesCapabilityRegistry() as $key => $c) {
        // Kapabilitas "menyala" bila endpoint ada DAN semua tier izinnya aktif.
        $caps[$key] = $c['available']
            && (empty($c['requires_manage']) || $manage)
            && (empty($c['requires_read']) || $read);
    }
    // Endpoint yang sudah ter-ship (Fase 2+).
    $endpoints['email_sequences'] = '/api/hermes/email-sequences';
    $endpoints['funnel_gate']     = '/api/hermes/funnel-gate';
    $endpoints['offers']          = '/api/hermes/offers';
    $endpoints['broadcast']       = '/api/hermes/broadcast';
    $endpoints['insights']        = '/api/hermes/insights';

    // Daftar metrik baca (bila tier baca aktif) agar Hermes tahu skema tanpa menebak.
    $insights = [];
    foreach (hermesInsightsRegistry() as $metric => $def) {
        $insights[$metric] = [
            'title'       => $def['title'],
            'params'      => array_values($def['params']),
            'description' => $def['description'],
        ];
    }

    return [
        'ok'          => true,
        'app_version' => defined('APP_VERSION') ? APP_VERSION : '',
        'api_version' => '1',
        'agent'       => 'hermes',
        'manage'      => $manage,
        'read'        => $read,
        'capabilities'=> $caps,
        'registry'    => array_map(static fn($c) => [
            'title'           => $c['title'],
            'available'       => $c['available'],
            'requires_manage' => !empty($c['requires_manage']),
            'requires_read'   => !empty($c['requires_read']),
            'description'     => $c['description'],
        ], hermesCapabilityRegistry()),
        'endpoints'   => $endpoints,
        'insights'    => [
            'endpoint'    => '/api/hermes/insights',
            'method'      => 'GET',
            'usage'       => '/api/hermes/insights?metric=<nama>&period=30d',
            'enabled'     => $read,
            'metrics'     => $insights,
            'definitions' => hermesInsightsDefinitions(),
        ],
        'funnel_gate' => hermesFunnelGateSchema(),
        'offers'      => hermesOffersSchema(),
        'broadcast'   => hermesBroadcastSchema(),
        'products'    => hermesProductCatalog(), // id produk agar Hermes bisa menautkan offer
        'constraints' => [
            'sequence_max_steps'      => 50,
            'delay_days_max'          => 365,
            'subject_max'             => 255,
            'body_max_bytes'          => 500000,
            'body_formats'            => ['text', 'html'],
            'ingest_status'           => 'inactive_only', // sequence masuk selalu inactive
            'funnel_gate_max_steps'     => 30,
            'funnel_gate_max_questions' => 100,
            'funnel_gate_max_options'   => 50,
            'funnel_gate_max_rules'     => 30,
            'funnel_gate_status'        => 'draft_only', // form masuk selalu draft
            'offers_types'              => ['upsell', 'bump', 'flash_bonus'],
            'offers_status'             => 'inactive_only', // offer masuk selalu inactive
            'offers_headline_max'       => 200,
            'offers_description_max'     => 1000,
            'broadcast_channels'        => ['email', 'whatsapp'],
            'broadcast_segments'        => ['all_users', 'active_members', 'leads_all', 'product_buyers', 'email_openers', 'email_clickers'],
            'broadcast_status'          => 'draft_only', // campaign masuk selalu draft
            'broadcast_subject_max'     => 200,
            'broadcast_body_max'        => 60000,
        ],
    ];
}

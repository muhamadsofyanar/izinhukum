<?php
// ════════════════════════════════════════════════════════════════
// Averion — Landing Builder (mode 'builder' untuk Canvas)
//
// Visual block-builder no-code. Data disimpan sebagai JSON envelope di
// kolom canvas_pages.content:
//   { "v":1, "layout":"mobile_first", "theme":{...}, "blocks":[{type,cfg}] }
//
// RENDER: server-side JSON → HTML mandiri (CSS inline, TANPA Tailwind —
// build tailwind.css tidak lengkap). CTA meneruskan ?ref= via JS (cache-safe:
// HTML statis, personalisasi per-pengunjung di klien).
//
// EXTENSIBLE: tambah blok baru = tambah 1 entri di avLbRegistry() (fields +
// defaults) lalu 1 case di avLbRenderBlock(). Editor meng-generate form dari
// registry secara otomatis — tak perlu sentuh JS editor.
// ════════════════════════════════════════════════════════════════

if (!function_exists('avLbRegistry')) {

/**
 * Registry tipe blok. Tiap entri:
 *   label    — nama di palet editor
 *   icon     — path 'd' SVG (viewBox 0 0 24 24) untuk ikon palet
 *   fields   — [{key,type,label,placeholder?,help?,options?}]
 *              type: text|textarea|lines|pairs|url|image|number|color|toggle|select|product
 *   defaults — nilai awal cfg
 */
function avLbRegistry(): array
{
    return [
        'hero' => [
            'label' => 'Hero',
            'icon'  => 'M4 5h16v10H4z M4 19h10',
            'fields' => [
                ['key' => 'image',        'type' => 'image',    'label' => 'Gambar (opsional)', 'help' => 'Tampil penuh di atas.'],
                ['key' => 'badge',        'type' => 'text',     'label' => 'Badge (label kecil di atas judul)', 'placeholder' => 'mis. KELAS ONLINE'],
                ['key' => 'headline',     'type' => 'text',     'label' => 'Judul Utama'],
                ['key' => 'subheadline',  'type' => 'textarea', 'label' => 'Sub-judul'],
                ['key' => 'cta_label',    'type' => 'text',     'label' => 'Teks Tombol'],
                ['key' => 'cta_mode',     'type' => 'select',   'label' => 'Tujuan Tombol', 'options' => ['none' => 'Tanpa tombol', 'product' => 'Checkout produk', 'url' => 'URL kustom']],
                ['key' => 'cta_product',  'type' => 'product',  'label' => 'Produk'],
                ['key' => 'cta_url',      'type' => 'url',      'label' => 'URL kustom'],
                ['key' => 'align',        'type' => 'select',   'label' => 'Perataan', 'options' => ['center' => 'Tengah', 'left' => 'Kiri']],
            ],
            'defaults' => [
                'image' => '', 'badge' => '', 'headline' => 'Judul Penawaran Anda', 'subheadline' => 'Jelaskan manfaat utama dalam satu kalimat singkat.',
                'cta_label' => 'Pesan Sekarang', 'cta_mode' => 'none', 'cta_product' => '', 'cta_url' => '', 'align' => 'center',
            ],
        ],
        'text' => [
            'label' => 'Teks',
            'icon'  => 'M4 6h16 M4 12h16 M4 18h10',
            'fields' => [
                ['key' => 'heading', 'type' => 'text',     'label' => 'Judul (opsional)'],
                ['key' => 'body',    'type' => 'textarea', 'label' => 'Isi', 'help' => 'Pisahkan paragraf dengan baris kosong.'],
                ['key' => 'align',   'type' => 'select',   'label' => 'Perataan', 'options' => ['left' => 'Kiri', 'center' => 'Tengah']],
            ],
            'defaults' => ['heading' => '', 'body' => 'Tulis penjelasan di sini.', 'align' => 'left'],
        ],
        'image' => [
            'label' => 'Gambar',
            'icon'  => 'M3 5h18v14H3z M8 11l3 3 3-4 4 5',
            'fields' => [
                ['key' => 'image',   'type' => 'image', 'label' => 'Gambar'],
                ['key' => 'caption', 'type' => 'text',  'label' => 'Keterangan (opsional)'],
                ['key' => 'link',    'type' => 'url',   'label' => 'Tautan saat diklik (opsional)'],
            ],
            'defaults' => ['image' => '', 'caption' => '', 'link' => ''],
        ],
        'video' => [
            'label' => 'Video',
            'icon'  => 'M4 5h16v14H4z M10 9l5 3-5 3z',
            'fields' => [
                ['key' => 'url',     'type' => 'url',  'label' => 'URL YouTube / Vimeo'],
                ['key' => 'heading', 'type' => 'text', 'label' => 'Judul (opsional)'],
            ],
            'defaults' => ['url' => '', 'heading' => ''],
        ],
        'benefits' => [
            'label' => 'Manfaat',
            'icon'  => 'M9 11l3 3L22 4 M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
            'fields' => [
                ['key' => 'heading', 'type' => 'text',  'label' => 'Judul'],
                ['key' => 'items',   'type' => 'lines', 'label' => 'Poin Manfaat', 'help' => 'Satu manfaat per baris.'],
            ],
            'defaults' => ['heading' => 'Yang Anda Dapatkan', 'items' => "Manfaat pertama\nManfaat kedua\nManfaat ketiga"],
        ],
        'cta' => [
            'label' => 'Ajakan (CTA)',
            'icon'  => 'M5 12h14 M13 6l6 6-6 6',
            'fields' => [
                ['key' => 'text',        'type' => 'text',    'label' => 'Teks Ajakan'],
                ['key' => 'cta_label',   'type' => 'text',    'label' => 'Teks Tombol'],
                ['key' => 'cta_mode',    'type' => 'select',  'label' => 'Tujuan Tombol', 'options' => ['product' => 'Checkout produk', 'url' => 'URL kustom']],
                ['key' => 'cta_product', 'type' => 'product', 'label' => 'Produk'],
                ['key' => 'cta_url',     'type' => 'url',     'label' => 'URL kustom'],
            ],
            'defaults' => ['text' => 'Siap memulai?', 'cta_label' => 'Pesan Sekarang', 'cta_mode' => 'product', 'cta_product' => '', 'cta_url' => ''],
        ],
        'pricing' => [
            'label' => 'Harga',
            'icon'  => 'M12 1v22 M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
            'fields' => [
                ['key' => 'title',       'type' => 'text',    'label' => 'Nama Paket'],
                ['key' => 'price',       'type' => 'text',    'label' => 'Harga', 'placeholder' => 'Rp 199.000'],
                ['key' => 'price_note',  'type' => 'text',    'label' => 'Catatan harga (opsional)', 'placeholder' => 'sekali bayar'],
                ['key' => 'features',    'type' => 'lines',   'label' => 'Fitur', 'help' => 'Satu fitur per baris.'],
                ['key' => 'cta_label',   'type' => 'text',    'label' => 'Teks Tombol'],
                ['key' => 'cta_mode',    'type' => 'select',  'label' => 'Tujuan Tombol', 'options' => ['product' => 'Checkout produk', 'url' => 'URL kustom']],
                ['key' => 'cta_product', 'type' => 'product', 'label' => 'Produk'],
                ['key' => 'cta_url',     'type' => 'url',     'label' => 'URL kustom'],
            ],
            'defaults' => ['title' => 'Paket Lengkap', 'price' => 'Rp 199.000', 'price_note' => 'sekali bayar', 'features' => "Akses selamanya\nUpdate gratis\nGaransi 30 hari", 'cta_label' => 'Beli Sekarang', 'cta_mode' => 'product', 'cta_product' => '', 'cta_url' => ''],
        ],
        'testimonial' => [
            'label' => 'Testimoni',
            'icon'  => 'M7 8h10 M7 12h6 M5 20l-2 2V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H9z',
            'fields' => [
                ['key' => 'heading', 'type' => 'text',  'label' => 'Judul (opsional)'],
                ['key' => 'items',   'type' => 'pairs', 'label' => 'Testimoni', 'help' => 'Satu per baris: Nama :: Isi testimoni'],
            ],
            'defaults' => ['heading' => 'Kata Mereka', 'items' => "Budi :: Produk ini mengubah cara saya bekerja.\nSari :: Sangat worth it, recommended!"],
        ],
        'faq' => [
            'label' => 'FAQ',
            'icon'  => 'M12 17h.01 M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3 M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z',
            'fields' => [
                ['key' => 'heading', 'type' => 'text',  'label' => 'Judul'],
                ['key' => 'items',   'type' => 'pairs', 'label' => 'Pertanyaan & Jawaban', 'help' => 'Satu per baris: Pertanyaan :: Jawaban'],
            ],
            'defaults' => ['heading' => 'Pertanyaan Umum', 'items' => "Apakah ada garansi? :: Ya, 30 hari uang kembali.\nBagaimana cara aksesnya? :: Instan via email setelah bayar."],
        ],
        'countdown' => [
            'label' => 'Hitung Mundur',
            'icon'  => 'M12 7v5l3 2 M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z',
            'fields' => [
                ['key' => 'heading', 'type' => 'text', 'label' => 'Judul'],
                ['key' => 'end',     'type' => 'text', 'label' => 'Berakhir', 'placeholder' => '2026-12-31 23:59', 'help' => 'Format: YYYY-MM-DD HH:MM (WIB).'],
                ['key' => 'expired', 'type' => 'text', 'label' => 'Teks saat habis'],
            ],
            'defaults' => ['heading' => 'Penawaran berakhir dalam', 'end' => '', 'expired' => 'Penawaran telah berakhir.'],
        ],
        'lead_form' => [
            'label' => 'Form Lead',
            'icon'  => 'M4 4h16v16H4z M8 9h8 M8 13h5',
            'fields' => [
                ['key' => 'heading',   'type' => 'text',     'label' => 'Judul'],
                ['key' => 'subtext',   'type' => 'textarea', 'label' => 'Teks pendukung', 'help' => 'Butuh Tipe Halaman = "Landing Page Lead Magnet" + lead magnet tertaut agar form aktif.'],
                ['key' => 'button',    'type' => 'text',     'label' => 'Teks Tombol'],
                ['key' => 'ask_email', 'type' => 'toggle',   'label' => 'Minta email juga (selain nama & WhatsApp)'],
            ],
            'defaults' => ['heading' => 'Daftar Sekarang', 'subtext' => 'Isi data untuk akses instan.', 'button' => 'Kirim', 'ask_email' => '1'],
        ],
    ];
}

/** Apakah envelope memuat blok lead_form (→ halaman tak boleh di-cache). */
function avLbHasLeadForm(array $page): bool
{
    if (($page['mode'] ?? '') !== 'builder') return false;
    $env = avLbDecode($page['content'] ?? null);
    foreach ($env['blocks'] as $b) {
        if (($b['type'] ?? '') === 'lead_form') return true;
    }
    return false;
}

/** Default tema. */
function avLbThemeDefaults(): array
{
    $brand = function_exists('getSetting') ? (string) getSetting('brand_color', '#6366f1') : '#6366f1';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brand)) $brand = '#6366f1';
    return ['brand' => $brand, 'bg' => '#ffffff', 'text' => '#0f172a', 'font' => 'system'];
}

/** Decode envelope content → struktur ternormalisasi (selalu valid). */
function avLbDecode(?string $content): array
{
    $data = $content !== null && $content !== '' ? json_decode($content, true) : null;
    if (!is_array($data)) $data = [];
    $theme = array_merge(avLbThemeDefaults(), is_array($data['theme'] ?? null) ? $data['theme'] : []);
    $blocks = [];
    foreach ((is_array($data['blocks'] ?? null) ? $data['blocks'] : []) as $b) {
        if (!is_array($b)) continue;
        $type = (string) ($b['type'] ?? '');
        if (!isset(avLbRegistry()[$type])) continue;
        $cfg = is_array($b['cfg'] ?? null) ? $b['cfg'] : [];
        $blocks[] = ['type' => $type, 'cfg' => $cfg];
    }
    return [
        'v'      => 1,
        'layout' => ($data['layout'] ?? '') === 'responsive' ? 'responsive' : 'mobile_first',
        'theme'  => $theme,
        'blocks' => $blocks,
    ];
}

/**
 * Validasi + kanonikalisasi envelope dari input editor → JSON string siap simpan.
 * Membuang tipe/field tak dikenal (allow-list dari registry). Semua string di-trim.
 */
function avLbSanitizeEnvelope($raw): string
{
    $data = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
    if (!is_array($data)) $data = [];
    $reg = avLbRegistry();

    $theme = avLbThemeDefaults();
    if (is_array($data['theme'] ?? null)) {
        foreach (['brand', 'bg', 'text'] as $k) {
            $v = (string) ($data['theme'][$k] ?? '');
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) $theme[$k] = strtolower($v);
        }
        $font = (string) ($data['theme']['font'] ?? '');
        if (in_array($font, ['system', 'serif', 'rounded'], true)) $theme['font'] = $font;
    }

    $blocks = [];
    foreach ((is_array($data['blocks'] ?? null) ? $data['blocks'] : []) as $b) {
        if (!is_array($b)) continue;
        $type = (string) ($b['type'] ?? '');
        if (!isset($reg[$type])) continue;
        $inCfg  = is_array($b['cfg'] ?? null) ? $b['cfg'] : [];
        $outCfg = [];
        foreach ($reg[$type]['fields'] as $f) {
            $key = $f['key'];
            $val = $inCfg[$key] ?? ($reg[$type]['defaults'][$key] ?? '');
            if ($f['type'] === 'toggle') {
                $outCfg[$key] = !empty($val) ? '1' : '0';
            } elseif ($f['type'] === 'number') {
                $outCfg[$key] = (string) (int) $val;
            } elseif ($f['type'] === 'product') {
                $outCfg[$key] = (string) (int) $val;
            } else {
                // Buang tag HTML (XSS-safe; render meng-escape lagi). Batasi panjang.
                $s = is_string($val) ? $val : (is_scalar($val) ? (string) $val : '');
                $s = strip_tags($s);
                $max = in_array($f['type'], ['textarea', 'lines', 'pairs'], true) ? 8000 : 2000;
                if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
                $outCfg[$key] = trim($s);
            }
        }
        $blocks[] = ['type' => $type, 'cfg' => $outCfg];
        if (count($blocks) >= 60) break; // batas wajar
    }

    return json_encode([
        'v'      => 1,
        'layout' => ($data['layout'] ?? '') === 'responsive' ? 'responsive' : 'mobile_first',
        'theme'  => $theme,
        'blocks' => $blocks,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Pisah textarea → array baris non-kosong (field 'lines'). */
function avLbLines(string $s): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $s) as $ln) {
        $ln = trim($ln);
        if ($ln !== '') $out[] = $ln;
    }
    return $out;
}

/** Pisah field 'pairs' → [[a,b], ...] via pemisah ' :: '. */
function avLbPairs(string $s): array
{
    $out = [];
    foreach (avLbLines($s) as $ln) {
        $parts = preg_split('/\s*::\s*/', $ln, 2);
        $out[] = [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
    }
    return $out;
}

/** Bangun href tombol CTA. Return [href, isInternal] atau null bila tak ada. */
function avLbCtaHref(array $cfg, array $ctx): ?array
{
    $mode = $cfg['cta_mode'] ?? 'none';
    if ($mode === 'product') {
        $pid = (int) ($cfg['cta_product'] ?? 0);
        $slug = $ctx['products'][$pid]['slug'] ?? '';
        if ($slug === '') return null;
        return [rtrim($ctx['app_url'], '/') . '/order/' . rawurlencode($slug), true];
    }
    if ($mode === 'url') {
        $u = trim((string) ($cfg['cta_url'] ?? ''));
        if ($u === '' || !preg_match('#^https?://#i', $u)) return null;
        return [$u, false];
    }
    return null;
}

/** HTML satu tombol CTA (class lp-cta → ref-forward JS). */
function avLbCtaButton(array $cfg, array $ctx, string $labelKey = 'cta_label'): string
{
    $href = avLbCtaHref($cfg, $ctx);
    if ($href === null) return '';
    $label = trim((string) ($cfg[$labelKey] ?? '')) ?: 'Pesan Sekarang';
    $rel = $href[1] ? '' : ' target="_blank" rel="noopener nofollow"';
    return '<a class="lp-cta" data-cta="' . ($href[1] ? '1' : '0') . '" href="' . e($href[0]) . '"' . $rel . '>' . e($label) . '</a>';
}

/** Embed video (YouTube/Vimeo) dari URL → iframe responsif, atau '' bila invalid. */
function avLbVideoEmbed(string $url): string
{
    $url = trim($url);
    $embed = '';
    if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{6,})#i', $url, $m)) {
        $embed = 'https://www.youtube.com/embed/' . $m[1];
    } elseif (preg_match('#vimeo\.com/(?:video/)?(\d+)#i', $url, $m)) {
        $embed = 'https://player.vimeo.com/video/' . $m[1];
    }
    if ($embed === '') return '';
    return '<div class="lp-video"><iframe src="' . e($embed) . '" title="Video" loading="lazy" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>';
}

/** Render satu blok → HTML (sudah di-escape). */
function avLbRenderBlock(string $type, array $cfg, array $ctx): string
{
    switch ($type) {

        case 'hero': {
            $align = ($cfg['align'] ?? 'center') === 'left' ? 'left' : 'center';
            $h = '<section class="lp-sec lp-hero" style="text-align:' . $align . '">';
            if (trim((string) ($cfg['image'] ?? '')) !== '') {
                $h .= '<img class="lp-hero-img" src="' . e($cfg['image']) . '" alt="" loading="eager">';
            }
            $h .= '<div class="lp-hero-body">';
            if (trim((string) ($cfg['badge'] ?? '')) !== '')       $h .= '<span class="lp-badge">' . e($cfg['badge']) . '</span>';
            if (trim((string) ($cfg['headline'] ?? '')) !== '')    $h .= '<h1 class="lp-h1">' . e($cfg['headline']) . '</h1>';
            if (trim((string) ($cfg['subheadline'] ?? '')) !== '') $h .= '<p class="lp-sub">' . nl2br(e($cfg['subheadline'])) . '</p>';
            if (($cfg['cta_mode'] ?? 'none') !== 'none')           $h .= avLbCtaButton($cfg, $ctx);
            $h .= '</div></section>';
            return $h;
        }

        case 'text': {
            $align = ($cfg['align'] ?? 'left') === 'center' ? 'center' : 'left';
            $h = '<section class="lp-sec lp-text" style="text-align:' . $align . '">';
            if (trim((string) ($cfg['heading'] ?? '')) !== '') $h .= '<h2 class="lp-h2">' . e($cfg['heading']) . '</h2>';
            foreach (preg_split('/\n\s*\n/', (string) ($cfg['body'] ?? '')) as $para) {
                $para = trim($para);
                if ($para !== '') $h .= '<p class="lp-p">' . nl2br(e($para)) . '</p>';
            }
            return $h . '</section>';
        }

        case 'image': {
            $src = trim((string) ($cfg['image'] ?? ''));
            if ($src === '') return '';
            $img = '<img class="lp-img" src="' . e($src) . '" alt="' . e((string) ($cfg['caption'] ?? '')) . '" loading="lazy">';
            $link = trim((string) ($cfg['link'] ?? ''));
            if ($link !== '' && preg_match('#^https?://#i', $link)) {
                $img = '<a href="' . e($link) . '" target="_blank" rel="noopener">' . $img . '</a>';
            }
            $h = '<section class="lp-sec lp-imgblock">' . $img;
            if (trim((string) ($cfg['caption'] ?? '')) !== '') $h .= '<p class="lp-cap">' . e($cfg['caption']) . '</p>';
            return $h . '</section>';
        }

        case 'video': {
            $embed = avLbVideoEmbed((string) ($cfg['url'] ?? ''));
            if ($embed === '') return '';
            $h = '<section class="lp-sec lp-videoblock">';
            if (trim((string) ($cfg['heading'] ?? '')) !== '') $h .= '<h2 class="lp-h2">' . e($cfg['heading']) . '</h2>';
            return $h . $embed . '</section>';
        }

        case 'benefits': {
            $items = avLbLines((string) ($cfg['items'] ?? ''));
            if (!$items) return '';
            $h = '<section class="lp-sec lp-benefits">';
            if (trim((string) ($cfg['heading'] ?? '')) !== '') $h .= '<h2 class="lp-h2">' . e($cfg['heading']) . '</h2>';
            $h .= '<ul class="lp-blist">';
            $check = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
            foreach ($items as $it) $h .= '<li>' . $check . '<span>' . e($it) . '</span></li>';
            return $h . '</ul></section>';
        }

        case 'cta': {
            $h = '<section class="lp-sec lp-ctaband">';
            if (trim((string) ($cfg['text'] ?? '')) !== '') $h .= '<p class="lp-ctatext">' . e($cfg['text']) . '</p>';
            $h .= avLbCtaButton($cfg, $ctx);
            return $h . '</section>';
        }

        case 'pricing': {
            $h = '<section class="lp-sec lp-pricing"><div class="lp-pricecard">';
            if (trim((string) ($cfg['title'] ?? '')) !== '') $h .= '<p class="lp-ptitle">' . e($cfg['title']) . '</p>';
            if (trim((string) ($cfg['price'] ?? '')) !== '') {
                $h .= '<p class="lp-price">' . e($cfg['price']);
                if (trim((string) ($cfg['price_note'] ?? '')) !== '') $h .= '<span class="lp-pnote"> ' . e($cfg['price_note']) . '</span>';
                $h .= '</p>';
            }
            $feats = avLbLines((string) ($cfg['features'] ?? ''));
            if ($feats) {
                $h .= '<ul class="lp-blist">';
                $check = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
                foreach ($feats as $ft) $h .= '<li>' . $check . '<span>' . e($ft) . '</span></li>';
                $h .= '</ul>';
            }
            $h .= avLbCtaButton($cfg, $ctx);
            return $h . '</div></section>';
        }

        case 'testimonial': {
            $pairs = avLbPairs((string) ($cfg['items'] ?? ''));
            if (!$pairs) return '';
            $h = '<section class="lp-sec lp-testi">';
            if (trim((string) ($cfg['heading'] ?? '')) !== '') $h .= '<h2 class="lp-h2">' . e($cfg['heading']) . '</h2>';
            foreach ($pairs as [$name, $quote]) {
                if ($quote === '' && $name === '') continue;
                $h .= '<figure class="lp-tcard"><blockquote>' . e($quote !== '' ? $quote : $name) . '</blockquote>';
                if ($quote !== '' && $name !== '') $h .= '<figcaption>— ' . e($name) . '</figcaption>';
                $h .= '</figure>';
            }
            return $h . '</section>';
        }

        case 'faq': {
            $pairs = avLbPairs((string) ($cfg['items'] ?? ''));
            if (!$pairs) return '';
            $h = '<section class="lp-sec lp-faq">';
            if (trim((string) ($cfg['heading'] ?? '')) !== '') $h .= '<h2 class="lp-h2">' . e($cfg['heading']) . '</h2>';
            foreach ($pairs as [$q, $a]) {
                if ($q === '') continue;
                $h .= '<details class="lp-faqitem"><summary>' . e($q) . '</summary><div class="lp-faqa">' . nl2br(e($a)) . '</div></details>';
            }
            return $h . '</section>';
        }

        case 'countdown': {
            $end = trim((string) ($cfg['end'] ?? ''));
            $ts  = $end !== '' ? strtotime($end) : 0;
            $h = '<section class="lp-sec lp-countdown">';
            if (trim((string) ($cfg['heading'] ?? '')) !== '') $h .= '<p class="lp-cdhead">' . e($cfg['heading']) . '</p>';
            if ($ts > 0) {
                $h .= '<div class="lp-cd" data-end="' . (int) $ts . '" data-expired="' . e((string) ($cfg['expired'] ?? '')) . '">'
                    . '<div class="lp-cdbox"><b data-d>00</b><small>Hari</small></div>'
                    . '<div class="lp-cdbox"><b data-h>00</b><small>Jam</small></div>'
                    . '<div class="lp-cdbox"><b data-m>00</b><small>Menit</small></div>'
                    . '<div class="lp-cdbox"><b data-s>00</b><small>Detik</small></div>'
                    . '</div>';
            }
            return $h . '</section>';
        }

        case 'lead_form': {
            $magnet = $ctx['magnet'] ?? null;
            $h = '<section class="lp-sec lp-leadform">';
            if (trim((string) ($cfg['heading'] ?? '')) !== '') $h .= '<h2 class="lp-h2">' . e($cfg['heading']) . '</h2>';
            if (trim((string) ($cfg['subtext'] ?? '')) !== '') $h .= '<p class="lp-sub" style="margin-bottom:14px">' . nl2br(e($cfg['subtext'])) . '</p>';

            if (!$magnet) {
                // Belum ada lead magnet tertaut → pesan (admin yang lihat saat setup).
                $h .= '<div class="lp-formnote">Form belum aktif. Setel <strong>Tipe Halaman = Landing Page Lead Magnet</strong> lalu tautkan sebuah lead magnet.</div>';
                return $h . '</section>';
            }

            // Magnet ber-OTP → alihkan ke halaman pendaftaran khusus (alur OTP lengkap).
            if (!empty($magnet['require_otp'])) {
                $h .= '<a class="lp-cta" href="' . e(rtrim($ctx['app_url'], '/') . '/lp/' . rawurlencode($magnet['slug'])) . '">'
                    . e(trim((string) ($cfg['button'] ?? '')) ?: 'Daftar Sekarang') . '</a>';
                return $h . '</section>';
            }

            // Form inline → submit-lead.php (storage + notifikasi + akses bonus).
            $askEmail = ($cfg['ask_email'] ?? '1') === '1';
            $btn   = trim((string) ($cfg['button'] ?? '')) ?: 'Kirim';
            $h .= '<form class="lp-form" method="POST" action="' . e(rtrim($ctx['app_url'], '/') . '/actions/public/submit-lead') . '">'
                . '<input type="hidden" name="csrf_token" value="' . e((string) ($ctx['csrf'] ?? '')) . '">'
                . '<input type="hidden" name="slug" value="' . e($magnet['slug']) . '">'
                . '<input type="hidden" name="lead_magnet_id" value="' . (int) $magnet['id'] . '">'
                . '<input type="text" name="name" required placeholder="Nama lengkap" autocomplete="name">'
                . '<input type="tel" name="whatsapp" required placeholder="Nomor WhatsApp (08xx)" inputmode="numeric" autocomplete="tel">';
            if ($askEmail) {
                $h .= '<input type="email" name="email" placeholder="Email (opsional)" autocomplete="email">';
            }
            $h .= '<button type="submit" class="lp-cta" style="width:100%;text-align:center">' . e($btn) . '</button>'
                . '</form>';
            return $h . '</section>';
        }
    }
    return '';
}

/** CSS shell mandiri (mobile-first). */
function avLbShellCss(array $theme): string
{
    $fontStack = match ($theme['font'] ?? 'system') {
        'serif'   => 'Georgia,"Times New Roman",serif',
        'rounded' => '"Segoe UI",Verdana,system-ui,sans-serif',
        default   => 'system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif',
    };
    $brand = $theme['brand'];
    $bg    = $theme['bg'];
    $text  = $theme['text'];
    return <<<CSS
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background-color:#eef1f6;background-image:radial-gradient(circle, rgba(15,23,42,.06) 1px, transparent 1.4px);background-size:18px 18px;color:{$text};font-family:{$fontStack};line-height:1.6;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}
img{max-width:100%;height:auto;display:block}
a{color:inherit}
.lp-wrap{max-width:480px;margin:0 auto;min-height:100vh;background:{$bg}}
.lp-sec{padding:24px 20px}
.lp-sec+.lp-sec{padding-top:2px}
.lp-sec:first-child{padding-top:26px}
.lp-h1{font-size:26px;line-height:1.25;font-weight:800;margin:0 0 10px;letter-spacing:-.01em}
.lp-h2{font-size:19px;font-weight:700;margin:0 0 12px;letter-spacing:-.01em}
.lp-sub{font-size:15px;color:#52607a;margin:0 0 18px}
.lp-p{font-size:15px;margin:0 0 12px;color:#374151}
.lp-hero-img{width:100%;border-radius:8px;margin-bottom:16px}
.lp-badge{display:inline-block;background:color-mix(in srgb,{$brand} 12%,transparent);color:{$brand};font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:5px 11px;border-radius:5px;margin-bottom:12px}
.lp-cta{display:inline-block;background:{$brand};color:#fff;font-weight:700;font-size:15px;padding:13px 26px;border-radius:7px;text-decoration:none;box-shadow:0 5px 14px color-mix(in srgb,{$brand} 32%,transparent);transition:transform .12s,box-shadow .12s}
.lp-cta:active{transform:translateY(1px)}
.lp-img{width:100%;border-radius:8px}
.lp-cap{font-size:13px;color:#64748b;text-align:center;margin:8px 0 0}
.lp-video{position:relative;padding-top:56.25%;border-radius:8px;overflow:hidden;background:#000}
.lp-video iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
.lp-blist{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:11px}
.lp-blist li{display:flex;gap:10px;align-items:flex-start;font-size:15px}
.lp-blist svg{width:20px;height:20px;flex:none;color:{$brand};margin-top:1px}
.lp-ctaband{text-align:center;background:color-mix(in srgb,{$brand} 7%,{$bg});border-top:1px solid color-mix(in srgb,{$brand} 14%,transparent);border-bottom:1px solid color-mix(in srgb,{$brand} 14%,transparent)}
.lp-ctatext{font-size:17px;font-weight:700;margin:0 0 14px}
.lp-pricecard{border:1px solid #e5e7eb;border-radius:10px;padding:22px;text-align:center;background:#fff}
.lp-ptitle{font-size:14px;font-weight:600;color:#64748b;margin:0 0 6px;text-transform:uppercase;letter-spacing:.04em}
.lp-price{font-size:32px;font-weight:800;margin:0 0 4px;color:{$text}}
.lp-pnote{font-size:14px;font-weight:500;color:#94a3b8}
.lp-pricecard .lp-blist{margin:16px 0 20px;text-align:left}
.lp-tcard{margin:0 0 14px;padding:16px;border-radius:8px;background:color-mix(in srgb,{$brand} 5%,{$bg});border:1px solid #eef1f6}
.lp-tcard blockquote{margin:0;font-size:15px;font-style:italic;color:#374151}
.lp-tcard figcaption{margin-top:8px;font-size:13px;font-weight:600;color:{$brand}}
.lp-faqitem{border-bottom:1px solid #eef1f6}
.lp-faqitem summary{cursor:pointer;list-style:none;padding:13px 0;font-weight:600;font-size:15px;display:flex;justify-content:space-between;gap:10px}
.lp-faqitem summary::-webkit-details-marker{display:none}
.lp-faqitem summary::after{content:"+";color:{$brand};font-weight:700}
.lp-faqitem[open] summary::after{content:"\\2212"}
.lp-faqa{padding:0 0 14px;font-size:14px;color:#52607a}
.lp-countdown{text-align:center}
.lp-cdhead{font-size:15px;font-weight:600;margin:0 0 12px;color:#52607a}
.lp-cd{display:flex;gap:10px;justify-content:center}
.lp-cdbox{background:{$text};color:#fff;border-radius:7px;padding:10px 0;min-width:62px}
.lp-cdbox b{display:block;font-size:24px;font-weight:800;line-height:1}
.lp-cdbox small{font-size:11px;opacity:.75}
.lp-form{display:flex;flex-direction:column;gap:10px}
.lp-form input{width:100%;font-size:15px;padding:12px 14px;border:1px solid #d1d5db;border-radius:7px;outline:none;background:#fff;color:{$text}}
.lp-form input:focus{border-color:{$brand};box-shadow:0 0 0 3px color-mix(in srgb,{$brand} 18%,transparent)}
.lp-formnote{font-size:13px;color:#92650a;background:#fef9c3;border:1px solid #fde68a;border-radius:7px;padding:12px 14px}
.lp-footer{margin-top:8px;padding:24px 20px 36px;border-top:1px solid #eef1f6;text-align:center}
.lp-foot-brand{font-size:15px;font-weight:700;color:{$text};margin:0 0 4px;letter-spacing:-.01em}
.lp-foot-copy{font-size:12px;color:#94a3b8;margin:0}
.lp-empty{padding:60px 20px;text-align:center;color:#94a3b8;font-size:14px}
CSS;
}

/** JS mandiri: teruskan query (?ref/utm) ke CTA internal + jalankan countdown. */
function avLbRuntimeJs(): string
{
    return <<<'JS'
(function(){
  // Teruskan query masuk (ref/utm/dll) ke tombol checkout internal — cache-safe.
  try {
    var q = location.search.replace(/^\?/, '');
    if (q) {
      document.querySelectorAll('a.lp-cta[data-cta="1"]').forEach(function(a){
        try {
          var u = new URL(a.getAttribute('href'), location.origin);
          new URLSearchParams(q).forEach(function(v,k){ if(!u.searchParams.has(k)) u.searchParams.set(k,v); });
          a.setAttribute('href', u.pathname + u.search + u.hash);
        } catch(e){}
      });
    }
  } catch(e){}
  // Countdown.
  document.querySelectorAll('.lp-cd').forEach(function(el){
    var end = parseInt(el.getAttribute('data-end'),10)*1000;
    var exp = el.getAttribute('data-expired')||'';
    function pad(n){return (n<10?'0':'')+n;}
    function tick(){
      var d = end - Date.now();
      if (d <= 0){ el.innerHTML = '<p style="margin:0;font-weight:600">'+exp.replace(/[<>&]/g,'')+'</p>'; return; }
      var s=Math.floor(d/1000);
      var dd=Math.floor(s/86400), hh=Math.floor(s%86400/3600), mm=Math.floor(s%3600/60), ss=s%60;
      var set=function(sel,v){var n=el.querySelector(sel); if(n) n.textContent=pad(v);};
      set('[data-d]',dd); set('[data-h]',hh); set('[data-m]',mm); set('[data-s]',ss);
      setTimeout(tick, 1000);
    }
    tick();
  });
})();
JS;
}

/**
 * Render halaman builder penuh → string HTML mandiri.
 * Pixel per-halaman disuntik oleh pemanggil (canvas-render) ke <head>.
 */
function avLbRenderPage(array $page): string
{
    $env    = avLbDecode($page['content'] ?? null);
    $theme  = $env['theme'];
    $blocks = $env['blocks'];

    $examLanding = ($page['page_type'] ?? '') === 'product_landing'
        && in_array($page['slug'] ?? '', ['ujian-wsl-1', 'ujian-wsl-2', 'ujian-wst-health', 'ujian-wst-learning', 'ujian-wst-tahfizh', 'beli-voucher-stifin'], true);
    if ($examLanding) {
        $sampleFeatures = avLbRegistry()['pricing']['defaults']['features'];
        foreach ($blocks as &$block) {
            if ($block['type'] === 'pricing' && ($block['cfg']['features'] ?? '') === $sampleFeatures) {
                $block['cfg']['features'] = '';
            }
        }
        unset($block);
    }

    // Konteks: map produk id → {slug,name} untuk resolusi CTA.
    $ctx = ['app_url' => rtrim(APP_URL, '/'), 'products' => [], 'magnet' => null, 'csrf' => ''];
    $pids = [];
    $hasForm = false;
    foreach ($blocks as $b) {
        if (!empty($b['cfg']['cta_product'])) $pids[(int) $b['cfg']['cta_product']] = true;
        if (($b['type'] ?? '') === 'lead_form') $hasForm = true;
    }
    // Form lead reuse pipeline submit-lead.php → butuh lead magnet tertaut + CSRF.
    if ($hasForm) {
        if (function_exists('generateCSRF')) { generateCSRF(); }
        $ctx['csrf'] = (string) ($_SESSION['csrf_token'] ?? '');
        $lmId = (int) ($page['linked_lead_magnet_id'] ?? 0);
        if ($lmId > 0) {
            try {
                $ms = getDB()->prepare("SELECT id, slug, title, require_otp FROM lead_magnets WHERE id = ? AND status = 'published' LIMIT 1");
                $ms->execute([$lmId]);
                $m = $ms->fetch();
                if ($m) $ctx['magnet'] = ['id' => (int) $m['id'], 'slug' => (string) $m['slug'], 'title' => (string) $m['title'], 'require_otp' => (int) $m['require_otp']];
            } catch (Throwable $e) { /* magnet tak ada → form tampil pesan */ }
        }
    }
    if ($pids) {
        try {
            $ph = implode(',', array_fill(0, count($pids), '?'));
            $st = getDB()->prepare("SELECT id, slug, name FROM products WHERE id IN ($ph)");
            $st->execute(array_keys($pids));
            foreach ($st->fetchAll() as $r) {
                $ctx['products'][(int) $r['id']] = ['slug' => (string) $r['slug'], 'name' => (string) $r['name']];
            }
        } catch (Throwable $e) { /* produk tak ada → CTA disembunyikan */ }
    }

    $body = '';
    foreach ($blocks as $b) {
        $body .= avLbRenderBlock($b['type'], $b['cfg'], $ctx);
    }
    if (trim($body) === '') {
        $body = '<div class="lp-empty">Halaman ini belum memiliki konten.</div>';
    }

    // Footer white-label (nama situs dari setting). Selalu tampil di dasar kolom.
    $appName = function_exists('getSetting') ? trim((string) getSetting('app_name', '')) : '';
    if ($appName === '') $appName = 'Averion';
    $footer = '<footer class="lp-footer">'
            . '<p class="lp-foot-brand">' . e($appName) . '</p>'
            . '<p class="lp-foot-copy">&copy; ' . date('Y') . ' ' . e($appName) . '. Semua hak dilindungi.</p>'
            . '</footer>';

    $title = trim((string) ($page['title'] ?? 'Landing Page'));
    $desc  = trim((string) ($page['meta_desc'] ?? ''));
    $css   = avLbShellCss($theme);
    if ($examLanding) {
        $css .= <<<'CSS'

.lp-exam .lp-hero{padding:38px 24px;background:color-mix(in srgb,currentColor 5%,transparent)}
.lp-exam .lp-pricecard .lp-cta{margin-top:14px}
@media (min-width: 760px) {
  .lp-exam{max-width:980px;min-height:auto;margin:32px auto;border-radius:20px;overflow:hidden;box-shadow:0 16px 48px #172b241a}
  .lp-exam .lp-hero{padding:64px 44px}
  .lp-exam .lp-h1{font-size:clamp(32px,4vw,46px)}
  .lp-exam .lp-sub{max-width:60ch;margin:0 auto 22px}
  .lp-exam .lp-sec:not(.lp-hero){padding:24px 52px}
  .lp-exam .lp-pricecard{max-width:440px;margin:0 auto}
}
CSS;
    }
    $js    = avLbRuntimeJs();

    $head = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">'
          . '<meta name="viewport" content="width=device-width,initial-scale=1">'
          . '<title>' . e($title) . '</title>';
    if ($desc !== '') $head .= '<meta name="description" content="' . e($desc) . '">';
    $head .= '<style>' . $css . '</style></head>';

    return $head . '<body><div class="lp-wrap' . ($examLanding ? ' lp-exam' : '') . '">' . $body . $footer . '</div><script>' . $js . '</script></body></html>';
}

} // function_exists guard

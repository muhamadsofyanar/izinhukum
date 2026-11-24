<?php
// ─── Averion UI Theme / Appearance Engine ─────────────────────────
// Mendefinisikan preset theme dashboard. TIDAK menyentuh brand_color (rebrand)
// maupun dark mode — keduanya tetap berjalan independen. Theme hanya menambah
// CSS variable & class kosmetik pada area dashboard.

/**
 * Daftar preset theme. Tiap preset berisi nilai CSS variable + metadata UI.
 * --card-style adalah FLAG untuk PHP conditional (bukan CSS variable nyata),
 * dibaca di pages/admin/dashboard.php & pages/user/dashboard.php:
 *   'flat'     → kartu statistik = .card-base bg-white polos (TANPA .stat-card-N).
 *                Theme "kartu putih beraksen" (Slate/Ink) pakai ini + aksen di
 *                .card-base agar teks tetap gelap (tak kena aturan hero-putih).
 *   'gradient' → kartu statistik dapat class .stat-card-N → di-hook CSS theme
 *                (hero/gradient berwarna, teks kartu-1 otomatis putih).
 * 'preview_style' (opsional) mengendalikan tampilan Live Preview di /admin/branding:
 *   flat|hero|gradient|gradient-warm|frost|emerald|accent-left|accent-top.
 * 'preview_gradients' (opsional) = 3 warna kartu untuk preview gradient/gradient-warm.
 */
function getAllThemePresets(): array
{
    return [
        // Bahasa desain: KOTAK-TEGAS (radius kecil ala Linear/Stripe) — keputusan
        // user 12 Jul 2026. --control-radius utk elemen dalam (input/tombol/ikon).
        'default' => [
            'label'           => 'Default',
            'description'     => 'Clean dan profesional, nuansa flat modern',
            '--bg-canvas'     => '#f9fafb',
            '--card-radius'   => '0.5rem',
            '--control-radius'=> '0.375rem',
            '--card-shadow'   => '0 1px 3px rgba(0,0,0,0.10)',
            '--card-style'    => 'flat',
            '--sidebar-radius'=> '0px',
            'preview_class'   => '',
            'preview_style'   => 'flat',
        ],
        'airy' => [
            'label'           => 'Airy',
            'description'     => 'Kartu hero gradient brand, nuansa lembut — terasa premium',
            '--bg-canvas'     => '#f0f0f7',
            '--card-radius'   => '0.5rem',
            '--control-radius'=> '0.375rem',
            '--card-shadow'   => '0 8px 32px rgba(99,102,241,0.10)',
            '--card-style'    => 'gradient',
            '--sidebar-radius'=> '0.5rem',
            'preview_class'   => 'theme-airy',
            'preview_style'   => 'hero',
        ],
        // Kartu statistik gradient multi-warna (gaya Airy generasi awal yang
        // disukai user) — pilihan sadar utk yang suka nuansa ceria; Airy tetap
        // satu-brand. Radius tetap kotak-tegas sesuai bahasa desain.
        'vivid' => [
            'label'           => 'Vivid',
            'description'     => 'Kartu gradient warna-warni yang hidup — ceria dan penuh energi',
            '--bg-canvas'     => '#f4f4fb',
            '--card-radius'   => '0.5rem',
            '--control-radius'=> '0.375rem',
            '--card-shadow'   => '0 8px 32px rgba(139,92,246,0.12)',
            '--card-style'    => 'gradient',
            '--sidebar-radius'=> '0.5rem',
            'preview_class'   => 'theme-vivid',
            'preview_style'   => 'gradient',
            'preview_gradients' => [
                'linear-gradient(135deg, #6ee7f7, #60a5fa)',
                'linear-gradient(135deg, #a78bfa, #818cf8)',
                'linear-gradient(135deg, #f9a8d4, #f472b6)',
            ],
        ],
        'corporate' => [
            'label'           => 'Corporate',
            'description'     => 'Sharp & dense — padat informasi, cocok untuk power user',
            '--bg-canvas'     => '#f1f5f9',
            '--card-radius'   => '0.25rem',
            '--control-radius'=> '0.125rem',
            '--card-shadow'   => '0 1px 2px rgba(0,0,0,0.08)',
            '--card-style'    => 'flat',
            '--sidebar-radius'=> '0px',
            'preview_class'   => 'theme-corporate',
            'preview_style'   => 'flat',
        ],
        'minimal' => [
            'label'           => 'Minimal',
            'description'     => 'Ultra clean, borderless, lapang',
            '--bg-canvas'     => '#ffffff',
            '--card-radius'   => '0.625rem',
            '--control-radius'=> '0.5rem',
            '--card-shadow'   => 'none',
            '--card-style'    => 'flat',
            '--sidebar-radius'=> '0.5rem',
            'preview_class'   => 'theme-minimal',
            'preview_style'   => 'flat',
        ],
        // Dark-first luxe (16 Jul 2026): identitas tema ada di DARK MODE —
        // charcoal hangat kecokelatan + aura brand (gaya dashboard fintech
        // premium). Toggle dark/light tetap dihormati: light mode = warm ivory
        // pendamping. Aksen SELALU diturunkan dari brand_color (white-label,
        // TIDAK hardcode oranye) — nilai di bawah hanya utk light mode; palet
        // dark ada di app.css (html.dark.theme-noir). Radius 0.75rem = pilihan
        // sadar sedikit di atas skala kotak-tegas, meniru referensi.
        'noir' => [
            'label'           => 'Noir',
            'description'     => 'Dark-first premium — charcoal hangat + aura brand. Terbaik dengan dark mode aktif',
            '--bg-canvas'     => '#f7f4ee',
            '--card-radius'   => '0.75rem',
            '--control-radius'=> '0.5rem',
            '--card-shadow'   => '0 10px 36px rgba(64,46,20,0.10)',
            '--card-style'    => 'gradient',
            '--sidebar-radius'=> '0.5rem',
            'preview_class'   => 'theme-noir',
            'preview_style'   => 'hero',
        ],
        'frost' => [
            'label'           => 'Frost',
            'description'     => 'Glassmorphism crystal — kartu transparan buram (backdrop blur) dengan border halus melayang',
            '--bg-canvas'     => '#f2f4f8',
            '--card-radius'   => '0.875rem',
            '--control-radius'=> '0.5rem',
            '--card-shadow'   => '0 12px 40px rgba(100,116,139,0.08)',
            '--card-style'    => 'flat',
            '--sidebar-radius'=> '0.75rem',
            'preview_class'   => 'theme-frost',
            'preview_style'   => 'frost',
        ],
        'emerald' => [
            'label'           => 'Emerald Luxe',
            'description'     => 'Executive prestige — kombinasi zamrud hijau mewah dan emas metalik yang berkelas tinggi',
            '--bg-canvas'     => '#f3f6f3',
            '--card-radius'   => '0.5rem',
            '--control-radius'=> '0.375rem',
            '--card-shadow'   => '0 8px 30px rgba(16,185,129,0.08)',
            '--card-style'    => 'gradient',
            '--sidebar-radius'=> '0.375rem',
            'preview_class'   => 'theme-emerald',
            'preview_style'   => 'emerald',
        ],
        // ── Preset baru (P2, 15 Agu 2026) — memperluas variasi tanpa mengubah
        //    arsitektur. Aksen tetap diturunkan dari brand_color (white-label);
        //    yang divariasikan = tekstur/neutral/bentuk kartu. Rose & Sunset
        //    bergaya kartu berwarna (gradient); Slate & Ink kartu putih beraksen
        //    (flat) agar teks tetap gelap terbaca — lihat catatan --card-style. ──
        'rose' => [
            'label'           => 'Rose Quartz',
            'description'     => 'Blush hangat, kartu hero brand — lembut & ramah',
            '--bg-canvas'     => '#fdf4f4',
            '--card-radius'   => '0.75rem',
            '--control-radius'=> '0.5rem',
            '--card-shadow'   => '0 8px 30px rgba(190,90,120,0.10)',
            '--card-style'    => 'gradient',
            '--sidebar-radius'=> '0.5rem',
            'preview_class'   => 'theme-rose',
            'preview_style'   => 'hero',
        ],
        'slate' => [
            'label'           => 'Slate Pro',
            'description'     => 'Netral abu dingin, kartu beraksen garis brand — profesional & tenang',
            '--bg-canvas'     => '#f1f5f9',
            '--card-radius'   => '0.625rem',
            '--control-radius'=> '0.375rem',
            '--card-shadow'   => '0 2px 8px rgba(15,23,42,0.07)',
            '--card-style'    => 'flat',
            '--sidebar-radius'=> '0.375rem',
            'preview_class'   => 'theme-slate',
            'preview_style'   => 'accent-left',
        ],
        'mono' => [
            'label'           => 'Ink Mono',
            'description'     => 'Editorial monokrom, garis rambut + aksen brand tipis — tegas & fokus',
            '--bg-canvas'     => '#fafafa',
            '--card-radius'   => '0.25rem',
            '--control-radius'=> '0.125rem',
            '--card-shadow'   => '0 1px 2px rgba(0,0,0,0.06)',
            '--card-style'    => 'flat',
            '--sidebar-radius'=> '0px',
            'preview_class'   => 'theme-mono',
            'preview_style'   => 'accent-top',
        ],
        'sunset' => [
            'label'           => 'Sunset',
            'description'     => 'Gradient hangat amber–rose yang energik — ceria & bersemangat',
            '--bg-canvas'     => '#fdf6f0',
            '--card-radius'   => '0.75rem',
            '--control-radius'=> '0.5rem',
            '--card-shadow'   => '0 8px 30px rgba(234,88,12,0.10)',
            '--card-style'    => 'gradient',
            '--sidebar-radius'=> '0.5rem',
            'preview_class'   => 'theme-sunset',
            'preview_style'   => 'gradient-warm',
            'preview_gradients' => [
                'linear-gradient(135deg, #fbbf24, #f97316)',
                'linear-gradient(135deg, #fb923c, #ef4444)',
                'linear-gradient(135deg, #f472b6, #fb7185)',
            ],
        ],
    ];
}

/**
 * Ambil konfigurasi theme aktif (atau theme tertentu bila $theme diisi).
 * Return: array preset + 'key' (nama theme tervalidasi) + 'sidebar_style'.
 */
function getThemeConfig(?string $theme = null): array
{
    $presets = getAllThemePresets();

    if ($theme === null) {
        $theme = getSetting('ui_theme', 'default');
    }
    if (!isset($presets[$theme])) {
        $theme = 'default';
    }

    $config        = $presets[$theme];
    $config['key'] = $theme;

    $style = getSetting('ui_sidebar_style', 'light');
    if (!in_array($style, ['light', 'dark', 'colored'], true)) {
        $style = 'light';
    }
    $config['sidebar_style'] = $style;
    $config['topbar_style']  = getSetting('ui_topbar_style', 'default');

    return $config;
}

/**
 * Kelas (dan inline style) untuk elemen <header> topbar sesuai ui_topbar_style.
 * Dipakai di partials/topbar.php. Tidak menyentuh preset theme yang sudah ada.
 */
function getTopbarClasses(): array
{
    $style = getSetting('ui_topbar_style', 'default');

    $map = [
        'default' => [
            'bg'           => 'bg-white dark:bg-gray-900',
            'border'       => 'border-b border-gray-200 dark:border-gray-700',
            'text'         => 'text-gray-800 dark:text-gray-100',
            'shadow'       => '',
            'inline_style' => '',
        ],
        // "Hero Brand" kalem — gradien brand halus + search pill frosted +
        // shadow brand lembut (detail di app.css .topbar-colored; inline_style
        // = fallback warna solid bila CSS belum termuat).
        'colored' => [
            'bg'           => '',
            'border'       => '',
            'text'         => 'text-white',
            'shadow'       => '',
            'inline_style' => 'background-color: var(--brand-color);',
        ],
        // "Command Deck" — bar ramping nyaris hitam, search command bar di
        // TENGAH (≥lg), garis aksen brand glow di tepi bawah (app.css .topbar-dark).
        'dark' => [
            'bg'           => 'bg-gray-900',
            'border'       => '',
            'text'         => 'text-gray-100',
            'shadow'       => '',
            'inline_style' => '',
        ],
        'floating' => [
            // bg/border/shadow di-handle penuh via .topbar-floating (app.css):
            // transparan + frosted blur + shadow lembut. Jangan set bg solid di sini.
            'bg'           => '',
            'border'       => '',
            'text'         => 'text-gray-800 dark:text-gray-100',
            'shadow'       => '',
            'inline_style' => '',
        ],
    ];

    return $map[$style] ?? $map['default'];
}

/**
 * Apakah request saat ini berada di area dashboard (admin/member)?
 * Theme HANYA diterapkan di area dashboard agar halaman publik
 * (login, register, products, landing) tidak terpengaruh.
 * Catatan: 'checkout' (logged-in, pakai layout member) ikut di-theme agar
 * konsisten dengan /member; checkout publik guest tetap netral (segmen beda).
 * Fail-safe: segmen tak dikenal → false (tidak di-theme).
 */
function isDashboardContext(): bool
{
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
    $base = defined('APP_URL') ? rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '', '/') : '';
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    $segment = strtolower(explode('/', trim($uri, '/'))[0] ?? '');

    // Segmen pertama untuk area dashboard. Catatan: 'certificates'/'downloads' (plural,
    // area member) berbeda dari 'certificate'/'download' (singular, publik) → aman.
    $dashboardSegments = [
        'admin', 'dashboard', 'member', 'transactions',
        'profile', 'affiliate', 'downloads', 'certificates', 'checkout',
        'bantuan',
        // Halaman member lain yang memuat sidebar-user → WAJIB ikut agar sidebar
        // style (Midnight Rail dll) & tema konsisten. Tiap route baru ber-sidebar
        // user HARUS ditambahkan di sini (gotcha: sebelumnya /panduan terlewat → sidebar putih).
        'panduan', 'katalog', 'rewards', 'jv-earnings', 'reseller', 'agency-seo',
        'penawaran',
    ];

    return in_array($segment, $dashboardSegments, true);
}

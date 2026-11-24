<?php
// ─── Format & tampilan ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/**
 * Hitung warna teks (hitam/putih) berdasarkan luminance warna background.
 * Mengimplementasikan WCAG relative luminance formula.
 */
function getContrastTextColor(string $hex, string $mode = 'auto'): string {
    if ($mode === 'white') return '#ffffff';
    if ($mode === 'dark')  return '#1f2937';
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#ffffff';
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;
    $toLinear = fn($c) => $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    $lum = 0.2126 * $toLinear($r) + 0.7152 * $toLinear($g) + 0.0722 * $toLinear($b);
    return $lum > 0.3 ? '#1f2937' : '#ffffff';
}

/**
 * Buat warna lebih gelap atau lebih terang berdasarkan persentase.
 * Positif = lebih terang, negatif = lebih gelap.
 */
function shadeHexColor(string $hex, int $pct): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#' . $hex;
    $r = max(0, min(255, hexdec(substr($hex, 0, 2)) + (int)(hexdec(substr($hex, 0, 2)) * $pct / 100)));
    $g = max(0, min(255, hexdec(substr($hex, 2, 2)) + (int)(hexdec(substr($hex, 2, 2)) * $pct / 100)));
    $b = max(0, min(255, hexdec(substr($hex, 4, 2)) + (int)(hexdec(substr($hex, 4, 2)) * $pct / 100)));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/**
 * Konversi hex ke rgba string.
 */
function hexToRgba(string $hex, float $alpha): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return "rgba(99,102,241,{$alpha})";
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "rgba({$r},{$g},{$b},{$alpha})";
}

/**
 * Badge ikon utk daftar channel Pembayaran Manual (rekening bank & e-wallet).
 * Preset e-wallet umum (GoPay/OVO/DANA/ShopeePay/LinkAja) dikenali dari nama
 * channel (case-insensitive) → badge warna brand + monogram. SENGAJA bukan
 * logo resmi hasil trace — Averion white-label didistribusikan ke banyak
 * buyer, membundel logotype bermerek berisiko klaim trademark; warna brand +
 * monogram tetap gampang dikenali di checkout tanpa risiko itu. Nama
 * custom/tak dikenal → ikon generik sesuai tipe (bank/wallet).
 *
 * @param string $size 'md' (36px, daftar/invoice) | 'sm' (28px, chip checkout)
 */
function paymentChannelIcon(string $type, string $name, string $size = 'md'): string
{
    $sm  = $size === 'sm';
    $box = $sm ? 'w-7 h-7' : 'w-9 h-9';
    $ico = $sm ? 'w-3.5 h-3.5' : 'w-4 h-4';

    $presets = [
        'gopay'      => ['bg' => '#00AA13', 'label' => 'GP'],
        'go-pay'     => ['bg' => '#00AA13', 'label' => 'GP'],
        'go pay'     => ['bg' => '#00AA13', 'label' => 'GP'],
        'ovo'        => ['bg' => '#4C3494', 'label' => 'OVO'],
        'dana'       => ['bg' => '#118EEA', 'label' => 'DANA'],
        'shopeepay'  => ['bg' => '#EE4D2D', 'label' => 'SP'],
        'shopee pay' => ['bg' => '#EE4D2D', 'label' => 'SP'],
        'linkaja'    => ['bg' => '#E4002B', 'label' => 'LA'],
        'link aja'   => ['bg' => '#E4002B', 'label' => 'LA'],
    ];
    $key = strtolower(trim($name));

    if ($type === 'ewallet' && isset($presets[$key])) {
        $p  = $presets[$key];
        $fs = strlen($p['label']) > 2 ? ($sm ? '7px' : '8px') : ($sm ? '9px' : '10px');
        return '<div class="' . $box . ' rounded-lg flex items-center justify-center shrink-0 text-white font-extrabold" '
             . 'style="background:' . e($p['bg']) . ';font-size:' . $fs . ';letter-spacing:.02em">' . e($p['label']) . '</div>';
    }

    if ($type === 'ewallet') {
        return '<div class="' . $box . ' rounded-lg flex items-center justify-center shrink-0 bg-gray-100 dark:bg-gray-800 text-gray-400">'
             . '<svg class="' . $ico . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg></div>';
    }

    return '<div class="' . $box . ' rounded-lg flex items-center justify-center shrink-0 bg-gray-100 dark:bg-gray-800 text-gray-400">'
         . '<svg class="' . $ico . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="21" x2="21" y2="21"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="5 6 12 3 19 6"/><line x1="4" y1="10" x2="4" y2="21"/><line x1="20" y1="10" x2="20" y2="21"/><line x1="8" y1="14" x2="8" y2="17"/><line x1="12" y1="14" x2="12" y2="17"/><line x1="16" y1="14" x2="16" y2="17"/></svg></div>';
}

/**
 * Konfigurasi mata uang aktif (setting global). Simbol dari currency_symbol,
 * aturan angka (desimal + pemisah) per kode di currency. Default IDR = perilaku
 * lama (Rp, titik ribuan, 0 desimal) → backward-compatible penuh.
 * @return array{code:string,symbol:string,decimals:int,thousand:string,decimal:string}
 */
function currencyFormat(): array
{
    $code   = strtoupper(trim((string) (getSetting('currency', 'IDR') ?: 'IDR')));
    $symbol = trim((string) getSetting('currency_symbol', 'Rp'));
    if ($symbol === '') $symbol = ($code === 'IDR') ? 'Rp' : ($code ?: 'Rp');

    // [desimal, pemisah ribuan, pemisah desimal] per mata uang.
    $rules = [
        'IDR' => [0, '.', ','],
        'MYR' => [2, ',', '.'],
        'SGD' => [2, ',', '.'],
        'USD' => [2, ',', '.'],
        'PHP' => [2, ',', '.'],
        'THB' => [2, ',', '.'],
        'EUR' => [2, '.', ','],
    ];
    [$dec, $ts, $ds] = $rules[$code] ?? [2, ',', '.']; // default internasional
    return ['code' => $code, 'symbol' => $symbol, 'decimals' => $dec, 'thousand' => $ts, 'decimal' => $ds];
}

/** Simbol mata uang aktif (untuk label form/UI, mis. "Harga (Rp)" → "Harga (RM)"). */
function currencySymbol(): string
{
    return currencyFormat()['symbol'];
}

/** Nama historis (dipakai ±59 file). Kini sadar-mata-uang via currencyFormat(). */
function formatRupiah(float $amount): string
{
    $c = currencyFormat();
    return $c['symbol'] . ' ' . number_format($amount, $c['decimals'], $c['decimal'], $c['thousand']);
}

/** Ringkas untuk chart/label (tanpa simbol). Suffiks IDR = rb/jt/M; lainnya = K/M/B. */
function formatRupiahShort(float $amount): string
{
    $c  = currencyFormat();
    $ts = $c['thousand'];
    $ds = $c['decimal'];
    if ($c['code'] === 'IDR') {
        if ($amount >= 1_000_000_000) return number_format($amount / 1_000_000_000, 1, $ds, $ts) . ' M';
        if ($amount >= 1_000_000)     return number_format($amount / 1_000_000, 1, $ds, $ts) . ' jt';
        if ($amount >= 1_000)         return number_format($amount / 1_000, 0, $ds, $ts) . ' rb';
        return number_format($amount, 0, $ds, $ts);
    }
    if ($amount >= 1_000_000_000) return number_format($amount / 1_000_000_000, 1, $ds, $ts) . 'B';
    if ($amount >= 1_000_000)     return number_format($amount / 1_000_000, 1, $ds, $ts) . 'M';
    if ($amount >= 1_000)         return number_format($amount / 1_000, 1, $ds, $ts) . 'K';
    return number_format($amount, 0, $ds, $ts);
}

/** Konfigurasi mata uang untuk sisi JavaScript (di-inject sebagai window.APP_CURRENCY). */
function currencyJsConfig(): string
{
    $c = currencyFormat();
    return json_encode([
        'symbol'   => $c['symbol'],
        'decimals' => $c['decimals'],
        'thousand' => $c['thousand'],
        'decimal'  => $c['decimal'],
        'code'     => $c['code'],
    ], JSON_UNESCAPED_UNICODE) ?: '{"symbol":"Rp","decimals":0,"thousand":".","decimal":",","code":"IDR"}';
}

// ─── Harga pendamping Ringgit (display-only, pasar Malaysia) ───
// MURNI TAMPILAN — transaksi tetap Rupiah, tanpa konversi kurs (vendor input RM manual).
// RM ditaruh sebagai badge kecil DI BAWAH harga Rupiah di halaman publik produk.
/** Format nilai Ringgit: bulat → "RM 13"; ada pecahan → "RM 40,50" (ribuan titik). */
function formatRinggit(float $priceMyr): string
{
    $myr = (floor($priceMyr) == $priceMyr)
        ? number_format($priceMyr, 0, ',', '.')
        : number_format($priceMyr, 2, ',', '.');
    return 'RM ' . $myr;
}

/**
 * Badge kecil "RM x" (premium, aksen brand) untuk ditaruh DI BAWAH harga Rupiah pada
 * halaman publik produk. Kosong bila tak ada harga alias. RM murni tampilan.
 */
function ringgitBadge(?float $priceMyr): string
{
    if ($priceMyr === null || $priceMyr <= 0) return '';
    // Dinonaktifkan saat mode mata uang global aktif (currency != IDR) → hindari dobel mata uang.
    if (strtoupper((string) (getSetting('currency', 'IDR') ?: 'IDR')) !== 'IDR') return '';
    return '<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold" '
         . 'style="color: var(--brand-color); background: color-mix(in srgb, var(--brand-color) 10%, transparent);">'
         . e(formatRinggit($priceMyr)) . '</span>';
}

/**
 * Label CTA/teks singkat halaman publik produk per bahasa. BUKAN i18n penuh — hanya
 * secukupnya untuk produk yang ditandai bahasa Melayu. Fallback ke 'id'. Catatan:
 * ini sengaja berbeda dari rencana i18n global lang/+t() (lihat memori malaysia-market-plan).
 */
function getProductLabels(string $language = 'id'): array
{
    $labels = [
        'id' => [
            'buy_now'      => 'Beli Sekarang',
            'checkout'     => 'Checkout',
            'total'        => 'Total',
            'order_now'    => 'Pesan Sekarang',
            'price'        => 'Harga',
        ],
        'ms' => [
            'buy_now'      => 'Beli Sekarang',
            'checkout'     => 'Pembayaran',
            'total'        => 'Jumlah',
            'order_now'    => 'Pesan Sekarang',
            'price'        => 'Harga',
        ],
    ];
    return $labels[$language] ?? $labels['id'];
}

/**
 * Label periode langganan yang ramah dari jumlah hari akses. Kosong bila 0 (lifetime).
 * Dipakai untuk menampilkan harga "Rp X / periode" pada produk berjangka.
 */
function subscriptionPeriodLabel(int $days): string
{
    if ($days <= 0) return '';
    return match ($days) {
        7       => 'minggu',
        14      => '2 minggu',
        30      => 'bulan',
        60      => '2 bulan',
        90      => '3 bulan',
        180     => '6 bulan',
        365     => 'tahun',
        default => $days . ' hari',
    };
}

/**
 * Label ramah-pengguna untuk tipe materi (content_type). Menghindari jargon teknis
 * seperti "HTML"/"Embed" muncul di area member. Dipakai bersama viewer + daftar modul.
 */
function materialTypeLabel(string $type): string
{
    return [
        'video' => 'Video',
        'text'  => 'Artikel',
        'file'  => 'File',
        'link'  => 'Tautan',
        'embed' => 'Interaktif',
        'html'  => 'Konten',
    ][$type] ?? 'Materi';
}

function renderStatusBadge(string $status, string $context = 'order'): string
{
    $maps = [
        'order' => [
            'paid'      => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'Lunas'],
            'pending'   => ['bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', 'Menunggu'],
            'failed'    => ['bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', 'Gagal'],
            'cancelled' => ['bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300', 'Dibatalkan'],
            'expired'   => ['bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400', 'Kedaluwarsa'],
        ],
        'member' => [
            'active'  => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'Aktif'],
            'expired' => ['bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', 'Expired'],
            'revoked' => ['bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300', 'Dicabut'],
        ],
        'user' => [
            'active'   => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'Aktif'],
            'inactive' => ['bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300', 'Nonaktif'],
            'banned'   => ['bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', 'Banned'],
        ],
        'broadcast' => [
            'draft'     => ['bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400', 'Draft'],
            'scheduled' => ['bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400', 'Dijadwalkan'],
            'sending'   => ['bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', 'Mengirim'],
            'paused'    => ['bg-orange-100 text-orange-700', 'Dijeda'],
            'sent'      => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'Terkirim'],
            'failed'    => ['bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', 'Gagal'],
        ],
        'commission' => [
            'pending'  => ['bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', 'Pending'],
            'approved' => ['bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',         'Disetujui'],
            'rejected' => ['bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',             'Ditolak'],
            'paid'     => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',     'Dibayar'],
        ],
        'product' => [
            'active'      => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'Aktif'],
            'inactive'    => ['bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300', 'Nonaktif'],
            'draft'       => ['bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', 'Draft'],
            'coming_soon' => ['bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400', 'Coming Soon'],
        ],
    ];
    $config = $maps[$context][$status] ?? ['bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300', $status];
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' . $config[0] . '">' . e($config[1]) . '</span>';
}

function formatFileSize(int $bytes): string
{
    if ($bytes < 1024)               return $bytes . ' B';
    if ($bytes < 1024 * 1024)        return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
    return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}

<?php
// ─── Email ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/**
 * Kirim email HTML via Mailketing. Drop-in helper; tidak pernah throw.
 * @return bool true bila email terkirim sukses.
 */
function sendEmail(string $to, string $subject, string $htmlBody, $userId = null): bool {
    $result = MailketingClient::sendEmail($to, $subject, $htmlBody, $userId);
    return $result['ok'] ?? false;
}

/**
 * Bungkus konten email dengan layout HTML premium (email-client-safe, table-based).
 * Gaya: card putih rounded di atas bg lembut, badge ikon brand terpusat, judul besar,
 * konten terpusat, dan footer pill brand. Ikon pakai checkmark Unicode (bukan SVG —
 * Gmail/Outlook menghapus SVG).
 *
 * @param string $title     Judul (biasanya subject) — tampil sebagai heading terpusat.
 * @param string $innerHtml Konten HTML bagian dalam (boleh mengandung <p>, dll).
 * @param string $variant   Aksen + ikon badge: 'success' (✓ brand), 'welcome' (★ brand),
 *                          'info' (! amber, mis. order menunggu pembayaran).
 */
function emailLayout(string $title, string $innerHtml, string $variant = 'success', string $align = 'center'): string
{
    // Perataan isi hero (ikon + judul tetap di tengah; hanya body yang ikut $align).
    $align = in_array($align, ['left', 'center', 'right'], true) ? $align : 'center';
    $app   = getSetting('app_name', 'STIFIn Mulia');
    $brand = getSetting('brand_color', '#10b981');
    // Validasi brand color (cegah nilai aneh masuk ke style). Fallback ke default.
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $brand)) {
        $brand = '#10b981';
    }
    // Aksen + ikon per jenis email (glyph Unicode — Gmail/Outlook menghapus SVG).
    $variants = [
        'success' => ['accent' => $brand,    'glyph' => '&#10003;'], // ✓
        'welcome' => ['accent' => $brand,    'glyph' => '&#9733;'],  // ★
        'info'    => ['accent' => '#f59e0b', 'glyph' => '&#33;'],    // !
    ];
    $vt     = $variants[$variant] ?? $variants['success'];
    $accent = $vt['accent'];
    $glyph  = $vt['glyph'];

    // Tint (campur warna ke putih; makin tinggi faktor makin lembut). PENTING:
    //  - badge bulat ($soft) pakai ACCENT VARIAN (mis. amber utk 'info' pending),
    //    supaya status email langsung terbaca dari ikon.
    //  - latar halaman ($bg) & footer pill ($pill) SELALU pakai BRAND color →
    //    warna belakang konsisten mengikuti rebrand pembeli, bukan ikut varian.
    $tint = static fn(string $hx, float $t): string => sprintf(
        '#%02x%02x%02x',
        (int) round(hexdec(substr($hx, 1, 2)) + (255 - hexdec(substr($hx, 1, 2))) * $t),
        (int) round(hexdec(substr($hx, 3, 2)) + (255 - hexdec(substr($hx, 3, 2))) * $t),
        (int) round(hexdec(substr($hx, 5, 2)) + (255 - hexdec(substr($hx, 5, 2))) * $t)
    );
    $soft = $tint($accent, 0.80); // badge = accent varian
    $pill = $tint($brand, 0.86);  // footer = brand
    $bg   = $tint($brand, 0.92);  // latar  = brand

    $appE   = e($app);
    $year   = date('Y');

    // Headline premium & presisi: badge di atas sudah mewakili status, jadi buang
    // emoji/simbol dari JUDUL agar heading bersih (mis. "Pembayaran Berhasil! ✅"
    // → "Pembayaran Berhasil!"). Hanya memengaruhi tampilan H1 — subject inbox
    // yang dipakai adapter TIDAK berubah, body di DB juga tak disentuh.
    $titleClean = preg_replace(
        '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{2122}\x{2139}\x{FE0F}\x{200D}]/u',
        '', $title
    );
    $titleClean = trim(preg_replace('/\s{2,}/', ' ', $titleClean ?? $title));
    // Jaga-jaga: kalau judul ternyata HANYA emoji (hasil kosong), pakai judul asli.
    $titleE = e($titleClean !== '' ? $titleClean : $title);

    // Samakan sudut default tombol/box di body (radius 10px bawaan seed) ke gaya
    // KOTAK chrome. Hanya menyentuh nilai 10px bawaan saat render — body tersimpan
    // tak diubah, dan radius kustom admin (≠10px) tidak terpengaruh.
    $innerHtml = str_replace('border-radius:10px', 'border-radius:8px', $innerHtml);

    // Chrome premium "struk": latar rebrand lembut, kartu putih compact & KOTAK
    // (sudut kecil 10px), badge kotak, lekukan+garis putus ala struk sebelum
    // footer, chip brand. Table-based + inline style agar aman di Gmail/Outlook
    // (SVG & <style> eksternal di-strip klien email).
    return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light only">'
        // Mobile: kecilkan padding samping & atas agar konten tidak sempit di HP.
        // Progressive enhancement — klien yang mengabaikan @media tetap pakai nilai desktop.
        . '<style>@media only screen and (max-width:600px){'
        . '.em-pad{padding-left:24px!important;padding-right:24px!important;}'
        . '.em-hero{padding-top:34px!important;padding-bottom:18px!important;}'
        . '.em-foot{padding-top:18px!important;padding-bottom:28px!important;}'
        . '}</style></head>'
        . '<body style="margin:0;padding:0;background:' . $bg . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $bg . ';padding:40px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:468px;background:#ffffff;border-radius:10px;border:1px solid #e6eaf2;overflow:hidden;">'
        // ── Hero: badge KOTAK (56px, sudut 10px) + judul (tengah) ──
        . '<tr><td class="em-pad em-hero" style="padding:38px 36px 20px;text-align:center;">'
        . '<table role="presentation" align="center" cellpadding="0" cellspacing="0" style="margin:0 auto 18px;"><tr>'
        . '<td style="width:56px;height:56px;background:' . $soft . ';border-radius:10px;text-align:center;vertical-align:middle;">'
        . '<span style="font-size:27px;line-height:56px;color:' . $accent . ';font-weight:700;">' . $glyph . '</span>'
        . '</td></tr></table>'
        . ($titleE !== '' ? '<h1 style="margin:0;font-size:20px;line-height:1.35;color:#0f172a;font-weight:700;letter-spacing:-0.021em;">' . $titleE . '</h1>' : '')
        . '</td></tr>'
        // ── Konten body ($align) — padding lebih rapat biar compact ──
        . '<tr><td class="em-pad" style="padding:4px 36px 22px;">'
        . '<div style="font-size:14px;line-height:1.7;color:#475569;text-align:' . $align . ';">' . $innerHtml . '</div>'
        . '</td></tr>'
        // ── Lekukan "struk": dua takik ber-latar brand di tepi + garis putus,
        //    tepat sebelum footer. Full-bleed; overflow:hidden kartu memotong
        //    takik jadi setengah lingkaran (margin negatif). Outlook yang
        //    mengabaikan radius tetap tampil rapi sbg aksen tepi + garis putus. ──
        . '<tr><td style="padding:0;font-size:0;line-height:0;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td width="12" valign="middle" style="padding:0;font-size:0;line-height:0;">'
        . '<div style="width:16px;height:16px;background:' . $bg . ';border-radius:50%;margin-left:-8px;line-height:16px;font-size:0;">&nbsp;</div></td>'
        . '<td valign="middle" style="padding:0 2px;font-size:0;line-height:0;">'
        . '<div style="border-top:2px dashed #ccd4e1;height:0;font-size:0;line-height:0;">&nbsp;</div></td>'
        . '<td width="12" align="right" valign="middle" style="padding:0;font-size:0;line-height:0;">'
        . '<div style="width:16px;height:16px;background:' . $bg . ';border-radius:50%;margin-right:-8px;line-height:16px;font-size:0;">&nbsp;</div></td>'
        . '</tr></table>'
        . '</td></tr>'
        // ── Footer: chip brand (kotak) + catatan (pemisah = lekukan struk di atas) ──
        . '<tr><td class="em-pad em-foot" style="padding:16px 36px 32px;text-align:center;">'
        . '<table role="presentation" align="center" cellpadding="0" cellspacing="0" style="margin:0 auto 10px;"><tr>'
        . '<td style="background:' . $pill . ';border-radius:6px;padding:6px 16px;">'
        . '<span style="font-size:11.5px;color:' . $accent . ';font-weight:700;letter-spacing:0.02em;">&#10022;&nbsp; ' . $appE . '</span>'
        . '</td></tr></table>'
        . '<p style="margin:0;font-size:11.5px;line-height:1.6;color:#9aa4b2;">Email ini dikirim otomatis. Mohon tidak membalas.<br>&copy; ' . $year . ' ' . $appE . '</p>'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * Ubah changelog teks-polos (paragraf + baris bullet "- ") menjadi HTML email rapi:
 * paragraf jadi <p>, baris bullet jadi daftar berbasis tabel (bukan <ul> — margin
 * <ul> tak konsisten di Outlook). Baris lanjutan (wrap) digabung ke item/paragraf
 * sebelumnya. Semua teks di-escape. Kembalikan '' bila kosong.
 */
function formatChangelogHtml(string $raw, string $accent = '#10b981'): string
{
    $raw = trim(str_replace(["\r\n", "\r"], "\n", $raw));
    if ($raw === '') return '';

    $lines  = explode("\n", $raw);
    $html   = '';
    $items  = [];   // grup bullet berjalan
    $para   = '';   // buffer paragraf berjalan
    $inList = false;

    $flushPara = function () use (&$para, &$html) {
        $t = trim($para);
        if ($t !== '') {
            $html .= '<p style="margin:0 0 12px;font-size:14px;line-height:1.7;color:#475569;">' . e($t) . '</p>';
        }
        $para = '';
    };
    $flushList = function () use (&$items, &$html, $accent) {
        if (!$items) return;
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;">';
        foreach ($items as $it) {
            $html .= '<tr>'
                . '<td width="18" valign="top" style="padding:2px 0;font-size:15px;line-height:1.6;color:' . $accent . ';font-weight:700;">&bull;</td>'
                . '<td valign="top" style="padding:2px 0 2px 8px;font-size:14px;line-height:1.6;color:#475569;">' . e($it) . '</td>'
                . '</tr>';
        }
        $html .= '</table>';
        $items = [];
    };

    foreach ($lines as $ln) {
        if (preg_match('/^\s*[-*\x{2022}]\s+(.*)$/u', $ln, $m)) {   // bullet: - * •
            $flushPara();
            $items[] = trim($m[1]);
            $inList  = true;
        } elseif (trim($ln) === '') {                              // baris kosong = pemisah
            $flushPara();
            $flushList();
            $inList = false;
        } elseif ($inList && $items) {                             // lanjutan item bullet
            $items[count($items) - 1] .= ' ' . trim($ln);
        } else {                                                   // lanjutan paragraf
            $para .= ($para === '' ? '' : ' ') . trim($ln);
        }
    }
    $flushPara();
    $flushList();
    return $html;
}

// ─── Render placeholder global (dipakai email sequence & abandoned cart) ──
// Perilaku disamakan dgn NotificationDispatcher::renderPlaceholders: escape HTML
// hanya utk channel email (WA teks polos, adapter tak pernah decode balik),
// inject variabel global (app_name/brand_color/app_url) agar template seed yang
// memakainya tak keluar literal, dan buang sisa placeholder yang tak ter-pass.
function renderPlaceholders(string $template, array $data, string $channel = 'email'): string
{
    $data = array_merge([
        'app_name'    => getSetting('app_name', 'STIFIn Mulia'),
        'brand_color' => getSetting('brand_color', '#6366f1'),
        'app_url'     => baseUrl(),
    ], $data);
    foreach ($data as $key => $value) {
        $value    = (string) $value;
        $template = str_replace('{{' . $key . '}}', $channel === 'email' ? e($value) : $value, $template);
    }
    return preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $template);
}

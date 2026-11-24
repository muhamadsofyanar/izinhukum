<?php
// Helper presentasi produk untuk landing (product-detail) — konten sadar-kategori.
// Prinsip: default otomatis per kategori, di-override kolom editable bila diisi.
// Backward-compatible: produk lama (kolom kosong) → hasil sama seperti sebelumnya.

/** Label kategori manusiawi (Indonesia) untuk badge, bukan raw enum. */
function productTypeLabel(string $type): string
{
    return [
        'class'      => 'Kelas Online',
        'membership' => 'Membership',
        'ebook'      => 'E-book',
        'software'   => 'Software',
        'jasa'       => 'Jasa',
        'coaching'   => 'Coaching',
        'webinar'    => 'Webinar',
        'template'   => 'Template',
        'other'      => 'Produk',
    ][$type] ?? 'Produk';
}

/** Nama kategori yang terlihat pembeli, dengan label tipe lama sebagai fallback. */
function productCatalogLabel(array $product): string
{
    $category = trim((string) ($product['catalog_category'] ?? ''));
    return $category !== '' ? $category : ucfirst((string) ($product['type'] ?? 'other'));
}

/** Default "Yang kamu dapat" spesifik kategori (dipakai bila highlights editable kosong). */
function productCategoryHighlights(string $type, array $p): array
{
    switch ($type) {
        case 'software':
            $lisensi = (!empty($p['license_enabled']) && (int) ($p['license_seats'] ?? 0) > 0)
                ? 'Lisensi ' . (int) $p['license_seats'] . ' aktivasi'
                : 'Lisensi penuh untuk dipakai sendiri';
            return [$lisensi, 'File & panduan instalasi', 'Update versi'];
        case 'ebook':      return ['File e-book siap diunduh'];
        case 'template':   return ['File template siap pakai', 'Bebas dipakai untuk proyek sendiri'];
        case 'jasa':       return ['Dikerjakan tim profesional', 'Update progres pengerjaan'];
        case 'coaching':   return ['Sesi coaching bersama mentor', 'Bimbingan personal'];
        case 'webinar':    return ['Akses sesi webinar', 'Rekaman sesi'];
        case 'membership': return ['Akses keanggotaan penuh', 'Konten & update berkala'];
        default:           return [];
    }
}

/** Pecah teks bullet (1 per baris) → array bersih. */
function productBulletLines(?string $raw): array
{
    $raw = trim((string) $raw);
    if ($raw === '') return [];
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    return array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
}

/**
 * Presentasi lengkap produk untuk landing.
 * @return array{type_label:string, highlights:string[], includes:string[]}
 */
function productPresentation(array $p, int $classCnt = 0, int $matCnt = 0): array
{
    $type = (string) ($p['type'] ?? 'other');

    // ── Highlights (Yang kamu dapat) ──
    $editable = productBulletLines($p['highlights'] ?? null);
    if ($editable) {
        // Override penuh: penjual pegang kendali.
        $highlights = $editable;
    } else {
        $highlights = [];
        // Turunan course (kelas): modul + materi.
        if ($classCnt > 0) $highlights[] = $classCnt . ' modul pembelajaran';
        if ($matCnt   > 0) $highlights[] = $matCnt . ' materi lengkap';
        // Default spesifik kategori.
        foreach (productCategoryHighlights($type, $p) as $h) $highlights[] = $h;
        // Turunan universal.
        $highlights[] = !empty($p['access_duration_days'])
            ? 'Akses penuh ' . (int) $p['access_duration_days'] . ' hari'
            : 'Akses seumur hidup';
        if (!empty($p['certificate_enabled'])) $highlights[] = 'Sertifikat kelulusan';
        $highlights[] = 'Akses langsung setelah pembayaran';
        if ((float) ($p['affiliate_commission'] ?? 0) > 0) {
            $highlights[] = 'Ikut program afiliasi ' . number_format((float) $p['affiliate_commission'], 1) . '%';
        }
        $highlights = array_values(array_unique($highlights));
    }

    // ── Isi Paket (editable saja; hanya relevan bila tak ada kurikulum) ──
    $includes = productBulletLines($p['includes'] ?? null);

    return [
        'type_label' => trim((string) ($p['catalog_category'] ?? '')) ?: productTypeLabel($type),
        'highlights' => $highlights,
        'includes'   => $includes,
    ];
}

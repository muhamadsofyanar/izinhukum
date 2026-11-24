<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers/landing-builder.php';

$programs = [
    ['wsl-1', 'Workshop STIFIn Level 1', 'WSL 1', 150000,
        'Pengenalan konsep STIFIn, Mesin Kecerdasan, dan Personality Genetik.',
        ['Pengenalan Mesin Kecerdasan dan Personality Genetik', 'Fitrah dan keunikan setiap individu', 'Kemistri pola hubungan dalam konsep STIFIn']],
    ['wsl-2', 'Workshop STIFIn Level 2', 'WSLP', 350000,
        'Pendalaman konsep STIFIn untuk peserta yang ingin melanjutkan pembelajaran dan menyiapkan diri menjadi promotor.',
        ['Teori dan tipologi dalam konsep STIFIn', 'Penerapan pada hubungan sosial dan pendampingan', 'Loyalitas klien dan investasi transformasional']],
    ['wst-health', 'Workshop STIFIn Tematik Health', 'WST', 300000,
        'Pembahasan kebiasaan sehat dan pengelolaan keseharian dalam kerangka workshop STIFIn.',
        ['Pembahasan konsep sehat dan sakit', 'Refleksi kebiasaan, pikiran, dan respons sehari-hari', 'Pengelolaan keseimbangan fisik dan mental']],
    ['wst-learning', 'Workshop STIFIn Learning', 'WST', 300000,
        'Pengenalan tahapan STIFIn Learning untuk menyusun proses belajar yang terarah.',
        ['Belajar sebagai bagian dari pengembangan diri', 'Tahapan dalam konsep STIFIn Learning', 'Penerapan tahapan pada kegiatan belajar']],
    ['wst-tahfizh', 'Workshop STIFIn Tahfizh', 'WST', 300000,
        'Pembahasan pendekatan STIFIn dalam membangun kebiasaan menghafal Al-Qur’an.',
        ['Tahapan menghafal dalam kerangka STIFIn Tahfizh', 'Latihan menghafal dan penguatan hafalan', 'Menjaga konsistensi murajaah']],
];

$pdo = getDB();
$pdo->exec(file_get_contents(__DIR__ . '/../migrations/stifin-promoter-accounts.sql'));

$findProduct = $pdo->prepare('SELECT id, canvas_id FROM products WHERE slug = ?');
$findCanvas = $pdo->prepare('SELECT id FROM canvas_pages WHERE slug = ?');
$createProduct = $pdo->prepare(
    "INSERT INTO products (name, slug, short_description, description, price, type, catalog_category,
         pricing_type, status, checkout_mode, public_form_template, show_coupon, cta_label)
     VALUES (?, ?, ?, ?, ?, 'class', ?, 'fixed', 'draft', 'public', 'clean', 0, 'Bayar Biaya Ujian')"
);
$createCanvas = $pdo->prepare(
    "INSERT INTO canvas_pages (slug, title, meta_desc, mode, content, page_type, linked_product_id, is_active)
     VALUES (?, ?, ?, 'builder', ?, 'product_landing', ?, 0)"
);
$linkCanvas = $pdo->prepare("UPDATE products SET lp_type = 'canvas', canvas_id = ? WHERE id = ?");
$results = [];

$pdo->beginTransaction();
try {
    foreach ($programs as [$slug, $name, $category, $price, $summary, $topics]) {
        $landingSlug = 'ujian-' . $slug;
        $findProduct->execute([$slug]);
        $product = $findProduct->fetch();
        $findCanvas->execute([$landingSlug]);
        $canvasId = $findCanvas->fetchColumn();
        if ($product || $canvasId) {
            $results[] = $slug . ' dilewati karena slug produk atau Canvas sudah ada.';
            continue;
        }

        $note = 'Pembayaran ini mencakup biaya ujian satu peserta. Hubungi admin cabang untuk memastikan jadwal dan ketentuan kepesertaan.';
        $description = $summary . "\n\n" . implode("\n", $topics) . "\n\n" . $note;
        $createProduct->execute([$name, $slug, $summary, $description, $price, $category]);
        $productId = (int) $pdo->lastInsertId();
        $cta = ['cta_label' => 'Bayar Biaya Ujian', 'cta_mode' => 'product', 'cta_product' => $productId];
        $rupiah = 'Rp' . number_format($price, 0, ',', '.');
        $info = $slug === 'wst-health'
            ? $note . ' Materi bersifat edukatif dan keputusan kesehatan dapat dikonsultasikan kepada tenaga kesehatan.'
            : $note;
        $content = avLbSanitizeEnvelope([
            'theme' => ['brand' => '#123d30', 'bg' => '#f5f2e9', 'text' => '#172b24', 'font' => 'system'],
            'blocks' => [
                ['type' => 'hero', 'cfg' => $cta + ['badge' => 'STIFIn Mulia', 'headline' => 'Ujian ' . $name, 'subheadline' => $summary]],
                ['type' => 'benefits', 'cfg' => ['heading' => 'Topik workshop', 'items' => implode("\n", $topics)]],
                ['type' => 'text', 'cfg' => ['heading' => 'Sebelum membayar', 'body' => $info]],
                ['type' => 'pricing', 'cfg' => $cta + ['title' => 'Biaya ujian', 'price' => $rupiah, 'price_note' => 'Per peserta', 'features' => '']],
            ],
        ]);
        $createCanvas->execute([$landingSlug, 'Ujian ' . $name, $summary, $content, $productId]);
        $linkCanvas->execute([(int) $pdo->lastInsertId(), $productId]);
        $results[] = $slug . ' dibuat sebagai draf dengan Canvas /' . $landingSlug . ' dan checkout /order/' . $slug . '.';
    }
    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}
echo implode(PHP_EOL, $results) . PHP_EOL;

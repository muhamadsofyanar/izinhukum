<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers/landing-builder.php';

$pdo = getDB();
foreach (['stifin-vouchers.sql', 'stifin-voucher-transfers.sql'] as $migration) {
    foreach (explode(';', file_get_contents(__DIR__ . '/../migrations/' . $migration)) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
}

$slug = 'voucher-tes-stifin';
$landingSlug = 'beli-voucher-stifin';
$name = 'Voucher Tes STIFIn';
$summary = 'Beli kode voucher tes STIFIn sesuai jumlah yang Anda perlukan.';
$description = 'Harga Rp165.000 per voucher. Pilih jumlah di checkout. Kode dapat dilihat di menu Voucher Tes setelah pembayaran dikonfirmasi dan stok dialokasikan.';
$pdo->beginTransaction();
try {
    $existing = $pdo->prepare(
        'SELECT p.id, p.external_program_code, c.slug AS canvas_slug FROM products p
         LEFT JOIN canvas_pages c ON c.id = p.canvas_id WHERE p.slug = ?'
    );
    $existing->execute([$slug]);
    $product = $existing->fetch();
    if ($product && (($product['external_program_code'] ?? '') !== 'STIFIN-VOUCHER-CODE'
        || ($product['canvas_slug'] ?? '') !== $landingSlug)) {
        throw new RuntimeException('Slug voucher sudah dipakai oleh produk lain. Periksa katalog.');
    }
    $productId = (int) ($product['id'] ?? 0);
    $envProduct = (int) getenv('STIFIN_VOUCHER_PRODUCT_ID');
    $setting = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'stifin_voucher_product_id' FOR UPDATE");
    $configured = (int) $setting->fetchColumn();
    foreach ([$envProduct, $configured] as $currentId) {
        if ($currentId > 0 && $currentId !== $productId) {
            throw new RuntimeException('Distribusi voucher masih diarahkan ke produk lain. Periksa konfigurasi sebelum membuat katalog baru.');
        }
    }

    if (!$product) {
        $pdo->prepare(
            "INSERT INTO products (name, slug, external_program_code, short_description, description,
                 price, type, catalog_category, pricing_type, status, checkout_mode, show_coupon, cta_label)
             VALUES (?, ?, 'STIFIN-VOUCHER-CODE', ?, ?, 165000, 'other', 'Voucher STIFIn', 'fixed', 'draft', 'login', 0, 'Beli Voucher')"
        )->execute([$name, $slug, $summary, $description]);
        $productId = (int) $pdo->lastInsertId();
        $cta = ['cta_mode' => 'product', 'cta_product' => $productId, 'cta_label' => 'Pilih Jumlah Voucher'];
        $content = avLbSanitizeEnvelope([
            'theme' => ['brand' => '#123d30', 'bg' => '#f5f2e9', 'text' => '#172b24', 'font' => 'system'],
            'blocks' => [
                ['type' => 'hero', 'cfg' => $cta + ['headline' => $name, 'subheadline' => $summary]],
                ['type' => 'pricing', 'cfg' => $cta + ['title' => 'Harga satuan', 'price' => 'Rp165.000', 'price_note' => 'per voucher', 'features' => '']],
                ['type' => 'text', 'cfg' => ['heading' => 'Cara pembelian', 'body' =>
                    "Masuk dengan akun Anda, isi jumlah voucher, lalu periksa total dan pilih metode pembayaran.\n\n"
                    . 'Setelah pembayaran dikonfirmasi dan stok dialokasikan, buka Voucher Tes di dashboard untuk melihat kode.']],
                ['type' => 'text', 'cfg' => ['heading' => 'Saldo promotor', 'body' =>
                    'Promotor dengan akun yang sudah ditautkan dapat mengecek saldo Voucher dan Free dari STIFIn pusat di menu Voucher Tes. Kode yang dibeli ditampilkan di riwayat pesanan.']],
            ],
        ]);
        $pdo->prepare(
            "INSERT INTO canvas_pages (slug, title, meta_desc, mode, content, page_type, linked_product_id, is_active)
             VALUES (?, ?, ?, 'builder', ?, 'product_landing', ?, 0)"
        )->execute([$landingSlug, $name, $summary, $content, $productId]);
        $pdo->prepare("UPDATE products SET lp_type = 'canvas', canvas_id = ? WHERE id = ?")
            ->execute([(int) $pdo->lastInsertId(), $productId]);
    }
    $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value, setting_group)
         VALUES ('stifin_voucher_product_id', ?, 'general')
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute([(string) $productId]);
    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}
echo "Produk voucher #{$productId} tersambung ke Canvas /{$landingSlug}/ dan checkout /order/{$slug}." . PHP_EOL;

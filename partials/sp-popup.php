<?php
// ════════════════════════════════════════════════════════════════
// Inject script popup Social Proof (social-proof.js).
// Hormati master toggle sp_enabled + toggle per-konteks. Konteks dideteksi
// dari URL; halaman standalone bisa memaksa dengan menyetel $spForceContext
// (nilai: 'checkout' atau 'product') sebelum meng-include file ini.
//
// Kontrol per produk (products.sp_mode) — halaman ber-konteks produk
// (product-detail / checkout) menyetel sebelum include:
//   $spProductId (int) + $spMode ('all'|'own'|'off')
//   all = campur semua produk (default), own = hanya pembelian produk ini,
//   off = popup disembunyikan di halaman produk tsb.
// ════════════════════════════════════════════════════════════════
if (function_exists('getSetting')
    && getSetting('sp_enabled', '0') === '1'
    && (string) getSetting('sp_integration_id', '') !== ''
    && (($spMode ?? 'all') !== 'off')) {

    $spIntId = (string) getSetting('sp_integration_id', '');
    $spCtx   = $spForceContext ?? '';
    if ($spCtx === '') {
        $spUri      = $_SERVER['REQUEST_URI'] ?? '';
        $spIsCk     = strpos($spUri, '/checkout') !== false;
        $spIsProd   = strpos($spUri, '/products/') !== false && !$spIsCk;
        $spCtx      = $spIsCk ? 'checkout' : ($spIsProd ? 'product' : '');
    }

    $spShow = ($spCtx === 'checkout' && getSetting('sp_show_on_checkout', '1') === '1')
           || ($spCtx === 'product'  && getSetting('sp_show_on_product_page', '1') === '1');

    if ($spShow) {
        $spVer = (int) @filemtime(__DIR__ . '/../social-proof.js');
        $spUrl = rtrim(APP_URL, '/') . '/social-proof.js?id=' . rawurlencode($spIntId) . '&v=' . $spVer;
        if (($spMode ?? 'all') === 'own' && (int) ($spProductId ?? 0) > 0) {
            $spUrl .= '&product_id=' . (int) $spProductId; // feed hanya kirim pembelian produk ini
        }
        echo '<script src="' . e($spUrl) . '" async defer></script>' . PHP_EOL;
    }
}
$spForceContext = null; // reset agar tidak bocor ke include berikutnya
$spProductId = null; $spMode = null;

<?php
// ════════════════════════════════════════════════════════════════════════
// Hermes Agent — GET /api/hermes/capabilities  (BACA saja)
// Discovery: apa yang aktif & batasnya, agar agen (Hermes) menyetel diri sendiri
// (idempoten) tanpa hardcode. Sekaligus health-check token.
// Auth: Bearer token + hermes_enabled.
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../helpers/hermes.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    hermesApiSend(405, ['ok' => false, 'error' => 'Metode tidak diizinkan. Hanya GET.']);
}
hermesApiAuthorize(false); // token valid + hermes_enabled

// Discovery di-cache ~60 detik (Hermes sering memanggil ini utk menyetel diri):
// payload bergantung pada setting/produk yang jarang berubah. Fail-soft.
hermesApiSend(200, function_exists('cacheRemember')
    ? cacheRemember('hermes_capabilities', 60, 'hermesCapabilitiesPayload')
    : hermesCapabilitiesPayload());

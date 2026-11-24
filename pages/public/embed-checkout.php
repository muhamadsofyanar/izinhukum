<?php
// ════════════════════════════════════════════════════════════════
// Embed Checkout — varian form order publik yang boleh di-iframe
// pada situs eksternal. Reuse penuh public-checkout.php (flag EMBED_MODE).
// Rute: /embed/checkout/{slug}
// ════════════════════════════════════════════════════════════════
$GLOBALS['EMBED_MODE'] = true;
require __DIR__ . '/public-checkout.php';

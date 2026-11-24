<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Integrasi';
$topbarTitle = 'Pengaturan Integrasi';

$appName = getSetting('app_name', 'STIFIn Mulia');

// ─── Lisensi Vendor (Fulfillment) — HANYA instalasi vendor ───
// Digerbangi konstanta config.php (neverOverwrite → tak pernah ada di instalasi
// pembeli). Tanpa ini, setiap pembeli melihat section "Lisensi Vendor" yang tak
// pernah bisa mereka pakai. Lihat vfAvailable() di helpers/vendor-fulfillment.php.
$vfShow      = function_exists('vfAvailable') && vfAvailable();
$vfUrl = ''; $vfHasSecret = false; $vfOn = false; $vfMap = [];
$vfProducts = [];
$vfStats    = ['pending' => 0, 'failed' => 0];

if ($vfShow) {
    $vfUrl       = (string) getSetting('vendor_fulfillment_url', '');
    $vfHasSecret = trim((string) getSetting('vendor_fulfillment_secret', '')) !== '';
    $vfOn        = getSetting('vendor_fulfillment_enabled', '0') === '1';
    // Peta dibaca MENTAH dari setting (bukan lewat vfProductMap yang ikut mati
    // saat integrasi dinonaktifkan) — biar isian tak hilang dari layar.
    $vfMapRaw = json_decode((string) getSetting('vendor_fulfillment_map', ''), true);
    if (is_array($vfMapRaw)) {
        // Nilai bisa string (legacy=averion) atau {plan,product_code}. Normalkan
        // via helper bersama supaya layar & penyimpanan sepakat soal bentuknya.
        foreach ($vfMapRaw as $k => $v) {
            if ((int) $k <= 0) continue;
            $vfMap[(int) $k] = function_exists('vfNormalizeMapValue')
                ? vfNormalizeMapValue($v)
                : ['plan' => (string) $v, 'product_code' => 'averion', 'tier' => ''];
        }
    }
    try {
        $vfProducts = getDB()->query("SELECT id, name FROM products WHERE status <> 'draft' ORDER BY name ASC")->fetchAll();
        $vfStats = getDB()->query(
            "SELECT COALESCE(SUM(status='pending'),0) AS pending, COALESCE(SUM(status='failed'),0) AS failed
               FROM license_fulfillments"
        )->fetch() ?: $vfStats;
    } catch (Exception $e) {
        // Tabel belum ada (pra-migrasi) → tampilkan form tanpa statistik.
        error_log('Integrations: vendor fulfillment stats error: ' . $e->getMessage());
    }
    // Agency SEO Engine (paket 50 slot) — konfigurasi produk pemicu kuota.
    $asMap = function_exists('agencySeoProductMap') ? agencySeoProductMap() : [];
    // Produk yang BELUM jadi paket agency (untuk dropdown "+ Tambah produk").
    $asUnmapped = array_values(array_filter($vfProducts ?? [], fn($p) => !isset($asMap[(int) $p['id']])));
}

// ─── Baca payment_gateways table (sumber kebenaran untuk gateway) ───
$gateways = [];
try {
    $rows = getDB()->query("SELECT * FROM payment_gateways ORDER BY id ASC")->fetchAll();
    foreach ($rows as $row) {
        $gateways[$row['provider']] = $row;
    }
} catch (Exception $e) {
    error_log('integrations load payment_gateways error: ' . $e->getMessage());
}

$gw = function(string $provider, string $field, string $default = '') use ($gateways): string {
    return (string) ($gateways[$provider][$field] ?? $default);
};

// ─── Baca notification API keys dari settings table ───────────────
$notifKeys = ['mailketing_api_key','mailketing_sender_email','mailketing_sender_name',
              'fonte_api_key','onesender_api_key','onesender_api_url','starsender_api_key','dripsender_api_key',
              'kirimdev_api_key','kirimdev_phone_number_id',
              'kirimdev_otp_template_name','kirimdev_otp_method',
              'averionrelay_api_key','averionrelay_api_url',
              'wa_sender_number',
              'fonte_enabled','onesender_enabled','starsender_enabled','dripsender_enabled','kirimdev_enabled','averionrelay_enabled',
              'wa_otp_login_enabled','wa_otp_provider',
              'mailketing_list_order','mailketing_list_paid','mailketing_lists_cache',
              'telegram_bot_token','telegram_chat_id','telegram_enabled',
              'telegram_alert_order_failed','telegram_alert_admin_login'];
$ns = [];
try {
    $ph   = implode(',', array_fill(0, count($notifKeys), '?'));
    $stmt = getDB()->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($ph)");
    $stmt->execute($notifKeys);
    foreach ($stmt->fetchAll() as $r) {
        $ns[$r['setting_key']] = $r['setting_value'];
    }
} catch (Exception $e) {
    error_log('integrations load settings error: ' . $e->getMessage());
}

$sv = fn(string $key, string $default = '') => $ns[$key] ?? $default;

// Active tab dari URL — support deep-link /admin/integrations?tab=payment
$activeTab = in_array($_GET['tab'] ?? '', ['payment','email','whatsapp','telegram','manual']) ? $_GET['tab'] : 'payment';

// ─── Manual Transfer: setting + rekening aktif (preview) ───────────
$manualTransferEnabled      = getSetting('manual_transfer_enabled', '1') === '1';
$manualTransferInstructions = getSetting('manual_transfer_instructions', '');
$manualUniqueCodeEnabled    = getSetting('manual_unique_code_enabled', '1') === '1';
$previewBankAccounts = [];
try {
    $previewBankAccounts = getDB()->query(
        "SELECT bank_name, account_number, account_name FROM bank_accounts WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 5"
    )->fetchAll();
} catch (Exception $e) {
    error_log('integrations: bank preview error: ' . $e->getMessage());
}

generateCSRF();
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="mb-5">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Integrasi</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
          Konfigurasi payment gateway, email, dan WhatsApp — satu tempat untuk semua integrasi pihak ketiga.
        </p>
      </div>

      <!-- ─── Tab Nav ────────────────────────────────────────────── -->
      <div class="border-b border-gray-200 dark:border-gray-800 mb-6">
        <nav class="flex gap-1" id="tabNav">
          <?php
          $tabs = [
              'payment'  => ['label' => 'Payment Gateway', 'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
              'email'    => ['label' => 'Email',           'icon' => 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
              'whatsapp' => ['label' => 'WhatsApp',        'icon' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
              'telegram' => ['label' => 'Telegram',        'icon' => 'M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z'],
              'manual'   => ['label' => 'Manual Transfer',  'icon' => 'M3 10h18M3 10l9-6 9 6M5 10v8m14-8v8M9 14v4m6-4v4M3 21h18'],
          ];
          foreach ($tabs as $tid => $tab):
              $isActive = $tid === $activeTab;
          ?>
          <button onclick="switchTab('<?= $tid ?>')" id="tab-<?= $tid ?>"
                  class="tab-btn flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition
                         <?= $isActive ? 'border-[var(--brand-color)] text-[var(--brand-color)]' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300' ?>">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="<?= e($tab['icon']) ?>"/></svg>
            <?= e($tab['label']) ?>
          </button>
          <?php endforeach; ?>
        </nav>
      </div>

      <!-- ══════════════════════════════════════════════════════════ -->
      <!-- TAB: PAYMENT GATEWAY                                       -->
      <!-- Setiap gateway = form terpisah → save-payment-gateway.php  -->
      <!-- (menulis ke tabel payment_gateways yang dibaca Gateway class) -->
      <!-- ══════════════════════════════════════════════════════════ -->
      <div id="panel-payment" class="tab-panel <?= $activeTab !== 'payment' ? 'hidden' : '' ?> max-w-3xl space-y-5">

        <!-- Peringatan keamanan -->
        <div class="bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800/40 rounded-xl p-4 flex gap-3">
          <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
          <p class="text-sm text-amber-800 dark:text-amber-300">
            API key disimpan di database. Switch ke mode <strong>Production</strong> HANYA setelah testing sandbox benar-benar tuntas.
          </p>
        </div>

        <?php
        $gwDefs = [
            'duitku' => [
                'label'  => 'Duitku',
                'desc'   => 'VA Bank, E-wallet, QRIS via Duitku Indonesia',
                'color'  => '#003399',
                'icon'   => 'D',
                'docs'   => 'https://docs.duitku.com',
                'fields' => [
                    ['key' => 'merchant_code', 'label' => 'Merchant Code',  'placeholder' => 'DS12345'],
                    ['key' => 'api_key',       'label' => 'API Key',        'placeholder' => 'xxxxxxxxxxxxxxxx'],
                ],
            ],
            'xendit' => [
                'label'  => 'Xendit',
                'desc'   => 'Invoice, VA, E-wallet, QRIS via Xendit',
                'color'  => '#4F46E5',
                'icon'   => 'X',
                'docs'   => 'https://docs.xendit.co',
                'fields' => [
                    ['key' => 'api_key',        'label' => 'Secret Key (API Key)',           'placeholder' => 'xnd_development_xxxxxxx'],
                    ['key' => 'callback_token', 'label' => 'Webhook Verification Token',     'placeholder' => 'Token dari dashboard Xendit'],
                ],
            ],
            'moota' => [
                'label'  => 'Moota',
                'desc'   => 'Monitoring mutasi rekening bank otomatis',
                'color'  => '#059669',
                'icon'   => 'M',
                'docs'   => 'https://moota.co/kb/',
                'fields' => [
                    ['key' => 'api_key',        'label' => 'API Key (Server Key V2)',           'placeholder' => 'Server Key API V2 Moota'],
                    ['key' => 'callback_token', 'label' => 'Secret Token (Webhook Signature)',   'placeholder' => 'Secret Token webhook — untuk verifikasi HMAC Signature'],
                ],
            ],
            'paypal' => [
                'label'  => 'PayPal',
                'desc'   => 'Kartu / saldo PayPal — untuk pembeli luar negeri (ditagih USD)',
                'color'  => '#003087',
                'icon'   => 'P',
                'docs'   => 'https://developer.paypal.com/dashboard/applications',
                'fields' => [
                    ['key' => 'api_key',    'label' => 'Client ID',      'placeholder' => 'Client ID REST App PayPal'],
                    ['key' => 'secret_key', 'label' => 'Secret',         'placeholder' => 'Secret REST App PayPal'],
                ],
            ],
            'midtrans' => [
                'label'  => 'Midtrans',
                'desc'   => 'VA Bank, E-wallet (GoPay/ShopeePay), QRIS, Kartu via Snap',
                'color'  => '#1A88E0',
                'icon'   => 'M',
                'docs'   => 'https://docs.midtrans.com',
                'fields' => [
                    ['key' => 'api_key',       'label' => 'Server Key',   'placeholder' => 'Mid-server-xxxxxxxx (sandbox: SB-Mid-server-...)'],
                    ['key' => 'secret_key',    'label' => 'Client Key (opsional)', 'placeholder' => 'Mid-client-xxxxxxxx'],
                    ['key' => 'merchant_code', 'label' => 'Merchant ID',  'placeholder' => 'G000000000'],
                ],
            ],
            'tripay' => [
                'label'  => 'Tripay',
                'desc'   => 'VA Bank, QRIS, E-wallet, Retail (Alfamart) — pembeli pilih channel',
                'color'  => '#0F9D58',
                'icon'   => 'T',
                'docs'   => 'https://tripay.co.id/developer',
                'fields' => [
                    ['key' => 'merchant_code', 'label' => 'Kode Merchant', 'placeholder' => 'T0001'],
                    ['key' => 'api_key',       'label' => 'API Key',       'placeholder' => 'API Key Tripay (Bearer)'],
                    ['key' => 'secret_key',    'label' => 'Private Key',   'placeholder' => 'Private Key Tripay (signature)'],
                ],
            ],
        ];

        // Kurs IDR→USD PayPal saat ini (disimpan di config_json).
        $paypalRateCfg = json_decode((string) $gw('paypal', 'config_json'), true) ?: [];
        $paypalRateVal = (float) ($paypalRateCfg['idr_usd_rate'] ?? 0);
        ?>

        <div id="payment-accordion" class="space-y-2.5">
        <?php foreach ($gwDefs as $provider => $def):
            $isActive = $gw($provider, 'is_active') === '1';
            $mode     = $gw($provider, 'mode', 'sandbox');
        ?>
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-payment-gateway"
                class="js-ajax-save bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 overflow-hidden">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="provider"   value="<?= e($provider) ?>">

            <!-- Header accordion (klik untuk expand/collapse) -->
            <div class="pay-accordion-toggle flex items-center justify-between px-5 py-3.5 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors select-none"
                 data-pay-section="<?= e($provider) ?>" role="button">
              <div class="flex items-center gap-3 flex-1 min-w-0">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center text-white text-sm font-bold shrink-0" style="background:<?= e($def['color']) ?>"><?= e($def['icon']) ?></div>
                <div class="min-w-0">
                  <h3 class="font-semibold text-gray-900 dark:text-white text-sm truncate"><?= e($def['label']) ?></h3>
                  <p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?= e($def['desc']) ?></p>
                </div>
                <?php if ($isActive): ?>
                  <span class="inline-flex items-center gap-1 text-[11px] font-medium text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/20 px-2 py-0.5 rounded-full shrink-0"><span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>Aktif</span>
                <?php else: ?>
                  <span class="text-[11px] font-medium text-gray-500 bg-gray-100 dark:bg-gray-700/60 dark:text-gray-400 px-2 py-0.5 rounded-full shrink-0">Nonaktif</span>
                <?php endif; ?>
              </div>
              <div class="flex items-center gap-2.5 shrink-0 ml-3">
                <!-- Toggle aktif/nonaktif — stopPropagation agar tidak memicu expand -->
                <label class="relative inline-flex items-center cursor-pointer shrink-0" onclick="event.stopPropagation()">
                  <input type="hidden"   name="is_active" value="0">
                  <input type="checkbox" name="is_active" value="1" id="active_<?= e($provider) ?>"
                         <?= $isActive ? 'checked' : '' ?> class="sr-only peer" onclick="event.stopPropagation()">
                  <div class="w-9 h-5 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                  <div class="absolute left-0.5 top-0.5 w-4 h-4 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-4"></div>
                </label>
                <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" id="pay-chevron-<?= e($provider) ?>"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
              </div>
            </div>

            <!-- Konten accordion (expandable) -->
            <div class="pay-accordion-content" id="pay-content-<?= e($provider) ?>" data-auto-expand="<?= $isActive ? '1' : '0' ?>"
                 style="max-height:0;overflow:hidden;transition:max-height .22s ease">
              <div class="px-5 pb-5 pt-4 border-t border-gray-100 dark:border-gray-800 space-y-4">

                <div class="flex flex-wrap items-center justify-between gap-3">
                  <p class="text-xs text-gray-400 dark:text-gray-600">Tampilkan metode ini di halaman checkout saat diaktifkan.</p>
                  <a href="<?= e($def['docs']) ?>" target="_blank" rel="noopener" class="text-xs text-[var(--brand-color)] hover:underline whitespace-nowrap shrink-0">Dokumentasi ↗</a>
                </div>

                <?php if ($provider !== 'moota'): ?>
                <!-- Mode sandbox / production (Moota tidak punya mode — rekening bank asli) -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Mode</label>
                  <div class="flex gap-5">
                    <label class="flex items-center gap-2 cursor-pointer">
                      <input type="radio" name="mode" value="sandbox" <?= $mode !== 'production' ? 'checked' : '' ?> class="w-4 h-4 accent-[var(--brand-color)]">
                      <span class="text-sm text-gray-700 dark:text-gray-300">Sandbox <span class="text-xs text-gray-400">(testing)</span></span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                      <input type="radio" name="mode" value="production" <?= $mode === 'production' ? 'checked' : '' ?> class="w-4 h-4 accent-[var(--brand-color)]">
                      <span class="text-sm text-gray-700 dark:text-gray-300">Production <span class="text-xs text-red-500 font-medium">(uang nyata!)</span></span>
                    </label>
                  </div>
                </div>
                <?php endif; ?>

                <!-- Credential fields -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <?php foreach ($def['fields'] as $field): ?>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5"><?= e($field['label']) ?></label>
                    <div class="relative">
                      <input type="password"
                             name="<?= e($field['key']) ?>"
                             id="field_<?= e($provider) ?>_<?= e($field['key']) ?>"
                             value=""
                             placeholder="<?= $gw($provider, $field['key']) !== '' ? '•••••••••• tersimpan — isi untuk mengganti' : e($field['placeholder']) ?>"
                             autocomplete="new-password"
                             class="w-full pr-10 px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                      <button type="button"
                              onclick="togglePw('field_<?= e($provider) ?>_<?= e($field['key']) ?>', this)"
                              class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <svg class="w-4 h-4 eye-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="w-4 h-4 eye-hide hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                      </button>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>

                <?php if ($provider === 'paypal'): ?>
                <!-- Kurs IDR→USD + catatan (PayPal tak dukung IDR → tagih USD) -->
                <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Kurs IDR → USD <span class="text-red-500">*</span></label>
                  <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-500 shrink-0">Rp</span>
                    <input type="number" name="paypal_rate" step="1" min="1" value="<?= $paypalRateVal > 0 ? e((string) $paypalRateVal) : '' ?>"
                           placeholder="16000"
                           class="w-40 px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                    <span class="text-sm text-gray-500">= 1 USD</span>
                  </div>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1.5">PayPal tidak mendukung IDR. Nominal ditagih = <strong>total order (IDR) ÷ kurs ini</strong> dalam USD. order.total tetap IDR (komisi &amp; membership tak berubah). Perbarui kurs berkala.</p>
                </div>
                <div class="bg-blue-50 dark:bg-blue-900/10 border border-blue-200 dark:border-blue-800/40 rounded-lg p-3.5">
                  <p class="text-xs text-blue-800 dark:text-blue-300 leading-relaxed">
                    Ambil <strong>Client ID</strong> &amp; <strong>Secret</strong> dari
                    <a href="https://developer.paypal.com/dashboard/applications" target="_blank" rel="noopener" class="underline">developer.paypal.com</a>
                    (Apps &amp; Credentials). Pakai kredensial <strong>Sandbox</strong> saat mode Sandbox, <strong>Live</strong> saat Production. Uang masuk ke akun PayPal Anda. Tak perlu daftar webhook — pembayaran dikonfirmasi saat pembeli kembali dari PayPal.
                  </p>
                </div>
                <?php endif; ?>

                <?php if ($provider === 'xendit'): ?>
                <!-- URL Webhook — didaftarkan manual di Dashboard Xendit > Settings > Callbacks > Invoices paid -->
                <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">URL Webhook <span class="font-normal text-gray-400">(daftarkan di Xendit)</span></label>
                  <div class="flex gap-2">
                    <input type="text" id="xendit-webhook-url" readonly value="<?= e(rtrim(APP_URL, '/') . '/webhooks/xendit-callback.php') ?>"
                           class="flex-1 px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-400 font-mono outline-none">
                    <button type="button" onclick="copyWebhookUrl('xendit-webhook-url', this)"
                            class="px-4 py-2.5 rounded-lg text-sm font-medium text-white whitespace-nowrap" style="background:var(--brand-color)">Copy</button>
                  </div>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1.5">Daftarkan URL ini di Dashboard Xendit → Settings → Callbacks → bagian <strong>Invoices</strong> → <strong>Invoices paid</strong>. Tanpa ini, status pembayaran tidak akan ter-update otomatis.</p>
                </div>
                <?php endif; ?>

                <?php if ($provider === 'midtrans'): ?>
                <!-- URL Notifikasi — didaftarkan manual di Dashboard Midtrans > Settings > Configuration -->
                <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Payment Notification URL <span class="font-normal text-gray-400">(daftarkan di Midtrans)</span></label>
                  <div class="flex gap-2">
                    <input type="text" id="midtrans-webhook-url" readonly value="<?= e(rtrim(APP_URL, '/') . '/webhooks/midtrans-callback.php') ?>"
                           class="flex-1 px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-400 font-mono outline-none">
                    <button type="button" onclick="copyWebhookUrl('midtrans-webhook-url', this)"
                            class="px-4 py-2.5 rounded-lg text-sm font-medium text-white whitespace-nowrap" style="background:var(--brand-color)">Copy</button>
                  </div>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1.5">Daftarkan di Dashboard Midtrans → Settings → Configuration → <strong>Payment Notification URL</strong>. Ambil <strong>Server Key</strong> &amp; <strong>Client Key</strong> di Settings → Access Keys (pakai kredensial <strong>Sandbox</strong> saat mode Sandbox). Tanpa notifikasi ini, status pembayaran tidak ter-update otomatis.</p>
                </div>
                <?php endif; ?>

                <?php if ($provider === 'tripay'): ?>
                <!-- URL Callback — didaftarkan manual di Dashboard Tripay > Merchant -->
                <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Callback URL <span class="font-normal text-gray-400">(daftarkan di Tripay)</span></label>
                  <div class="flex gap-2">
                    <input type="text" id="tripay-webhook-url" readonly value="<?= e(rtrim(APP_URL, '/') . '/webhooks/tripay-callback.php') ?>"
                           class="flex-1 px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-400 font-mono outline-none">
                    <button type="button" onclick="copyWebhookUrl('tripay-webhook-url', this)"
                            class="px-4 py-2.5 rounded-lg text-sm font-medium text-white whitespace-nowrap" style="background:var(--brand-color)">Copy</button>
                  </div>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1.5">Daftarkan di Dashboard Tripay → Merchant → <strong>Callback URL</strong>. Ambil <strong>Kode Merchant</strong>, <strong>API Key</strong> &amp; <strong>Private Key</strong> di Merchant → API. Pembeli memilih channel (VA/QRIS/dll) saat checkout, lalu diarahkan ke halaman pembayaran Tripay. Pakai kredensial <strong>Sandbox</strong> saat mode Sandbox.</p>
                </div>
                <?php endif; ?>

                <?php if ($provider === 'moota'): ?>
                <!-- Test Koneksi + URL Webhook (khusus Moota — selaras dgn license server) -->
                <div class="border-t border-gray-100 dark:border-gray-800 pt-4 space-y-4">
                  <div>
                    <div class="flex items-center gap-3">
                      <button type="button" id="moota-test-btn" onclick="testMootaConnection()"
                              class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition disabled:opacity-50 whitespace-nowrap">Test Koneksi</button>
                      <p id="moota-test-result" class="text-xs hidden"></p>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-1.5">Simpan dulu sebelum tes. Server Key dari dashboard Moota → Pengaturan API.</p>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">URL Webhook <span class="font-normal text-gray-400">(daftarkan di Moota)</span></label>
                    <div class="flex gap-2">
                      <input type="text" id="moota-webhook-url" readonly value="<?= e(rtrim(APP_URL, '/') . '/webhooks/moota-callback') ?>"
                             class="flex-1 px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-400 font-mono outline-none">
                      <button type="button" onclick="copyWebhookUrl('moota-webhook-url', this)"
                              class="px-4 py-2.5 rounded-lg text-sm font-medium text-white whitespace-nowrap" style="background:var(--brand-color)">Copy</button>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-1.5">Daftarkan URL ini di Moota → Webhook, lalu isi <strong>Secret Token</strong> yang sama di atas.</p>
                  </div>
                  <div>
                    <?php $mootaCfg = json_decode($gw('moota', 'config_json') ?: '', true) ?: []; $mootaBankInfo = trim((string) ($mootaCfg['bank_info'] ?? '')); ?>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Info Rekening <span class="font-normal text-gray-400">(otomatis dari Moota)</span></label>
                    <div id="moota-bank-info"
                         class="w-full min-h-[76px] px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white whitespace-pre-line"><?php if ($mootaBankInfo !== ''): ?><?= e($mootaBankInfo) ?><?php else: ?><span class="text-gray-400">Klik <strong>Test Koneksi</strong> untuk mengambil rekening yang terdaftar di akun Moota.</span><?php endif; ?></div>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-1.5">Diambil <strong>otomatis</strong> dari rekening yang terdaftar di akun Moota saat Test Koneksi — tak perlu diketik manual. Inilah yang ditampilkan ke pembeli yang memilih Moota.</p>
                  </div>
                </div>
                <?php endif; ?>

                <div class="flex justify-end pt-1">
                  <button type="submit" data-loading data-loading-text="Menyimpan..."
                          class="px-5 py-2 rounded-lg text-sm font-semibold text-white transition hover:opacity-90"
                          style="background:var(--brand-color)">
                    Simpan <?= e($def['label']) ?>
                  </button>
                </div>

              </div>
            </div>
          </form>
        <?php endforeach; ?>

        <?php if ($vfShow): ?>
        <?php
        // ── Lisensi Vendor (Fulfillment) — HANYA instalasi vendor ──
        // Ditaruh DI DALAM #payment-accordion supaya: (a) ikut lebar max-w-3xl milik
        // #panel-payment (sebelumnya di luar → kartunya jebol melebihi yg lain), dan
        // (b) dapat perilaku buka/tutup + ingat-posisi dari initAccordion tanpa JS baru.
        $vfUnmapped = array_filter($vfProducts, fn($p) => !isset($vfMap[(int) $p['id']]));
        ?>
        <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-vendor-fulfillment"
              class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 overflow-hidden">
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

          <div class="pay-accordion-toggle flex items-center justify-between px-5 py-3.5 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors select-none"
               data-pay-section="vendorlicense" role="button">
            <div class="flex items-center gap-3 flex-1 min-w-0">
              <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)]">
                <svg class="w-4 h-4 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
                </svg>
              </div>
              <div class="min-w-0">
                <h3 class="font-semibold text-gray-900 dark:text-white text-sm truncate">Lisensi Vendor</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 truncate">Terbitkan license key otomatis dari server lisensi</p>
              </div>
              <?php if ($vfOn): ?>
                <span class="inline-flex items-center gap-1 text-[11px] font-medium text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/20 px-2 py-0.5 rounded-full shrink-0"><span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>Aktif</span>
              <?php else: ?>
                <span class="text-[11px] font-medium text-gray-500 bg-gray-100 dark:bg-gray-700/60 dark:text-gray-400 px-2 py-0.5 rounded-full shrink-0">Nonaktif</span>
              <?php endif; ?>
            </div>
            <div class="flex items-center gap-2.5 shrink-0 ml-3">
              <label class="relative inline-flex items-center cursor-pointer shrink-0" onclick="event.stopPropagation()">
                <input type="hidden"   name="vendor_fulfillment_enabled" value="0">
                <input type="checkbox" name="vendor_fulfillment_enabled" value="1" class="sr-only peer" <?= $vfOn ? 'checked' : '' ?>>
                <div class="w-9 h-5 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                <div class="absolute left-0.5 top-0.5 w-4 h-4 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-4"></div>
              </label>
              <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" id="pay-chevron-vendorlicense"
                   viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
              </svg>
            </div>
          </div>

          <div class="pay-accordion-content" id="pay-content-vendorlicense" data-auto-expand="<?= $vfOn ? '1' : '0' ?>"
               style="max-height:0;overflow:hidden;transition:max-height .22s ease">
            <div class="px-5 pb-5 pt-4 border-t border-gray-100 dark:border-gray-800 space-y-4">

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">URL Endpoint Vendor</label>
                  <input type="url" name="vendor_fulfillment_url" value="<?= e($vfUrl) ?>" placeholder="https://vendor.contoh.com/api/fulfill"
                         class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Rahasia Bersama</label>
                  <input type="password" name="vendor_fulfillment_secret" value="" autocomplete="new-password"
                         placeholder="<?= $vfHasSecret ? '•••••••• (tersimpan — isi utk ganti)' : 'tempel rahasia dari panel vendor' ?>"
                         class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                </div>
              </div>
              <p class="text-[11px] text-gray-400 dark:text-gray-500 -mt-2">Panel vendor → Pengaturan → Fulfillment API. Rahasia dikosongkan = yang lama dipertahankan.</p>

              <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Produk yang Dipetakan</label>
                <?php if ($vfMap): ?>
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-800 mb-2">
                  <?php foreach ($vfProducts as $vp): $pid = (int) $vp['id']; if (!isset($vfMap[$pid])) continue; ?>
                  <div class="flex items-center gap-3 px-3 py-2">
                    <span class="flex-1 min-w-0 text-sm text-gray-700 dark:text-gray-300 truncate"><?= e($vp['name']) ?>
                      <span class="text-xs text-gray-400 font-mono">#<?= $pid ?></span>
                    </span>
                    <input type="text" name="vf_code[<?= $pid ?>]" value="<?= e($vfMap[$pid]['product_code']) ?>" maxlength="50" placeholder="averion"
                           title="Kode produk di server lisensi (mis. averion / leads / relay / agency)"
                           class="w-24 px-2.5 h-8 rounded-lg text-xs font-mono border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                    <input type="text" name="vf_plan[<?= $pid ?>]" value="<?= e($vfMap[$pid]['plan']) ?>" maxlength="50" placeholder="slug plan"
                           class="w-28 px-2.5 h-8 rounded-lg text-xs font-mono border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                    <input type="text" name="vf_tier[<?= $pid ?>]" value="<?= e($vfMap[$pid]['tier'] ?? '') ?>" maxlength="50" placeholder="tier"
                           title="Khusus Relay: tier paket (starter/business) → menentukan kuota nomor WA. Kosongkan untuk produk lain."
                           class="w-24 px-2.5 h-8 rounded-lg text-xs font-mono border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                    <span class="text-[11px] text-gray-400 shrink-0 w-20">kosongkan plan = lepas</span>
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-xs text-gray-400 dark:text-gray-500 mb-2">Belum ada produk dipetakan — integrasi tak akan jalan sampai minimal satu dipetakan.</p>
                <?php endif; ?>

                <?php if ($vfUnmapped): ?>
                <div class="flex items-center gap-2">
                  <select name="vf_new_product" class="flex-1 min-w-0 px-2.5 h-8 rounded-lg text-xs border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                    <option value="">+ Tambah produk…</option>
                    <?php foreach ($vfUnmapped as $vp): ?>
                    <option value="<?= (int) $vp['id'] ?>"><?= e($vp['name']) ?> (#<?= (int) $vp['id'] ?>)</option>
                    <?php endforeach; ?>
                  </select>
                  <input type="text" name="vf_new_code" value="" maxlength="50" placeholder="averion"
                         title="Kode produk di server lisensi (mis. averion / leads / relay)"
                         class="w-24 px-2.5 h-8 rounded-lg text-xs font-mono border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                  <input type="text" name="vf_new_plan" value="" maxlength="50" placeholder="slug plan"
                         class="w-28 px-2.5 h-8 rounded-lg text-xs font-mono border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                  <input type="text" name="vf_new_tier" value="" maxlength="50" placeholder="tier"
                         title="Khusus Relay: tier paket (starter/business) → menentukan kuota nomor WA. Kosongkan untuk produk lain."
                         class="w-24 px-2.5 h-8 rounded-lg text-xs font-mono border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                  <span class="text-[11px] text-gray-400 shrink-0 w-20">lalu Simpan</span>
                </div>
                <?php endif; ?>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1.5"><strong>Kode produk</strong> di server lisensi (mis. <span class="font-mono">averion</span> / <span class="font-mono">leads</span> / <span class="font-mono">relay</span> / <span class="font-mono">agency</span>; kosong = <span class="font-mono">averion</span>). <strong>Slug plan</strong> (mis. <span class="font-mono">monthly</span> / <span class="font-mono">yearly</span>) menentukan masa berlaku di <strong>vendor</strong>, bukan durasi produk di sini. <strong>Khusus <span class="font-mono">agency</span>:</strong> isi Slug plan dengan <strong>ID Agency Pack</strong> (angka, mis. <span class="font-mono">1</span>) dari panel vendor — pembeli otomatis dapat akun/top-up slot + email set-password; tak ada license key per-perangkat. <strong>Tier</strong> hanya untuk <span class="font-mono">relay</span> (mis. <span class="font-mono">starter</span> / <span class="font-mono">business</span>) → menentukan kuota nomor WA; kosongkan untuk produk lain. Produk yang dipetakan tak lagi menerbitkan license key lokal.</p>
              </div>

              <?php if ($vfStats['pending'] > 0 || $vfStats['failed'] > 0): ?>
              <div class="flex flex-wrap items-center gap-2">
                <?php if ($vfStats['pending'] > 0): ?>
                <span class="text-xs px-2.5 py-1 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300"><?= (int) $vfStats['pending'] ?> sedang diantre</span>
                <?php endif; ?>
                <?php if ($vfStats['failed'] > 0): ?>
                <span class="text-xs px-2.5 py-1 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400"><?= (int) $vfStats['failed'] ?> gagal</span>
                <?php endif; ?>
                <a href="<?= e(APP_URL) ?>/admin/license-fulfillments" class="text-xs font-semibold text-[var(--brand-color)] hover:underline">Lihat antrean →</a>
              </div>
              <?php endif; ?>

              <div class="flex justify-end pt-1">
                <button type="submit" data-loading data-loading-text="Menyimpan..."
                        class="px-5 py-2 rounded-lg text-sm font-semibold text-white transition hover:opacity-90"
                        style="background:var(--brand-color)">Simpan Lisensi Vendor</button>
              </div>

            </div>
          </div>
        </form>

        <!-- ── Agency SEO Engine (paket 50 slot) — HANYA instalasi vendor ── -->
        <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-agency-seo"
              class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mt-5">
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <div class="p-5 space-y-4">
            <div>
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Agency SEO Engine</h3>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Tetapkan produk mana saja yang, saat lunas, memberi pembeli kuota lisensi SEO lifetime untuk dibagikan ke kliennya lewat portal <span class="font-mono">/agency-seo</span>. Isi jumlah slot tiap produk paket (kosong/0 = bukan paket agency). <strong>Bisa lebih dari satu produk</strong> (mis. tier/whitelabel berbeda).</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Produk paket agency (aktif)</label>
              <?php if ($asMap): ?>
              <div class="rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-800 mb-2">
                <?php foreach (($vfProducts ?? []) as $p): $pid = (int) $p['id']; if (!isset($asMap[$pid])) continue; ?>
                <div class="flex items-center gap-3 px-3 py-2">
                  <span class="flex-1 min-w-0 text-sm text-gray-700 dark:text-gray-300 truncate"><?= e($p['name']) ?>
                    <span class="text-xs text-gray-400 font-mono">#<?= $pid ?></span>
                  </span>
                  <input type="number" name="as_slot[<?= $pid ?>]" value="<?= (int) $asMap[$pid] ?>" min="0" max="1000" step="1"
                         class="w-24 px-2.5 h-8 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                  <span class="text-[11px] text-gray-400 shrink-0 w-20">slot (0 = lepas)</span>
                </div>
                <?php endforeach; ?>
              </div>
              <?php else: ?>
              <p class="text-xs text-gray-400 dark:text-gray-500 mb-2">Belum ada produk paket — portal <span class="font-mono">/agency-seo</span> tak terisi sampai minimal satu produk diberi slot.</p>
              <?php endif; ?>

              <?php if (!empty($asUnmapped)): ?>
              <div class="flex items-center gap-2">
                <select name="as_new_product" class="flex-1 min-w-0 px-2.5 h-8 rounded-lg text-xs border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                  <option value="">+ Tambah produk…</option>
                  <?php foreach ($asUnmapped as $p): ?>
                  <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (#<?= (int) $p['id'] ?>)</option>
                  <?php endforeach; ?>
                </select>
                <input type="number" name="as_new_slot" value="" min="1" max="1000" step="1" placeholder="slot (mis. 50)"
                       class="w-28 px-2.5 h-8 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                <span class="text-[11px] text-gray-400 shrink-0 w-16">lalu Simpan</span>
              </div>
              <?php endif; ?>
              <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1.5">Penerbitan per slot memakai endpoint &amp; rahasia Lisensi Vendor di atas. Pembeli yang order lagi (produk mana pun di atas) otomatis dapat kuota tambahan di portal. Berlaku untuk order gateway <strong>maupun aktivasi manual</strong> (Input Transaksi Manual / konfirmasi bayar).</p>
            </div>
            <div class="flex justify-end">
              <button type="submit" data-loading data-loading-text="Menyimpan..."
                      class="px-5 py-2 rounded-lg text-sm font-semibold text-white transition hover:opacity-90"
                      style="background:var(--brand-color)">Simpan Agency SEO</button>
            </div>
          </div>
        </form>
        <?php endif; ?>
        </div>

      </div>

      <!-- ══════════════════════════════════════════════════════════ -->
      <!-- TAB: EMAIL                                                 -->
      <!-- Menulis ke settings table via save-notification-template  -->
      <!-- ══════════════════════════════════════════════════════════ -->
      <div id="panel-email" class="tab-panel <?= $activeTab !== 'email' ? 'hidden' : '' ?> max-w-2xl">

        <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-notification-template" class="js-ajax-save">
          <input type="hidden" name="csrf_token"    value="<?= e($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="mode"          value="save_keys">
          <input type="hidden" name="redirect_back" value="/admin/integrations?tab=email">

          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
              <div class="w-9 h-9 rounded-lg bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center">
                <svg class="text-orange-600 dark:text-orange-400" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
              </div>
              <div>
                <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Mailketing</h3>
                <p class="text-xs text-gray-400 dark:text-gray-600">Email marketing & notifikasi transaksional</p>
              </div>
            </div>
            <div class="p-5 space-y-4">

              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">API Key</label>
                <div class="relative">
                  <input type="password" name="mailketing_api_key" id="mailKey"
                         value="" placeholder="<?= $sv('mailketing_api_key') !== '' ? '•••••••••• tersimpan — isi untuk mengganti' : 'API key dari dashboard Mailketing' ?>"
                         autocomplete="new-password"
                         class="w-full pr-10 px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
                  <button type="button" onclick="togglePw('mailKey', this)"
                          class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <svg class="w-4 h-4 eye-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="w-4 h-4 eye-hide hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                  </button>
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Kosongkan untuk mode simulasi (email tidak benar-benar terkirim)</p>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Email Pengirim</label>
                  <input type="email" name="mailketing_sender_email"
                         value="<?= e($sv('mailketing_sender_email')) ?>" placeholder="noreply@domain.com"
                         class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Nama Pengirim</label>
                  <input type="text" name="mailketing_sender_name"
                         value="<?= e($sv('mailketing_sender_name', $appName)) ?>" placeholder="Nama bisnis"
                         class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
                </div>
              </div>

              <!-- List Otomatis (auto add subscriber saat order & lunas) -->
              <div class="border-t border-gray-100 dark:border-gray-800 pt-4 mt-4">
                <div class="flex items-center justify-between gap-2 mb-1">
                  <p class="text-sm font-medium text-gray-700 dark:text-gray-300">List Otomatis</p>
                  <button type="button" id="mk-load-lists"
                          class="text-xs font-semibold px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 whitespace-nowrap disabled:opacity-50">Muat daftar list</button>
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500 mb-3">Otomatis tambahkan pembeli ke list Mailketing saat order dibuat &amp; saat lunas. Kosongkan = nonaktif. Klik <strong>Muat daftar list</strong> untuk mengambil pilihan (butuh API key tersimpan).</p>
                <?php
                $mkCache = json_decode((string) $sv('mailketing_lists_cache', ''), true);
                if (!is_array($mkCache)) $mkCache = [];
                $mkOrder = (string) $sv('mailketing_list_order', '');
                $mkPaid  = (string) $sv('mailketing_list_paid', '');
                $mkSelCls = 'w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none';
                $mkOpt = function (string $selected) use ($mkCache) {
                    $h = '<option value="">— Nonaktif —</option>';
                    $found = false;
                    foreach ($mkCache as $l) {
                        $id = (string) ($l['list_id'] ?? '');
                        if ($id === '') continue;
                        $nm = (string) ($l['list_name'] ?? $id);
                        $sel = $id === $selected ? ' selected' : '';
                        if ($sel !== '') $found = true;
                        $h .= '<option value="' . e($id) . '"' . $sel . '>' . e($nm) . ' (#' . e($id) . ')</option>';
                    }
                    if ($selected !== '' && !$found) {
                        $h .= '<option value="' . e($selected) . '" selected>List #' . e($selected) . '</option>';
                    }
                    return $h;
                };
                ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">List saat <strong>Order dibuat</strong></label>
                    <select name="mailketing_list_order" id="mk-list-order" data-selected="<?= e($mkOrder) ?>" class="<?= $mkSelCls ?>"><?= $mkOpt($mkOrder) ?></select>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">List saat <strong>Order Lunas</strong></label>
                    <select name="mailketing_list_paid" id="mk-list-paid" data-selected="<?= e($mkPaid) ?>" class="<?= $mkSelCls ?>"><?= $mkOpt($mkPaid) ?></select>
                  </div>
                </div>
                <p id="mk-lists-result" class="text-xs mt-2 hidden"></p>
              </div>

              <!-- Test Kirim Email -->
              <div class="border-t border-gray-100 dark:border-gray-800 pt-4 mt-4">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Test Kirim Email</p>
                <div class="flex gap-2">
                  <input type="email"
                         id="test-email-input"
                         placeholder="email tujuan tes"
                         class="flex-1 px-4 py-2.5 rounded-xl text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                  <button type="button"
                          id="btn-test-email"
                          onclick="testKirimEmail()"
                          class="px-4 py-2.5 border border-gray-200 dark:border-gray-700 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition whitespace-nowrap disabled:opacity-50">
                    Test Kirim
                  </button>
                </div>
                <p id="test-email-result" class="text-xs mt-1.5 hidden"></p>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-1.5">Simpan API key dulu sebelum tes.</p>
              </div>

            </div>
            <div class="px-5 py-4 bg-gray-50 dark:bg-gray-800/50 border-t border-gray-100 dark:border-gray-800 flex justify-end">
              <button type="submit" data-loading data-loading-text="Menyimpan..."
                      class="px-5 py-2 rounded-lg text-sm font-semibold text-white transition hover:opacity-90"
                      style="background:var(--brand-color)">
                Simpan Pengaturan Email
              </button>
            </div>
          </div>
        </form>

        <p class="mt-4 text-xs text-gray-400 dark:text-gray-600">
          Template email per event (registrasi, order, dsb) dikelola di
          <a href="<?= e(APP_URL) ?>/admin/notifications" class="text-[var(--brand-color)] hover:underline">Pengaturan Notifikasi</a>.
        </p>
      </div>

      <!-- ══════════════════════════════════════════════════════════ -->
      <!-- TAB: WHATSAPP                                              -->
      <!-- Per-provider API key + enabled toggle → save_keys mode    -->
      <!-- ══════════════════════════════════════════════════════════ -->
      <div id="panel-whatsapp" class="tab-panel <?= $activeTab !== 'whatsapp' ? 'hidden' : '' ?> max-w-2xl">

        <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-notification-template" class="js-ajax-save">
          <input type="hidden" name="csrf_token"    value="<?= e($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="mode"          value="save_keys">
          <input type="hidden" name="redirect_back" value="/admin/integrations?tab=whatsapp">

          <?php
          $otpEnabled         = $sv('wa_otp_login_enabled', '0') === '1';
          $currentOtpProvider = $sv('wa_otp_provider', '');
          $otpProviderLabels  = ['fonte'=>'Fonte','onesender'=>'OneSender','starsender'=>'StarSender','dripsender'=>'DripSender','kirimdev'=>'Kirimdev','averionrelay'=>'Averion Relay'];

          // Cek provider mana yang dipakai untuk notifikasi event
          $eventProviders = [];
          try {
              $eventProviders = getDB()->query(
                  "SELECT DISTINCT provider FROM notification_templates WHERE channel='whatsapp' AND is_active=1"
              )->fetchAll(PDO::FETCH_COLUMN);
          } catch (Exception $e) {}

          // Provider yang BENAR-BENAR mengirim. Menyalakan toggle sebuah provider
          // TIDAK otomatis membuatnya dipakai: selama template masih menunjuk
          // provider lain yang juga menyala, yang lama tetap menang (lihat
          // NotificationDispatcher::effectiveWaProvider). Dihitung lewat dispatcher
          // — bukan disalin ulang di sini — supaya layar & pengirim tak pernah beda
          // pendapat saat urutan/aturannya berubah.
          if (!class_exists('NotificationDispatcher')
              && file_exists(__DIR__ . '/../../notifications/NotificationDispatcher.php')) {
              require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
          }
          $waHasDispatcher = class_exists('NotificationDispatcher');
          $waEffective = [];
          if ($waHasDispatcher) {
              foreach ($eventProviders as $p) {
                  $eff = NotificationDispatcher::effectiveWaProvider((string) $p);
                  if ($eff !== '') $waEffective[$eff] = true;
              }
          }
          // Provider yang menyala tapi tak mengirim apa pun (kalah dari pilihan template).
          $waIdle = [];
          if ($waHasDispatcher && $waEffective) {
              foreach (NotificationDispatcher::WA_PROVIDER_PRIORITY as $wp) {
                  if ($sv($wp . '_enabled', '0') === '1' && !isset($waEffective[$wp])) $waIdle[] = $wp;
              }
          }
          $waLabel    = fn($p) => $otpProviderLabels[$p] ?? ucfirst((string) $p);
          $waEffNames = implode(', ', array_map($waLabel, array_keys($waEffective)));
          ?>

          <!-- ─── Badge info pemakaian provider ──────────────────────── -->
          <div class="bg-blue-50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 rounded-lg px-4 py-3 mb-4 text-sm space-y-1">
            <?php if ($otpEnabled && $currentOtpProvider): ?>
              <p class="text-blue-700 dark:text-blue-400">
                <strong>Login OTP</strong> memakai provider:
                <strong><?= e($otpProviderLabels[$currentOtpProvider] ?? ucfirst($currentOtpProvider)) ?></strong>
              </p>
            <?php endif; ?>
            <?php if (!empty($waEffective)): ?>
              <p class="text-blue-700 dark:text-blue-400">
                <strong>Notifikasi Event</strong> dikirim lewat:
                <strong><?= e($waEffNames) ?></strong>
              </p>
            <?php elseif (!empty($eventProviders)): ?>
              <p class="text-blue-700 dark:text-blue-400">
                <strong>Notifikasi Event</strong> belum bisa terkirim — template menunjuk
                <strong><?= e(implode(', ', array_map($waLabel, $eventProviders))) ?></strong>,
                tapi tidak ada provider WhatsApp yang menyala.
              </p>
            <?php endif; ?>
            <?php if ((!$otpEnabled || !$currentOtpProvider) && empty($eventProviders)): ?>
              <p class="text-blue-700 dark:text-blue-400">
                Belum ada provider aktif untuk OTP maupun notifikasi event. Aktifkan provider di bawah, lalu atur pemakaiannya di section terkait.
              </p>
            <?php endif; ?>
          </div>

          <?php if (!empty($waIdle)): ?>
          <!-- Jebakan yang sering makan korban: admin menyalakan provider baru (mis.
               Averion Relay) dan mengira WA langsung pindah — padahal template masih
               menunjuk provider lama yang juga menyala, jadi tak ada yang berubah dan
               tak ada pesan error apa pun. Peringatan ini yang membuatnya kelihatan.
               Catatan warna: dark:bg-amber-900/xx TIDAK ter-compile di tailwind.css →
               amber untuk light, netral gray untuk dark. -->
          <div class="flex items-start gap-3 p-4 mb-4 rounded-lg border border-amber-200 dark:border-gray-800 bg-amber-50 dark:bg-gray-900">
            <svg class="w-4 h-4 mt-0.5 shrink-0 text-amber-600 dark:text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
              <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <div class="text-sm min-w-0">
              <p class="font-semibold text-amber-800 dark:text-amber-400">
                <?= e(implode(', ', array_map($waLabel, $waIdle))) ?> menyala tapi belum dipakai
              </p>
              <p class="text-amber-700 dark:text-gray-300 mt-0.5">
                Template notifikasi WhatsApp masih menunjuk <strong><?= e($waEffNames) ?></strong>,
                dan provider itu juga menyala — jadi pesan tetap dikirim lewat sana.
                Untuk memindahkan: ubah provider template di halaman
                <a href="<?= e(APP_URL) ?>/admin/notifications" class="font-semibold underline">Notifikasi</a>,
                atau matikan <strong><?= e($waEffNames) ?></strong> di bawah.
              </p>
            </div>
          </div>
          <?php endif; ?>

          <?php
          // Ikon eye reusable (inline)
          $eyeShow = '<svg class="w-4 h-4 eye-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
          $eyeHide = '<svg class="w-4 h-4 eye-hide hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
          $chevronSvg = '<svg class="w-4 h-4 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>';

          // Helper: render toggle switch
          // onclick stopPropagation mencegah klik toggle memicu expand/collapse card
          $renderToggle = function(string $name, bool $checked) {
              $c = $checked ? 'checked' : '';
              return '<label class="relative inline-flex items-center cursor-pointer shrink-0" onclick="event.stopPropagation()">
                <input type="hidden" name="' . $name . '" value="0">
                <input type="checkbox" name="' . $name . '" value="1" ' . $c . ' class="sr-only peer" onclick="event.stopPropagation()">
                <div class="w-9 h-5 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                <div class="absolute left-0.5 top-0.5 w-4 h-4 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-4"></div>
              </label>';
          };
          ?>

          <div class="space-y-2.5" id="whatsapp-accordion">

            <!-- ─── 4 provider unofficial: Fonte, OneSender, StarSender, DripSender ── -->
            <?php
            $waProviderDefs = [
                'fonte'      => ['label' => 'Fonte',      'key' => 'fonte_api_key',      'placeholder' => 'Authorization token dari Fonnte',      'desc' => 'Provider unofficial Fonnte — tidak terikat 24-hour window, cocok untuk OTP ke nomor manapun.'],
                'onesender'  => ['label' => 'OneSender',  'key' => 'onesender_api_key',  'placeholder' => 'Bearer token dari OneSender',           'desc' => 'Provider unofficial — tidak terikat 24-hour window WhatsApp.'],
                'starsender' => ['label' => 'StarSender', 'key' => 'starsender_api_key', 'placeholder' => 'API key dari StarSender',               'desc' => 'Provider unofficial — tidak terikat 24-hour window WhatsApp.'],
                'dripsender' => ['label' => 'DripSender', 'key' => 'dripsender_api_key', 'placeholder' => 'API key dari DripSender',              'desc' => 'Provider unofficial — tidak terikat 24-hour window WhatsApp. Ambil API key di dashboard DripSender, menu Bot / Integrasi API.'],
                'averionrelay' => ['label' => 'Averion Relay', 'key' => 'averionrelay_api_key', 'placeholder' => 'AVRN-XXXX-XXXX-XXXX', 'desc' => 'WhatsApp gateway resmi Averion. Ambil License Key dari dashboard Averion Relay Anda (menu License Key), lalu tempel di bawah.'],
            ];
            foreach ($waProviderDefs as $slug => $def):
                $isEnabled = $sv($slug . '_enabled', '0') === '1';
                $isFilled  = !empty($sv($def['key']));
                $fieldId   = 'wa_' . $def['key'];
            ?>
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 overflow-hidden">
              <!-- Card header — satu area klik, toggle switch punya stopPropagation sendiri -->
              <div class="wa-accordion-toggle flex items-center justify-between px-5 py-3.5 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors select-none"
                   data-wa-section="<?= $slug ?>" role="button">
                <div class="flex items-center gap-2.5 flex-1 min-w-0">
                  <svg class="w-[15px] h-[15px] text-green-500 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2z"/>
                  </svg>
                  <span class="text-sm font-medium text-gray-900 dark:text-white truncate"><?= e($def['label']) ?></span>
                  <?php if ($isFilled): ?>
                    <span class="text-[11px] px-1.5 py-0.5 rounded-md bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 font-medium shrink-0">Terisi</span>
                  <?php else: ?>
                    <span class="text-[11px] px-1.5 py-0.5 rounded-md bg-gray-100 dark:bg-gray-700/60 text-gray-400 dark:text-gray-500 shrink-0">Belum diisi</span>
                  <?php endif; ?>
                  <?php if ($isEnabled && !empty($waEffective) && !isset($waEffective[$slug])): ?>
                    <span class="text-[11px] px-1.5 py-0.5 rounded-md bg-amber-50 dark:bg-gray-800 text-amber-700 dark:text-amber-400 font-medium shrink-0"
                          title="Provider ini menyala, tapi template notifikasi masih menunjuk provider lain yang juga menyala — jadi belum ada pesan yang lewat sini.">Tidak dipakai</span>
                  <?php endif; ?>
                  <?php if ($slug === 'averionrelay'): ?>
                  <!-- Relay = layanan berlangganan: dari sini admin bisa langsung beli
                       (belum punya key) atau memperpanjang (key kedaluwarsa). WAJIB
                       stopPropagation: header kartu ini area klik accordion, tanpa itu
                       klik link ikut membuka/menutup panel. -->
                  <a href="https://vendor.averion.id/storefront?product=relay"
                     target="_blank" rel="noopener noreferrer"
                     onclick="event.stopPropagation()"
                     title="Beli atau perpanjang langganan Averion Relay"
                     class="inline-flex items-center gap-1 text-[11px] font-medium text-[var(--brand-color)] hover:underline shrink-0">
                    Beli / Perpanjang
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                      <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                  </a>
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-2.5 shrink-0 ml-3">
                  <?= $renderToggle($slug . '_enabled', $isEnabled) ?>
                  <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" id="wa-chevron-<?= $slug ?>"
                       viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                  </svg>
                </div>
              </div>
              <!-- Card body (expandable) -->
              <div class="wa-accordion-content" id="wa-content-<?= $slug ?>"
                   style="max-height:0;overflow:hidden;transition:max-height .22s ease">
                <div class="px-5 pb-5 pt-4 border-t border-gray-100 dark:border-gray-800 space-y-3">
                  <p class="text-xs text-gray-400 dark:text-gray-500"><?= e($def['desc']) ?></p>
                  <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">
                      <?= $slug === 'averionrelay' ? 'License Key' : e($def['label']) . ' API Key' ?>
                    </label>
                    <div class="relative">
                      <input type="password"
                             name="<?= e($def['key']) ?>"
                             id="<?= e($fieldId) ?>"
                             value=""
                             placeholder="<?= $sv($def['key']) !== '' ? '•••••••••• tersimpan — isi untuk mengganti' : ($slug === 'averionrelay' ? 'AVRN-XXXX-XXXX-XXXX' : e($def['placeholder'])) ?>"
                             autocomplete="new-password"
                             class="w-full pr-10 px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
                      <button type="button" onclick="togglePw('<?= e($fieldId) ?>', this)"
                              class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <?= $eyeShow ?><?= $eyeHide ?>
                      </button>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                      <?= $slug === 'averionrelay' ? 'License Key Averion Relay yang kamu dapat saat beli/aktivasi. Kosongkan untuk mode simulasi.' : 'Kosongkan untuk mode simulasi' ?>
                    </p>
                  </div>

                  <?php if ($slug === 'onesender'): ?>
                  <!-- OneSender self-hosted: URL server API bisa dikonfigurasi.
                       Ini akar bug "OneSender tidak jalan" — URL dulu hardcode
                       ke OneSender Cloud, bukan server milik user. -->
                  <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">API URL OneSender</label>
                    <input type="url" name="onesender_api_url" value="<?= e($sv('onesender_api_url', '')) ?>"
                           placeholder="https://api.onesender.id/api/v1/messages"
                           class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Kosongkan untuk OneSender Cloud. <strong>Isi dengan URL server OneSender kamu sendiri</strong> bila self-hosted — contoh: <span class="font-mono">https://wa.domainmu.com/api/v1/messages</span>.</p>
                  </div>
                  <?php endif; ?>

                  <?php // Averion Relay: hosted SaaS di relay.averion.id — URL endpoint TIDAK
                  // ditampilkan ke customer (selalu sama, tak perlu dikonfigurasi). Untuk
                  // dev lokal (Ady), isi manual setting averionrelay_api_url via DB. ?>

                  <!-- Test Kirim WhatsApp -->
                  <div class="border-t border-gray-100 dark:border-gray-800 pt-3 mt-1">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Test Kirim WhatsApp</label>
                    <div class="flex gap-2">
                      <input type="text" id="wa-test-to-<?= e($slug) ?>" placeholder="08xxxxxxxxxx"
                             class="flex-1 px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
                      <button type="button" id="wa-test-btn-<?= e($slug) ?>" onclick="testKirimWa('<?= e($slug) ?>')"
                              class="px-4 py-2.5 border border-gray-200 dark:border-gray-700 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition whitespace-nowrap disabled:opacity-50">Test Kirim</button>
                    </div>
                    <p id="wa-test-result-<?= e($slug) ?>" class="text-xs mt-1.5 hidden"></p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Simpan API key dulu sebelum tes.</p>
                  </div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>

            <!-- ─── Kirimdev (provider resmi Cloud API, 2 field + template sub-section) ── -->
            <?php
            $kirimdevEnabled = $sv('kirimdev_enabled', '0') === '1';
            $kirimdevFilled  = !empty($sv('kirimdev_api_key'));
            ?>
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 overflow-hidden">
              <div class="wa-accordion-toggle flex items-center justify-between px-5 py-3.5 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors select-none"
                   data-wa-section="kirimdev" role="button">
                <div class="flex items-center gap-2.5 flex-1 min-w-0">
                  <svg class="w-[15px] h-[15px] text-blue-400 dark:text-blue-500 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                  </svg>
                  <span class="text-sm font-medium text-gray-900 dark:text-white truncate">Kirimdev (WhatsApp Cloud API)</span>
                  <?php if ($kirimdevFilled): ?>
                    <span class="text-[11px] px-1.5 py-0.5 rounded-md bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 font-medium shrink-0">Terisi</span>
                  <?php else: ?>
                    <span class="text-[11px] px-1.5 py-0.5 rounded-md bg-gray-100 dark:bg-gray-700/60 text-gray-400 dark:text-gray-500 shrink-0">Belum diisi</span>
                  <?php endif; ?>
                  <?php if ($kirimdevEnabled && !empty($waEffective) && !isset($waEffective['kirimdev'])): ?>
                    <span class="text-[11px] px-1.5 py-0.5 rounded-md bg-amber-50 dark:bg-gray-800 text-amber-700 dark:text-amber-400 font-medium shrink-0"
                          title="Provider ini menyala, tapi template notifikasi masih menunjuk provider lain yang juga menyala — jadi belum ada pesan yang lewat sini.">Tidak dipakai</span>
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-2.5 shrink-0 ml-3">
                  <?= $renderToggle('kirimdev_enabled', $kirimdevEnabled) ?>
                  <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" id="wa-chevron-kirimdev"
                       viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                  </svg>
                </div>
              </div>
              <div class="wa-accordion-content" id="wa-content-kirimdev"
                   style="max-height:0;overflow:hidden;transition:max-height .22s ease">
                <div class="px-5 pb-5 pt-4 border-t border-gray-100 dark:border-gray-800 space-y-4">
                  <p class="text-xs text-gray-400 dark:text-gray-500">Provider resmi Meta/WhatsApp Cloud API. Memerlukan 24-hour conversation window atau template message yang disetujui Meta.</p>

                  <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Kirimdev API Key</label>
                    <div class="relative">
                      <input type="password"
                             name="kirimdev_api_key"
                             id="wa_kirimdev_api_key"
                             value=""
                             placeholder="<?= $sv('kirimdev_api_key') !== '' ? '•••••••••• tersimpan — isi untuk mengganti' : 'kdv_live_xxxxxxxxxxxxxxxx' ?>"
                             autocomplete="new-password"
                             class="w-full pr-10 px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
                      <button type="button" onclick="togglePw('wa_kirimdev_api_key', this)"
                              class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <?= $eyeShow ?><?= $eyeHide ?>
                      </button>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Kosongkan untuk mode simulasi</p>
                  </div>

                  <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Kirimdev Phone Number ID</label>
                    <input type="text"
                           name="kirimdev_phone_number_id"
                           value="<?= e($sv('kirimdev_phone_number_id')) ?>"
                           placeholder="106540352242922"
                           class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">ID nomor WA pengirim — dapatkan dari dashboard Kirimdev → GET /accounts</p>
                  </div>

                  <!-- Test Kirim WhatsApp -->
                  <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Test Kirim WhatsApp</label>
                    <div class="flex gap-2">
                      <input type="text" id="wa-test-to-kirimdev" placeholder="08xxxxxxxxxx"
                             class="flex-1 px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
                      <button type="button" id="wa-test-btn-kirimdev" onclick="testKirimWa('kirimdev')"
                              class="px-4 py-2.5 border border-gray-200 dark:border-gray-700 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition whitespace-nowrap disabled:opacity-50">Test Kirim</button>
                    </div>
                    <p id="wa-test-result-kirimdev" class="text-xs mt-1.5 hidden"></p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Simpan API key & Phone Number ID dulu. Free-text hanya berhasil bila penerima sudah chat &lt;24 jam.</p>
                  </div>

                  <!-- Template Message sub-section -->
                  <div class="bg-gray-50 dark:bg-gray-800/60 rounded-xl p-4 space-y-3">
                    <p class="text-[11px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Template Message (OTP)</p>
                    <div>
                      <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Nama Template OTP</label>
                      <input type="text"
                             name="kirimdev_otp_template_name"
                             value="<?= e($sv('kirimdev_otp_template_name')) ?>"
                             placeholder="otp_verification"
                             class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
                      <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                        Nama template yang <strong>sudah disetujui Meta</strong> (kategori AUTHENTICATION).
                      </p>
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Bahasa Template</label>
                      <input type="text"
                             name="kirimdev_otp_template_lang"
                             value="<?= e($sv('kirimdev_otp_template_lang', 'id')) ?>"
                             placeholder="id"
                             class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
                      <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                        Kode bahasa <strong>persis seperti saat template di-approve Meta</strong> — mis. <span class="font-mono">id</span>, <span class="font-mono">en_US</span>, <span class="font-mono">en</span>. Salah kode → gagal kirim.
                      </p>
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Metode Pengiriman OTP via Kirimdev</label>
                      <select name="kirimdev_otp_method"
                              class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
                        <option value="text" <?= $sv('kirimdev_otp_method', 'text') === 'text' ? 'selected' : '' ?>>
                          Teks Bebas (gagal jika penerima belum chat &gt;24 jam)
                        </option>
                        <option value="template" <?= $sv('kirimdev_otp_method', 'text') === 'template' ? 'selected' : '' ?>>
                          Template Message (perlu approval Meta dulu)
                        </option>
                      </select>
                      <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                        ⚠ Jangan pilih "Template Message" sebelum template benar-benar disetujui Meta.
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- ─── Login via WhatsApp OTP ──────────────────────────────── -->
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 overflow-hidden">
              <button type="button"
                      class="wa-accordion-toggle w-full flex items-center justify-between px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors text-left"
                      data-wa-section="otp">
                <div class="flex items-center gap-2.5">
                  <svg class="w-[15px] h-[15px] text-gray-400 dark:text-gray-500 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="5" y="11" width="14" height="10" rx="2" ry="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>
                  </svg>
                  <span class="text-sm font-medium text-gray-900 dark:text-white">Login via WhatsApp OTP</span>
                  <?php if ($otpEnabled && $currentOtpProvider): ?>
                    <span class="text-[11px] px-1.5 py-0.5 rounded-md bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 font-medium">
                      Aktif — <?= e($otpProviderLabels[$currentOtpProvider] ?? $currentOtpProvider) ?>
                    </span>
                  <?php else: ?>
                    <span class="text-[11px] px-1.5 py-0.5 rounded-md bg-gray-100 dark:bg-gray-700/60 text-gray-400 dark:text-gray-500">Nonaktif</span>
                  <?php endif; ?>
                </div>
                <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" id="wa-chevron-otp"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
              </button>
              <div class="wa-accordion-content" id="wa-content-otp"
                   data-auto-expand="<?= ($otpEnabled && $currentOtpProvider) ? '1' : '0' ?>"
                   style="max-height:0;overflow:hidden;transition:max-height .22s ease">
                <div class="px-5 pb-5 pt-4 border-t border-gray-100 dark:border-gray-800 space-y-4">

                  <div class="flex items-center justify-between">
                    <div>
                      <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Aktifkan Login OTP</p>
                      <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Tampilkan opsi "Login dengan WhatsApp" di halaman login</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer shrink-0">
                      <input type="hidden"   name="wa_otp_login_enabled" value="0">
                      <input type="checkbox" name="wa_otp_login_enabled" value="1"
                             <?= $otpEnabled ? 'checked' : '' ?> class="sr-only peer">
                      <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                      <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                    </label>
                  </div>

                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Provider WhatsApp untuk OTP</label>
                    <select name="wa_otp_provider"
                            class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
                      <option value="">— Pilih provider —</option>
                      <?php foreach (['fonte'=>'Fonte (Fonnte)','onesender'=>'OneSender','starsender'=>'StarSender','dripsender'=>'DripSender','kirimdev'=>'Kirimdev (WhatsApp Cloud API)','averionrelay'=>'Averion Relay'] as $val => $label): ?>
                      <option value="<?= e($val) ?>" <?= $currentOtpProvider === $val ? 'selected' : '' ?>>
                        <?= e($label) ?>
                      </option>
                      <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                      Provider ini <strong>khusus untuk OTP</strong> — terpisah dari provider notifikasi event.
                      Pastikan API key provider yang dipilih sudah diisi dan toggle-nya diaktifkan di atas.
                    </p>
                  </div>

                  <?php $otpMsgDefault = "Kode OTP login *{app_name}* kamu:\n\n*{code}*\n\nBerlaku {minutes} menit. Jangan bagikan kode ini kepada siapapun."; ?>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Teks Pesan OTP</label>
                    <textarea name="wa_otp_message" rows="5"
                              class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none leading-relaxed font-mono"><?= e($sv('wa_otp_message', $otpMsgDefault)) ?></textarea>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                      Placeholder: <span class="font-mono">{code}</span> (kode OTP, wajib ada), <span class="font-mono">{app_name}</span>, <span class="font-mono">{minutes}</span>. Pakai <span class="font-mono">*teks*</span> untuk <strong>tebal</strong> di WhatsApp.
                    </p>
                    <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                      ⚠ Hanya untuk provider teks-bebas (Fonte/OneSender/StarSender/DripSender). <strong>Kirimdev mode Template</strong> memakai template Meta — edit di dashboard Kirimdev.
                    </p>
                  </div>

                </div>
              </div>
            </div>

          </div><!-- /#whatsapp-accordion -->

          <div class="mt-4 flex justify-end">
            <button type="submit" data-loading data-loading-text="Menyimpan..."
                    class="px-5 py-2 rounded-lg text-sm font-semibold text-white transition hover:opacity-90"
                    style="background:var(--brand-color)">
              Simpan Pengaturan WhatsApp
            </button>
          </div>
        </form>

      </div>

      <!-- ══════════════════════════════════════════════════════════ -->
      <!-- TAB: TELEGRAM — notifikasi INTERNAL admin (bukan customer)  -->
      <!-- Menulis ke settings via save-notification-template (save_keys). -->
      <!-- ══════════════════════════════════════════════════════════ -->
      <div id="panel-telegram" class="tab-panel <?= $activeTab !== 'telegram' ? 'hidden' : '' ?> max-w-2xl">
        <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-notification-template" class="js-ajax-save">
          <input type="hidden" name="csrf_token"    value="<?= e($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="mode"          value="save_keys">
          <input type="hidden" name="redirect_back" value="/admin/integrations?tab=telegram">

          <?php
          $tgEnabled    = $sv('telegram_enabled', '0') === '1';
          $tgTokenSet   = trim($sv('telegram_bot_token', '')) !== '';
          $tgChatId     = $sv('telegram_chat_id', '');
          $tgAlertFail  = $sv('telegram_alert_order_failed', '1') === '1';
          $tgAlertLogin = $sv('telegram_alert_admin_login', '1') === '1';
          ?>

          <div class="bg-blue-50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 rounded-lg px-4 py-3 mb-4 text-sm">
            <p class="text-blue-700 dark:text-blue-400">
              Notifikasi <strong>internal admin/tim</strong> (bukan ke pelanggan): order masuk, order gagal/batal,
              dan login admin dari IP baru dikirim ke chat atau grup Telegram secara real-time.
            </p>
            <p class="text-blue-700 dark:text-blue-400 mt-1">
              Belum punya Bot Token / Chat ID?
              <a href="<?= e(APP_URL) ?>/admin/docs#telegram" class="font-semibold underline">Lihat panduan setup</a>.
            </p>
          </div>

          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-5 space-y-4">

            <!-- Master toggle -->
            <div class="flex items-center justify-between">
              <div class="min-w-0 pr-3">
                <p class="text-sm font-medium text-gray-900 dark:text-white">Aktifkan Notifikasi Telegram</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Saklar utama. Saat mati, tidak ada pesan Telegram yang dikirim.</p>
              </div>
              <?= $renderToggle('telegram_enabled', $tgEnabled) ?>
            </div>

            <!-- Bot Token -->
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Bot Token</label>
              <div class="relative">
                <input type="password" name="telegram_bot_token" id="tg-token" value=""
                       placeholder="<?= $tgTokenSet ? '•••••••••• tersimpan — isi untuk mengganti' : 'mis. 8673151257:AAE...' ?>"
                       autocomplete="new-password"
                       class="w-full pr-10 px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
                <button type="button" onclick="togglePw('tg-token', this)"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><?= $eyeShow ?><?= $eyeHide ?></button>
              </div>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Dapat dari <strong>@BotFather</strong>. Kosongkan = mode simulasi (tidak mengirim).</p>
            </div>

            <!-- Chat ID -->
            <div>
              <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Chat ID Tujuan</label>
              <input type="text" name="telegram_chat_id" id="tg-chat" value="<?= e($tgChatId) ?>"
                     placeholder="mis. 7963660698 atau -100xxxxxxxxx (grup)"
                     class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none">
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Chat pribadi (angka) atau grup/channel (diawali <code class="px-1 rounded bg-gray-100 dark:bg-gray-800">-100</code>). Kirim <code class="px-1 rounded bg-gray-100 dark:bg-gray-800">/start</code> ke bot dulu agar bot boleh mengirim ke kamu.</p>
            </div>

            <!-- Jenis alert -->
            <div class="pt-3 border-t border-gray-100 dark:border-gray-800 space-y-3">
              <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Jenis Notifikasi</p>
              <div class="flex items-center justify-between">
                <p class="text-sm text-gray-700 dark:text-gray-300">Order gagal / dibatalkan</p>
                <?= $renderToggle('telegram_alert_order_failed', $tgAlertFail) ?>
              </div>
              <div class="flex items-center justify-between">
                <p class="text-sm text-gray-700 dark:text-gray-300">Login admin dari IP baru</p>
                <?= $renderToggle('telegram_alert_admin_login', $tgAlertLogin) ?>
              </div>
              <p class="text-xs text-gray-400 dark:text-gray-500">
                Pesan <strong>order masuk</strong> dikelola sebagai template di
                <a href="<?= e(APP_URL) ?>/admin/notifications" class="underline text-[var(--brand-color)]">Notifikasi</a>
                (channel Telegram) — teksnya bisa kamu edit dan aktif/nonaktifkan di sana.
              </p>
            </div>

            <!-- Test + Simpan -->
            <div class="pt-4 border-t border-gray-100 dark:border-gray-800 flex flex-wrap items-center gap-3">
              <button type="submit" data-loading data-loading-text="Menyimpan..."
                      class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white transition hover:opacity-90"
                      style="background:var(--brand-color)">Simpan Pengaturan Telegram</button>
              <button type="button" id="tg-test-btn" onclick="testTelegram()"
                      class="px-4 py-2.5 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Test Kirim</button>
              <span id="tg-test-result" class="text-xs hidden"></span>
            </div>
            <p class="text-xs text-gray-400 dark:text-gray-500">Test memakai Bot Token &amp; Chat ID yang <strong>sudah tersimpan</strong> — simpan dulu sebelum menekan Test.</p>
          </div>
        </form>
      </div>

      <!-- ─── Panel: Manual Transfer ──────────────────────────────── -->
      <div id="panel-manual" class="tab-panel <?= $activeTab !== 'manual' ? 'hidden' : '' ?> max-w-2xl space-y-5">

        <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-settings" class="js-ajax-save">
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="group" value="integrations">

          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Manual Transfer</h3>
              <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">User pilih rekening tujuan, transfer, lalu upload bukti. Admin konfirmasi manual.</p>
            </div>
            <div class="p-5 space-y-5">
              <div class="flex items-center justify-between">
                <div>
                  <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Aktifkan pembayaran Manual Transfer</p>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Saat aktif & ada rekening aktif, opsi muncul di checkout.</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input type="hidden" name="settings[manual_transfer_enabled]" value="0">
                  <input type="checkbox" name="settings[manual_transfer_enabled]" value="1" <?= $manualTransferEnabled ? 'checked' : '' ?> class="sr-only peer">
                  <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                  <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                </label>
              </div>

              <div class="flex items-center justify-between">
                <div>
                  <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Kode unik nominal</p>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Tambah 3 digit acak (1–999) ke total agar tiap transfer unik &amp; mudah dicocokkan. Mis. Rp150.000 → Rp150.347.</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input type="hidden" name="settings[manual_unique_code_enabled]" value="0">
                  <input type="checkbox" name="settings[manual_unique_code_enabled]" value="1" <?= $manualUniqueCodeEnabled ? 'checked' : '' ?> class="sr-only peer">
                  <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                  <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                </label>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Instruksi Pembayaran</label>
                <textarea name="settings[manual_transfer_instructions]" rows="3" maxlength="1000"
                          class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white resize-none focus:ring-2 focus:ring-[var(--brand-color)] outline-none"><?= e($manualTransferInstructions) ?></textarea>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Tampil di halaman upload bukti transfer user.</p>
              </div>

              <button type="submit" class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
                Simpan Pengaturan
              </button>
            </div>
          </div>
        </form>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Daftar Rekening Bank</h3>
            <a href="<?= e(APP_URL) ?>/admin/bank-accounts" class="text-xs font-semibold text-[var(--brand-color)] hover:underline">Kelola Rekening →</a>
          </div>
          <?php if (empty($previewBankAccounts)): ?>
          <div class="p-5 text-sm text-gray-500 dark:text-gray-400">Belum ada rekening aktif. <a href="<?= e(APP_URL) ?>/admin/bank-accounts" class="text-[var(--brand-color)] hover:underline">Tambah rekening</a> agar opsi Manual Transfer muncul di checkout.</div>
          <?php else: ?>
          <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800/50 text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
              <tr><th class="px-5 py-2.5 text-left font-semibold">Bank</th><th class="px-5 py-2.5 text-left font-semibold">Nomor Rekening</th><th class="px-5 py-2.5 text-left font-semibold">Atas Nama</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($previewBankAccounts as $b): ?>
              <tr>
                <td class="px-5 py-2.5 font-medium text-gray-900 dark:text-white"><?= e($b['bank_name']) ?></td>
                <td class="px-5 py-2.5 font-mono text-gray-700 dark:text-gray-300"><?= e($b['account_number']) ?></td>
                <td class="px-5 py-2.5 text-gray-700 dark:text-gray-300"><?= e($b['account_name']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>

      </div>

    </main>
  </div>
</div>

<script>
const TAB_ON  = ['border-[var(--brand-color)]','text-[var(--brand-color)]'];
const TAB_OFF = ['border-transparent','text-gray-500','dark:text-gray-400','hover:text-gray-700','dark:hover:text-gray-300'];

function switchTab(name) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.add('hidden'));
    document.querySelectorAll('.tab-btn').forEach(b => {
        b.classList.remove(...TAB_ON);
        b.classList.add(...TAB_OFF);
    });
    const panel = document.getElementById('panel-' + name);
    if (panel) panel.classList.remove('hidden');
    const btn = document.getElementById('tab-' + name);
    if (btn) { btn.classList.add(...TAB_ON); btn.classList.remove(...TAB_OFF); }
    const url = new URL(window.location);
    url.searchParams.set('tab', name);
    window.history.replaceState({}, '', url);
}

function togglePw(fieldId, btn) {
    const input = document.getElementById(fieldId);
    const show  = btn.querySelector('.eye-show');
    const hide  = btn.querySelector('.eye-hide');
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        show.classList.add('hidden');
        hide.classList.remove('hidden');
    } else {
        input.type = 'password';
        show.classList.remove('hidden');
        hide.classList.add('hidden');
    }
}

// Accordion WA & Payment dihandle oleh initIntegrationAccordions() di app.js

async function testKirimEmail() {
  const email  = document.getElementById('test-email-input').value.trim();
  const result = document.getElementById('test-email-result');
  const btn    = document.getElementById('btn-test-email');

  if (!email) {
    result.textContent = 'Isi email tujuan dulu.';
    result.className   = 'text-xs mt-1.5 text-red-500';
    result.classList.remove('hidden');
    return;
  }

  btn.disabled    = true;
  btn.textContent = 'Mengirim...';
  result.classList.add('hidden');

  try {
    const res  = await fetch(<?= json_encode(rtrim(APP_URL, '/') . '/actions/admin/test-email') ?>, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        email,
        csrf_token: document.querySelector('[name="csrf_token"]')?.value ?? ''
      })
    });
    const data = await res.json();

    result.textContent = data.message;
    result.className   = 'text-xs mt-1.5 ' +
                         (data.success ? 'text-green-600' : 'text-red-500');
    result.classList.remove('hidden');
  } catch (e) {
    result.textContent = 'Request gagal.';
    result.className   = 'text-xs mt-1.5 text-red-500';
    result.classList.remove('hidden');
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Test Kirim';
  }
}

// ── Mailketing: muat daftar list untuk dropdown ──────────────
(function () {
  const btn = document.getElementById('mk-load-lists');
  if (!btn) return;
  const result = document.getElementById('mk-lists-result');
  function rebuild(sel, lists) {
    if (!sel) return;
    const cur = sel.value || sel.getAttribute('data-selected') || '';
    let html = '<option value="">— Nonaktif —</option>';
    let found = false;
    lists.forEach(function (l) {
      const id = String(l.list_id == null ? '' : l.list_id);
      if (!id) return;
      const nm = String(l.list_name == null ? id : l.list_name).replace(/</g, '&lt;');
      const s = id === cur ? ' selected' : '';
      if (s) found = true;
      html += '<option value="' + id + '"' + s + '>' + nm + ' (#' + id + ')</option>';
    });
    if (cur && !found) html += '<option value="' + cur + '" selected>List #' + cur + '</option>';
    sel.innerHTML = html;
  }
  btn.addEventListener('click', async function () {
    btn.disabled = true; const t = btn.textContent; btn.textContent = 'Memuat...';
    if (result) result.classList.add('hidden');
    try {
      const res = await fetch(<?= json_encode(rtrim(APP_URL, '/') . '/actions/admin/fetch-mailketing-lists') ?>, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: document.querySelector('[name="csrf_token"]')?.value ?? '' })
      });
      const data = await res.json();
      if (data.success && Array.isArray(data.lists)) {
        rebuild(document.getElementById('mk-list-order'), data.lists);
        rebuild(document.getElementById('mk-list-paid'), data.lists);
        result.textContent = '✓ ' + data.lists.length + ' list dimuat. Pilih lalu klik Simpan.';
        result.className = 'text-xs mt-2 text-green-600';
      } else {
        result.textContent = '✗ ' + (data.message || 'Gagal memuat list.');
        result.className = 'text-xs mt-2 text-red-500';
      }
      result.classList.remove('hidden');
    } catch (e) {
      result.textContent = '✗ Request gagal.';
      result.className = 'text-xs mt-2 text-red-500';
      result.classList.remove('hidden');
    } finally {
      btn.disabled = false; btn.textContent = t;
    }
  });
})();

async function testKirimWa(provider) {
  const input  = document.getElementById('wa-test-to-' + provider);
  const result = document.getElementById('wa-test-result-' + provider);
  const btn    = document.getElementById('wa-test-btn-' + provider);
  const to     = (input?.value ?? '').trim();

  if (!to) {
    result.textContent = 'Isi nomor tujuan dulu.';
    result.className   = 'text-xs mt-1.5 text-red-500';
    result.classList.remove('hidden');
    return;
  }

  btn.disabled    = true;
  btn.textContent = 'Mengirim...';
  result.classList.add('hidden');

  try {
    const res  = await fetch(<?= json_encode(rtrim(APP_URL, '/') . '/actions/admin/test-wa') ?>, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        provider,
        to,
        csrf_token: document.querySelector('[name="csrf_token"]')?.value ?? ''
      })
    });
    const data = await res.json();

    result.textContent = data.message;
    result.className   = 'text-xs mt-1.5 ' + (data.success ? 'text-green-600' : 'text-red-500');
    result.classList.remove('hidden');
  } catch (e) {
    result.textContent = 'Request gagal.';
    result.className   = 'text-xs mt-1.5 text-red-500';
    result.classList.remove('hidden');
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Test Kirim';
  }
}

async function testTelegram() {
  const btn    = document.getElementById('tg-test-btn');
  const result = document.getElementById('tg-test-result');
  if (!btn || !result) return;
  const label = btn.textContent;
  btn.disabled = true; btn.textContent = 'Mengirim...';
  result.classList.add('hidden');
  try {
    const res  = await fetch(<?= json_encode(rtrim(APP_URL, '/') . '/actions/admin/test-telegram') ?>, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ csrf_token: document.querySelector('[name="csrf_token"]')?.value ?? '' })
    });
    const data = await res.json();
    result.textContent = data.message;
    result.className   = 'text-xs ' + (data.success ? 'text-green-600' : 'text-red-500');
    result.classList.remove('hidden');
  } catch (e) {
    result.textContent = 'Request gagal.';
    result.className   = 'text-xs text-red-500';
    result.classList.remove('hidden');
  } finally {
    btn.disabled = false; btn.textContent = label;
  }
}

async function testMootaConnection() {
  const btn = document.getElementById('moota-test-btn');
  const res = document.getElementById('moota-test-result');
  if (!btn || !res) return;
  btn.disabled = true; btn.textContent = 'Menguji...';
  res.classList.add('hidden');
  try {
    const r = await fetch(<?= json_encode(rtrim(APP_URL, '/') . '/actions/admin/test-moota') ?>, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ csrf_token: document.querySelector('[name="csrf_token"]')?.value ?? '' })
    });
    const d = await r.json();
    res.textContent = d.message;
    res.className   = 'text-xs ' + (d.success ? 'text-green-600' : 'text-red-500');
    res.classList.remove('hidden');
    // Info Rekening terisi OTOMATIS dari rekening terdaftar di Moota.
    if (d.success && typeof d.bank_info !== 'undefined') {
      const box = document.getElementById('moota-bank-info');
      if (box) {
        if (d.bank_info && d.bank_info.trim() !== '') { box.textContent = d.bank_info; }
        else { box.innerHTML = '<span class="text-amber-600">Belum ada rekening terdaftar di Moota. Tambahkan dulu di dashboard Moota.</span>'; }
      }
    }
  } catch (e) {
    res.textContent = 'Request gagal.';
    res.className   = 'text-xs text-red-500';
    res.classList.remove('hidden');
  } finally {
    btn.disabled = false; btn.textContent = 'Test Koneksi';
  }
}

function copyWebhookUrl(inputId, btn) {
  const inp = document.getElementById(inputId);
  if (!inp) return;
  inp.select();
  const done = function () { const t = btn.textContent; btn.textContent = 'Tersalin'; setTimeout(function () { btn.textContent = t; }, 1500); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(inp.value).then(done).catch(function () { document.execCommand('copy'); done(); });
  } else {
    document.execCommand('copy'); done();
  }
}
</script>

<?php include __DIR__ . '/../../partials/save-toast.php'; ?>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('notifications')) { redirect('/admin/no-access'); }

$pageTitle   = 'Notifikasi';
$topbarTitle = 'Notifikasi Email & WhatsApp';

$pdo = getDB();

// ─── Load templates ───────────────────────────────────────────
$templates = [];
try {
    $templates = $pdo->query(
        "SELECT * FROM notification_templates ORDER BY event_key ASC, channel ASC"
    )->fetchAll();
} catch (Exception $e) {
    error_log('Notifications load templates error: ' . $e->getMessage());
}

// ─── Load logs (Tab 2) ───────────────────────────────────────
$logStatus  = $_GET['log_status']  ?? '';
$logChannel = $_GET['log_channel'] ?? '';
$logPage    = max(1, (int) ($_GET['log_page'] ?? 1));
$logPerPage = 30;
$logOffset  = ($logPage - 1) * $logPerPage;
$activeTab  = ($_GET['tab'] ?? 'templates') === 'logs' ? 'logs' : 'templates';

$logWhere  = ['1=1'];
$logParams = [];
if (in_array($logStatus, ['pending', 'sent', 'failed'], true)) {
    $logWhere[]  = 'status = ?';
    $logParams[] = $logStatus;
}
if (in_array($logChannel, ['email', 'whatsapp'], true)) {
    $logWhere[]  = 'channel = ?';
    $logParams[] = $logChannel;
}
$logWhereStr = implode(' AND ', $logWhere);

$logs      = [];
$logTotal  = 0;
$logPages  = 1;
try {
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM notification_logs WHERE $logWhereStr");
    $cntStmt->execute($logParams);
    $logTotal = (int) $cntStmt->fetchColumn();
    $logPages = max(1, (int) ceil($logTotal / $logPerPage));

    $lStmt = $pdo->prepare(
        "SELECT * FROM notification_logs WHERE $logWhereStr ORDER BY created_at DESC LIMIT $logPerPage OFFSET $logOffset"
    );
    $lStmt->execute($logParams);
    $logs = $lStmt->fetchAll();
} catch (Exception $e) {
    error_log('Notifications load logs error: ' . $e->getMessage());
}

generateCSRF();

// Event KHUSUS instalasi vendor. Templatenya ikut ter-seed lewat migrasi ke SEMUA
// instalasi (tak apa — tak pernah terpicu tanpa konstanta AVERION_VENDOR_INSTALL),
// tapi jangan sampai muncul di daftar Notifikasi pembeli: mereka akan bingung
// melihat template fitur yang tak mereka punya.
$vendorOnlyEvents = ['license_fulfillment_failed'];
if (!function_exists('vfAvailable') || !vfAvailable()) {
    $templates = array_values(array_filter(
        $templates,
        fn($t) => !in_array($t['event_key'], $vendorOnlyEvents, true)
    ));
}

// CATATAN: daftar ini WAJIB memuat SEMUA event yang benar-benar dipicu kode.
// Event yang tidak terdaftar di sini tetap tampil di tabel (diambil dari DB) TAPI
// TAK BISA DISIMPAN — save-notification-template.php menolaknya "Event tidak valid".
// Dulu 6 event (booking/waitlist/renewal) tertinggal saat fiturnya dirilis → admin
// bisa melihat templatenya tapi gagal menyimpan setiap kali mengedit.
$validEvents   = [
    'register', 'order_created', 'payment_success', 'access_activated',
    'order_cancelled',
    'public_checkout_welcome', 'access_link', 'abandoned_cart', 'abandoned_cart_2', 'abandoned_cart_3', 'abandoned_cart_bonus',
    'affiliate_new_order',
    'commission_approved', 'payout_processing', 'payout_paid', 'payout_rejected',
    'lead_captured', 'lead_admin_notify', 'payment_admin_notify',
    'renewal_reminder', 'renewal_expired', 'license_fulfillment_failed',
    'booking_confirmed', 'settlement_reminder', 'booking_expired',
    'waitlist_joined', 'waitlist_launch',
];
// Dropdown "buat template baru" juga tak boleh menawarkan event vendor ke pembeli.
if (!function_exists('vfAvailable') || !vfAvailable()) {
    $validEvents = array_values(array_diff($validEvents, $vendorOnlyEvents));
}
$eventLabels   = [
    'register'                => 'Registrasi Baru',
    'order_created'           => 'Order Dibuat',
    'payment_success'         => 'Pembayaran Berhasil',
    'access_activated'        => 'Akses Diaktifkan',
    'order_cancelled'         => 'Transaksi Dibatalkan',
    'public_checkout_welcome' => 'Checkout Publik — Akun Baru',
    'access_link'             => 'Akses Instan — Link Login Pasca-Bayar (Akun Lama)',
    'abandoned_cart'          => 'Keranjang Tertinggal — Pengingat #1',
    'abandoned_cart_2'        => 'Keranjang Tertinggal — Pengingat #2',
    'abandoned_cart_3'        => 'Keranjang Tertinggal — Pengingat #3 (Terakhir)',
    'abandoned_cart_bonus'    => 'Keranjang Tertinggal — Sisipan Urgensi Bonus Kilat',
    'affiliate_new_order'     => 'Afiliasi — Penjualan Baru via Link',
    'commission_approved'     => 'Komisi Disetujui',
    'payout_processing'       => 'Pencairan Diproses',
    'payout_paid'             => 'Pencairan Dibayar',
    'payout_rejected'         => 'Pencairan Ditolak',
    'lead_captured'           => 'Lead Magnet — Konfirmasi ke Lead',
    'lead_admin_notify'       => 'Lead Magnet — Notifikasi Admin',
    'payment_admin_notify'    => 'Pembayaran Masuk — Notifikasi Admin',
    'renewal_reminder'        => 'Langganan — Pengingat Perpanjang',
    'renewal_expired'         => 'Langganan — Sudah Berakhir (Ajakan Aktifkan Lagi)',
    'license_fulfillment_failed' => 'Lisensi Vendor — Penerbitan Gagal (Notifikasi Admin)',
    'booking_confirmed'       => 'Booking — DP Diterima',
    'settlement_reminder'     => 'Booking — Pengingat Pelunasan',
    'booking_expired'         => 'Booking — Hangus',
    'waitlist_joined'         => 'Waitlist — Konfirmasi Pendaftaran',
    'waitlist_launch'         => 'Waitlist — Produk Sudah Rilis',
];

// Daftar placeholder yang tersedia per event (dipakai untuk chip auto-insert di
// modal edit + referensi statis di atas tabel). {{app_name}} & {{brand_color}}
// di-inject global oleh NotificationDispatcher, jadi selalu tersedia.
$placeholdersByEvent = [
    'register'                => ['name','email','login_url','dashboard_url','app_name','brand_color'],
    'order_created'           => ['name','email','order_number','product_name','total','payment_method','invoice_url','app_name','brand_color'],
    'payment_success'         => ['name','email','order_number','total','paid_at','invoice_url','member_url','app_name','brand_color'],
    'access_activated'        => ['name','email','product_name','order_number','member_url','invoice_url','app_name','brand_color'],
    'order_cancelled'         => ['name','order_number','product_name','total','app_name','brand_color'],
    'public_checkout_welcome' => ['name','email','temp_password','login_url','member_url','app_name','brand_color'],
    'access_link'             => ['name','email','product_name','access_url','expiry_minutes','member_url','app_name','brand_color'],
    'abandoned_cart'          => ['name','email','product_name','total','order_number','checkout_url','app_name','brand_color'],
    'abandoned_cart_2'        => ['name','email','product_name','total','order_number','checkout_url','app_name','brand_color'],
    'abandoned_cart_3'        => ['name','email','product_name','total','order_number','checkout_url','app_name','brand_color'],
    'abandoned_cart_bonus'    => ['flash_bonus_list','app_name','brand_color'],
    'affiliate_new_order'     => ['name','amount','order_number','product_name','total','buyer_name','affiliate_url','app_name','brand_color'],
    'commission_approved'     => ['name','email','amount','order_number','affiliate_url','app_name','brand_color'],
    'payout_processing'       => ['name','email','amount','bank_name','bank_account_no','bank_account_name','affiliate_url','app_name','brand_color'],
    'payout_paid'             => ['name','email','amount','bank_name','affiliate_url','app_name','brand_color'],
    'payout_rejected'         => ['name','email','amount','reason','affiliate_url','app_name','brand_color'],
    'lead_captured'           => ['name','whatsapp','email','lead_magnet','app_name','brand_color'],
    'lead_admin_notify'       => ['name','whatsapp','email','lead_magnet','app_name','brand_color'],
    'payment_admin_notify'    => ['name','whatsapp','email','product_name','order_number','total','paid_at','invoice_url','app_name','brand_color'],
    'renewal_reminder'        => ['name','product_name','days_left','expiry_date','renew_url','member_url','app_name','brand_color'],
    'renewal_expired'         => ['name','product_name','days_ago','expiry_date','renew_url','member_url','app_name','brand_color'],
    'license_fulfillment_failed' => ['order_number','product_name','customer_name','email','reason','admin_url','app_name','brand_color'],
    'booking_confirmed'       => ['name','product_name','booking_amount','settlement_total','due_date','settlement_url','app_name','brand_color'],
    'settlement_reminder'     => ['name','product_name','days_left','settlement_total','due_date','settlement_url','app_name','brand_color'],
    'booking_expired'         => ['name','product_name','due_date','product_url','app_name','brand_color'],
    'waitlist_joined'         => ['name','product_name','product_url','app_name','brand_color'],
    'waitlist_launch'         => ['name','product_name','product_url','app_name','brand_color'],
];

// Status enabled per provider — untuk warning di modal edit
$providerEnabledMap = [];
$activeWaProvider   = ''; // provider WA pertama yang sedang aktif (untuk default template baru)
foreach (['fonte', 'onesender', 'starsender', 'dripsender', 'kirimdev', 'averionrelay'] as $_p) {
    $providerEnabledMap[$_p] = getSetting($_p . '_enabled', '0') === '1';
    if ($activeWaProvider === '' && $providerEnabledMap[$_p]) {
        $activeWaProvider = $_p;
    }
}
// Mailketing tidak punya toggle enabled — cukup cek ada/tidak API key
$providerEnabledMap['mailketing'] = !empty(getSetting('mailketing_api_key', ''));
// Telegram = channel internal admin: aktif bila toggle telegram_enabled ON DAN
// chat_id terisi (persis syarat kirim di NotificationDispatcher). Tanpa baris ini
// map tidak pernah punya kunci 'telegram' → template Telegram selalu salah dicap
// "Provider nonaktif" walau bot sudah dikonfigurasi.
$providerEnabledMap['telegram'] = getSetting('telegram_enabled', '0') === '1'
    && trim((string) getSetting('telegram_chat_id', '')) !== '';

$channelBadge = function(string $ch): string {
    if ($ch === 'email') {
        return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">Email</span>';
    }
    if ($ch === 'telegram') {
        // Warna sky Telegram via inline style — terbaca di light & dark, tanpa
        // menambah utility Tailwind baru (sky-* belum ter-compile di tailwind.css).
        return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" style="background:rgba(56,189,248,0.15);color:#0284c7">Telegram</span>';
    }
    return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400">WhatsApp</span>';
};

$statusBadge = function(string $st): string {
    return match ($st) {
        'sent'    => '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400">Terkirim</span>',
        'failed'  => '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400">Gagal</span>',
        default   => '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-50 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">Pending</span>',
    };
};
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="mb-5 flex items-center justify-between gap-4">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Notifikasi</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Template email &amp; WhatsApp otomatis per event</p>
        </div>
        <?php if ($activeTab === 'templates'): ?>
        <button type="button" onclick="openNotifEditor(null, 'new')"
          class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold text-white"
          style="background:var(--brand-color)">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambah Template
        </button>
        <?php endif; ?>
      </div>

      <!-- ─── Tabs ─────────────────────────────────────────── -->
      <div class="flex gap-1 mb-5 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-1 w-fit shadow-sm">
        <a href="?tab=templates"
           class="px-4 py-1.5 rounded-lg text-sm font-medium transition-colors <?= $activeTab === 'templates' ? 'bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' ?>">
          Template
        </a>
        <a href="?tab=logs"
           class="px-4 py-1.5 rounded-lg text-sm font-medium transition-colors <?= $activeTab === 'logs' ? 'bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' ?>">
          Log Notifikasi
          <?php if ($logTotal > 0): ?><span class="ml-1 text-xs text-gray-400">(<?= number_format($logTotal) ?>)</span><?php endif; ?>
        </a>
      </div>

      <?php if ($activeTab === 'templates'): ?>
      <!-- ════════════════════════════════════════════════════════ -->
      <!-- TAB 1: TEMPLATES                                        -->
      <!-- ════════════════════════════════════════════════════════ -->

      <!-- Placeholder reference — accordion (default tertutup), chip click-to-copy -->
      <?php
        // Placeholder global (selalu tersedia di semua event) dipisah agar grup
        // per-event tidak penuh pengulangan.
        $globalPh = ['app_name', 'brand_color'];
      ?>
      <details class="group bg-gray-50 dark:bg-gray-900/50 border border-gray-200 dark:border-gray-800 rounded-xl mb-4 overflow-hidden">
        <summary class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 cursor-pointer select-none list-none">
          <div class="flex items-center gap-2 min-w-0">
            <svg class="w-4 h-4 text-gray-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate">Placeholder Tersedia per Event</span>
            <span class="text-xs text-gray-400 shrink-0">· <?= count($placeholdersByEvent) ?> event</span>
          </div>
          <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="px-4 pb-4 pt-1 border-t border-gray-200 dark:border-gray-800">
          <p class="text-[11px] text-gray-400 dark:text-gray-500 mb-3 mt-2">Klik chip untuk menyalin placeholder. Saat mengedit template, chip yang sama bisa langsung disisipkan ke isi pesan.</p>

          <!-- Grup global -->
          <div class="mb-3">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-1.5">Tersedia di semua event</p>
            <div class="flex flex-wrap gap-1.5">
              <?php foreach ($globalPh as $ph): ?>
              <button type="button" onclick="copyPlaceholder(this, '{{<?= e($ph) ?>}}')"
                      class="ph-chip px-2 py-0.5 rounded-md text-xs font-mono whitespace-nowrap transition-colors"
                      title="Klik untuk menyalin">{{<?= e($ph) ?>}}</button>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Grup per event -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-3">
            <?php foreach ($placeholdersByEvent as $ek => $phs): ?>
            <div>
              <p class="text-[11px] font-semibold text-gray-600 dark:text-gray-300 mb-1.5">
                <?= e($eventLabels[$ek] ?? $ek) ?>
                <code class="ml-1 text-[10px] font-normal text-gray-400"><?= e($ek) ?></code>
              </p>
              <div class="flex flex-wrap gap-1.5">
                <?php foreach ($phs as $ph): if (in_array($ph, $globalPh, true)) continue; ?>
                <button type="button" onclick="copyPlaceholder(this, '{{<?= e($ph) ?>}}')"
                        class="ph-chip px-2 py-0.5 rounded-md text-xs font-mono whitespace-nowrap transition-colors"
                        title="Klik untuk menyalin">{{<?= e($ph) ?>}}</button>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </details>

      <!-- Template table -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-5">
        <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
          <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Template Notifikasi</h3>
          <span class="text-xs text-gray-400"><?= count($templates) ?> template</span>
        </div>
        <?php if (empty($templates)): ?>
        <div class="px-5 py-12 text-center">
          <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <p class="text-sm text-gray-400 dark:text-gray-500">Belum ada template. Tambah template pertama kamu.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800/50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Event</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Channel</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Provider</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Subject</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <!-- Slot editor inline untuk "Tambah Template" -->
              <tr class="notif-editor-row hidden" data-editor-for="new">
                <td colspan="6" class="p-0"><div class="notif-editor-slot"></div></td>
              </tr>
              <?php foreach ($templates as $t): ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                <td class="px-4 py-3">
                  <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="text-sm font-medium text-gray-900 dark:text-white"><?= e($eventLabels[$t['event_key']] ?? $t['event_key']) ?></span>
                    <?php if (in_array($t['event_key'], $validEvents, true)): ?>
                      <span class="text-[11px] px-1.5 py-0.5 bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 rounded font-medium">Bawaan Sistem</span>
                    <?php endif; ?>
                  </div>
                  <p class="text-xs text-gray-400 font-mono mt-0.5"><?= e($t['event_key']) ?></p>
                </td>
                <td class="px-4 py-3"><?= $channelBadge($t['channel']) ?></td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400"><?= e(ucfirst($t['provider'] ?? '—')) ?></td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 max-w-xs truncate"><?= e($t['subject'] ?: '—') ?></td>
                <td class="px-4 py-3">
                  <?php
                  $tProvider   = $t['provider'] ?? '';
                  $tProviderOk = $providerEnabledMap[$tProvider] ?? false;
                  ?>
                  <?php if (!$t['is_active']): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">Nonaktif</span>
                  <?php elseif (!$tProviderOk): ?>
                    <span title="Provider <?= e(ucfirst($tProvider)) ?> belum diaktifkan di Pengaturan → Integrasi, jadi template ini tidak akan terkirim lewat provider tersebut."
                          class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                      <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                      Provider nonaktif
                    </span>
                  <?php else: ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400">Aktif</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3">
                  <div class="flex items-center justify-end gap-1.5">
                    <button type="button" data-editor-btn="<?= (int)$t['id'] ?>"
                      onclick="openNotifEditor(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>, <?= (int)$t['id'] ?>)"
                      class="notif-edit-btn inline-flex items-center gap-1.5 pl-2.5 pr-2 py-1.5 rounded-lg text-xs font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-[var(--brand-color)] hover:text-[var(--brand-color)] transition-colors">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                      <span>Edit</span>
                      <svg class="notif-edit-chevron w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-notification-template"
                      class="contents" id="del-notif-<?= (int)$t['id'] ?>">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="mode"       value="delete">
                      <input type="hidden" name="id"         value="<?= (int)$t['id'] ?>">
                      <button type="button" title="Hapus template"
                        onclick="confirmAndSubmit('del-notif-<?= (int)$t['id'] ?>', {title:'Hapus Template?',message:'Template ini akan dihapus permanen.',variant:'danger'})"
                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
              <tr class="notif-editor-row hidden" data-editor-for="<?= (int)$t['id'] ?>">
                <td colspan="6" class="p-0"><div class="notif-editor-slot"></div></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- Info box: API key pindah ke Integrasi -->
      <div class="bg-blue-50 dark:bg-blue-900/10 border border-blue-200 dark:border-blue-800/40 rounded-xl p-4 flex gap-3">
        <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <div>
          <p class="text-sm font-medium text-blue-800 dark:text-blue-300">API key provider dikelola di halaman Integrasi</p>
          <p class="text-sm text-blue-700 dark:text-blue-400 mt-0.5">
            Konfigurasi API key untuk Email (Mailketing) dan WhatsApp (Fonnte, OneSender, StarSender, DripSender, Kirimdev, Averion Relay) tersedia di
            <a href="<?= e(APP_URL) ?>/admin/integrations?tab=email" class="font-semibold underline underline-offset-2">Integrasi → Email</a>
            dan
            <a href="<?= e(APP_URL) ?>/admin/integrations?tab=whatsapp" class="font-semibold underline underline-offset-2">Integrasi → WhatsApp</a>.
          </p>
        </div>
      </div>

      <?php else: ?>
      <!-- ════════════════════════════════════════════════════════ -->
      <!-- TAB 2: LOG NOTIFIKASI                                   -->
      <!-- ════════════════════════════════════════════════════════ -->

      <!-- Filter + Aksi -->
      <div class="mb-4 flex flex-wrap gap-3 items-end justify-between">
      <form method="GET" action="" class="flex flex-wrap gap-3 items-end">
        <input type="hidden" name="tab" value="logs">
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1">Channel</label>
          <select name="log_channel"
            class="h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
            <option value="">Semua Channel</option>
            <option value="email"     <?= $logChannel === 'email'     ? 'selected' : '' ?>>Email</option>
            <option value="whatsapp"  <?= $logChannel === 'whatsapp'  ? 'selected' : '' ?>>WhatsApp</option>
            <option value="telegram"  <?= $logChannel === 'telegram'  ? 'selected' : '' ?>>Telegram</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1">Status</label>
          <select name="log_status"
            class="h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
            <option value="">Semua Status</option>
            <option value="sent"    <?= $logStatus === 'sent'    ? 'selected' : '' ?>>Terkirim</option>
            <option value="failed"  <?= $logStatus === 'failed'  ? 'selected' : '' ?>>Gagal</option>
            <option value="pending" <?= $logStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
          </select>
        </div>
        <button type="submit"
          class="px-4 h-9 rounded-lg text-sm font-semibold text-white"
          style="background:var(--brand-color)">Filter</button>
        <?php if ($logStatus || $logChannel): ?>
        <a href="?tab=logs" class="px-3 h-9 inline-flex items-center rounded-lg text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 border border-gray-200 dark:border-gray-700">Reset</a>
        <?php endif; ?>
      </form>

      <?php if (!empty($logs)): ?>
      <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/clear-notification-logs" id="clear-notif-logs">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <button type="button"
          onclick="confirmAndSubmit('clear-notif-logs', {title:'Bersihkan Log Notifikasi?',message:'Semua log notifikasi akan dihapus permanen. Tindakan ini tidak bisa dibatalkan.',confirmText:'Ya, Bersihkan',variant:'danger'})"
          class="px-4 h-9 inline-flex items-center gap-1.5 rounded-lg text-sm font-medium text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
          Bersihkan Log
        </button>
      </form>
      <?php endif; ?>
      </div>

      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($logs)): ?>
        <div class="px-5 py-12 text-center">
          <p class="text-sm text-gray-400 dark:text-gray-500">Belum ada log notifikasi.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800/50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Waktu</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Event</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Channel</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Provider</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Penerima</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($logs as $log): ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap"><?= e(date('d/m/y H:i', strtotime($log['created_at']))) ?></td>
                <td class="px-4 py-3">
                  <span class="text-sm text-gray-700 dark:text-gray-300"><?= e($eventLabels[$log['event_key']] ?? $log['event_key']) ?></span>
                </td>
                <td class="px-4 py-3"><?= $channelBadge($log['channel'] ?? '') ?></td>
                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400"><?= e(ucfirst($log['provider'] ?? '—')) ?></td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 font-mono"><?= e($log['recipient'] ?? '—') ?></td>
                <td class="px-4 py-3"><?= $statusBadge($log['status']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <?php if ($logPages > 1): ?>
        <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
          <span>Halaman <?= $logPage ?> dari <?= $logPages ?> (<?= number_format($logTotal) ?> entri)</span>
          <div class="flex gap-1">
            <?php for ($p = 1; $p <= $logPages; $p++): ?>
              <?php if ($p === $logPage): ?>
                <span class="px-2.5 py-1 rounded-lg text-xs font-semibold text-white" style="background:var(--brand-color)"><?= $p ?></span>
              <?php elseif (abs($p - $logPage) <= 2 || $p === 1 || $p === $logPages): ?>
                <a href="?tab=logs&log_page=<?= $p ?>&log_status=<?= urlencode($logStatus) ?>&log_channel=<?= urlencode($logChannel) ?>"
                   class="px-2.5 py-1 rounded-lg text-xs border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800"><?= $p ?></a>
              <?php endif; ?>
            <?php endfor; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php endif; ?>

    </main>
  </div>
</div>

<!-- ─── Editor Template (inline, di-relokasi ke baris tabel) ────────────────────────────────────────── -->
<div id="notifEditorParking" class="hidden">
  <div id="notifEditorCard" class="border-y-2" style="border-color: color-mix(in srgb, var(--brand-color) 25%, transparent); background: color-mix(in srgb, var(--brand-color) 4%, transparent);">

    <!-- Header -->
    <div class="max-w-3xl mx-auto w-full flex items-center justify-between px-5 py-3 border-b border-gray-200 dark:border-gray-800">
      <h3 id="notifModalTitle" class="text-sm font-bold text-gray-900 dark:text-white">Tambah Template</h3>
      <button type="button" onclick="closeNotifEditor()" title="Tutup editor" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <!-- Body -->
    <form id="notifForm" method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-notification-template">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="mode"       value="save_template">
        <input type="hidden" name="id"         id="notifId" value="0">

        <div class="max-w-3xl mx-auto w-full p-5 space-y-4">

          <!-- event_key -->
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Event</label>
            <select name="event_key" id="notifEventKey" required onchange="renderPlaceholderChips(this.value)"
              class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
              <option value="">— Pilih Event —</option>
              <?php foreach ($validEvents as $ek): ?>
              <option value="<?= e($ek) ?>"><?= e($eventLabels[$ek] ?? $ek) ?> (<?= e($ek) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- channel -->
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Channel</label>
            <select name="channel" id="notifChannel" required onchange="onChannelChange(this.value)"
              class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
              <option value="">— Pilih Channel —</option>
              <option value="email">Email</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="telegram">Telegram</option>
            </select>
          </div>

          <!-- provider -->
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Provider</label>
            <select name="provider" id="notifProvider" required onchange="onProviderChange(this.value)"
              class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
              <option value="">— Pilih Provider —</option>
            </select>
          </div>

          <!-- Warning: provider belum aktif -->
          <div id="notifProviderWarning" class="hidden bg-amber-50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/30 rounded-lg p-3 text-xs text-amber-700 dark:text-amber-400">
            ⚠️ Provider <strong id="notifProviderWarningName"></strong> belum diaktifkan.
            Aktifkan dulu di <a href="<?= e(APP_URL) ?>/admin/integrations" class="underline font-medium">Pengaturan Integrasi</a>
            sebelum mengaktifkan template ini.
          </div>

          <!-- subject (email only) -->
          <div id="notifSubjectWrap">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Subject Email</label>
            <input type="text" name="subject" id="notifSubject" placeholder="Misal: Selamat datang, {{name}}!"
              class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
          </div>

          <!-- body -->
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Isi Pesan</label>
            <textarea name="body" id="notifBody" rows="5" required
              placeholder="Halo {{name}}, terima kasih sudah mendaftar!"
              class="w-full px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] resize-y"></textarea>
            <!-- Chip placeholder dinamis — terisi sesuai event yang dipilih. Klik = sisip ke isi pesan. -->
            <div class="mt-2">
              <p id="notifChipsHint" class="text-xs text-gray-400 dark:text-gray-500 mb-1.5">Pilih event untuk melihat placeholder yang tersedia. Klik chip untuk menyisipkannya.</p>
              <div id="notifPlaceholderChips" class="flex flex-wrap gap-1.5"></div>
            </div>
          </div>

          <!-- is_active -->
          <div class="flex items-center justify-between py-2 border-t border-gray-100 dark:border-gray-800">
            <div>
              <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Aktifkan Template</p>
              <p class="text-xs text-gray-400 dark:text-gray-500">Template nonaktif tidak akan dikirim</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" name="is_active" id="notifIsActive" value="1" class="sr-only peer">
              <div class="w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer peer-checked:bg-[var(--brand-color)] after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5"></div>
            </label>
          </div>

        </div>

        <!-- Footer -->
        <div class="max-w-3xl mx-auto w-full px-5 py-3 border-t border-gray-200 dark:border-gray-800 flex justify-end gap-3">
          <button type="button" onclick="closeNotifEditor()"
            class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">
            Batal
          </button>
          <button type="submit" data-loading="Menyimpan..."
            class="px-5 py-2 rounded-lg text-sm font-semibold text-white"
            style="background:var(--brand-color)">Simpan Template</button>
        </div>
      </form>
    </div>
  </div>

<!-- ─── Modal Test Kirim ───────────────────────────────────────── -->
<div id="testModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeTestModal()"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-800 w-full max-w-sm">
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800">
        <h3 class="text-base font-bold text-gray-900 dark:text-white">Test Kirim</h3>
        <button type="button" onclick="closeTestModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-notification-template" class="p-6 space-y-4">
        <input type="hidden" name="csrf_token"    value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="mode"          value="test_send">
        <input type="hidden" name="test_provider" id="testProvider" value="">
        <p id="testModalDesc" class="text-sm text-gray-500 dark:text-gray-400"></p>
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Penerima</label>
          <input type="text" name="test_recipient" id="testRecipient" required
            placeholder="email@contoh.com atau 628xxx"
            class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
        </div>
        <div class="flex justify-end gap-3 pt-2">
          <button type="button" onclick="closeTestModal()"
            class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Batal</button>
          <button type="submit" data-loading="Mengirim..."
            class="px-5 py-2 rounded-lg text-sm font-semibold text-white"
            style="background:var(--brand-color)">Kirim Test</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const providerMap = {
  email:     ['mailketing'],
  whatsapp:  ['fonte', 'onesender', 'starsender', 'dripsender', 'kirimdev', 'averionrelay'],
  telegram:  ['telegram']
};
const providerLabels = {
  mailketing:  'Mailketing (Email)',
  fonte:       'Fonnte (WhatsApp)',
  onesender:   'OneSender (WhatsApp)',
  starsender:  'StarSender (WhatsApp)',
  dripsender:  'DripSender (WhatsApp)',
  kirimdev:    'Kirimdev (WhatsApp Cloud API)',
  averionrelay:'Averion Relay (WhatsApp)',
  telegram:    'Telegram (Bot)',
};

// Status enabled per provider — dari PHP
const providerEnabledMap = <?= json_encode($providerEnabledMap) ?>;
// Provider WA yang sedang aktif (default untuk template WA baru). '' jika tak ada.
const activeWaProvider = <?= json_encode($activeWaProvider) ?>;

// Placeholder tersedia per event (dari PHP). Dipakai untuk chip auto-insert.
const placeholdersByEvent = <?= json_encode($placeholdersByEvent) ?>;

// Ikon untuk tombol salin di chip form edit (toggle copy ⇄ check).
const ICON_COPY  = '<svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
const ICON_CHECK = '<svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';

// Render chip placeholder sesuai event terpilih. Tiap chip punya 2 aksi:
// klik teks = sisip {{token}} ke kursor textarea; klik ikon = salin ke clipboard.
function renderPlaceholderChips(eventKey) {
  const box  = document.getElementById('notifPlaceholderChips');
  const hint = document.getElementById('notifChipsHint');
  if (!box) return;
  box.innerHTML = '';
  const list = placeholdersByEvent[eventKey] || [];
  if (!eventKey || list.length === 0) {
    hint.textContent = 'Pilih event untuk melihat placeholder yang tersedia. Klik chip untuk menyisipkannya.';
    return;
  }
  hint.textContent = 'Klik teks chip untuk menyisipkan ke isi pesan, atau ikon salin untuk meng-copy:';
  list.forEach(function(ph) {
    const token = '{{' + ph + '}}';
    const chip = document.createElement('span');
    chip.className = 'ph-chip ph-chip-group inline-flex items-center rounded-md text-xs font-mono overflow-hidden';

    const ins = document.createElement('button');
    ins.type = 'button';
    ins.textContent = token;
    ins.className = 'px-2 py-0.5';
    ins.title = 'Sisipkan ke isi pesan';
    ins.onclick = function() { insertPlaceholder(token); };

    const cp = document.createElement('button');
    cp.type = 'button';
    cp.className = 'ph-chip-divider px-1.5 py-0.5 inline-flex items-center opacity-70 hover:opacity-100 transition-opacity';
    cp.title = 'Salin placeholder';
    cp.innerHTML = ICON_COPY;
    cp.onclick = function(e) { e.stopPropagation(); copyChipIcon(cp, token); };

    chip.appendChild(ins);
    chip.appendChild(cp);
    box.appendChild(chip);
  });
}

// Salin via tombol ikon: ganti ikon jadi centang sebentar lalu kembali.
function copyChipIcon(btn, token) {
  const done = function() { btn.innerHTML = ICON_CHECK; setTimeout(function(){ btn.innerHTML = ICON_COPY; }, 1500); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(token).then(done).catch(function(){ legacyCopy(token); done(); });
  } else {
    legacyCopy(token); done();
  }
}

// Salin chip referensi (tombol teks): pakai helper global bila ada (swap teks → "Tersalin ✓").
function copyPlaceholder(btn, token) {
  if (typeof copyToClipboard === 'function') { copyToClipboard(token, btn, 'Tersalin ✓'); return; }
  legacyCopy(token);
}

function legacyCopy(text) {
  const ta = document.createElement('textarea');
  ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
  document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); } catch (e) {}
  document.body.removeChild(ta);
}

// Sisip teks ke textarea isi pesan pada posisi kursor (fallback: di akhir).
function insertPlaceholder(token) {
  const ta = document.getElementById('notifBody');
  if (!ta) return;
  const start = ta.selectionStart ?? ta.value.length;
  const end   = ta.selectionEnd   ?? ta.value.length;
  ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
  const pos = start + token.length;
  ta.focus();
  ta.setSelectionRange(pos, pos);
}

function onChannelChange(channel) {
  const sel = document.getElementById('notifProvider');
  sel.innerHTML = '<option value="">— Pilih Provider —</option>';
  (providerMap[channel] || []).forEach(p => {
    const opt = document.createElement('option');
    opt.value = p; opt.textContent = providerLabels[p] || p;
    sel.appendChild(opt);
  });
  // Default cerdas: email cuma 1 provider → pilih otomatis. WhatsApp → ikuti
  // provider yang sedang diaktifkan admin, supaya template baru tidak ter-set
  // ke provider nonaktif. (Saat EDIT, nilai tersimpan di-set ulang setelahnya.)
  if ((providerMap[channel] || []).length === 1) {
    sel.value = providerMap[channel][0];
  } else if (channel === 'whatsapp' && activeWaProvider) {
    sel.value = activeWaProvider;
  }
  document.getElementById('notifSubjectWrap').style.display = channel === 'email' ? '' : 'none';
  onProviderChange(sel.value);
}

function onProviderChange(provider) {
  const warning     = document.getElementById('notifProviderWarning');
  const warningName = document.getElementById('notifProviderWarningName');
  if (provider && providerEnabledMap[provider] === false) {
    warningName.textContent = providerLabels[provider] || provider;
    warning.classList.remove('hidden');
  } else {
    warning.classList.add('hidden');
  }
}

// Isi field form editor sesuai data template (null = mode Tambah).
function populateNotifForm(tmpl) {
  document.getElementById('notifModalTitle').textContent = tmpl ? 'Edit Template' : 'Tambah Template';
  document.getElementById('notifId').value         = tmpl ? tmpl.id        : 0;
  document.getElementById('notifEventKey').value   = tmpl ? tmpl.event_key : '';
  document.getElementById('notifChannel').value    = tmpl ? tmpl.channel   : '';
  document.getElementById('notifBody').value       = tmpl ? (tmpl.body || '') : '';
  document.getElementById('notifIsActive').checked = tmpl ? !!parseInt(tmpl.is_active) : false;

  onChannelChange(tmpl ? tmpl.channel : '');
  renderPlaceholderChips(tmpl ? tmpl.event_key : '');

  if (tmpl) {
    document.getElementById('notifProvider').value = tmpl.provider || '';
    document.getElementById('notifSubject').value  = tmpl.subject  || '';
    onProviderChange(tmpl.provider || '');
  } else {
    document.getElementById('notifSubject').value = '';
    onProviderChange('');
  }
}

// Editor inline (expandable row): relokasi #notifEditorCard ke baris terpilih.
let notifEditorOpenKey = null;

function openNotifEditor(tmpl, key) {
  key = String(key);
  // Klik Edit pada baris yang sedang terbuka → tutup (toggle).
  if (notifEditorOpenKey === key) { closeNotifEditor(); return; }
  closeNotifEditor(); // pastikan hanya satu editor terbuka

  const row = document.querySelector('.notif-editor-row[data-editor-for="' + key + '"]');
  if (!row) return;
  row.querySelector('.notif-editor-slot').appendChild(document.getElementById('notifEditorCard'));
  populateNotifForm(tmpl);
  row.classList.remove('hidden');
  notifEditorOpenKey = key;

  const btn = document.querySelector('.notif-edit-btn[data-editor-btn="' + key + '"]');
  if (btn) btn.classList.add('is-open');

  row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function closeNotifEditor() {
  document.getElementById('notifEditorParking')
          .appendChild(document.getElementById('notifEditorCard'));
  document.querySelectorAll('.notif-editor-row').forEach(function (r) { r.classList.add('hidden'); });
  document.querySelectorAll('.notif-edit-btn.is-open').forEach(function (b) { b.classList.remove('is-open'); });
  notifEditorOpenKey = null;
}

function testSendModal(provider, channel) {
  document.getElementById('testProvider').value = provider;
  document.getElementById('testRecipient').value = channel === 'email'
    ? '<?= e($_SESSION['email'] ?? '') ?>' : '';
  document.getElementById('testModalDesc').textContent =
    'Kirim pesan test via ' + (providerLabels[provider] || provider) + '.';
  document.getElementById('testModal').classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function closeTestModal() {
  document.getElementById('testModal').classList.add('hidden');
  document.body.style.overflow = '';
}

// Submit loading state
document.querySelectorAll('button[data-loading]').forEach(function(btn) {
  btn.closest('form').addEventListener('submit', function() {
    btn.disabled = true;
    btn.textContent = btn.dataset.loading;
  });
});
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

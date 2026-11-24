<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

// ─── Daftar suara yang valid (dipakai validasi & dropdown) ──────────────────
$SP_SOUNDS = [
    'crystal_bell'     => 'Crystal Bell — Lonceng Kristal',
    'cash_register'    => 'Cash Register — Kasir Retro',
    'soft_chime'       => 'Soft Chime — Seruling Lembut',
    'water_drop'       => 'Water Drop — Tetes Air',
    'notification_pop' => 'Notification Pop — Pop Modern',
    'gentle_ding'      => 'Gentle Ding — Nada Bersih',
    'double_tap'       => 'Double Tap — Ketukan Ganda',
    'success_chime'    => 'Success Chime — Nada Sukses',
];

// ═══ POST HANDLER (PRG) ═════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        flash('sp', 'Token keamanan tidak valid.', 'error');
        redirect('/admin/social-proof');
    }

    $action = $_POST['action'] ?? '';

    // ── Reset Integration ID ────────────────────────────────────────────────
    if ($action === 'reset_integration_id') {
        try {
            setSetting('sp_integration_id', bin2hex(random_bytes(16)), 'social_proof');
            flash('sp', 'Integration ID berhasil di-reset. Perbarui semua embed script lama.', 'success');
        } catch (Exception $e) {
            error_log('SP reset integration_id error: ' . $e->getMessage());
            flash('sp', 'Gagal me-reset Integration ID.', 'error');
        }
        redirect('/admin/social-proof');
    }

    // ── Simpan konfigurasi ──────────────────────────────────────────────────
    if ($action === 'save_social_proof') {
        $clampInt = function ($v, int $min, int $max, int $dflt): string {
            $v = is_numeric($v) ? (int) $v : $dflt;
            if ($v < $min) $v = $min;
            if ($v > $max) $v = $max;
            return (string) $v;
        };
        $oneOf = function ($v, array $allowed, string $dflt): string {
            return in_array($v, $allowed, true) ? (string) $v : $dflt;
        };

        $template = trim((string) ($_POST['sp_template'] ?? ''));
        if ($template === '') {
            $template = '{buyer_name} dari {city} baru saja membeli {product_name}';
        }
        $template = mb_substr($template, 0, 220);

        $soundKeys = array_keys($SP_SOUNDS);

        try {
            // General
            setSetting('sp_enabled', isset($_POST['sp_enabled']) ? '1' : '0', 'social_proof');
            setSetting('sp_show_on_checkout', isset($_POST['sp_show_on_checkout']) ? '1' : '0', 'social_proof');
            setSetting('sp_show_on_product_page', isset($_POST['sp_show_on_product_page']) ? '1' : '0', 'social_proof');

            // Data source
            setSetting('sp_status_filter', $oneOf($_POST['sp_status_filter'] ?? '', ['paid', 'pending', 'all'], 'paid'), 'social_proof');
            setSetting('sp_name_mask', $oneOf($_POST['sp_name_mask'] ?? '', ['partial', 'full'], 'partial'), 'social_proof');
            setSetting('sp_avatar_mode', $oneOf($_POST['sp_avatar_mode'] ?? '', ['product', 'none'], 'product'), 'social_proof');

            // Popup display
            setSetting('sp_theme', $oneOf($_POST['sp_theme'] ?? '', ['light', 'dark'], 'light'), 'social_proof');
            setSetting('sp_popup_style', $oneOf($_POST['sp_popup_style'] ?? '', ['card', 'toast', 'minimal'], 'card'), 'social_proof');
            setSetting('sp_position', $oneOf($_POST['sp_position'] ?? '', ['bl', 'br', 'tl', 'tr'], 'br'), 'social_proof');
            setSetting('sp_template', $template, 'social_proof');

            // Timing
            setSetting('sp_delay_first', $clampInt($_POST['sp_delay_first'] ?? '', 0, 600000, 3500), 'social_proof');
            setSetting('sp_interval', $clampInt($_POST['sp_interval'] ?? '', 2000, 1800000, 9000), 'social_proof');
            setSetting('sp_duration', $clampInt($_POST['sp_duration'] ?? '', 2000, 600000, 5000), 'social_proof');
            setSetting('sp_max_popups', $clampInt($_POST['sp_max_popups'] ?? '', 1, 50, 8), 'social_proof');

            // Sound
            setSetting('sp_sound_enabled', isset($_POST['sp_sound_enabled']) ? '1' : '0', 'social_proof');
            setSetting('sp_sound_type', $oneOf($_POST['sp_sound_type'] ?? '', $soundKeys, 'crystal_bell'), 'social_proof');
            setSetting('sp_checkout_interaction_enabled', isset($_POST['sp_checkout_interaction_enabled']) ? '1' : '0', 'social_proof');

            // Admin order notification
            setSetting('sp_notif_order_enabled', isset($_POST['sp_notif_order_enabled']) ? '1' : '0', 'social_proof');
            setSetting('sp_notif_order_sound', $oneOf($_POST['sp_notif_order_sound'] ?? '', $soundKeys, 'cash_register'), 'social_proof');

            // Integration ID — generate sekali jika belum ada (tidak dari form).
            if ((string) getSetting('sp_integration_id', '') === '') {
                setSetting('sp_integration_id', bin2hex(random_bytes(16)), 'social_proof');
            }

            flash('sp', 'Konfigurasi Social Proof berhasil disimpan.', 'success');
        } catch (Exception $e) {
            error_log('SP save error: ' . $e->getMessage());
            flash('sp', 'Gagal menyimpan konfigurasi.', 'error');
        }
        redirect('/admin/social-proof');
    }

    redirect('/admin/social-proof');
}

// ═══ GET DISPLAY ════════════════════════════════════════════════════════════
$pageTitle   = 'Social Proof';
$topbarTitle = 'Social Proof';

// Auto-generate Integration ID saat pertama membuka halaman (agar embed langsung siap).
if ((string) getSetting('sp_integration_id', '') === '') {
    try {
        setSetting('sp_integration_id', bin2hex(random_bytes(16)), 'social_proof');
    } catch (Exception $e) {
        error_log('SP auto-gen integration_id error: ' . $e->getMessage());
    }
}

// Helper baca setting dengan default.
$g = function (string $key, string $default = '') : string {
    return (string) getSetting($key, $default);
};
$isOn = function (string $key, string $default = '0') use ($g): bool {
    return $g($key, $default) === '1';
};

$intId   = $g('sp_integration_id', '');
$jsVer   = (int) @filemtime(__DIR__ . '/../../social-proof.js');
$embedSrc = rtrim(APP_URL, '/') . '/social-proof.js?id=' . rawurlencode($intId) . '&v=' . $jsVer;
$feedUrl  = rtrim(APP_URL, '/') . '/social-proof-feed.php?id=' . rawurlencode($intId);
$embedTag = '<script src="' . $embedSrc . '" async></script>';

$enabled = $isOn('sp_enabled');

// Select helper
$sel = function (string $key, string $value, string $default) use ($g): string {
    return $g($key, $default) === $value ? 'selected' : '';
};

generateCSRF();

// Komponen toggle (DRY)
function sp_toggle(string $name, bool $checked, string $label, string $hint = ''): void { ?>
  <label class="flex items-start gap-3 cursor-pointer">
    <div class="relative mt-0.5 shrink-0">
      <input type="checkbox" name="<?= e($name) ?>" value="1" class="sr-only peer" <?= $checked ? 'checked' : '' ?>>
      <div class="w-10 h-5 bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] rounded-full transition-colors"></div>
      <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
    </div>
    <div>
      <span class="text-sm text-gray-700 dark:text-gray-300 font-medium"><?= e($label) ?></span>
      <?php if ($hint !== ''): ?><p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5"><?= e($hint) ?></p><?php endif; ?>
    </div>
  </label>
<?php }

$inputClass  = 'w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition';
$labelClass  = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5';
$cardClass   = 'bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm';
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php
      // Flash khusus halaman ini (key 'sp')
      if (($f = getFlash('sp')) !== null) {
          $isErr = ($f['type'] ?? '') === 'error';
          $wrap  = $isErr
              ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300'
              : 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300';
          echo '<div class="mb-4 px-4 py-3 rounded-xl border text-sm font-medium ' . $wrap . '">' . e($f['message']) . '</div>';
      }
      ?>

      <!-- Header -->
      <div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
        <div>
          <div class="flex items-center gap-3">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Social Proof</h2>
            <?php if ($enabled): ?>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">AKTIF</span>
            <?php else: ?>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">OFF</span>
            <?php endif; ?>
          </div>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Popup notifikasi pembelian real-time untuk meningkatkan konversi</p>
        </div>
        <a href="<?= e(APP_URL) ?>/admin/integrations" class="inline-flex items-center gap-2 px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
          Kembali
        </a>
      </div>

      <form method="POST" action="<?= e(APP_URL) ?>/admin/social-proof" class="space-y-5 pb-28">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="save_social_proof">

        <!-- SECTION 1: General -->
        <div class="<?= $cardClass ?>">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">1 · General</h3></div>
          <div class="p-5 space-y-4">
            <?php sp_toggle('sp_enabled', $isOn('sp_enabled'), 'Aktifkan Social Proof'); ?>
            <?php sp_toggle('sp_show_on_checkout', $isOn('sp_show_on_checkout', '1'), 'Tampilkan di halaman Checkout'); ?>
            <?php sp_toggle('sp_show_on_product_page', $isOn('sp_show_on_product_page', '1'), 'Tampilkan di halaman Produk Publik'); ?>
            <p class="text-xs text-gray-400 dark:text-gray-500 border-t border-gray-100 dark:border-gray-800 pt-3">Popup hanya tampil ke pengunjung yang sedang browsing, tidak akan mengganggu proses pembayaran maupun area member/admin.</p>
          </div>
        </div>

        <!-- SECTION 2: Data Source -->
        <div class="<?= $cardClass ?>">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">2 · Sumber Data</h3></div>
          <div class="p-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label class="<?= $labelClass ?>">Status Order</label>
              <select name="sp_status_filter" class="<?= $inputClass ?>">
                <option value="paid" <?= $sel('sp_status_filter','paid','paid') ?>>Sudah dibayar (paid)</option>
                <option value="pending" <?= $sel('sp_status_filter','pending','paid') ?>>Menunggu (pending)</option>
                <option value="all" <?= $sel('sp_status_filter','all','paid') ?>>Semua</option>
              </select>
            </div>
            <div>
              <label class="<?= $labelClass ?>">Sensor Nama</label>
              <select name="sp_name_mask" class="<?= $inputClass ?>">
                <option value="partial" <?= $sel('sp_name_mask','partial','partial') ?>>Sensor sebagian</option>
                <option value="full" <?= $sel('sp_name_mask','full','partial') ?>>Tampil full</option>
              </select>
            </div>
            <div>
              <label class="<?= $labelClass ?>">Avatar / Gambar</label>
              <select name="sp_avatar_mode" class="<?= $inputClass ?>">
                <option value="product" <?= $sel('sp_avatar_mode','product','product') ?>>Gunakan cover produk</option>
                <option value="none" <?= $sel('sp_avatar_mode','none','product') ?>>Tanpa gambar</option>
              </select>
            </div>
            <p class="sm:col-span-3 text-xs text-gray-400 dark:text-gray-500">Hanya menampilkan data transaksi REAL dari 30 hari terakhir.</p>
          </div>
        </div>

        <!-- SECTION 3: Tampilan Popup -->
        <div class="<?= $cardClass ?>">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">3 · Tampilan Popup</h3>
            <button type="button" onclick="showSpPreview()" class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Preview Popup</button>
          </div>
          <div class="p-5 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <label class="<?= $labelClass ?>">Style Popup</label>
                <select id="sp_popup_style" name="sp_popup_style" class="<?= $inputClass ?>">
                  <option value="card" <?= $sel('sp_popup_style','card','card') ?>>Card (thumbnail)</option>
                  <option value="toast" <?= $sel('sp_popup_style','toast','card') ?>>Toast (compact)</option>
                  <option value="minimal" <?= $sel('sp_popup_style','minimal','card') ?>>Minimal (frosted)</option>
                </select>
              </div>
              <div>
                <label class="<?= $labelClass ?>">Tema Warna</label>
                <select id="sp_theme" name="sp_theme" class="<?= $inputClass ?>">
                  <option value="light" <?= $sel('sp_theme','light','light') ?>>Light</option>
                  <option value="dark" <?= $sel('sp_theme','dark','light') ?>>Dark</option>
                </select>
              </div>
              <div>
                <label class="<?= $labelClass ?>">Posisi Popup</label>
                <select id="sp_position" name="sp_position" class="<?= $inputClass ?>">
                  <option value="bl" <?= $sel('sp_position','bl','br') ?>>Kiri Bawah</option>
                  <option value="br" <?= $sel('sp_position','br','br') ?>>Kanan Bawah</option>
                  <option value="tl" <?= $sel('sp_position','tl','br') ?>>Kiri Atas</option>
                  <option value="tr" <?= $sel('sp_position','tr','br') ?>>Kanan Atas</option>
                </select>
              </div>
            </div>
            <div>
              <label class="<?= $labelClass ?>">Template Teks Popup</label>
              <textarea id="sp_template" name="sp_template" rows="2" maxlength="220" class="<?= $inputClass ?>"><?= e($g('sp_template','{buyer_name} dari {city} baru saja membeli {product_name}')) ?></textarea>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1.5">Placeholder tersedia:
                <code class="px-1 rounded bg-gray-100 dark:bg-gray-800">{buyer_name}</code>
                <code class="px-1 rounded bg-gray-100 dark:bg-gray-800">{city}</code>
                <code class="px-1 rounded bg-gray-100 dark:bg-gray-800">{product_name}</code>
                <code class="px-1 rounded bg-gray-100 dark:bg-gray-800">{amount}</code>
                <code class="px-1 rounded bg-gray-100 dark:bg-gray-800">{time_ago}</code>
              </p>
            </div>
          </div>
        </div>

        <!-- SECTION 4: Timing -->
        <div class="<?= $cardClass ?>">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">4 · Timing</h3></div>
          <div class="p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
              <label class="<?= $labelClass ?>">Delay pertama (ms)</label>
              <input type="number" name="sp_delay_first" min="0" max="600000" value="<?= e($g('sp_delay_first','3500')) ?>" class="<?= $inputClass ?>">
              <p class="text-xs text-gray-400 mt-1">3500 = tampil setelah 3.5 detik</p>
            </div>
            <div>
              <label class="<?= $labelClass ?>">Interval antar popup (ms)</label>
              <input type="number" name="sp_interval" min="2000" max="1800000" value="<?= e($g('sp_interval','9000')) ?>" class="<?= $inputClass ?>">
              <p class="text-xs text-gray-400 mt-1">9000 = setiap 9 detik</p>
            </div>
            <div>
              <label class="<?= $labelClass ?>">Durasi popup tampil (ms)</label>
              <input type="number" name="sp_duration" min="2000" max="600000" value="<?= e($g('sp_duration','5000')) ?>" class="<?= $inputClass ?>">
              <p class="text-xs text-gray-400 mt-1">5000 = tampil selama 5 detik</p>
            </div>
            <div>
              <label class="<?= $labelClass ?>">Maksimal popup per sesi</label>
              <input type="number" name="sp_max_popups" min="1" max="50" value="<?= e($g('sp_max_popups','8')) ?>" class="<?= $inputClass ?>">
            </div>
          </div>
        </div>

        <!-- SECTION 5: Sound -->
        <div class="<?= $cardClass ?>">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">5 · Suara Popup</h3></div>
          <div class="p-5 space-y-4">
            <?php sp_toggle('sp_sound_enabled', $isOn('sp_sound_enabled'), 'Aktifkan Suara Popup'); ?>
            <div>
              <label class="<?= $labelClass ?>">Pilih Suara</label>
              <div class="flex items-center gap-2">
                <select id="sp_sound_select" name="sp_sound_type" class="<?= $inputClass ?>">
                  <?php foreach ($SP_SOUNDS as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= $sel('sp_sound_type', $val, 'crystal_bell') ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" onclick="spPreviewSound()" class="shrink-0 inline-flex items-center gap-1.5 px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 4 20 12 6 20 6 4"/></svg>Preview</button>
              </div>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1.5">Volume soft, tidak akan mengganggu. Preview diputar saat ganti pilihan.</p>
            </div>
            <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
              <?php sp_toggle('sp_checkout_interaction_enabled', $isOn('sp_checkout_interaction_enabled'), 'Aktifkan Audio Interaksi Form Checkout', 'Sound yang sama akan berbunyi saat pengunjung mengklik field di form checkout.'); ?>
            </div>
          </div>
        </div>

        <!-- SECTION 6: Notifikasi Order Masuk -->
        <div class="<?= $cardClass ?>">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">6 · Notifikasi Order Masuk (Admin)</h3></div>
          <div class="p-5 space-y-4">
            <?php sp_toggle('sp_notif_order_enabled', $isOn('sp_notif_order_enabled', '1'), 'Aktifkan Notifikasi Order Baru', 'Popup + suara muncul saat ada order baru di semua halaman admin (dashboard, transaksi, dll). Popup menumpuk & tetap tampil sampai Anda tutup.'); ?>
            <div>
              <label class="<?= $labelClass ?>">Suara Notifikasi Order</label>
              <div class="flex items-center gap-2">
                <select id="notif_order_sound_select" name="sp_notif_order_sound" class="<?= $inputClass ?>">
                  <?php foreach ($SP_SOUNDS as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= $sel('sp_notif_order_sound', $val, 'cash_register') ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" onclick="notifOrderPreviewSound()" class="shrink-0 inline-flex items-center gap-1.5 px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 4 20 12 6 20 6 4"/></svg>Preview</button>
              </div>
            </div>
          </div>
        </div>

        <!-- SECTION 7: Integrasi Eksternal -->
        <div class="<?= $cardClass ?>">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">7 · Integrasi Eksternal</h3></div>
          <div class="p-5 space-y-4">
            <p class="text-xs text-gray-400 dark:text-gray-500">Pasang di landing page WordPress, HTML, atau website manapun.</p>
            <div>
              <label class="<?= $labelClass ?>">Kode Embed Script</label>
              <textarea id="sp_embed_tag" readonly rows="2" class="<?= $inputClass ?> font-mono text-xs"><?= e($embedTag) ?></textarea>
              <button type="button" onclick="spCopy('sp_embed_tag')" class="mt-2 px-3 py-1.5 text-xs rounded-lg text-white" style="background:var(--brand-color)">Copy Script</button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="<?= $labelClass ?>">Integration ID</label>
                <input id="sp_int_id" type="text" readonly value="<?= e($intId) ?>" class="<?= $inputClass ?> font-mono text-xs">
              </div>
              <div>
                <label class="<?= $labelClass ?>">Endpoint Feed URL</label>
                <div class="flex items-center gap-2">
                  <input id="sp_feed_url" type="text" readonly value="<?= e($feedUrl) ?>" class="<?= $inputClass ?> font-mono text-xs">
                  <button type="button" onclick="spCopy('sp_feed_url')" class="shrink-0 px-3 py-2 text-xs rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Copy</button>
                </div>
              </div>
            </div>
            <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
              <p class="flex items-start gap-1.5 text-xs text-amber-600 dark:text-amber-400 mb-2">
                <svg class="w-3.5 h-3.5 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span>Setelah reset, semua embed script lama tidak akan berfungsi dan harus diganti.</span>
              </p>
              <button type="button"
                onclick="confirmAndRun({title:'Reset Integration ID?',message:'Semua embed script lama akan berhenti berfungsi dan harus diganti dengan yang baru.',confirmText:'Ya, Reset',variant:'warning'}, () => document.getElementById('sp-reset-form').submit())"
                class="px-3 py-2 text-sm rounded-lg border border-amber-300 dark:border-amber-700 text-amber-700 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20 transition">Reset Integration ID</button>
            </div>
          </div>
        </div>

        <!-- SECTION 8: Diagnostics -->
        <div class="<?= $cardClass ?>">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">8 · Diagnostics</h3></div>
          <div class="p-5 grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
            <div><div class="text-xs text-gray-400 mb-1">Last Request At</div><div class="text-gray-700 dark:text-gray-300"><?= e($g('sp_last_at') ?: '-') ?></div></div>
            <div><div class="text-xs text-gray-400 mb-1">Sumber Data</div><div class="text-gray-700 dark:text-gray-300"><?= e($g('sp_last_source') ?: '-') ?></div></div>
            <div><div class="text-xs text-gray-400 mb-1">Produk Terakhir</div><div class="text-gray-700 dark:text-gray-300"><?= e($g('sp_last_product') ?: '-') ?></div></div>
          </div>
        </div>

        <!-- STICKY FOOTER -->
        <div class="fixed bottom-0 left-0 lg:left-60 right-0 bg-white/90 dark:bg-gray-900/90 backdrop-blur border-t border-gray-200 dark:border-gray-800 px-5 py-3 z-10">
          <div class="flex flex-wrap items-center justify-between gap-4">
            <p class="text-xs text-gray-400 dark:text-gray-500 hidden sm:block">Perubahan langsung aktif setelah disimpan.</p>
            <button type="submit" class="ml-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-semibold text-white rounded-lg" style="background:var(--brand-color)">Simpan Konfigurasi</button>
          </div>
        </div>
      </form>

      <!-- Form reset terpisah (di luar form utama) -->
      <form id="sp-reset-form" method="POST" action="<?= e(APP_URL) ?>/admin/social-proof" class="hidden">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="reset_integration_id">
      </form>

    </main>
  </div>
</div>

<?php include __DIR__ . '/../../partials/sp-sound-engine.php'; ?>
<script>
// Preview suara
function spPreviewSound() { window.spPlaySound(document.getElementById('sp_sound_select').value); }
function notifOrderPreviewSound() { window.spPlaySound(document.getElementById('notif_order_sound_select').value); }

// Auto-preview saat ganti pilihan
document.getElementById('sp_sound_select').addEventListener('change', spPreviewSound);
document.getElementById('notif_order_sound_select').addEventListener('change', notifOrderPreviewSound);

// Copy to clipboard dengan fallback
function spCopy(id) {
  var el = document.getElementById(id);
  if (!el) return;
  el.select(); el.setSelectionRange(0, 99999);
  var done = function () { el.blur(); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(el.value).then(done, function () { try { document.execCommand('copy'); } catch (e) {} done(); });
  } else {
    try { document.execCommand('copy'); } catch (e) {}
    done();
  }
}

// Preview popup visual (data dummy statis, BUKAN data real)
function showSpPreview() {
  var style = document.getElementById('sp_popup_style').value;
  var theme = document.getElementById('sp_theme').value;
  var pos   = document.getElementById('sp_position').value;
  var tpl   = document.getElementById('sp_template').value || '{buyer_name} dari {city} baru saja membeli {product_name}';
  var text  = tpl.replace('{buyer_name}', '<strong>Budi S***</strong>').replace('{city}', 'Jakarta')
                 .replace('{product_name}', 'Kelas Online Premium').replace('{amount}', 'Rp 150.000').replace('{time_ago}', '2 menit lalu');

  var bag = function (sz, color) {
    return '<svg width="' + sz + '" height="' + sz + '" viewBox="0 0 24 24" fill="none" stroke="' + (color || 'currentColor') + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>';
  };
  var chk = function (sz) {
    return '<svg width="' + sz + '" height="' + sz + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
  };
  var seal = function (sz) {
    return '<svg width="' + sz + '" height="' + sz + '" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" clip-rule="evenodd" d="M8.603 3.799A4.49 4.49 0 0 1 12 2.25c1.357 0 2.573.6 3.397 1.549a4.49 4.49 0 0 1 3.498 1.307 4.491 4.491 0 0 1 1.307 3.497A4.49 4.49 0 0 1 21.75 12a4.49 4.49 0 0 1-1.549 3.397 4.491 4.491 0 0 1-1.307 3.497 4.491 4.491 0 0 1-3.497 1.307A4.49 4.49 0 0 1 12 21.75a4.49 4.49 0 0 1-3.397-1.549 4.49 4.49 0 0 1-3.498-1.306 4.491 4.491 0 0 1-1.307-3.498A4.49 4.49 0 0 1 2.25 12c0-1.357.6-2.573 1.549-3.397a4.49 4.49 0 0 1 1.307-3.497 4.49 4.49 0 0 1 3.497-1.307Zm7.007 6.387a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z"/></svg>';
  };

  var old = document.getElementById('sp-preview-pop'); if (old) old.remove();

  var dark = theme === 'dark';
  var fromTop = (pos === 'tl' || pos === 'tr');
  var posCss = { bl: 'left:18px;bottom:18px;', br: 'right:18px;bottom:18px;', tl: 'left:18px;top:18px;', tr: 'right:18px;top:18px;' }[pos] || 'right:18px;bottom:18px;';

  var base = 'position:fixed;z-index:2147483600;' + posCss +
    'display:flex;align-items:center;gap:10px;border-radius:8px;font-family:-apple-system,sans-serif;' +
    'opacity:0;transform:translateY(' + (fromTop ? '-12px' : '12px') + ') scale(.98);transition:opacity .35s cubic-bezier(.16,1,.3,1),transform .45s cubic-bezier(.16,1,.3,1);';

  var box, inner;
  if (style === 'toast') {
    box = base + 'padding:7px 11px;border-radius:8px;' + (dark ? 'background:#1e293b;color:#fff;border:1px solid #334155;' : 'background:#fff;color:#1e293b;border:1px solid #e2e8f0;') + 'box-shadow:0 1px 2px rgba(15,23,42,.06),0 6px 20px -6px rgba(15,23,42,.18);';
    inner = '<span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:rgba(22,163,74,.12);color:#16a34a;flex-shrink:0;">' + chk(13) + '</span><span style="font-size:13px;font-weight:500;">' + text + '</span>';
  } else if (style === 'minimal') {
    box = base + 'padding:9px 30px 9px 10px;border-radius:8px;' + (dark ? 'background:rgba(30,41,59,.72);color:#e2e8f0;border:1px solid rgba(255,255,255,.08);' : 'background:rgba(255,255,255,.78);color:#334155;border:1px solid rgba(255,255,255,.6);') + 'backdrop-filter:blur(10px) saturate(140%);box-shadow:0 1px 2px rgba(15,23,42,.05),0 4px 16px -4px rgba(15,23,42,.14);';
    inner = '<div style="width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:color-mix(in srgb,var(--brand-color) 12%,transparent);color:var(--brand-color);">' + bag(18, 'var(--brand-color)') + '</div>' +
            '<div><div style="font-size:13px;font-weight:500;line-height:1.35;">' + text + '</div><div style="font-size:11px;color:#94a3b8;margin-top:2px;">2 menit lalu</div></div>' +
            '<span style="position:absolute;top:7px;right:8px;color:#16a34a;display:flex;line-height:0;filter:drop-shadow(0 1px 1.5px rgba(0,0,0,.18));">' + seal(16) + '</span>';
  } else {
    box = base + 'width:290px;padding:10px;border-left:3px solid var(--brand-color);' + (dark ? 'background:#1e293b;color:#fff;border-top:1px solid #334155;border-right:1px solid #334155;border-bottom:1px solid #334155;' : 'background:#fff;color:#1e293b;border-top:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;') + 'box-shadow:0 1px 2px rgba(15,23,42,.06),0 10px 30px -8px rgba(15,23,42,.22);';
    inner = '<div style="position:relative;flex-shrink:0;">' +
              '<div style="width:44px;height:44px;border-radius:6px;background:color-mix(in srgb,var(--brand-color) 12%,transparent);color:var(--brand-color);display:flex;align-items:center;justify-content:center;">' + bag(20, 'var(--brand-color)') + '</div>' +
              '<span style="position:absolute;right:-3px;bottom:-3px;width:15px;height:15px;border-radius:50%;background:#16a34a;display:flex;align-items:center;justify-content:center;box-sizing:border-box;border:2px solid ' + (dark ? '#1e293b' : '#fff') + ';"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>' +
            '</div>' +
            '<div><div style="font-size:13px;font-weight:500;line-height:1.35;">' + text + '</div><div style="font-size:11px;color:#94a3b8;margin-top:2px;">Pembelian terverifikasi · 2 menit lalu</div></div>';
  }

  var el = document.createElement('div');
  el.id = 'sp-preview-pop';
  el.setAttribute('style', box);
  el.innerHTML = inner + '<div style="position:absolute;left:0;right:0;bottom:0;height:2px;overflow:hidden;border-bottom-left-radius:8px;border-bottom-right-radius:8px;pointer-events:none;"><i id="sp-pv-bar" style="display:block;height:100%;width:100%;background:var(--brand-color);opacity:.85;"></i></div>';
  document.body.appendChild(el);

  // Progress bar countdown + hover-to-pause (mirror social-proof.js).
  var VIS = 4000, bar = el.querySelector('#sp-pv-bar'), rem = VIS, ts = 0, tmr = null;
  function pvHide() { el.style.opacity = '0'; el.style.transform = 'translateY(6px) scale(.98)'; setTimeout(function () { el.remove(); }, 300); }
  function pvRun() { ts = Date.now(); if (bar) { bar.style.transition = 'width ' + rem + 'ms linear'; void bar.offsetWidth; bar.style.width = '0%'; } tmr = setTimeout(pvHide, rem); }
  el.addEventListener('mouseenter', function () { if (!tmr) return; clearTimeout(tmr); tmr = null; rem -= (Date.now() - ts); if (rem < 0) rem = 0; if (bar) { bar.style.transition = 'none'; bar.style.width = (rem / VIS * 100) + '%'; } });
  el.addEventListener('mouseleave', function () { if (rem > 0 && !tmr) pvRun(); });

  setTimeout(function () { el.style.opacity = '1'; el.style.transform = 'translateY(0) scale(1)'; if (bar) { bar.style.transition = 'none'; bar.style.width = '100%'; void bar.offsetWidth; } pvRun(); }, 500);
}
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

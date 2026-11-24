<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Pengaturan';
$topbarTitle = 'Pengaturan Sistem';

$settings = [];
try {
    $rows = getDB()->query("SELECT setting_key, setting_value FROM settings ORDER BY id ASC")->fetchAll();
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    error_log('Settings load error: ' . $e->getMessage());
    flash('error', 'Gagal memuat pengaturan.', 'error');
}

$s = function(string $key, string $default = '') use ($settings): string {
    return $settings[$key] ?? $default;
};

generateCSRF();

// ─── Cron Job siap-salin ──────────────────────────────────────────
// cron_secret dipakai variant URL (?token=) — auto-generate sekali kalau
// belum ada, supaya perintah selalu siap salin tanpa langkah tambahan.
$cronSecret = $s('cron_secret', '');
if ($cronSecret === '') {
    $cronSecret = bin2hex(random_bytes(20));
    setSetting('cron_secret', $cronSecret, 'system');
}

// Deteksi binary PHP CLI: PHP_BINARY TIDAK selalu berupa PHP asli — di
// Apache mod_php (mis. XAMPP) ia mengarah ke httpd/apache2, di sebagian host
// ke php-fpm/php-cgi (bukan CLI). Validasi basename-nya benar-benar "php*"
// dulu; kalau tidak, pakai pola umum panel hosting (CloudPanel/cPanel/
// Plesk taruh versi di nama file) sebagai fallback lebih akurat.
$cronPhpBinary = (function (): string {
    if (defined('PHP_BINARY') && PHP_BINARY !== '' && is_file(PHP_BINARY)) {
        $base = strtolower(basename(PHP_BINARY));
        if (str_starts_with($base, 'php') && !str_contains($base, 'fpm') && !str_contains($base, 'cgi')) {
            return PHP_BINARY;
        }
    }
    $verGuess = '/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    if (is_file($verGuess)) return $verGuess;
    if (is_file('/usr/bin/php')) return '/usr/bin/php';
    return 'php';
})();
$cronScriptPath = realpath(__DIR__ . '/../../cron/process.php') ?: (__DIR__ . '/../../cron/process.php');
$cronCliCmd     = $cronPhpBinary . ' ' . $cronScriptPath;
$cronUrlCmd     = 'wget -qO- "' . rtrim(APP_URL, '/') . '/cron/process?token=' . $cronSecret . '" >/dev/null 2>&1';

// Kelas input reusable
$inputCls = 'w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition';
$saveBtn  = 'px-5 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition';
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="mb-5 flex items-center justify-between gap-3">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Pengaturan Sistem</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Konfigurasi fitur dan parameter aplikasi</p>
        </div>
        <div class="hidden sm:flex items-center gap-2 shrink-0">
          <button type="button" id="expandAll" class="px-3 py-1.5 rounded-lg text-xs font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-white dark:hover:bg-gray-800 transition">Buka semua</button>
          <button type="button" id="collapseAll" class="px-3 py-1.5 rounded-lg text-xs font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-white dark:hover:bg-gray-800 transition">Tutup semua</button>
        </div>
      </div>

      <div class="max-w-2xl space-y-3" id="settingsAccordion">

        <!-- ═══ Poin Reward ═══ -->
        <div class="acc bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-settings" class="js-ajax-save">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="group" value="points">
            <button type="button" class="acc-head w-full flex items-center gap-3 px-5 py-4 text-left transition hover:bg-gray-50 dark:hover:bg-gray-800/50">
              <span class="w-9 h-9 rounded-lg bg-[var(--brand-color)]/10 flex items-center justify-center shrink-0">
                <svg class="w-[18px] h-[18px] text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.66 3.58 3 8 3s8-1.34 8-3V6"/><path d="M4 12v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/></svg>
              </span>
              <span class="flex-1 min-w-0">
                <span class="block font-semibold text-sm text-gray-900 dark:text-white">Poin Reward</span>
                <span class="block text-xs text-gray-400 dark:text-gray-500 truncate">Poin loyalitas dari tiap transaksi &amp; potongan di checkout</span>
              </span>
              <svg class="acc-chevron w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div class="acc-body" style="max-height:0;overflow:hidden;transition:max-height .28s ease">
              <div class="border-t border-gray-100 dark:border-gray-800 p-5 space-y-4">
                <div class="flex items-center justify-between">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Aktifkan Poin Reward</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Member dapat poin tiap transaksi lunas, lalu bisa ditukar jadi potongan harga</p>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="hidden" name="settings[points_enabled]" value="0">
                    <input type="checkbox" name="settings[points_enabled]" value="1" <?= $s('points_enabled', '0') === '1' ? 'checked' : '' ?> class="sr-only peer">
                    <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                    <div class="absolute left-1 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-5"></div>
                  </label>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Dapat 1 poin setiap belanja (Rp)</label>
                  <input type="number" name="settings[points_earn_per_amount]" value="<?= e($s('points_earn_per_amount', '1000')) ?>" min="1" step="1" class="w-40 <?= $inputCls ?>">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Contoh: 1.000 &rarr; belanja Rp 249.000 menghasilkan 249 poin. Dihitung dari nominal yang benar-benar dibayar.</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Nilai 1 poin saat ditukar (Rp)</label>
                  <input type="number" name="settings[points_value]" value="<?= e($s('points_value', '50')) ?>" min="0" step="1" class="w-40 <?= $inputCls ?>">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Dua isian di atas menentukan besar cashback. Contoh bawaan (1 poin per Rp 1.000, 1 poin = Rp 50) setara <strong>5%</strong>.</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Minimal poin untuk bisa ditukar</label>
                  <input type="number" name="settings[points_min_redeem]" value="<?= e($s('points_min_redeem', '100')) ?>" min="0" step="1" class="w-40 <?= $inputCls ?>">
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Maksimal potongan poin per transaksi (%)</label>
                  <input type="number" name="settings[points_max_discount_percent]" value="<?= e($s('points_max_discount_percent', '50')) ?>" min="1" max="100" step="1" class="w-32 <?= $inputCls ?>">
                  <p class="text-xs text-amber-600 dark:text-amber-500 mt-1"><strong>Penting:</strong> jaga di bawah 100% agar total belanja tidak pernah jatuh ke Rp 0 (mencegah order lunas otomatis tanpa pembayaran).</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Sebutan poin</label>
                    <input type="text" name="settings[points_label]" value="<?= e($s('points_label', 'Poin')) ?>" maxlength="20" class="w-full <?= $inputCls ?>">
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Mis. Poin, Koin, Reward.</p>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Nama menu di dashboard member</label>
                    <input type="text" name="settings[points_menu_label]" value="<?= e($s('points_menu_label', 'Reward Saya')) ?>" maxlength="30" class="w-full <?= $inputCls ?>">
                  </div>
                </div>

                <div class="flex justify-end pt-1">
                  <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">Simpan</button>
                </div>
              </div>
            </div>
          </form>
        </div>

        <!-- ═══ Program Afiliasi ═══ -->
        <div class="acc bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-settings" class="js-ajax-save">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="group" value="affiliate">
            <button type="button" class="acc-head w-full flex items-center gap-3 px-5 py-4 text-left transition hover:bg-gray-50 dark:hover:bg-gray-800/50">
              <span class="w-9 h-9 rounded-lg bg-[var(--brand-color)]/10 flex items-center justify-center shrink-0">
                <svg class="w-[18px] h-[18px] text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>
              </span>
              <span class="flex-1 min-w-0">
                <span class="block font-semibold text-sm text-gray-900 dark:text-white">Program Afiliasi</span>
                <span class="block text-xs text-gray-400 dark:text-gray-500 truncate">Komisi dan status program afiliasi</span>
              </span>
              <svg class="acc-chevron w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div class="acc-body" style="max-height:0;overflow:hidden;transition:max-height .28s ease">
              <div class="border-t border-gray-100 dark:border-gray-800 p-5 space-y-4">
                <div class="flex items-center justify-between">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Aktifkan Program Afiliasi</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Saat mati: menu &amp; halaman afiliasi disembunyikan, link referral (/go, /r) tidak mengatribusi, dan tidak ada komisi baru. Data, saldo, pencairan, &amp; riwayat lama tetap aman.</p>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="hidden" name="settings[affiliate_enabled]" value="0">
                    <input type="checkbox" name="settings[affiliate_enabled]" value="1" <?= $s('affiliate_enabled', '1') === '1' ? 'checked' : '' ?> class="sr-only peer">
                    <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                    <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                  </label>
                </div>
                <div class="flex items-center justify-between">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Produk tanpa komisi di-set = tanpa komisi</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Saat nyala: produk yang kolom komisinya 0 (dan tanpa aturan komisi khusus produk) tidak membayar komisi afiliasi sama sekali — kolom komisi per-produk jadi saklar wajib. Saat mati: produk 0 tetap memakai rate default global/level.</p>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="hidden" name="settings[affiliate_zero_no_commission]" value="0">
                    <input type="checkbox" name="settings[affiliate_zero_no_commission]" value="1" <?= $s('affiliate_zero_no_commission', '0') === '1' ? 'checked' : '' ?> class="sr-only peer">
                    <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                    <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                  </label>
                </div>
                <div class="flex items-center justify-between">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Leaderboard di Dashboard Affiliate</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Affiliate melihat papan peringkat komisi bulan ini. Nama peserta lain disamarkan &amp; nominalnya disembunyikan — hanya posisi &amp; komisi sendiri yang terlihat.</p>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="hidden" name="settings[affiliate_leaderboard_enabled]" value="0">
                    <input type="checkbox" name="settings[affiliate_leaderboard_enabled]" value="1" <?= $s('affiliate_leaderboard_enabled', '0') === '1' ? 'checked' : '' ?> class="sr-only peer">
                    <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                    <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                  </label>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Siapa yang Bisa Jadi Affiliate</label>
                  <select name="settings[affiliate_mode]" class="w-full max-w-xs <?= $inputCls ?>">
                    <option value="all" <?= $s('affiliate_mode', 'all') === 'all' ? 'selected' : '' ?>>Semua member (langsung dapat link)</option>
                    <option value="buyers" <?= $s('affiliate_mode', 'all') === 'buyers' ? 'selected' : '' ?>>Hanya member yang sudah membeli produk</option>
                  </select>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Mode "sudah membeli": member tanpa pembelian lunas belum bisa memakai link affiliate, dan link mereka tidak menghasilkan komisi sampai memenuhi syarat.</p>
                </div>
                <div class="flex items-center justify-between">
                  <div class="pr-4">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Tampilkan Menu Afiliasi ke Semua Member</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Aktif: menu "Link Afiliasi Saya" muncul untuk semua member yang memenuhi mode di atas — termasuk yang belum punya transaksi/komisi. Nonaktif (default): menu hanya untuk member yang sudah punya aktivitas afiliasi atau berhak komisi (mis. via Commission Rules).</p>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="hidden" name="settings[affiliate_menu_show_all]" value="0">
                    <input type="checkbox" name="settings[affiliate_menu_show_all]" value="1" <?= $s('affiliate_menu_show_all', '0') === '1' ? 'checked' : '' ?> class="sr-only peer">
                    <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                    <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                  </label>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Komisi Default (%)</label>
                  <div class="flex items-center gap-2">
                    <input type="number" name="settings[affiliate_commission_rate]" value="<?= e($s('affiliate_commission_rate', '10')) ?>" min="0" max="100" step="0.5" class="w-32 <?= $inputCls ?>">
                    <span class="text-sm text-gray-500 dark:text-gray-400">% dari total transaksi</span>
                  </div>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Rate ini digunakan sebagai default. Bisa di-override per produk.</p>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Minimum Pencairan Komisi</label>
                  <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-500"><?= e(currencySymbol()) ?></span>
                    <input type="number" name="settings[min_payout_amount]" value="<?= e($s('min_payout_amount', '50000')) ?>" min="0" step="10000" class="w-40 <?= $inputCls ?>">
                  </div>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Jumlah minimum yang bisa diajukan affiliate untuk pencairan.</p>
                </div>
                <div class="pt-3 border-t border-gray-100 dark:border-gray-800 space-y-4">
                  <div class="flex items-center justify-between">
                    <div>
                      <p class="text-sm font-medium text-gray-700 dark:text-gray-300">General Cookie</p>
                      <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Dengan mengaktifkan ini, pembeli secara otomatis akan terdaftar sesuai affiliasi ke semua pembelian produk</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer shrink-0">
                      <input type="hidden" name="settings[affiliate_general_cookie]" value="0">
                      <input type="checkbox" name="settings[affiliate_general_cookie]" value="1" <?= $s('affiliate_general_cookie', '1') === '1' ? 'checked' : '' ?> class="sr-only peer">
                      <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                      <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                    </label>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Umur Cookie (Hari)</label>
                    <div class="flex items-center gap-2">
                      <input type="number" name="settings[affiliate_cookie_days]" value="<?= e($s('affiliate_cookie_days', '30')) ?>" min="0" max="3650" class="w-32 <?= $inputCls ?>">
                      <span class="text-sm text-gray-500 dark:text-gray-400">Hari</span>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Umur cookie dalam satuan hari. Isi dengan <strong>0</strong> jika umur cookie selamanya (lifetime).</p>
                  </div>
                  <div class="flex items-center justify-between">
                    <div>
                      <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Kaitkan Afiliasi (Lock Customer)</p>
                      <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Dengan mengaktifkan ini, pembeli yang sudah pernah terdaftar atas affiliasi lain, untuk pembelian selanjutnya akan selalu berdasarkan affiliasi sebelumnya. Penggunaan link affiliasi maupun kupon affiliasi tidak akan berpengaruh lagi.</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer shrink-0">
                      <input type="hidden" name="settings[affiliate_lock_customer]" value="0">
                      <input type="checkbox" name="settings[affiliate_lock_customer]" value="1" <?= $s('affiliate_lock_customer', '0') === '1' ? 'checked' : '' ?> class="sr-only peer">
                      <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                      <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                    </label>
                  </div>
                </div>
              </div>
              <div class="px-5 py-3.5 border-t border-gray-100 dark:border-gray-800 flex justify-end">
                <button type="submit" data-loading data-loading-text="Menyimpan..." class="<?= $saveBtn ?>">Simpan</button>
              </div>
            </div>
          </form>
        </div>

        <!-- ═══ Pembayaran & Mata Uang ═══ -->
        <div class="acc bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-settings" class="js-ajax-save">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="group" value="payment">
            <button type="button" class="acc-head w-full flex items-center gap-3 px-5 py-4 text-left transition hover:bg-gray-50 dark:hover:bg-gray-800/50">
              <span class="w-9 h-9 rounded-lg bg-[var(--brand-color)]/10 flex items-center justify-center shrink-0">
                <svg class="w-[18px] h-[18px] text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
              </span>
              <span class="flex-1 min-w-0">
                <span class="block font-semibold text-sm text-gray-900 dark:text-white">Pembayaran &amp; Mata Uang</span>
                <span class="block text-xs text-gray-400 dark:text-gray-500 truncate">Mata uang dan batas waktu order</span>
              </span>
              <svg class="acc-chevron w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div class="acc-body" style="max-height:0;overflow:hidden;transition:max-height .28s ease">
              <div class="border-t border-gray-100 dark:border-gray-800 p-5 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Kode Mata Uang</label>
                    <input type="text" name="settings[currency]" value="<?= e($s('currency', 'IDR')) ?>" maxlength="10" class="<?= $inputCls ?> font-mono">
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Contoh: IDR, USD, MYR</p>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Simbol Mata Uang</label>
                    <input type="text" name="settings[currency_symbol]" value="<?= e($s('currency_symbol', 'Rp')) ?>" maxlength="5" class="<?= $inputCls ?> font-mono">
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Contoh: Rp, $, RM</p>
                  </div>
                </div>
                <div class="flex items-start gap-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/50 px-3 py-2.5">
                  <svg class="w-4 h-4 text-amber-500 dark:text-amber-400 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                  <p class="text-xs text-amber-700 dark:text-amber-300 leading-relaxed">Mengganti mata uang hanya menukar <strong>simbol &amp; format angka</strong> di seluruh aplikasi — <strong>tidak mengonversi kurs</strong>. Masukkan harga produk dalam nilai mata uang ini. Saat mata uang bukan IDR, badge "Harga Alias Ringgit" per-produk otomatis dinonaktifkan.</p>
                </div>
                <div>
                  <label for="order-wa" class="block text-sm font-medium mb-1.5">WhatsApp admin untuk konfirmasi pesanan</label>
                  <input id="order-wa" type="tel" name="settings[order_confirmation_whatsapp]" pattern="[1-9][0-9]{7,14}" value="<?= e($s('order_confirmation_whatsapp', '')) ?>" class="<?= $inputCls ?>">
                  <p class="text-xs mt-1">Isi dengan kode negara (62 untuk Indonesia), tanpa tanda plus atau spasi. Saat terisi, pelanggan mengirim konfirmasi dari halaman pesanan. Kosongkan untuk kembali ke pengiriman sesuai templat otomatis.</p>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Batas Waktu Order Pending</label>
                  <div class="flex items-center gap-2">
                    <input type="number" name="settings[order_expiry_hours]" value="<?= e($s('order_expiry_hours', '0')) ?>" min="0" max="720" class="w-32 <?= $inputCls ?>">
                    <span class="text-sm text-gray-500 dark:text-gray-400">jam</span>
                  </div>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Order pending yang melebihi batas ini otomatis di-cancel saat admin membuka halaman Transaksi. Isi <strong>0</strong> untuk menonaktifkan.</p>
                </div>
              </div>
              <div class="px-5 py-3.5 border-t border-gray-100 dark:border-gray-800 flex justify-end">
                <button type="submit" data-loading data-loading-text="Menyimpan..." class="<?= $saveBtn ?>">Simpan</button>
              </div>
            </div>
          </form>
        </div>

        <!-- ═══ Login dengan Google ═══ -->
        <div class="acc bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-settings" class="js-ajax-save">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="group" value="auth">
            <button type="button" class="acc-head w-full flex items-center gap-3 px-5 py-4 text-left transition hover:bg-gray-50 dark:hover:bg-gray-800/50">
              <span class="w-9 h-9 rounded-lg bg-[var(--brand-color)]/10 flex items-center justify-center shrink-0">
                <svg class="w-[18px] h-[18px] text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 7a2 2 0 0 1 2 2m4 0a6 6 0 0 1-7.743 5.743L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.586a1 1 0 0 1 .293-.707l5.964-5.964A6 6 0 1 1 21 9z"/></svg>
              </span>
              <span class="flex-1 min-w-0">
                <span class="flex items-center gap-2">
                  <span class="font-semibold text-sm text-gray-900 dark:text-white">Login dengan Google</span>
                  <?php if ($s('google_oauth_enabled', '0') === '1'): ?>
                  <span class="text-[10px] px-1.5 py-0.5 rounded-md bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 font-medium">Aktif</span>
                  <?php endif; ?>
                </span>
                <span class="block text-xs text-gray-400 dark:text-gray-500 truncate">Izinkan login/daftar dengan akun Google</span>
              </span>
              <svg class="acc-chevron w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div class="acc-body" style="max-height:0;overflow:hidden;transition:max-height .28s ease">
              <div class="border-t border-gray-100 dark:border-gray-800 p-5 space-y-4">
                <div class="flex items-center justify-between">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Aktifkan Login Google</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Tampilkan tombol "Login dengan Google" di halaman login</p>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="hidden" name="settings[google_oauth_enabled]" value="0">
                    <input type="checkbox" name="settings[google_oauth_enabled]" value="1" id="googleOauthEnabled" <?= $s('google_oauth_enabled', '0') === '1' ? 'checked' : '' ?> class="sr-only peer">
                    <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                    <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                  </label>
                </div>
                <div id="googleOauthFields" class="space-y-4">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Client ID</label>
                    <input type="text" name="settings[google_oauth_client_id]" value="<?= e($s('google_oauth_client_id', '')) ?>" placeholder="xxxxx.apps.googleusercontent.com" class="<?= $inputCls ?> font-mono">
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Client Secret</label>
                    <input type="password" name="settings[google_oauth_client_secret]" value="" placeholder="<?= $s('google_oauth_client_secret', '') !== '' ? '•••••••• (tersimpan — kosongkan jika tidak ingin mengubah)' : 'Masukkan Client Secret' ?>" autocomplete="new-password" class="<?= $inputCls ?>">
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Kosongkan jika tidak ingin mengubah nilai yang tersimpan.</p>
                  </div>
                  <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800/40 rounded-lg p-4">
                    <p class="text-sm font-medium text-blue-800 dark:text-blue-300 mb-2">Cara mendapatkan Client ID &amp; Secret:</p>
                    <ol class="text-xs text-blue-700 dark:text-blue-400 space-y-1 list-decimal list-inside">
                      <li>Buka <a href="https://console.cloud.google.com" target="_blank" rel="noopener" class="underline font-medium">console.cloud.google.com</a></li>
                      <li>Buat project baru atau pilih project yang ada</li>
                      <li>Buka menu <strong>APIs &amp; Services → Credentials</strong></li>
                      <li>Klik <strong>Create Credentials → OAuth 2.0 Client ID</strong></li>
                      <li>Application type: <strong>Web application</strong></li>
                      <li>Tambahkan Authorized redirect URI:
                        <code class="bg-blue-100 dark:bg-blue-900/50 px-1 rounded break-all"><?= e(rtrim(APP_URL, '/')) ?>/auth/google/callback</code>
                      </li>
                      <li>Copy <strong>Client ID</strong> dan <strong>Client Secret</strong> ke field di atas</li>
                    </ol>
                  </div>
                </div>
              </div>
              <div class="px-5 py-3.5 border-t border-gray-100 dark:border-gray-800 flex justify-end">
                <button type="submit" data-loading data-loading-text="Menyimpan..." class="<?= $saveBtn ?>">Simpan</button>
              </div>
            </div>
          </form>
        </div>

        <!-- ═══ Pengaturan Invoice ═══ -->
        <div class="acc bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-settings" class="js-ajax-save">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="group" value="invoice">
            <button type="button" class="acc-head w-full flex items-center gap-3 px-5 py-4 text-left transition hover:bg-gray-50 dark:hover:bg-gray-800/50">
              <span class="w-9 h-9 rounded-lg bg-[var(--brand-color)]/10 flex items-center justify-center shrink-0">
                <svg class="w-[18px] h-[18px] text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
              </span>
              <span class="flex-1 min-w-0">
                <span class="block font-semibold text-sm text-gray-900 dark:text-white">Pengaturan Invoice</span>
                <span class="block text-xs text-gray-400 dark:text-gray-500 truncate">Data penjual di Invoice PDF</span>
              </span>
              <svg class="acc-chevron w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div class="acc-body" style="max-height:0;overflow:hidden;transition:max-height .28s ease">
              <div class="border-t border-gray-100 dark:border-gray-800 p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php
                $invFields = [
                  'invoice_seller_name'    => ['Nama Penjual / Perusahaan', 'PT Karya Digital'],
                  'invoice_seller_email'   => ['Email Penjual', 'hello@domain.com'],
                  'invoice_seller_address' => ['Alamat', 'Jl. Contoh No. 1'],
                  'invoice_seller_city'    => ['Kota', 'Bandung'],
                  'invoice_seller_phone'   => ['Telepon / WA', '+62 812 xxxx xxxx'],
                  'invoice_seller_website' => ['Website', 'https://domain.com'],
                  'invoice_seller_npwp'    => ['NPWP (opsional)', '00.000.000.0-000.000'],
                ];
                foreach ($invFields as $k => $meta): ?>
                <div>
                  <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1.5"><?= e($meta[0]) ?></label>
                  <input type="text" name="settings[<?= e($k) ?>]" value="<?= e($s($k, '')) ?>" placeholder="<?= e($meta[1]) ?>" class="<?= $inputCls ?>">
                </div>
                <?php endforeach; ?>
                <div class="md:col-span-2">
                  <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1.5">Catatan Footer Invoice</label>
                  <input type="text" name="settings[invoice_footer_note]" value="<?= e($s('invoice_footer_note', 'Terima kasih atas kepercayaan Anda.')) ?>" class="<?= $inputCls ?>">
                </div>
                <div class="md:col-span-2 flex flex-wrap gap-6 pt-1">
                  <label class="flex items-center gap-2 cursor-pointer">
                    <input type="hidden" name="settings[invoice_show_logo]" value="0">
                    <input type="checkbox" name="settings[invoice_show_logo]" value="1" <?= $s('invoice_show_logo', '1') === '1' ? 'checked' : '' ?> class="rounded accent-[var(--brand-color)]">
                    <span class="text-sm text-gray-700 dark:text-gray-300">Tampilkan logo di invoice</span>
                  </label>
                  <label class="flex items-center gap-2 cursor-pointer">
                    <input type="hidden" name="settings[invoice_show_npwp]" value="0">
                    <input type="checkbox" name="settings[invoice_show_npwp]" value="1" <?= $s('invoice_show_npwp', '0') === '1' ? 'checked' : '' ?> class="rounded accent-[var(--brand-color)]">
                    <span class="text-sm text-gray-700 dark:text-gray-300">Tampilkan NPWP di invoice</span>
                  </label>
                </div>
              </div>
              <div class="px-5 py-3.5 border-t border-gray-100 dark:border-gray-800 flex justify-end">
                <button type="submit" data-loading data-loading-text="Menyimpan..." class="<?= $saveBtn ?>">Simpan</button>
              </div>
            </div>
          </form>
        </div>

        <!-- ═══ Review & Testimoni ═══ -->
        <div class="acc bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-settings" class="js-ajax-save">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="group" value="reviews">
            <button type="button" class="acc-head w-full flex items-center gap-3 px-5 py-4 text-left transition hover:bg-gray-50 dark:hover:bg-gray-800/50">
              <span class="w-9 h-9 rounded-lg bg-[var(--brand-color)]/10 flex items-center justify-center shrink-0">
                <svg class="w-[18px] h-[18px] text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              </span>
              <span class="flex-1 min-w-0">
                <span class="block font-semibold text-sm text-gray-900 dark:text-white">Review &amp; Testimoni</span>
                <span class="block text-xs text-gray-400 dark:text-gray-500 truncate">Kontrol global fitur review</span>
              </span>
              <svg class="acc-chevron w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div class="acc-body" style="max-height:0;overflow:hidden;transition:max-height .28s ease">
              <div class="border-t border-gray-100 dark:border-gray-800 p-5 space-y-3">
                <label class="flex items-center gap-2 cursor-pointer">
                  <input type="hidden" name="settings[reviews_enabled]" value="0">
                  <input type="checkbox" name="settings[reviews_enabled]" value="1" <?= $s('reviews_enabled','1')==='1'?'checked':'' ?> class="rounded accent-[var(--brand-color)]">
                  <span class="text-sm text-gray-700 dark:text-gray-300">Aktifkan fitur review (global)</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                  <input type="hidden" name="settings[reviews_require_access]" value="0">
                  <input type="checkbox" name="settings[reviews_require_access]" value="1" <?= $s('reviews_require_access','1')==='1'?'checked':'' ?> class="rounded accent-[var(--brand-color)]">
                  <span class="text-sm text-gray-700 dark:text-gray-300">Hanya pemilik produk yang bisa review</span>
                </label>
              </div>
              <div class="px-5 py-3.5 border-t border-gray-100 dark:border-gray-800 flex justify-end">
                <button type="submit" data-loading data-loading-text="Menyimpan..." class="<?= $saveBtn ?>">Simpan</button>
              </div>
            </div>
          </form>
        </div>

        <!-- ═══ Menu Dashboard Member ═══ -->
        <div class="acc bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-settings" class="js-ajax-save">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="group" value="member">
            <button type="button" class="acc-head w-full flex items-center gap-3 px-5 py-4 text-left transition hover:bg-gray-50 dark:hover:bg-gray-800/50">
              <span class="w-9 h-9 rounded-lg bg-[var(--brand-color)]/10 flex items-center justify-center shrink-0">
                <svg class="w-[18px] h-[18px] text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
              </span>
              <span class="flex-1 min-w-0">
                <span class="block font-semibold text-sm text-gray-900 dark:text-white">Menu Dashboard Member</span>
                <span class="block text-xs text-gray-400 dark:text-gray-500 truncate">Tampilkan / sembunyikan menu di sidebar member</span>
              </span>
              <svg class="acc-chevron w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div class="acc-body" style="max-height:0;overflow:hidden;transition:max-height .28s ease">
              <div class="border-t border-gray-100 dark:border-gray-800 p-5 space-y-4">
                <div class="flex items-center justify-between gap-4">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Menu &ldquo;File Downloads&rdquo;</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Tampilkan menu Unduhan file di dashboard member. Saat mati, menu &amp; halaman <code class="text-[11px]">/downloads</code> dinonaktifkan.</p>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="hidden" name="settings[member_menu_downloads]" value="0">
                    <input type="checkbox" name="settings[member_menu_downloads]" value="1" <?= $s('member_menu_downloads','1')==='1'?'checked':'' ?> class="sr-only peer">
                    <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                    <div class="absolute left-1 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-5"></div>
                  </label>
                </div>
                <div class="flex items-center justify-between gap-4">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Menu &ldquo;Sertifikat Saya&rdquo;</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Tampilkan menu Sertifikat di dashboard member. Saat mati, menu &amp; halaman <code class="text-[11px]">/certificates</code> dinonaktifkan.</p>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="hidden" name="settings[member_menu_certificates]" value="0">
                    <input type="checkbox" name="settings[member_menu_certificates]" value="1" <?= $s('member_menu_certificates','1')==='1'?'checked':'' ?> class="sr-only peer">
                    <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                    <div class="absolute left-1 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-5"></div>
                  </label>
                </div>
              </div>
              <div class="px-5 py-3.5 border-t border-gray-100 dark:border-gray-800 flex justify-end">
                <button type="submit" data-loading data-loading-text="Menyimpan..." class="<?= $saveBtn ?>">Simpan</button>
              </div>
            </div>
          </form>
        </div>

        <!-- ═══ Informasi Sistem ═══ -->
        <div class="acc bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <button type="button" class="acc-head w-full flex items-center gap-3 px-5 py-4 text-left transition hover:bg-gray-50 dark:hover:bg-gray-800/50">
            <span class="w-9 h-9 rounded-lg bg-[var(--brand-color)]/10 flex items-center justify-center shrink-0">
              <svg class="w-[18px] h-[18px] text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            </span>
            <span class="flex-1 min-w-0">
              <span class="flex items-center gap-2">
                <span class="font-semibold text-sm text-gray-900 dark:text-white">Informasi Sistem</span>
                <span class="text-[10px] px-1.5 py-0.5 rounded-md bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 font-mono">v<?= e(APP_VERSION) ?></span>
              </span>
              <span class="block text-xs text-gray-400 dark:text-gray-500 truncate">Versi aplikasi dan lingkungan server</span>
            </span>
            <svg class="acc-chevron w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
          </button>
          <div class="acc-body" style="max-height:0;overflow:hidden;transition:max-height .28s ease">
            <div class="border-t border-gray-100 dark:border-gray-800 p-5">
              <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2.5 text-sm">
                <?php
                // Versi diambil dari konstanta APP_VERSION (version.php) — sumber
                // kebenaran yang ikut ditimpa saat update sistem — BUKAN dari
                // settings.app_version yang bisa basi.
                $sysInfo = [
                    'Versi Averion'  => APP_VERSION,
                    'Versi PHP'      => PHP_VERSION,
                    'Platform'       => PHP_OS,
                    'Server'         => $_SERVER['SERVER_SOFTWARE'] ?? '-',
                    'Timezone'       => date_default_timezone_get(),
                    'Waktu Server'   => date('d M Y H:i:s'),
                ];
                foreach ($sysInfo as $label => $value):
                ?>
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-50 dark:border-gray-800/60 py-1.5 sm:border-0 sm:py-0">
                  <dt class="text-gray-500 dark:text-gray-400"><?= e($label) ?></dt>
                  <dd class="font-medium text-gray-900 dark:text-white font-mono text-xs text-right truncate"><?= e($value) ?></dd>
                </div>
                <?php endforeach; ?>
              </dl>
            </div>
          </div>
        </div>

        <!-- ═══ Cron Job / Penjadwalan ═══ -->
        <div class="acc bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <button type="button" class="acc-head w-full flex items-center gap-3 px-5 py-4 text-left transition hover:bg-gray-50 dark:hover:bg-gray-800/50">
            <span class="w-9 h-9 rounded-lg bg-[var(--brand-color)]/10 flex items-center justify-center shrink-0">
              <svg class="w-[18px] h-[18px] text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </span>
            <span class="flex-1 min-w-0">
              <span class="block font-semibold text-sm text-gray-900 dark:text-white">Cron Job / Penjadwalan</span>
              <span class="block text-xs text-gray-400 dark:text-gray-500 truncate">Perintah cron siap salin untuk hosting Anda</span>
            </span>
            <svg class="acc-chevron w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
          </button>
          <div class="acc-body" style="max-height:0;overflow:hidden;transition:max-height .28s ease">
            <div class="border-t border-gray-100 dark:border-gray-800 p-5 space-y-4">

              <div class="flex items-start gap-2.5 bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-900/40 rounded-xl p-3.5">
                <svg class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <p class="text-xs text-blue-800 dark:text-blue-300 leading-relaxed">
                  <strong>Opsional — sistem sudah punya auto-cron bawaan.</strong> Tugas terjadwal
                  (email sequence, keranjang tertinggal, reminder renewal &amp; pelunasan, waitlist
                  launch, booking hangus, backup harian, auto-retry notifikasi gagal) berjalan
                  otomatis dengan "menumpang" kunjungan halaman, jadi tanpa memasang cron pun
                  aplikasi tetap jalan. Memasang cron di bawah membuatnya tetap andal saat situs
                  benar-benar sepi (nol pengunjung) — disarankan untuk produksi. Salin perintah,
                  lalu tempel di menu <strong>Cron Jobs</strong> panel hosting Anda (CloudPanel/cPanel/Plesk).
                </p>
              </div>

              <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4">
                <div class="flex items-center justify-between gap-3 flex-wrap mb-1">
                  <div class="flex items-center gap-2">
                    <span class="text-[10px] px-1.5 py-0.5 rounded-md bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 font-semibold">Disarankan</span>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">Proses Otomatis Averion</span>
                  </div>
                  <span class="text-[11px] text-gray-400 dark:text-gray-600 font-mono">*/5 * * * * · Tiap 5 menit</span>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Jalankan semua tugas terjadwal (WA, email, reminder, backup, dll) walau situs sepi.</p>
                <div class="flex gap-2">
                  <input type="text" id="cron-cmd-cli" readonly value="<?= e($cronCliCmd) ?>"
                         class="flex-1 px-3.5 py-2.5 rounded-lg text-xs border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-400 font-mono outline-none overflow-x-auto">
                  <button type="button" onclick="copyCronCmd('cron-cmd-cli', this)"
                          class="px-4 py-2.5 rounded-lg text-sm font-medium text-white whitespace-nowrap shrink-0" style="background:var(--brand-color)">Salin</button>
                </div>
              </div>

              <details class="group">
                <summary class="text-xs text-gray-500 dark:text-gray-400 cursor-pointer hover:text-gray-700 dark:hover:text-gray-300 select-none">Panel hosting hanya izinkan cron via URL (bukan PHP CLI)? Pakai perintah alternatif ini →</summary>
                <div class="mt-3 flex gap-2">
                  <input type="text" id="cron-cmd-url" readonly value="<?= e($cronUrlCmd) ?>"
                         class="flex-1 px-3.5 py-2.5 rounded-lg text-xs border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-400 font-mono outline-none overflow-x-auto">
                  <button type="button" onclick="copyCronCmd('cron-cmd-url', this)"
                          class="px-4 py-2.5 rounded-lg text-sm font-medium text-white whitespace-nowrap shrink-0" style="background:var(--brand-color)">Salin</button>
                </div>
              </details>

              <div class="flex items-start gap-2.5 bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-900/40 rounded-xl p-3.5">
                <svg class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <p class="text-xs text-amber-800 dark:text-amber-300 leading-relaxed">
                  Path &amp; versi PHP di atas terdeteksi otomatis dari server ini
                  (<code class="font-mono"><?= e($cronPhpBinary) ?></code>). Jika panel hosting menolak
                  dengan pesan <em>"file not found"</em>, sesuaikan path PHP-nya (mis.
                  <code class="font-mono">php</code> saja, atau <code class="font-mono">/usr/local/bin/php</code>)
                  sesuai hosting Anda.
                </p>
              </div>

            </div>
          </div>
        </div>

        <!-- ═══ Zona Berbahaya ═══ -->
        <div class="acc bg-white dark:bg-gray-900 rounded-xl border border-red-200 dark:border-red-900/40 shadow-sm overflow-hidden">
          <button type="button" class="acc-head w-full flex items-center gap-3 px-5 py-4 text-left transition hover:bg-red-50/50 dark:hover:bg-red-900/10">
            <span class="w-9 h-9 rounded-lg bg-red-50 dark:bg-red-900/20 flex items-center justify-center shrink-0">
              <svg class="w-[18px] h-[18px] text-red-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </span>
            <span class="flex-1 min-w-0">
              <span class="block font-semibold text-sm text-red-600 dark:text-red-400">Zona Berbahaya</span>
              <span class="block text-xs text-gray-400 dark:text-gray-500 truncate">Tindakan sensitif akun admin</span>
            </span>
            <svg class="acc-chevron w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
          </button>
          <div class="acc-body" style="max-height:0;overflow:hidden;transition:max-height .28s ease">
            <div class="border-t border-red-100 dark:border-red-900/30 p-5">
              <div class="flex items-start justify-between gap-4">
                <div>
                  <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Ganti Password Admin</p>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Ubah password akun admin ini</p>
                </div>
                <a href="<?= e(APP_URL) ?>/profile" class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Ke Profil</a>
              </div>
            </div>
          </div>
        </div>

      </div>
    </main>
  </div>
</div>

<script>
// ─── Accordion pengaturan (toggle independen, semua tertutup saat load) ──
(function () {
  var heads = document.querySelectorAll('#settingsAccordion .acc-head');

  function bodyOf(head) { return head.parentElement.querySelector('.acc-body'); }
  function chevronOf(head) { return head.querySelector('.acc-chevron'); }
  function isOpen(body) { return body.style.maxHeight && body.style.maxHeight !== '0px'; }

  function open(head) {
    var body = bodyOf(head), chev = chevronOf(head);
    body.style.maxHeight = (body.scrollHeight + 24) + 'px';
    chev.style.transform = 'rotate(180deg)';
  }
  function close(head) {
    var body = bodyOf(head), chev = chevronOf(head);
    // Bila tinggi sedang 'none' (auto), kunci dulu ke px agar transisi mulus.
    if (body.style.maxHeight === 'none') {
      body.style.maxHeight = body.scrollHeight + 'px';
      void body.offsetHeight; // paksa reflow
    }
    body.style.maxHeight = '0px';
    chev.style.transform = 'rotate(0deg)';
  }

  heads.forEach(function (head) {
    head.addEventListener('click', function () {
      isOpen(bodyOf(head)) ? close(head) : open(head);
    });
  });

  // Setelah transisi buka selesai → maxHeight 'none' supaya konten dinamis
  // (mis. isi yang berubah tinggi) tidak terpotong.
  document.querySelectorAll('#settingsAccordion .acc-body').forEach(function (body) {
    body.addEventListener('transitionend', function () {
      if (isOpen(body)) body.style.maxHeight = 'none';
    });
  });

  var expandAll = document.getElementById('expandAll');
  var collapseAll = document.getElementById('collapseAll');
  if (expandAll)  expandAll.addEventListener('click', function () { heads.forEach(function (h) { if (!isOpen(bodyOf(h))) open(h); }); });
  if (collapseAll) collapseAll.addEventListener('click', function () { heads.forEach(function (h) { close(h); }); });
})();

// ─── Google OAuth: enable/disable field mengikuti toggle ──
(function () {
  var toggle = document.getElementById('googleOauthEnabled');
  var fields = document.getElementById('googleOauthFields');
  if (!toggle || !fields) return;
  function syncFields() {
    fields.style.opacity = toggle.checked ? '1' : '0.4';
    fields.querySelectorAll('input').forEach(function (i) { i.disabled = !toggle.checked; });
  }
  toggle.addEventListener('change', syncFields);
  syncFields();
})();

// ─── Cron Job: salin perintah ──
function copyCronCmd(inputId, btn) {
  var inp = document.getElementById(inputId);
  if (!inp) return;
  inp.select();
  var done = function () { var t = btn.textContent; btn.textContent = 'Tersalin'; setTimeout(function () { btn.textContent = t; }, 1500); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(inp.value).then(done).catch(function () { document.execCommand('copy'); done(); });
  } else {
    document.execCommand('copy'); done();
  }
}
</script>

<?php include __DIR__ . '/../../partials/save-toast.php'; ?>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

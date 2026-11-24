<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Branding';
$topbarTitle = 'Branding & Tampilan';

// Load semua setting branding sekaligus
$settings = [];
try {
    $stmt = getDB()->prepare(
        "SELECT setting_key, setting_value FROM settings
         WHERE setting_group IN ('branding','appearance')
         ORDER BY id ASC"
    );
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    error_log('Branding load error: ' . $e->getMessage());
}

$s = function(string $key, string $default = '') use ($settings): string {
    return $settings[$key] ?? $default;
};

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
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Branding & Tampilan</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Kustomisasi nama, logo, dan warna brand</p>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-5 gap-5">

        <!-- ─── Form (kiri 3/5) ──────────────────────────────── -->
        <div class="lg:col-span-3 space-y-5">

          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-settings"
                enctype="multipart/form-data" id="brandingForm" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="group" value="branding">

            <!-- Identitas Aplikasi -->
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
              <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Identitas Aplikasi</h3>
              </div>
              <div class="p-5 space-y-4">

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Nama Aplikasi</label>
                  <input type="text" name="settings[app_name]" id="inputAppName"
                         value="<?= e($s('app_name', 'STIFIn Mulia')) ?>"
                         maxlength="60"
                         class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                </div>

                <!-- Logo -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Logo</label>
                  <div class="flex items-start gap-4">
                    <div id="logoPreview"
                         class="w-16 h-16 rounded-xl border-2 border-dashed border-gray-200 dark:border-gray-700 flex items-center justify-center overflow-hidden shrink-0 bg-gray-50 dark:bg-gray-800">
                      <?php if ($s('app_logo')): ?>
                      <img src="<?= e($s('app_logo')) ?>" alt="Logo" class="max-w-full max-h-full object-contain">
                      <?php else: ?>
                      <svg class="w-6 h-6 text-gray-300 dark:text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/>
                        <polyline points="21 15 16 10 5 21"/>
                      </svg>
                      <?php endif; ?>
                    </div>
                    <div class="flex-1">
                      <label class="flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg border-2 border-dashed border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] hover:bg-[color-mix(in_srgb,var(--brand-color)_4%,transparent)] transition-colors">
                        <svg class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                          <polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        <span class="text-sm text-gray-500 dark:text-gray-400">Upload Logo</span>
                        <input type="file" name="logo_file" accept="image/png,image/jpeg,image/svg+xml,image/webp" class="sr-only"
                               onchange="previewImage(this, 'logoPreview')">
                      </label>
                      <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">PNG, JPG, SVG, WEBP — maks 2MB</p>
                      <?php if ($s('app_logo')): ?>
                      <p class="text-xs text-gray-400 mt-1 truncate">Current: <a href="<?= e($s('app_logo')) ?>" target="_blank" class="text-[var(--brand-color)] hover:underline">lihat</a></p>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <!-- Favicon -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Favicon</label>
                  <div class="flex items-start gap-4">
                    <div id="faviconPreview"
                         class="w-16 h-16 rounded-xl border-2 border-dashed border-gray-200 dark:border-gray-700 flex items-center justify-center overflow-hidden shrink-0 bg-gray-50 dark:bg-gray-800">
                      <?php if ($s('app_favicon')): ?>
                      <img src="<?= e($s('app_favicon')) ?>" alt="Favicon" class="w-8 h-8 object-contain">
                      <?php else: ?>
                      <svg class="w-6 h-6 text-gray-300 dark:text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/>
                      </svg>
                      <?php endif; ?>
                    </div>
                    <div class="flex-1">
                      <label class="flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg border-2 border-dashed border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] hover:bg-[color-mix(in_srgb,var(--brand-color)_4%,transparent)] transition-colors">
                        <svg class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                          <polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        <span class="text-sm text-gray-500 dark:text-gray-400">Upload Favicon</span>
                        <input type="file" name="favicon_file" accept="image/png,image/x-icon,image/svg+xml" class="sr-only"
                               onchange="previewImage(this, 'faviconPreview')">
                      </label>
                      <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">PNG, ICO, SVG — 32×32 atau 64×64 px</p>
                    </div>
                  </div>
                </div>

              </div>
            </div>

            <!-- Aplikasi (PWA) -->
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
              <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Aplikasi (PWA)</h3>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Izinkan pengunjung meng-install situs sebagai aplikasi di HP/desktop</p>
              </div>
              <div class="p-5">
                <div class="flex flex-wrap items-center justify-between gap-4">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Aktifkan Install sebagai Aplikasi (PWA)</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Memunculkan opsi &ldquo;Install&rdquo; di browser (Chrome Android &amp; desktop). Ikon &amp; nama mengikuti branding di atas. Tanpa penyimpanan offline. Di iPhone: Bagikan &rarr; Add to Home Screen.</p>
                  </div>
                  <label class="cursor-pointer shrink-0">
                    <input type="hidden" name="settings[pwa_enabled]" value="0">
                    <div class="relative">
                      <input type="checkbox" name="settings[pwa_enabled]" value="1" class="sr-only peer" <?= $s('pwa_enabled', '1') === '1' ? 'checked' : '' ?>>
                      <div class="w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                      <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
                    </div>
                  </label>
                </div>

                <!-- Ikon PWA kustom (opsional) -->
                <div class="mt-5 pt-5 border-t border-gray-100 dark:border-gray-800">
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Ikon Aplikasi (PWA)</label>
                  <div class="flex items-start gap-4">
                    <div id="pwaIconPreview" class="w-16 h-16 rounded-xl border-2 border-dashed border-gray-200 dark:border-gray-700 flex items-center justify-center overflow-hidden shrink-0 bg-gray-50 dark:bg-gray-800">
                      <?php if ($s('pwa_icon')): ?>
                      <img src="<?= e($s('pwa_icon')) ?>" alt="Ikon PWA" class="max-w-full max-h-full object-contain">
                      <?php else: ?>
                      <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background:<?= e($s('brand_color', '#6366f1')) ?>"><svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="currentColor"><path d="M12 4l9 18H3z"/></svg></div>
                      <?php endif; ?>
                    </div>
                    <div class="flex-1">
                      <label class="flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg border-2 border-dashed border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] hover:bg-[color-mix(in_srgb,var(--brand-color)_4%,transparent)] transition-colors">
                        <svg class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <span class="text-sm text-gray-500 dark:text-gray-400">Upload Ikon PWA</span>
                        <input type="file" name="pwa_icon_file" accept="image/png,image/jpeg,image/webp" class="sr-only" onchange="previewImage(this, 'pwaIconPreview')">
                      </label>
                      <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">PNG persegi disarankan (mis. 512&times;512). Kosongkan = pakai ikon bawaan (warna brand + segitiga).</p>
                      <?php if ($s('pwa_icon')): ?>
                      <label class="inline-flex items-center gap-1.5 mt-2 text-xs text-gray-500 dark:text-gray-400 cursor-pointer"><input type="checkbox" name="pwa_icon_reset" value="1" class="w-3.5 h-3.5"> Hapus ikon kustom (kembali ke bawaan)</label>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Konten Homepage -->
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
              <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Konten Homepage</h3>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Teks yang tampil di bagian atas halaman produk publik</p>
              </div>
              <div class="p-5 space-y-4">

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Headline</label>
                  <input type="text" name="settings[homepage_headline]"
                         value="<?= e($s('homepage_headline', 'Produk Averion')) ?>"
                         maxlength="100" placeholder="Produk Averion"
                         class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Maks 100 karakter. Kosongkan untuk pakai teks default.</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Sub Headline</label>
                  <textarea name="settings[homepage_subheadline]" rows="2" maxlength="200"
                            placeholder="Deskripsi singkat platform kamu..."
                            class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-none"><?= e($s('homepage_subheadline', '')) ?></textarea>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Maks 200 karakter.</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Teks Footer</label>
                  <input type="text" name="settings[footer_text]"
                         value="<?= e($s('footer_text', '')) ?>"
                         maxlength="200" placeholder="© {year} Averion. All rights reserved."
                         class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Tampil di footer halaman publik. Gunakan <code class="px-1 rounded bg-gray-100 dark:bg-gray-800">{year}</code> untuk tahun otomatis. Kosongkan untuk teks default.</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Badge Hero</label>
                  <input type="text" name="settings[homepage_hero_badge]"
                         value="<?= e($s('homepage_hero_badge', 'Platform produk & kelas digital')) ?>"
                         maxlength="60" placeholder="Platform produk & kelas digital"
                         class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Pill kecil di atas judul hero. Maks 60 karakter.</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Teks Tombol Hero (CTA)</label>
                  <input type="text" name="settings[homepage_hero_cta]"
                         value="<?= e($s('homepage_hero_cta', 'Jelajahi Produk')) ?>"
                         maxlength="40" placeholder="Jelajahi Produk"
                         class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Label tombol utama di hero. Maks 40 karakter.</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Tagline Footer</label>
                  <input type="text" name="settings[footer_tagline]"
                         value="<?= e($s('footer_tagline', 'Produk & kelas digital premium. Akses instan, pembayaran aman.')) ?>"
                         maxlength="200" placeholder="Produk & kelas digital premium. Akses instan, pembayaran aman."
                         class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Deskripsi singkat di footer, di bawah logo. Maks 200 karakter.</p>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-4 pt-4 border-t border-gray-100 dark:border-gray-800 mt-1">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Tampilkan Produk Unggulan</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Seksi spotlight produk terlaris di homepage.</p>
                  </div>
                  <label class="cursor-pointer shrink-0">
                    <input type="hidden" name="settings[homepage_show_spotlight]" value="0">
                    <div class="relative">
                      <input type="checkbox" name="settings[homepage_show_spotlight]" value="1" class="sr-only peer" <?= $s('homepage_show_spotlight', '1') === '1' ? 'checked' : '' ?>>
                      <div class="w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                      <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
                    </div>
                  </label>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-4 pt-4 border-t border-gray-100 dark:border-gray-800 mt-1">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Tampilkan Produk Baru Rilis</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Seksi produk aktif terbaru berdasarkan tanggal dibuat di homepage.</p>
                  </div>
                  <label class="cursor-pointer shrink-0">
                    <input type="hidden" name="settings[homepage_show_new_release]" value="0">
                    <div class="relative">
                      <input type="checkbox" name="settings[homepage_show_new_release]" value="1" class="sr-only peer" <?= $s('homepage_show_new_release', '1') === '1' ? 'checked' : '' ?>>
                      <div class="w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                      <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
                    </div>
                  </label>
                </div>

                <!-- Jumlah terjual di kartu produk -->
                <div class="flex flex-wrap items-center justify-between gap-4 pt-4 border-t border-gray-100 dark:border-gray-800 mt-1">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Tampilkan Jumlah Terjual</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Keterangan &ldquo;X terjual&rdquo; pada kartu produk di katalog (<?= e(APP_URL) ?>/products) &amp; storefront partner.</p>
                  </div>
                  <label class="cursor-pointer shrink-0">
                    <input type="hidden" name="settings[show_sold_count]" value="0">
                    <div class="relative">
                      <input type="checkbox" name="settings[show_sold_count]" value="1" class="sr-only peer" <?= $s('show_sold_count', '1') === '1' ? 'checked' : '' ?>>
                      <div class="w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                      <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
                    </div>
                  </label>
                </div>

                <!-- Display Produk Affiliate (rekomendasi produk orang lain) -->
                <div class="flex flex-wrap items-center justify-between gap-4 pt-4 border-t border-gray-100 dark:border-gray-800 mt-1">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Tampilkan Rekomendasi (Display Affiliate)</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Section kartu produk affiliate di homepage. Kelola isinya di <a href="<?= e(APP_URL) ?>/admin/affiliate-displays" class="underline text-[var(--brand-color)]">Display Affiliate</a>.</p>
                  </div>
                  <label class="cursor-pointer shrink-0">
                    <input type="hidden" name="settings[homepage_show_affiliate]" value="0">
                    <div class="relative">
                      <input type="checkbox" name="settings[homepage_show_affiliate]" value="1" class="sr-only peer" <?= $s('homepage_show_affiliate', '1') === '1' ? 'checked' : '' ?>>
                      <div class="w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                      <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
                    </div>
                  </label>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Judul Section Rekomendasi</label>
                  <input type="text" name="settings[homepage_affiliate_title]"
                         value="<?= e($s('homepage_affiliate_title', 'Rekomendasi Pilihan')) ?>"
                         maxlength="60" placeholder="Rekomendasi Pilihan"
                         class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Judul di atas kartu rekomendasi. Maks 60 karakter.</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Label Kartu Afiliasi</label>
                  <input type="text" name="settings[homepage_affiliate_badge_text]"
                         value="<?= e($s('homepage_affiliate_badge_text', 'Afiliasi')) ?>"
                         maxlength="30" placeholder="Afiliasi"
                         class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Label kecil di tiap kartu (kejujuran/disclosure). <strong>Kosongkan</strong> untuk menyembunyikan.</p>
                </div>

                <!-- Lead Magnet Gratis di homepage -->
                <div class="flex flex-wrap items-center justify-between gap-4 pt-4 border-t border-gray-100 dark:border-gray-800 mt-1">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Tampilkan Section Gratis (Lead Magnet)</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Kartu lead magnet gratis di homepage. Pilih magnet-nya di <a href="<?= e(APP_URL) ?>/admin/lead-magnets" class="underline text-[var(--brand-color)]">Lead Magnet</a> (centang "Tampilkan di homepage").</p>
                  </div>
                  <label class="cursor-pointer shrink-0">
                    <input type="hidden" name="settings[homepage_show_leadmagnet]" value="0">
                    <div class="relative">
                      <input type="checkbox" name="settings[homepage_show_leadmagnet]" value="1" class="sr-only peer" <?= $s('homepage_show_leadmagnet', '1') === '1' ? 'checked' : '' ?>>
                      <div class="w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                      <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
                    </div>
                  </label>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Judul Section Gratis</label>
                  <input type="text" name="settings[homepage_leadmagnet_title]"
                         value="<?= e($s('homepage_leadmagnet_title', 'Gratis Untukmu')) ?>"
                         maxlength="60" placeholder="Gratis Untukmu"
                         class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Judul di atas kartu lead magnet gratis. Maks 60 karakter.</p>
                </div>

              </div>
            </div>

            <!-- Warna Brand -->
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
              <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Warna Brand</h3>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Perubahan akan terlihat di preview secara langsung</p>
              </div>
              <div class="p-5 space-y-4">

                <div class="flex items-center gap-4">
                  <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Warna Utama</label>
                    <input type="color" name="settings[brand_color]" id="colorPrimary"
                           value="<?= e($s('brand_color', '#6366f1')) ?>"
                           class="w-12 h-10 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer p-0.5">
                  </div>
                  <div class="flex-1">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Hex</label>
                    <input type="text" id="hexPrimary" maxlength="7"
                           value="<?= e($s('brand_color', '#6366f1')) ?>"
                           class="w-full px-3 py-2 rounded-lg text-sm font-mono border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition uppercase"
                           onchange="syncColorFromHex('colorPrimary','hexPrimary','settings[brand_color]')">
                  </div>
                </div>

                <div class="flex items-center gap-4">
                  <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Warna Sekunder</label>
                    <input type="color" name="settings[brand_color_secondary]" id="colorSecondary"
                           value="<?= e($s('brand_color_secondary', '#818cf8')) ?>"
                           class="w-12 h-10 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer p-0.5">
                  </div>
                  <div class="flex-1">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Hex</label>
                    <input type="text" id="hexSecondary" maxlength="7"
                           value="<?= e($s('brand_color_secondary', '#818cf8')) ?>"
                           class="w-full px-3 py-2 rounded-lg text-sm font-mono border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition uppercase"
                           onchange="syncColorFromHex('colorSecondary','hexSecondary','settings[brand_color_secondary]')">
                  </div>
                </div>

                <!-- Swatch presets -->
                <div>
                  <p class="text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">Preset Warna</p>
                  <div class="flex items-center gap-2 flex-wrap">
                    <?php
                    $presets = [
                        ['#6366f1', '#818cf8', 'Indigo'],  // default
                        ['#8b5cf6', '#a78bfa', 'Purple'],
                        ['#ec4899', '#f472b6', 'Pink'],
                        ['#f59e0b', '#fbbf24', 'Amber'],
                        ['#10b981', '#34d399', 'Emerald'],
                        ['#3b82f6', '#60a5fa', 'Blue'],
                        ['#ef4444', '#f87171', 'Red'],
                        ['#1e293b', '#334155', 'Slate'],
                        // ── Preset baru (Appearance V2) ──
                        ['#0ea5e9', '#38bdf8', 'Ocean'],
                        ['#14b8a6', '#2dd4bf', 'Teal'],
                        ['#ea580c', '#fb923c', 'Orange'],
                        ['#dc2626', '#ef4444', 'Crimson'],
                        ['#737373', '#a3a3a3', 'Neutral'],
                    ];
                    foreach ($presets as $preset):
                        [$primary, $secondary] = $preset;
                        $presetName = $preset[2] ?? $primary;
                    ?>
                    <button type="button"
                            onclick="applyPreset('<?= $primary ?>', '<?= $secondary ?>')"
                            style="background-color: <?= $primary ?>;"
                            title="<?= e($presetName) ?>"
                            class="w-7 h-7 rounded-full border-2 border-transparent hover:border-gray-300 dark:hover:border-gray-600 transition-all hover:scale-110">
                    </button>
                    <?php endforeach; ?>
                  </div>
                </div>

              </div>
            </div>

            <!-- Tampilan -->
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
              <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Tampilan Default</h3>
              </div>
              <div class="p-5">
                <div class="flex items-center justify-between">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Dark Mode Default</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Aktifkan dark mode untuk user yang belum mengatur preferensi</p>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="settings[dark_mode_default]" value="0">
                    <input type="checkbox" name="settings[dark_mode_default]" value="1" id="darkModeToggle"
                           <?= $s('dark_mode_default') === '1' ? 'checked' : '' ?>
                           class="sr-only peer">
                    <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors peer-focus:ring-2 peer-focus:ring-[var(--brand-color)]/30"></div>
                    <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                  </label>
                </div>
              </div>
            </div>

            <!-- ═══ APPEARANCE (UI Theme + Sidebar Style) ═══ -->
            <?php
            $curTheme     = $s('ui_theme', 'default');
            $curSidebar   = $s('ui_sidebar_style', 'light');
            $themePresets = function_exists('getAllThemePresets') ? getAllThemePresets() : [];
            ?>
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
              <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Appearance</h3>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Atur nuansa tampilan dashboard. Tidak memengaruhi halaman publik.</p>
              </div>
              <div class="p-5 space-y-6">

                <!-- Hidden inputs (disubmit bersama form branding) -->
                <input type="hidden" name="settings[ui_theme]"         id="input_ui_theme"         value="<?= e($curTheme) ?>">
                <input type="hidden" name="settings[ui_sidebar_style]" id="input_ui_sidebar_style" value="<?= e($curSidebar) ?>">

                <!-- Bagian A: UI Theme -->
                <div>
                  <p class="text-sm font-medium text-gray-700 dark:text-gray-300">UI Theme</p>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mb-3">Pilih nuansa tampilan dashboard</p>
                  <?php
                  // Mini mockup representatif per theme (sidebar + topbar + stat cards + konten).
                  $themeMockup = function (string $key): string {
                      $wrap = fn(string $bg, string $inner): string =>
                          '<div class="rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700 mb-2 h-20" style="background:' . $bg . '"><div class="flex h-full">' . $inner . '</div></div>';
                      switch ($key) {
                          case 'airy':
                              // Sesuai tampilan nyata pasca Premium Polish: SATU kartu hero
                              // gradien brand + kartu netral (bukan warna-warni — itu Vivid).
                              return $wrap('#f0f0f7',
                                  '<div class="w-9 flex flex-col gap-1 p-1 pt-2 bg-white/80" style="border-radius:0 .375rem .375rem 0">'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-full"></div>'
                                  . '<div class="w-full h-1.5 rounded-full" style="background:var(--brand-color)"></div>'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-full"></div></div>'
                                  . '<div class="flex-1 p-1.5 flex flex-col gap-1">'
                                  . '<div class="h-2.5 bg-white/80 rounded shadow-sm w-full"></div>'
                                  . '<div class="flex gap-1">'
                                  . '<div class="flex-1 h-5 rounded" style="background:linear-gradient(135deg,var(--brand-color),color-mix(in srgb,var(--brand-color) 72%,#000))"></div>'
                                  . '<div class="flex-1 h-5 rounded bg-white border border-gray-200"></div>'
                                  . '<div class="flex-1 h-5 rounded bg-white border border-gray-200"></div></div>'
                                  . '<div class="flex-1 bg-white/80 rounded shadow-sm"></div></div>'
                              );
                          case 'vivid':
                              // Kartu gradient multi-warna (gaya Airy generasi awal).
                              return $wrap('#f4f4fb',
                                  '<div class="w-9 flex flex-col gap-1 p-1 pt-2 bg-white/80" style="border-radius:0 .375rem .375rem 0">'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-full"></div>'
                                  . '<div class="w-full h-1.5 rounded-full" style="background:#818cf8"></div>'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-full"></div></div>'
                                  . '<div class="flex-1 p-1.5 flex flex-col gap-1">'
                                  . '<div class="h-2.5 bg-white/80 rounded shadow-sm w-full"></div>'
                                  . '<div class="flex gap-1">'
                                  . '<div class="flex-1 h-5 rounded" style="background:linear-gradient(135deg,#6ee7f7,#60a5fa)"></div>'
                                  . '<div class="flex-1 h-5 rounded" style="background:linear-gradient(135deg,#a78bfa,#818cf8)"></div>'
                                  . '<div class="flex-1 h-5 rounded" style="background:linear-gradient(135deg,#f9a8d4,#f472b6)"></div></div>'
                                  . '<div class="flex-1 bg-white/80 rounded shadow-sm"></div></div>'
                              );
                          case 'corporate':
                              return $wrap('#f1f5f9',
                                  '<div class="w-9 flex flex-col gap-1 p-1 pt-2" style="background:#0f172a">'
                                  . '<div class="w-full h-1.5" style="background:#475569"></div>'
                                  . '<div class="w-full h-1.5" style="background:#38bdf8"></div>'
                                  . '<div class="w-full h-1.5" style="background:#475569"></div>'
                                  . '<div class="w-full h-1.5" style="background:#475569"></div></div>'
                                  . '<div class="flex-1 p-1.5 flex flex-col gap-1">'
                                  . '<div class="h-2.5 bg-white border border-gray-200 w-full"></div>'
                                  . '<div class="flex gap-1">'
                                  . '<div class="flex-1 h-5 bg-white border border-gray-200" style="border-radius:2px"></div>'
                                  . '<div class="flex-1 h-5 bg-white border border-gray-200" style="border-radius:2px"></div>'
                                  . '<div class="flex-1 h-5 bg-white border border-gray-200" style="border-radius:2px"></div></div>'
                                  . '<div class="flex-1 bg-white border border-gray-200" style="border-radius:2px"></div></div>'
                              );
                          case 'noir':
                              // Dark-first: charcoal hangat + SATU kartu hero gradien brand.
                              return $wrap('#16120d',
                                  '<div class="w-9 flex flex-col gap-1 p-1 pt-2" style="background:#1a140d">'
                                  . '<div class="w-full h-1.5 rounded-full" style="background:#3d3327"></div>'
                                  . '<div class="w-full h-1.5 rounded-full" style="background:var(--brand-color)"></div>'
                                  . '<div class="w-full h-1.5 rounded-full" style="background:#3d3327"></div></div>'
                                  . '<div class="flex-1 p-1.5 flex flex-col gap-1">'
                                  . '<div class="h-2.5 rounded w-full" style="background:#1e1811;border:1px solid rgba(255,234,200,0.10)"></div>'
                                  . '<div class="flex gap-1">'
                                  . '<div class="flex-1 h-5 rounded" style="background:linear-gradient(135deg,var(--brand-color),color-mix(in srgb,var(--brand-color) 66%,#1c130a))"></div>'
                                  . '<div class="flex-1 h-5 rounded" style="background:#1e1811;border:1px solid rgba(255,234,200,0.10)"></div>'
                                  . '<div class="flex-1 h-5 rounded" style="background:#1e1811;border:1px solid rgba(255,234,200,0.10)"></div></div>'
                                  . '<div class="flex-1 rounded" style="background:#1e1811;border:1px solid rgba(255,234,200,0.10)"></div></div>'
                              );
                          case 'minimal':
                              return $wrap('#ffffff',
                                  '<div class="w-9 bg-white border-r border-gray-100 flex flex-col gap-1 p-1 pt-2">'
                                  . '<div class="w-full h-1.5 bg-gray-100 rounded"></div>'
                                  . '<div class="w-2/3 h-1.5 bg-gray-900 rounded"></div>'
                                  . '<div class="w-full h-1.5 bg-gray-100 rounded"></div>'
                                  . '<div class="w-full h-1.5 bg-gray-100 rounded"></div></div>'
                                  . '<div class="flex-1 p-1.5 flex flex-col gap-1">'
                                  . '<div class="h-2.5 border border-gray-100 w-full rounded"></div>'
                                  . '<div class="flex gap-1">'
                                  . '<div class="flex-1 h-5 border border-gray-100 rounded"></div>'
                                  . '<div class="flex-1 h-5 border border-gray-100 rounded"></div>'
                                  . '<div class="flex-1 h-5 border border-gray-100 rounded"></div></div>'
                                  . '<div class="flex-1 border border-gray-100 rounded"></div></div>'
                              );
                          case 'frost':
                              return $wrap('#f2f4f8',
                                  '<div class="w-9 flex flex-col gap-1 p-1 pt-2 bg-white/40 border-r border-white/40" style="backdrop-filter:blur(4px)">'
                                  . '<div class="w-full h-1.5 bg-white/60 rounded"></div>'
                                  . '<div class="w-full h-1.5 rounded" style="background:var(--brand-color);opacity:.7"></div>'
                                  . '<div class="w-full h-1.5 bg-white/60 rounded"></div></div>'
                                  . '<div class="flex-1 p-1.5 flex flex-col gap-1">'
                                  . '<div class="h-2.5 bg-white/40 border border-white/60 w-full rounded-md shadow-sm"></div>'
                                  . '<div class="flex gap-1">'
                                  . '<div class="flex-1 h-5 bg-white/50 border border-white/60 rounded-md shadow-sm"></div>'
                                  . '<div class="flex-1 h-5 bg-white/50 border border-white/60 rounded-md shadow-sm"></div>'
                                  . '<div class="flex-1 h-5 bg-white/50 border border-white/60 rounded-md shadow-sm"></div></div>'
                                  . '<div class="flex-1 bg-white/45 border border-white/50 rounded-md shadow-sm"></div></div>'
                              );
                          case 'emerald':
                              return $wrap('#f3f6f3',
                                  '<div class="w-9 bg-white border-r border-gray-200 flex flex-col gap-1 p-1 pt-2">'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-sm"></div>'
                                  . '<div class="w-full h-1.5 rounded-sm" style="background:#10b981"></div>'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-sm"></div></div>'
                                  . '<div class="flex-1 p-1.5 flex flex-col gap-1">'
                                  . '<div class="h-2.5 bg-white rounded shadow-sm w-full"></div>'
                                  . '<div class="flex gap-1">'
                                  . '<div class="flex-1 h-5 bg-white rounded shadow-sm" style="border-left:2px solid #10b981"></div>'
                                  . '<div class="flex-1 h-5 bg-white rounded shadow-sm" style="border-left:2px solid #10b981"></div>'
                                  . '<div class="flex-1 h-5 bg-white rounded shadow-sm" style="border-left:2px solid #10b981"></div></div>'
                                  . '<div class="flex-1 bg-white rounded shadow-sm"></div></div>'
                              );
                          case 'rose':
                              // Sibling hangat Airy: blush + hero brand.
                              return $wrap('#fbeef0',
                                  '<div class="w-9 flex flex-col gap-1 p-1 pt-2 bg-white/80" style="border-radius:0 .375rem .375rem 0">'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-full"></div>'
                                  . '<div class="w-full h-1.5 rounded-full" style="background:var(--brand-color)"></div>'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-full"></div></div>'
                                  . '<div class="flex-1 p-1.5 flex flex-col gap-1">'
                                  . '<div class="h-2.5 bg-white/80 rounded shadow-sm w-full"></div>'
                                  . '<div class="flex gap-1">'
                                  . '<div class="flex-1 h-5 rounded" style="background:linear-gradient(135deg,var(--brand-color),color-mix(in srgb,var(--brand-color) 68%,#7f1d3a))"></div>'
                                  . '<div class="flex-1 h-5 rounded bg-white border" style="border-color:#f6dbe1"></div>'
                                  . '<div class="flex-1 h-5 rounded bg-white border" style="border-color:#f6dbe1"></div></div>'
                                  . '<div class="flex-1 bg-white/80 rounded shadow-sm"></div></div>'
                              );
                          case 'slate':
                              // Kartu putih beraksen garis brand kiri, sidebar terang.
                              return $wrap('#eef2f7',
                                  '<div class="w-9 bg-white border-r border-gray-200 flex flex-col gap-1 p-1 pt-2">'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-sm"></div>'
                                  . '<div class="w-full h-1.5 rounded-sm" style="background:var(--brand-color)"></div>'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-sm"></div></div>'
                                  . '<div class="flex-1 p-1.5 flex flex-col gap-1">'
                                  . '<div class="h-2.5 bg-white rounded shadow-sm w-full"></div>'
                                  . '<div class="flex gap-1">'
                                  . '<div class="flex-1 h-5 bg-white rounded shadow-sm" style="border-left:2px solid var(--brand-color)"></div>'
                                  . '<div class="flex-1 h-5 bg-white rounded shadow-sm" style="border-left:2px solid var(--brand-color)"></div>'
                                  . '<div class="flex-1 h-5 bg-white rounded shadow-sm" style="border-left:2px solid var(--brand-color)"></div></div>'
                                  . '<div class="flex-1 bg-white rounded shadow-sm"></div></div>'
                              );
                          case 'mono':
                              // Editorial monokrom sharp: kartu putih + aksen brand di atas.
                              return $wrap('#f5f5f5',
                                  '<div class="w-9 bg-white border-r border-gray-200 flex flex-col gap-1 p-1 pt-2">'
                                  . '<div class="w-full h-1.5 bg-gray-200"></div>'
                                  . '<div class="w-2/3 h-1.5" style="background:var(--brand-color)"></div>'
                                  . '<div class="w-full h-1.5 bg-gray-200"></div></div>'
                                  . '<div class="flex-1 p-1.5 flex flex-col gap-1">'
                                  . '<div class="h-2.5 bg-white border border-gray-200 w-full"></div>'
                                  . '<div class="flex gap-1">'
                                  . '<div class="flex-1 h-5 bg-white border border-gray-200" style="border-top:2px solid var(--brand-color);border-radius:2px"></div>'
                                  . '<div class="flex-1 h-5 bg-white border border-gray-200" style="border-top:2px solid var(--brand-color);border-radius:2px"></div>'
                                  . '<div class="flex-1 h-5 bg-white border border-gray-200" style="border-top:2px solid var(--brand-color);border-radius:2px"></div></div>'
                                  . '<div class="flex-1 bg-white border border-gray-200" style="border-radius:2px"></div></div>'
                              );
                          case 'sunset':
                              // Sibling hangat Vivid: gradient amber–rose.
                              return $wrap('#fbf1e8',
                                  '<div class="w-9 flex flex-col gap-1 p-1 pt-2 bg-white/80" style="border-radius:0 .375rem .375rem 0">'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-full"></div>'
                                  . '<div class="w-full h-1.5 rounded-full" style="background:#f97316"></div>'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-full"></div></div>'
                                  . '<div class="flex-1 p-1.5 flex flex-col gap-1">'
                                  . '<div class="h-2.5 bg-white/80 rounded shadow-sm w-full"></div>'
                                  . '<div class="flex gap-1">'
                                  . '<div class="flex-1 h-5 rounded" style="background:linear-gradient(135deg,#fbbf24,#f97316)"></div>'
                                  . '<div class="flex-1 h-5 rounded" style="background:linear-gradient(135deg,#fb923c,#ef4444)"></div>'
                                  . '<div class="flex-1 h-5 rounded" style="background:linear-gradient(135deg,#f472b6,#fb7185)"></div></div>'
                                  . '<div class="flex-1 bg-white/80 rounded shadow-sm"></div></div>'
                              );
                          default: // default theme
                              return $wrap('#f3f4f6',
                                  '<div class="w-9 bg-white border-r border-gray-200 flex flex-col gap-1 p-1 pt-2">'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-sm"></div>'
                                  . '<div class="w-full h-1.5 rounded-sm" style="background:#3b82f6;opacity:.85"></div>'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-sm"></div>'
                                  . '<div class="w-full h-1.5 bg-gray-200 rounded-sm"></div></div>'
                                  . '<div class="flex-1 p-1.5 flex flex-col gap-1">'
                                  . '<div class="h-2.5 bg-white rounded shadow-sm w-full"></div>'
                                  . '<div class="flex gap-1">'
                                  . '<div class="flex-1 h-5 bg-white rounded shadow-sm"></div>'
                                  . '<div class="flex-1 h-5 bg-white rounded shadow-sm"></div>'
                                  . '<div class="flex-1 h-5 bg-white rounded shadow-sm"></div></div>'
                                  . '<div class="flex-1 bg-white rounded shadow-sm"></div></div>'
                              );
                      }
                  };
                  ?>
                  <div class="grid grid-cols-2 gap-3">
                    <?php foreach ($themePresets as $key => $preset): ?>
                    <button type="button"
                            class="theme-card text-left rounded-xl border-2 p-3 transition <?= $key === $curTheme ? 'border-[var(--brand-color)] ring-2 ring-[var(--brand-color)]/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' ?>"
                            data-theme="<?= e($key) ?>"
                            data-preview-class="<?= e($preset['preview_class']) ?>"
                            data-bg="<?= e($preset['--bg-canvas']) ?>"
                            data-radius="<?= e($preset['--card-radius']) ?>"
                            data-shadow="<?= e($preset['--card-shadow']) ?>"
                            data-sidebar-radius="<?= e($preset['--sidebar-radius']) ?>">
                      <!-- Mini preview (mockup representatif per theme) -->
                      <?= $themeMockup($key) ?>
                      <p class="text-sm font-semibold text-gray-900 dark:text-white"><?= e($preset['label']) ?></p>
                      <p class="text-xs text-gray-400 dark:text-gray-500 leading-snug mt-0.5"><?= e($preset['description']) ?></p>
                    </button>
                    <?php endforeach; ?>
                  </div>
                </div>

                <!-- Bagian B: Sidebar Style -->
                <div>
                  <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Sidebar Style</p>
                  <div class="grid grid-cols-3 gap-3">
                    <?php
                    // Mini mockup per style — menggambarkan STRUKTUR (indikator
                    // item aktif), bukan sekadar swatch warna "Aa".
                    $sidebarOpts = [
                        'light'   => ['Classic',       'Putih, item aktif tint brand'],
                        'dark'    => ['Midnight Rail', 'Nyaris hitam, garis aksen kiri'],
                        'colored' => ['Brand Panel',   'Gradien brand, pill kaca'],
                    ];
                    $sidebarMockup = function (string $val): string {
                        $row = fn(string $style): string => '<div class="h-1.5 rounded-full" style="margin-left:6px;margin-right:6px;' . $style . '"></div>';
                        switch ($val) {
                            case 'dark': // Midnight Rail: garis aksen kiri menyala
                                return '<div class="h-12 rounded-lg mb-2 flex flex-col justify-center gap-1 py-1" style="background:#0a0d14">'
                                     . $row('background:#374151')
                                     . '<div class="h-2 flex items-center" style="background:rgba(255,255,255,.05);box-shadow:inset 2px 0 0 var(--brand-color)"><div class="h-1 w-7 ml-2 rounded-full" style="background:#e5e7eb"></div></div>'
                                     . $row('background:#374151')
                                     . '</div>';
                            case 'colored': // Brand Panel: gradien + pill kaca
                                return '<div class="h-12 rounded-lg mb-2 flex flex-col justify-center gap-1 py-1" style="background:linear-gradient(180deg,var(--brand-color),color-mix(in srgb,var(--brand-color) 76%,#000))">'
                                     . $row('background:rgba(255,255,255,.4)')
                                     . '<div class="h-2.5 rounded-md flex items-center" style="margin-left:4px;margin-right:4px;background:rgba(255,255,255,.22);border:1px solid rgba(255,255,255,.4)"><div class="h-1 w-7 ml-1.5 rounded-full" style="background:#fff"></div></div>'
                                     . $row('background:rgba(255,255,255,.4)')
                                     . '</div>';
                            default: // Classic
                                return '<div class="h-12 rounded-lg mb-2 flex flex-col justify-center gap-1 py-1 border border-gray-200" style="background:#ffffff">'
                                     . $row('background:#d1d5db')
                                     . '<div class="h-2.5 rounded-md flex items-center" style="margin-left:4px;margin-right:4px;background:color-mix(in srgb,var(--brand-color) 12%,transparent)"><div class="h-1 w-7 ml-1.5 rounded-full" style="background:var(--brand-color)"></div></div>'
                                     . $row('background:#d1d5db')
                                     . '</div>';
                        }
                    };
                    foreach ($sidebarOpts as $val => [$label, $desc]): ?>
                    <button type="button"
                            class="sidebar-style-btn rounded-xl border-2 p-3 text-left transition <?= $val === $curSidebar ? 'border-[var(--brand-color)] ring-2 ring-[var(--brand-color)]/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' ?>"
                            data-sidebar="<?= e($val) ?>">
                      <?= $sidebarMockup($val) ?>
                      <p class="text-xs font-medium text-gray-800 dark:text-gray-200"><?= e($label) ?></p>
                      <p class="text-[11px] text-gray-400 dark:text-gray-500 leading-snug"><?= e($desc) ?></p>
                    </button>
                    <?php endforeach; ?>
                  </div>
                </div>

                <!-- Bagian C: Topbar Style -->
                <div>
                  <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Topbar Style</p>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mb-2">Pilih gaya header atas dashboard</p>
                  <input type="hidden" name="settings[ui_topbar_style]" id="input_ui_topbar_style" value="<?= e($s('ui_topbar_style', 'default')) ?>">
                  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <?php
                    $curTopbar  = $s('ui_topbar_style', 'default');
                    $topbarOpts = [
                        'default'  => ['Classic',      'Putih bersih, border bawah'],
                        'colored'  => ['Hero Brand',   'Gradien brand + search pill frosted'],
                        'dark'     => ['Command Deck', 'Ramping, search di tengah, garis brand'],
                        'floating' => ['Floating',     'Melayang, frosted blur'],
                    ];
                    // Mini mockup per style — menggambarkan STRUKTUR barunya,
                    // bukan sekadar swatch warna.
                    $topbarMockup = function (string $val): string {
                        switch ($val) {
                            case 'colored': // Hero Brand: gradien + search pill frosted di kanan
                                return '<div class="h-full flex items-center gap-1 px-1.5" style="background:linear-gradient(120deg,var(--brand-color),color-mix(in srgb,var(--brand-color) 84%,#000))">'
                                     . '<div class="h-1 w-6 rounded-full" style="background:rgba(255,255,255,.6)"></div>'
                                     . '<div class="ml-auto h-2.5 w-9 rounded-full" style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.35)"></div>'
                                     . '<div class="w-2.5 h-2.5 rounded-full shrink-0" style="background:#fff;box-shadow:0 0 0 1.5px rgba(255,255,255,.45)"></div></div>';
                            case 'dark': // Command Deck: ramping, pill di tengah, garis brand bawah
                                return '<div class="h-full flex items-center px-1.5" style="background:#0b0e17;border-bottom:2px solid var(--brand-color);position:relative">'
                                     . '<div class="h-1 w-5 rounded-full" style="background:#4b5563"></div>'
                                     . '<div class="h-2.5 w-10 rounded-full" style="position:absolute;left:50%;transform:translateX(-50%);background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18)"></div>'
                                     . '<div class="ml-auto w-2.5 h-2.5 rounded-full" style="background:#cbd5e1"></div></div>';
                            case 'floating':
                                return '<div class="h-full flex items-center gap-1 px-1.5" style="background:#ffffff;box-shadow:0 4px 10px rgba(0,0,0,.18);border-radius:6px;margin:2px">'
                                     . '<div class="w-1.5 h-1.5 rounded-full" style="background:#9ca3af"></div>'
                                     . '<div class="h-1 w-6 rounded-full" style="background:#d1d5db"></div>'
                                     . '<div class="ml-auto w-2.5 h-2.5 rounded-full" style="background:#cbd5e1"></div></div>';
                            default: // Classic
                                return '<div class="h-full flex items-center gap-1 px-1.5" style="background:#ffffff;border-bottom:1px solid #e5e7eb">'
                                     . '<div class="w-1.5 h-1.5 rounded-full" style="background:#9ca3af"></div>'
                                     . '<div class="h-1 w-6 rounded-full" style="background:#d1d5db"></div>'
                                     . '<div class="ml-auto w-2.5 h-2.5 rounded-full" style="background:#cbd5e1"></div></div>';
                        }
                    };
                    foreach ($topbarOpts as $val => [$label, $desc]):
                    ?>
                    <button type="button"
                            class="topbar-style-btn rounded-xl border-2 p-2.5 text-left transition <?= $val === $curTopbar ? 'border-[var(--brand-color)] ring-2 ring-[var(--brand-color)]/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' ?>"
                            data-topbar="<?= e($val) ?>">
                      <div class="h-9 rounded-lg border border-gray-100 dark:border-gray-700 overflow-hidden mb-2 bg-gray-100 dark:bg-gray-950 p-1">
                        <?= $topbarMockup($val) ?>
                      </div>
                      <p class="text-xs font-medium text-gray-800 dark:text-gray-200"><?= e($label) ?></p>
                      <p class="text-[11px] text-gray-400 dark:text-gray-500 leading-snug"><?= e($desc) ?></p>
                    </button>
                    <?php endforeach; ?>
                  </div>
                </div>

              </div>
            </div>

            <div class="flex justify-end">
              <button type="submit" data-loading data-loading-text="Menyimpan..."
                      class="px-6 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
                Simpan Branding
              </button>
            </div>

          </form>
        </div>

        <!-- ─── Live Preview (kanan 2/5) ────────────────────── -->
        <div class="lg:col-span-2">
          <div class="sticky top-6">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-3">Preview Langsung</p>

            <!-- Browser chrome mockup -->
            <div id="branding-preview-shell"
                 class="rounded-xl overflow-hidden shadow-xl border border-gray-200 dark:border-gray-700 bg-white transition-all duration-300"
                 style="--preview-brand: <?= e($s('brand_color', '#6366f1')) ?>;">
              <!-- Browser bar -->
              <div class="flex items-center gap-1.5 px-3 py-2 bg-gray-100 border-b border-gray-200">
                <span class="w-2.5 h-2.5 rounded-full bg-red-400"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-yellow-400"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-green-400"></span>
                <div class="flex-1 mx-2 bg-white rounded text-[10px] text-gray-400 px-2 py-0.5 border border-gray-200"><?= e(preg_replace('#^https?://#', '', rtrim(APP_URL, '/'))) ?>/admin</div>
              </div>

              <!-- App shell -->
              <div class="flex" style="height: 340px;">

                <!-- SIDEBAR PREVIEW -->
                <div id="preview-sidebar" class="flex-shrink-0 flex flex-col transition-all duration-300"
                     style="width: 72px; background: #ffffff; border-right: 1px solid #e2e8f0;">
                  <div class="flex items-center gap-1.5 px-2 py-2.5 border-b border-black/5">
                    <div id="preview-logo-icon" class="w-5 h-5 rounded flex items-center justify-center flex-shrink-0" style="background: var(--preview-brand)">
                      <svg width="10" height="10" viewBox="0 0 10 10" fill="white"><polygon points="5,1 9,8 1,8"/></svg>
                    </div>
                    <span id="preview-app-name" class="text-[9px] font-bold truncate text-gray-800" style="max-width: 44px"><?= e($s('app_name', 'STIFIn Mulia')) ?></span>
                  </div>
                  <div class="flex flex-col gap-0.5 p-1.5 flex-1">
                    <div id="preview-menu-active" class="flex items-center gap-1 px-1.5 py-1 rounded text-[8px] font-medium transition-all"
                         style="background: rgba(99,102,241,0.12); color: var(--preview-brand);">
                      <div class="w-2 h-2 rounded-sm flex-shrink-0" style="background: currentColor; opacity: 0.7"></div>
                      <span>Dashboard</span>
                    </div>
                    <div class="flex items-center gap-1 px-1.5 py-1 rounded text-[8px] text-gray-400"><div class="w-2 h-2 rounded-sm bg-gray-300 flex-shrink-0"></div><span>Users</span></div>
                    <div class="flex items-center gap-1 px-1.5 py-1 rounded text-[8px] text-gray-400"><div class="w-2 h-2 rounded-sm bg-gray-300 flex-shrink-0"></div><span>Products</span></div>
                    <div class="mt-2 px-1.5"><div class="text-[6px] font-semibold tracking-widest uppercase text-gray-400 mb-1">Konten</div></div>
                    <div class="flex items-center gap-1 px-1.5 py-1 rounded text-[8px] text-gray-400"><div class="w-2 h-2 rounded-sm bg-gray-300 flex-shrink-0"></div><span>Classes</span></div>
                    <div class="flex items-center gap-1 px-1.5 py-1 rounded text-[8px] text-gray-400"><div class="w-2 h-2 rounded-sm bg-gray-300 flex-shrink-0"></div><span>Branding</span></div>
                  </div>
                  <div class="p-1.5 border-t border-black/5">
                    <div class="flex items-center gap-1">
                      <div class="w-4 h-4 rounded-full flex-shrink-0" style="background: var(--preview-brand)"></div>
                      <span class="text-[7px] text-gray-500 truncate">Admin</span>
                    </div>
                  </div>
                </div>

                <!-- MAIN CONTENT PREVIEW -->
                <div id="preview-content" class="flex-1 flex flex-col overflow-hidden transition-all duration-300" style="background: #f9fafb;">
                  <!-- TOPBAR PREVIEW -->
                  <div id="preview-topbar" class="flex items-center justify-between px-3 flex-shrink-0 transition-all duration-300"
                       style="height: 36px; background: #ffffff; border-bottom: 1px solid #e2e8f0;">
                    <span class="text-[9px] font-semibold text-gray-700" id="preview-page-title">Dashboard</span>
                    <div class="flex items-center gap-1.5">
                      <div class="w-4 h-4 rounded flex items-center justify-center opacity-60" style="background: rgba(0,0,0,0.05)">
                        <svg width="8" height="8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M15 17H9m6 0a3 3 0 01-6 0m6 0H5.5A1.5 1.5 0 014 15.5v-.5a7 7 0 0114 0v.5a1.5 1.5 0 01-1.5 1.5H15z"/></svg>
                      </div>
                      <div class="w-4 h-4 rounded-full flex items-center justify-center text-white text-[6px] font-bold" style="background: var(--preview-brand)">A</div>
                    </div>
                  </div>
                  <!-- PAGE CONTENT PREVIEW -->
                  <div class="flex-1 p-2.5 overflow-hidden">
                    <div id="preview-stat-cards" class="grid grid-cols-3 gap-1.5 mb-2">
                      <div class="preview-stat-card h-10 flex flex-col justify-between p-1.5 transition-all duration-300" style="background:#fff;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                        <div class="text-[6px] text-gray-400">Total Users</div><div class="text-[10px] font-bold text-gray-700">1,284</div>
                      </div>
                      <div class="preview-stat-card h-10 flex flex-col justify-between p-1.5 transition-all duration-300" style="background:#fff;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                        <div class="text-[6px] text-gray-400">Revenue</div><div class="text-[10px] font-bold text-gray-700">Rp 24jt</div>
                      </div>
                      <div class="preview-stat-card h-10 flex flex-col justify-between p-1.5 transition-all duration-300" style="background:#fff;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                        <div class="text-[6px] text-gray-400">Orders</div><div class="text-[10px] font-bold text-gray-700">348</div>
                      </div>
                    </div>
                    <div class="grid grid-cols-5 gap-1.5">
                      <div class="col-span-3 preview-card transition-all duration-300" style="background:#fff;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.08);padding:8px;height:90px;">
                        <div class="text-[6px] text-gray-400 mb-1">Revenue Chart</div>
                        <div class="flex items-end gap-0.5 h-12">
                          <div class="flex-1 rounded-t-sm transition-all" style="height:40%;background:var(--preview-brand);opacity:0.3"></div>
                          <div class="flex-1 rounded-t-sm transition-all" style="height:65%;background:var(--preview-brand);opacity:0.5"></div>
                          <div class="flex-1 rounded-t-sm transition-all" style="height:50%;background:var(--preview-brand);opacity:0.4"></div>
                          <div class="flex-1 rounded-t-sm transition-all" style="height:80%;background:var(--preview-brand);opacity:0.7"></div>
                          <div class="flex-1 rounded-t-sm transition-all" style="height:60%;background:var(--preview-brand);opacity:0.5"></div>
                          <div class="flex-1 rounded-t-sm transition-all" style="height:100%;background:var(--preview-brand);"></div>
                          <div class="flex-1 rounded-t-sm transition-all" style="height:75%;background:var(--preview-brand);opacity:0.6"></div>
                        </div>
                      </div>
                      <div class="col-span-2 preview-card transition-all duration-300" style="background:#fff;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.08);padding:8px;height:90px;">
                        <div class="text-[6px] text-gray-400 mb-1.5">Recent Orders</div>
                        <div class="space-y-1">
                          <div class="flex justify-between items-center"><div class="w-12 h-1 bg-gray-200 rounded"></div><div class="w-6 h-1 rounded" style="background:var(--preview-brand);opacity:0.4"></div></div>
                          <div class="flex justify-between items-center"><div class="w-10 h-1 bg-gray-200 rounded"></div><div class="w-8 h-1 bg-green-300 rounded"></div></div>
                          <div class="flex justify-between items-center"><div class="w-14 h-1 bg-gray-200 rounded"></div><div class="w-5 h-1 bg-yellow-300 rounded"></div></div>
                          <div class="flex justify-between items-center"><div class="w-9 h-1 bg-gray-200 rounded"></div><div class="w-7 h-1 rounded" style="background:var(--preview-brand);opacity:0.4"></div></div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

              </div>
            </div>

            <!-- Color swatch info -->
            <div class="flex items-center gap-3 mt-3">
              <div class="flex items-center gap-1.5">
                <div id="preview-swatch-primary" class="w-4 h-4 rounded-full border-2 border-white shadow" style="background: <?= e($s('brand_color', '#6366f1')) ?>"></div>
                <span id="preview-hex-primary" class="text-xs text-gray-500 font-mono"><?= e($s('brand_color', '#6366f1')) ?></span>
              </div>
              <div class="flex items-center gap-1.5">
                <div id="preview-swatch-secondary" class="w-4 h-4 rounded-full border-2 border-white shadow" style="background: <?= e($s('brand_color_secondary', '#818cf8')) ?>"></div>
                <span id="preview-hex-secondary" class="text-xs text-gray-500 font-mono"><?= e($s('brand_color_secondary', '#818cf8')) ?></span>
              </div>
              <div class="flex items-center gap-1.5 ml-auto">
                <span class="text-[10px] text-gray-400" id="preview-theme-label">Theme: Default</span>
              </div>
            </div>
          </div>
        </div>

      </div>
    </main>
  </div>
</div>

<script>
// ── Live Preview ─────────────────────────────────────────────
const root = document.documentElement;

function applyBrandColor(hex) {
  root.style.setProperty('--brand-color', hex);
  // Swatch lama (opsional) — preview swatch kini dihandle Live Preview Engine.
  var _sp = document.getElementById('swatchPrimary'); if (_sp) _sp.style.backgroundColor = hex;
  var _lp = document.getElementById('labelPrimary'); if (_lp) _lp.textContent = hex.toUpperCase();
}

function applySecondaryColor(hex) {
  root.style.setProperty('--brand-color-light', hex);
  var _ss = document.getElementById('swatchSecondary'); if (_ss) _ss.style.backgroundColor = hex;
  var _ls = document.getElementById('labelSecondary'); if (_ls) _ls.textContent = hex.toUpperCase();
}

document.getElementById('colorPrimary').addEventListener('input', function() {
  const hex = this.value;
  document.getElementById('hexPrimary').value = hex.toUpperCase();
  applyBrandColor(hex);
});

document.getElementById('colorSecondary').addEventListener('input', function() {
  const hex = this.value;
  document.getElementById('hexSecondary').value = hex.toUpperCase();
  applySecondaryColor(hex);
});

document.getElementById('inputAppName').addEventListener('input', function() {
  // Nama app di preview kini dihandle Live Preview Engine (#preview-app-name).
  var _pn = document.getElementById('previewAppName'); if (_pn) _pn.textContent = this.value || 'App';
});

function syncColorFromHex(colorId, hexId, fieldName) {
  const hex = document.getElementById(hexId).value.trim();
  if (/^#[0-9A-Fa-f]{6}$/.test(hex)) {
    document.getElementById(colorId).value = hex;
    document.querySelector(`[name="${fieldName}"]`).value = hex;
    if (colorId === 'colorPrimary') applyBrandColor(hex);
    else applySecondaryColor(hex);
  }
}

function applyPreset(primary, secondary) {
  document.getElementById('colorPrimary').value    = primary;
  document.getElementById('hexPrimary').value      = primary.toUpperCase();
  document.querySelector('[name="settings[brand_color]"]').value = primary;

  document.getElementById('colorSecondary').value  = secondary;
  document.getElementById('hexSecondary').value    = secondary.toUpperCase();
  document.querySelector('[name="settings[brand_color_secondary]"]').value = secondary;

  applyBrandColor(primary);
  applySecondaryColor(secondary);
}

// ── File Preview ─────────────────────────────────────────────
function previewImage(input, previewId) {
  const preview = document.getElementById(previewId);
  if (input.files && input.files[0]) {
    const file = input.files[0];

    // Cegah ERR_UPLOAD_FILE_CHANGED (umum di Android): salin byte file ke File
    // in-memory saat dipilih lalu pasang kembali ke input, agar submit tak membaca
    // ulang dari disk (file dari Google Photos/galeri cloud/Drive bisa "berubah atau
    // pindah" antara dipilih dan disubmit → Chrome membatalkan upload). Fallback aman
    // ke file asli bila browser lama/gagal. Pola sama dengan upload thumbnail produk.
    if (typeof file.arrayBuffer === 'function' && 'DataTransfer' in window) {
      file.arrayBuffer().then(function (buf) {
        try {
          const snap = new File([buf], file.name || 'upload',
            { type: file.type, lastModified: Date.now() });
          const dt = new DataTransfer();
          dt.items.add(snap);
          input.files = dt.files; // mengganti files TIDAK memicu ulang onchange
        } catch (ex) { /* fallback: biarkan file asli */ }
      }).catch(function () { /* fallback: biarkan file asli */ });
    }

    const reader = new FileReader();
    reader.onload = e => {
      preview.innerHTML = `<img src="${e.target.result}" class="max-w-full max-h-full object-contain">`;
    };
    reader.readAsDataURL(file);
  }
}

// ── Appearance: live preview UI Theme + Sidebar Style ────────────
(function () {
  var html = document.documentElement;
  var THEME_CLASSES = ['theme-airy', 'theme-corporate', 'theme-minimal', 'theme-noir', 'theme-frost', 'theme-emerald'];
  var SIDEBAR_CLASSES = ['sidebar-light', 'sidebar-dark', 'sidebar-colored'];

  // Pastikan konteks dashboard ter-theme walau (teoritis) class belum ada.
  if (!html.classList.contains('dash-themed')) html.classList.add('dash-themed');

  function selectCard(group, el) {
    group.forEach(function (c) {
      c.classList.remove('border-[var(--brand-color)]', 'ring-2', 'ring-[var(--brand-color)]/20');
      c.classList.add('border-gray-200', 'dark:border-gray-700');
    });
    el.classList.remove('border-gray-200', 'dark:border-gray-700');
    el.classList.add('border-[var(--brand-color)]', 'ring-2', 'ring-[var(--brand-color)]/20');
  }

  var themeCards = Array.prototype.slice.call(document.querySelectorAll('.theme-card'));
  themeCards.forEach(function (card) {
    card.addEventListener('click', function () {
      document.getElementById('input_ui_theme').value = card.dataset.theme;
      // Tukar class theme di <html>
      THEME_CLASSES.forEach(function (c) { html.classList.remove(c); });
      if (card.dataset.previewClass) html.classList.add(card.dataset.previewClass);
      // Update CSS variable agar bg-canvas & card langsung berubah
      html.style.setProperty('--bg-canvas', card.dataset.bg);
      html.style.setProperty('--card-radius', card.dataset.radius);
      html.style.setProperty('--card-shadow', card.dataset.shadow);
      html.style.setProperty('--sidebar-radius', card.dataset.sidebarRadius);
      selectCard(themeCards, card);

      // JUGA update Preview objek state!
      Preview.state.theme = card.dataset.theme;
      Preview.render();
    });
  });

  var sidebarBtns = Array.prototype.slice.call(document.querySelectorAll('.sidebar-style-btn'));
  sidebarBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('input_ui_sidebar_style').value = btn.dataset.sidebar;
      SIDEBAR_CLASSES.forEach(function (c) { html.classList.remove(c); });
      html.classList.add('sidebar-' + btn.dataset.sidebar);
      selectCard(sidebarBtns, btn);

      // JUGA update Preview objek state!
      Preview.state.sidebarStyle = btn.dataset.sidebar;
      Preview.render();
    });
  });

  // ── Topbar Style: live preview pada #topbar-main ──
  // Map ini mirror getTopbarClasses() di helpers/theme-config.php.
  var TOPBAR_MAP = {
    'default':  { cls: 'bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 text-gray-800 dark:text-gray-100', style: '' },
    'colored':  { cls: 'border-b border-white/10 text-white shadow-md', style: 'background-color: var(--brand-color);' },
    'dark':     { cls: 'bg-gray-900 border-b border-gray-700 text-gray-100', style: '' },
    'floating': { cls: 'text-gray-800 dark:text-gray-100', style: '' }
  };
  var topbarEl   = document.getElementById('topbar-main');
  var topbarBtns = Array.prototype.slice.call(document.querySelectorAll('.topbar-style-btn'));
  function applyTopbar(style) {
    if (!topbarEl) return;
    var m = TOPBAR_MAP[style] || TOPBAR_MAP['default'];
    topbarEl.className = 'topbar topbar-' + style + ' shrink-0 h-14 flex items-center justify-between px-4 gap-4 z-20 ' + m.cls;
    if (m.style) { topbarEl.setAttribute('style', m.style); } else { topbarEl.removeAttribute('style'); }
  }
  topbarBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('input_ui_topbar_style').value = btn.dataset.topbar;
      applyTopbar(btn.dataset.topbar);
      selectCard(topbarBtns, btn);

      // JUGA update Preview objek state!
      Preview.state.topbarStyle = btn.dataset.topbar;
      Preview.render();
    });
  });
})();
</script>

<!-- ════════ Live Preview Engine (mockup di kanan) ════════ -->
<?php
// P1 — Sumber tunggal untuk Live Preview: dibangun dari getAllThemePresets().
// Menambah theme baru cukup di helpers/theme-config.php; preview ikut otomatis
// (dulu themeMap di-hardcode di JS → rawan melenceng dari preset).
$themeMapForJs = [];
foreach ((function_exists('getAllThemePresets') ? getAllThemePresets() : []) as $tk => $tp) {
    $entry = [
        'bg'         => $tp['--bg-canvas']   ?? '#f9fafb',
        'cardRadius' => $tp['--card-radius'] ?? '0.5rem',
        'cardShadow' => $tp['--card-shadow'] ?? '0 1px 3px rgba(0,0,0,0.08)',
        'cardStyle'  => $tp['preview_style'] ?? ((($tp['--card-style'] ?? 'flat') === 'gradient') ? 'gradient' : 'flat'),
        'label'      => $tp['label'] ?? $tk,
    ];
    if (!empty($tp['preview_gradients'])) $entry['gradients'] = $tp['preview_gradients'];
    $themeMapForJs[$tk] = $entry;
}
?>
<script>
const Preview = {
  state: {
    brandColor:     '<?= e($s('brand_color', '#6366f1')) ?>',
    brandSecondary: '<?= e($s('brand_color_secondary', '#818cf8')) ?>',
    appName:        '<?= e($s('app_name', 'STIFIn Mulia')) ?>',
    theme:          '<?= e($s('ui_theme', 'default')) ?>',
    sidebarStyle:   '<?= e($s('ui_sidebar_style', 'light')) ?>',
    topbarStyle:    '<?= e($s('ui_topbar_style', 'default')) ?>',
  },
  // cardStyle: 'hero' = kartu-1 gradien brand + sisanya netral (Airy pasca
  // Premium Polish); 'gradient' = semua kartu warna-warni (Vivid); 'flat'.
  // Radius mengikuti bahasa kotak-tegas (--card-radius preset sebenarnya).
  // P1: peta theme = sumber tunggal dari getAllThemePresets() (lihat blok PHP di atas).
  themeMap: <?= json_encode($themeMapForJs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  sidebarMap: {
    light:   { bg: '#ffffff', border: '1px solid #e2e8f0', text: '#1e293b', activeText: null  },
    dark:    { bg: '#0f172a', border: 'none',              text: '#94a3b8', activeText: '#ffffff' },
    colored: { bg: null,      border: 'none',              text: 'rgba(255,255,255,0.8)', activeText: '#ffffff' },
  },
  topbarMap: {
    default:  { bg: '#ffffff', border: '1px solid #e2e8f0', text: '#374151', shadow: 'none' },
    colored:  { bg: null,      border: '1px solid rgba(255,255,255,0.15)', text: '#ffffff', shadow: '0 2px 8px rgba(0,0,0,0.15)' },
    dark:     { bg: '#111827', border: 'none', text: '#e2e8f0', shadow: 'none' },
    floating: { bg: 'rgba(255,255,255,0.7)', border: 'none', text: '#374151', shadow: '0 4px 16px -6px rgba(0,0,0,0.18)' },
  },
  gradientCards: [
    'linear-gradient(135deg, #6ee7f7, #60a5fa)',
    'linear-gradient(135deg, #a78bfa, #818cf8)',
    'linear-gradient(135deg, #f9a8d4, #f472b6)',
  ],
  render() {
    this.updateBrandColor();
    this.updateAppName();
    this.updateTheme();
    this.updateSidebar();
    this.updateTopbar();
    this.updateSwatches();
  },
  updateBrandColor() {
    const color = this.state.brandColor;
    const shell = document.getElementById('branding-preview-shell');
    if (shell) shell.style.setProperty('--preview-brand', color);
    const activeMenu = document.getElementById('preview-menu-active');
    // Hanya pewarnai active menu via brand bila sidebar tidak dark/colored.
    if (activeMenu && this.state.sidebarStyle === 'light') {
      activeMenu.style.color = color;
      activeMenu.style.background = color + '1f';
    }
  },
  updateAppName() {
    const el = document.getElementById('preview-app-name');
    if (el) el.textContent = this.state.appName || 'Averion';
  },
  updateTheme() {
    const cfg = this.themeMap[this.state.theme] || this.themeMap.default;
    const content = document.getElementById('preview-content');
    if (content) content.style.background = cfg.bg;
    const gradients = this.gradientCards;
    const self = this;
    document.querySelectorAll('.preview-stat-card').forEach(function (card, i) {
      // Reset residual styles from previous theme renders
      card.style.borderBottom = '';
      card.style.borderLeft = '';
      card.style.borderTop = '';
      card.style.backdropFilter = '';
      card.style.webkitBackdropFilter = '';
      if (cfg.cardStyle === 'gradient' || cfg.cardStyle === 'gradient-warm') {
        // Vivid/Sunset: semua kartu gradient warna-warni (set warna per-theme
        // via cfg.gradients bila ada, kalau tidak pakai default global).
        var grads = (cfg.gradients && cfg.gradients.length) ? cfg.gradients : gradients;
        card.style.background = grads[i] || grads[0];
        card.style.border = 'none';
        card.querySelectorAll('div').forEach(function (d) { d.style.color = 'rgba(255,255,255,0.9)'; });
      } else if (cfg.cardStyle === 'accent-left') {
        // Slate: kartu putih + garis brand kiri, teks gelap.
        card.style.background = '#ffffff';
        card.style.border = '1px solid #e2e8f0';
        card.style.borderLeft = '3px solid ' + self.state.brandColor;
        card.querySelectorAll('div').forEach(function (d) { d.style.color = ''; });
      } else if (cfg.cardStyle === 'accent-top') {
        // Ink Mono: kartu putih + garis brand atas, hairline border.
        card.style.background = '#ffffff';
        card.style.border = '1px solid #ececec';
        card.style.borderTop = '2px solid ' + self.state.brandColor;
        card.querySelectorAll('div').forEach(function (d) { d.style.color = ''; });
      } else if (cfg.cardStyle === 'hero' && i === 0) {
        // Airy / Noir: HANYA kartu pertama bergradien warna brand.
        card.style.background = 'linear-gradient(135deg, ' + self.state.brandColor + ', color-mix(in srgb, ' + self.state.brandColor + ' 72%, #000))';
        card.style.border = 'none';
        card.querySelectorAll('div').forEach(function (d) { d.style.color = 'rgba(255,255,255,0.9)'; });
      } else if (cfg.cardStyle === 'frost') {
        // Frost: Glassmorphism translucent
        card.style.background = 'rgba(255, 255, 255, 0.45)';
        card.style.border = '1px solid rgba(255, 255, 255, 0.5)';
        card.style.backdropFilter = 'blur(8px)';
        card.querySelectorAll('div').forEach(function (d) { d.style.color = ''; });
      } else if (cfg.cardStyle === 'emerald') {
        // Emerald Luxe: per-card identity — emerald hero, gold, mint, charcoal
        var emeraldStyles = [
          { bg: 'linear-gradient(135deg, #059669, #065f46)', border: 'none', textColor: 'rgba(255,255,255,0.95)' },
          { bg: 'linear-gradient(135deg, #fffbeb, #fef3c7)', border: '1px solid rgba(217,119,6,0.12)', textColor: '', borderBottom: '3px solid #d97706' },
          { bg: 'linear-gradient(135deg, #ecfdf5, #d1fae5)', border: '1px solid rgba(16,185,129,0.15)', textColor: '', borderBottom: '3px solid #10b981' },
          { bg: 'linear-gradient(135deg, #1a2e23, #0f2318)', border: '1px solid rgba(16,185,129,0.2)', textColor: 'rgba(255,255,255,0.9)', borderBottom: '3px solid #10b981' },
        ];
        var es = emeraldStyles[i] || emeraldStyles[0];
        card.style.background = es.bg;
        card.style.border = es.border;
        if (es.borderBottom) card.style.borderBottom = es.borderBottom;
        card.querySelectorAll('div').forEach(function (d) { d.style.color = es.textColor; });
      } else {
        card.style.background = '#ffffff';
        card.style.border = (self.state.theme === 'corporate' || cfg.cardStyle === 'hero') ? '1px solid #e2e8f0' : 'none';
        card.querySelectorAll('div').forEach(function (d) { d.style.color = ''; });
      }
      card.style.borderRadius = cfg.cardRadius;
      card.style.boxShadow = cfg.cardShadow;
    });
    document.querySelectorAll('.preview-card').forEach(function (card) {
      card.style.borderRadius = cfg.cardRadius;
      card.style.boxShadow = cfg.cardShadow;
      card.style.border = (self.state.theme === 'corporate') ? '1px solid #e2e8f0' : 'none';
    });
    const label = document.getElementById('preview-theme-label');
    if (label) label.textContent = 'Theme: ' + cfg.label;
  },
  updateSidebar() {
    const sidebar = document.getElementById('preview-sidebar');
    if (!sidebar) return;
    const style = this.state.sidebarStyle;
    const cfg = this.sidebarMap[style] || this.sidebarMap.light;
    const dark = (style === 'dark' || style === 'colored');
    sidebar.style.background = (style === 'colored') ? this.state.brandColor : cfg.bg;
    sidebar.style.borderRight = cfg.border;
    sidebar.querySelectorAll('.text-gray-400, .text-gray-500, .text-gray-800').forEach(function (el) {
      el.style.color = dark ? 'rgba(255,255,255,0.6)' : '';
    });
    sidebar.querySelectorAll('.border-t, .border-b').forEach(function (el) {
      el.style.borderColor = dark ? 'rgba(255,255,255,0.12)' : '';
    });
    const appName = document.getElementById('preview-app-name');
    if (appName) appName.style.color = dark ? '#ffffff' : '#1f2937';
    // Active menu di sidebar gelap/colored → putih, bukan brand.
    const activeMenu = document.getElementById('preview-menu-active');
    if (activeMenu) {
      if (dark) {
        activeMenu.style.color = '#ffffff';
        activeMenu.style.background = 'rgba(255,255,255,0.15)';
      } else {
        activeMenu.style.color = this.state.brandColor;
        activeMenu.style.background = this.state.brandColor + '1f';
      }
    }
  },
  updateTopbar() {
    const topbar = document.getElementById('preview-topbar');
    const pageTitle = document.getElementById('preview-page-title');
    if (!topbar) return;
    const style = this.state.topbarStyle;
    const cfg = this.topbarMap[style] || this.topbarMap.default;
    topbar.style.background = (style === 'colored') ? this.state.brandColor : cfg.bg;
    topbar.style.borderBottom = cfg.border;
    topbar.style.boxShadow = cfg.shadow;
    if (style === 'floating') {
      topbar.style.margin = '6px 6px 0 6px';
      topbar.style.borderRadius = '8px';
      topbar.style.backdropFilter = 'blur(6px)';
      topbar.style.webkitBackdropFilter = 'blur(6px)';
    } else {
      topbar.style.margin = '0';
      topbar.style.borderRadius = '0';
      topbar.style.backdropFilter = '';
      topbar.style.webkitBackdropFilter = '';
    }
    if (pageTitle) pageTitle.style.color = (style === 'colored' || style === 'dark') ? '#ffffff' : '#374151';
  },
  updateSwatches() {
    const p = document.getElementById('preview-swatch-primary');
    const s = document.getElementById('preview-swatch-secondary');
    const ph = document.getElementById('preview-hex-primary');
    const sh = document.getElementById('preview-hex-secondary');
    if (p) p.style.background = this.state.brandColor;
    if (s) s.style.background = this.state.brandSecondary;
    if (ph) ph.textContent = this.state.brandColor;
    if (sh) sh.textContent = this.state.brandSecondary;
  }
};

// ── Sambungkan input yang ADA di halaman (ID existing) ke Preview ──
(function () {
  var colorInput = document.getElementById('colorPrimary');
  var colorHex   = document.getElementById('hexPrimary');
  var colorSec   = document.getElementById('colorSecondary');
  var colorSecHex= document.getElementById('hexSecondary');
  var appName    = document.getElementById('inputAppName');

  if (colorInput) colorInput.addEventListener('input', function () { Preview.state.brandColor = this.value; Preview.render(); });
  if (colorHex)   colorHex.addEventListener('input', function () {
    if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) { Preview.state.brandColor = this.value; Preview.render(); }
  });
  if (colorSec)   colorSec.addEventListener('input', function () { Preview.state.brandSecondary = this.value; Preview.render(); });
  if (colorSecHex)colorSecHex.addEventListener('input', function () {
    if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) { Preview.state.brandSecondary = this.value; Preview.render(); }
  });
  if (appName)    appName.addEventListener('input', function () { Preview.state.appName = this.value; Preview.render(); });

  // Preset warna: tombol memakai onclick="applyPreset('#xxx','#yyy')"
  document.querySelectorAll('button[onclick^="applyPreset"]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var m = (this.getAttribute('onclick') || '').match(/applyPreset\('(#[0-9A-Fa-f]{6})'\s*,\s*'(#[0-9A-Fa-f]{6})'\)/);
      if (m) { Preview.state.brandColor = m[1]; Preview.state.brandSecondary = m[2]; Preview.render(); }
    });
  });

  // Theme / Sidebar / Topbar (kelas + data-attribute existing)
  document.querySelectorAll('.theme-card').forEach(function (c) {
    c.addEventListener('click', function () { Preview.state.theme = this.dataset.theme; Preview.render(); });
  });
  document.querySelectorAll('.sidebar-style-btn').forEach(function (b) {
    b.addEventListener('click', function () { Preview.state.sidebarStyle = this.dataset.sidebar; Preview.render(); });
  });
  document.querySelectorAll('.topbar-style-btn').forEach(function (b) {
    b.addEventListener('click', function () { Preview.state.topbarStyle = this.dataset.topbar; Preview.render(); });
  });

  Preview.render(); // render awal sesuai setting tersimpan
})();
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

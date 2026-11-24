<?php
// ─── Halaman akses mode "Embed langsung" (produk / lead magnet) ──────────
// Dipanggil dari class-detail.php saat $product['access_type'] === 'embed'.
// Memakai: $product (name, short_description, embed_html, embed_url), $previewMode.
// Layout STATIS full-height: embed mengisi sisa viewport, tanpa scroll halaman
// (scroll internal ada di dalam iframe bila kontennya panjang). HTML menang atas URL.
if (!isset($product) || !is_array($product)) { redirect('/member'); }

$embedHtml = trim((string) ($product['embed_html'] ?? ''));
$embedUrl  = trim((string) ($product['embed_url'] ?? ''));
$backUrl   = !empty($previewMode) ? '/admin/content' : '/member';

$pageTitle   = $product['name'];
$topbarTitle = $product['name'];

include __DIR__ . '/../../partials/head.php';
?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 flex flex-col overflow-hidden">

      <!-- Header ramping (tidak ikut scroll) -->
      <div class="flex items-center gap-3 px-4 lg:px-6 h-12 shrink-0 border-b border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900">
        <a href="<?= e(APP_URL) ?><?= e($backUrl) ?>"
           class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400 hover:text-[var(--brand-color)] transition shrink-0">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
          <span class="hidden sm:inline">Kembali</span>
        </a>
        <span class="w-px h-4 bg-gray-200 dark:bg-gray-700 shrink-0"></span>
        <h1 class="text-sm font-semibold text-gray-900 dark:text-white truncate"><?= e($product['name']) ?></h1>
        <?php if (!empty($previewMode)): ?>
          <span class="ml-auto shrink-0 inline-flex items-center gap-1 text-[11px] font-medium px-2 py-1 rounded-md"
                style="background:rgba(245,158,11,.12);color:#b45309;border:1px solid rgba(245,158,11,.28)">
            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            Pratinjau
          </span>
        <?php endif; ?>
      </div>

      <?php if ($embedHtml !== ''): ?>
        <!-- HTML dari admin: sandbox TANPA allow-same-origin → tak bisa menyentuh sesi member. -->
        <iframe sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation allow-modals allow-downloads"
                srcdoc="<?= e($embedHtml) ?>"
                class="flex-1 w-full block" style="border:0" title="<?= e($product['name']) ?>"></iframe>
      <?php elseif ($embedUrl !== ''): ?>
        <!-- URL tool eksternal: iframe biasa (origin situs itu sendiri). -->
        <iframe sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation allow-modals allow-downloads"
                src="<?= e($embedUrl) ?>"
                class="flex-1 w-full block" style="border:0" title="<?= e($product['name']) ?>"
                referrerpolicy="no-referrer"></iframe>
      <?php else: ?>
        <div class="flex-1 flex items-center justify-center p-10">
          <div class="text-center">
            <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mx-auto mb-3">
              <svg class="w-6 h-6 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
            </div>
            <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Konten belum tersedia</p>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Admin belum mengisi konten embed untuk produk ini.</p>
          </div>
        </div>
      <?php endif; ?>
    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

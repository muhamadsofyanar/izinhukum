<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Checkout Templates';
$topbarTitle = 'Checkout Templates';

$templates = [];
try {
    $templates = getDB()->query(
        "SELECT * FROM checkout_templates ORDER BY is_default DESC, id ASC"
    )->fetchAll();
} catch (Exception $e) {
    error_log('Checkout templates list error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data template.', 'error');
}

$layoutLabels = [
    'standard'       => 'Standard (2 Kolom)',
    'minimal'        => 'Minimal (1 Kolom)',
    'two_step'       => 'Two-Step (Wizard)',
    'sidebar_sticky' => 'Sidebar Sticky',
];

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

      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Checkout Templates</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Desain tampilan form checkout untuk setiap produk</p>
        </div>
        <button onclick="openModal()"
                class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand transition-opacity hover:opacity-90">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          Tambah Template
        </button>
      </div>

      <!-- Template Cards -->
      <?php if (empty($templates)): ?>
      <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm py-16 text-center">
        <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <rect x="2" y="3" width="20" height="18" rx="2"/>
          <path d="M8 3v18M16 9h2M16 13h2M16 17h2"/>
        </svg>
        <p class="text-sm font-medium text-gray-400 dark:text-gray-600">Belum ada template checkout</p>
        <button onclick="openModal()" class="mt-3 text-sm text-[var(--brand-color)] hover:underline font-medium">Buat template pertama →</button>
      </div>
      <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php foreach ($templates as $tpl): ?>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">

          <!-- Visual preview strip -->
          <div class="h-28 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-800 dark:to-gray-800/50 relative p-3 flex items-center justify-center">
            <?php if ($tpl['layout_type'] === 'standard'): ?>
            <!-- Standard: 3:2 grid mockup -->
            <div class="flex gap-2 w-full max-w-[160px]">
              <div class="flex-[3] space-y-1.5">
                <div class="h-2.5 bg-gray-200 dark:bg-gray-700 rounded w-full"></div>
                <div class="h-2.5 bg-gray-200 dark:bg-gray-700 rounded w-4/5"></div>
                <div class="h-6 bg-gray-200 dark:bg-gray-700 rounded w-full mt-2"></div>
                <div class="h-2.5 bg-[var(--brand-color)] rounded w-full opacity-70"></div>
              </div>
              <div class="flex-[2] bg-gray-200 dark:bg-gray-700 rounded-lg"></div>
            </div>
            <?php elseif ($tpl['layout_type'] === 'minimal'): ?>
            <!-- Minimal: single column centered -->
            <div class="w-28 space-y-1.5">
              <div class="h-5 bg-[var(--brand-color)] rounded opacity-70 w-1/2 mx-auto"></div>
              <div class="h-2 bg-gray-200 dark:bg-gray-700 rounded w-full"></div>
              <div class="h-2 bg-gray-200 dark:bg-gray-700 rounded w-4/5"></div>
              <div class="h-5 bg-[var(--brand-color)] rounded w-full mt-1"></div>
            </div>
            <?php elseif ($tpl['layout_type'] === 'two_step'): ?>
            <!-- Two-step: step indicator + form -->
            <div class="w-36 space-y-2">
              <div class="flex gap-1 items-center">
                <div class="w-5 h-5 rounded-full bg-[var(--brand-color)] flex items-center justify-center">
                  <div class="w-1.5 h-1.5 bg-white rounded-full"></div>
                </div>
                <div class="flex-1 h-px bg-gray-200 dark:bg-gray-700"></div>
                <div class="w-5 h-5 rounded-full bg-gray-200 dark:bg-gray-700"></div>
              </div>
              <div class="h-2 bg-gray-200 dark:bg-gray-700 rounded w-full"></div>
              <div class="h-2 bg-gray-200 dark:bg-gray-700 rounded w-3/4"></div>
              <div class="h-4 bg-[var(--brand-color)] rounded w-24 opacity-70"></div>
            </div>
            <?php else: ?>
            <!-- Sidebar Sticky: 60/40 layout -->
            <div class="flex gap-2 w-full max-w-[180px]">
              <div class="flex-[3] space-y-1.5">
                <div class="h-2.5 bg-gray-200 dark:bg-gray-700 rounded w-full"></div>
                <div class="h-2.5 bg-gray-200 dark:bg-gray-700 rounded w-4/5"></div>
                <div class="h-2.5 bg-gray-200 dark:bg-gray-700 rounded w-full"></div>
                <div class="h-4 bg-[var(--brand-color)] rounded w-full mt-2 opacity-70"></div>
              </div>
              <div class="flex-[2] bg-gray-100 dark:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600 p-1.5 space-y-1">
                <div class="h-1.5 bg-gray-300 dark:bg-gray-600 rounded w-full"></div>
                <div class="h-1.5 bg-gray-300 dark:bg-gray-600 rounded w-3/4"></div>
                <div class="h-3 bg-[var(--brand-color)] rounded w-full opacity-50 mt-1"></div>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($tpl['is_default']): ?>
            <span class="absolute top-2 right-2 px-2 py-0.5 rounded-full text-xs font-bold bg-[var(--brand-color)] text-white shadow-sm">Default</span>
            <?php endif; ?>
          </div>

          <div class="p-4">
            <div class="flex items-start justify-between gap-2 mb-2">
              <div class="min-w-0">
                <h3 class="font-semibold text-gray-900 dark:text-white text-sm truncate"><?= e($tpl['name']) ?></h3>
                <span class="inline-flex mt-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
                  <?= e($layoutLabels[$tpl['layout_type']] ?? $tpl['layout_type']) ?>
                </span>
              </div>
            </div>

            <!-- Field toggles -->
            <div class="flex items-center gap-2 flex-wrap mt-3 mb-4">
              <?php if ($tpl['show_phone']): ?>
              <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400">
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                No. HP
              </span>
              <?php endif; ?>
              <?php if ($tpl['show_address']): ?>
              <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400">
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Alamat
              </span>
              <?php endif; ?>
              <?php if ($tpl['show_notes']): ?>
              <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400">
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Catatan
              </span>
              <?php endif; ?>
            </div>

            <div class="flex items-center gap-2">
              <button onclick='openModal(<?= htmlspecialchars(json_encode($tpl), ENT_QUOTES) ?>)'
                      class="flex-1 px-3 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                Edit
              </button>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-checkout-template" id="del-tpl-<?= (int)$tpl['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
                <button type="button"
                        onclick="confirmAndSubmit('del-tpl-<?= (int)$tpl['id'] ?>',{title:'Hapus Template?',message:'Template ini akan dihapus. Produk yang menggunakannya akan kembali ke template default.',confirmText:'Ya, Hapus',variant:'danger'})"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition"
                        <?= $tpl['is_default'] ? 'disabled title="Tidak dapat menghapus template default"' : '' ?>>
                  Hapus
                </button>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>

<!-- ═══ MODAL TEMPLATE ═══ -->
<div id="tplModal" class="fixed inset-0 z-50 hidden">
  <div class="flex min-h-screen items-start justify-center px-4 pt-4 pb-10">
    <div onclick="closeModal()" class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xl border border-gray-200 dark:border-gray-700 my-4 flex flex-col" style="max-height:90vh">

      <!-- Sticky Header -->
      <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 dark:border-gray-800 shrink-0">
        <div>
          <h3 id="modalTitle" class="text-sm font-bold text-gray-900 dark:text-white">Tambah Template</h3>
          <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Konfigurasikan tampilan form checkout</p>
        </div>
        <button onclick="closeModal()" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>

      <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-checkout-template" class="flex flex-col flex-1 min-h-0">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="template_id" id="tplId" value="">

        <div class="overflow-y-auto flex-1 p-5 space-y-5">

          <!-- Name -->
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Nama Template <span class="text-red-500">*</span></label>
            <input type="text" name="name" id="tplName" required maxlength="100"
                   class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                   placeholder="Nama template checkout...">
          </div>

          <!-- Layout Type — visual card selector -->
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">Varian Layout <span class="text-red-500">*</span></label>
            <div class="grid grid-cols-2 gap-2">

              <!-- Standard -->
              <label class="flex flex-col gap-2 p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
                <input type="radio" name="layout_type" value="standard" class="sr-only" checked id="lt_standard">
                <div class="flex gap-1.5 h-12 items-stretch">
                  <div class="flex-[3] flex flex-col justify-between py-1">
                    <div class="h-1.5 bg-gray-200 dark:bg-gray-600 rounded"></div>
                    <div class="h-1.5 bg-gray-200 dark:bg-gray-600 rounded w-3/4"></div>
                    <div class="h-3 bg-[var(--brand-color)] rounded opacity-60"></div>
                  </div>
                  <div class="flex-[2] bg-gray-100 dark:bg-gray-700 rounded"></div>
                </div>
                <div>
                  <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">Standard</p>
                  <p class="text-[10px] text-gray-400 dark:text-gray-500 leading-tight">Form kiri, ringkasan kanan</p>
                </div>
              </label>

              <!-- Minimal -->
              <label class="flex flex-col gap-2 p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
                <input type="radio" name="layout_type" value="minimal" class="sr-only" id="lt_minimal">
                <div class="h-12 flex flex-col justify-center items-center gap-1">
                  <div class="h-3 bg-[var(--brand-color)] rounded w-16 opacity-70"></div>
                  <div class="h-1.5 bg-gray-200 dark:bg-gray-600 rounded w-full"></div>
                  <div class="h-3 bg-gray-200 dark:bg-gray-600 rounded w-full"></div>
                </div>
                <div>
                  <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">Minimal</p>
                  <p class="text-[10px] text-gray-400 dark:text-gray-500 leading-tight">1 kolom, harga besar di atas</p>
                </div>
              </label>

              <!-- Two Step -->
              <label class="flex flex-col gap-2 p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
                <input type="radio" name="layout_type" value="two_step" class="sr-only" id="lt_two_step">
                <div class="h-12 flex flex-col justify-center gap-1">
                  <div class="flex gap-1 items-center">
                    <div class="w-4 h-4 rounded-full bg-[var(--brand-color)]"></div>
                    <div class="flex-1 h-px bg-gray-200 dark:bg-gray-600"></div>
                    <div class="w-4 h-4 rounded-full bg-gray-200 dark:bg-gray-600"></div>
                  </div>
                  <div class="h-1.5 bg-gray-200 dark:bg-gray-600 rounded w-3/4"></div>
                  <div class="h-3 bg-[var(--brand-color)] rounded w-20 opacity-60"></div>
                </div>
                <div>
                  <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">Two-Step</p>
                  <p class="text-[10px] text-gray-400 dark:text-gray-500 leading-tight">Wizard 2 langkah</p>
                </div>
              </label>

              <!-- Sidebar Sticky -->
              <label class="flex flex-col gap-2 p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
                <input type="radio" name="layout_type" value="sidebar_sticky" class="sr-only" id="lt_sidebar_sticky">
                <div class="flex gap-1.5 h-12 items-stretch">
                  <div class="flex-[3] flex flex-col justify-between py-1">
                    <div class="h-1.5 bg-gray-200 dark:bg-gray-600 rounded"></div>
                    <div class="h-1.5 bg-gray-200 dark:bg-gray-600 rounded w-4/5"></div>
                    <div class="h-1.5 bg-gray-200 dark:bg-gray-600 rounded"></div>
                    <div class="h-3 bg-[var(--brand-color)] rounded opacity-60"></div>
                  </div>
                  <div class="flex-[2] bg-gray-100 dark:bg-gray-700 rounded border border-gray-200 dark:border-gray-600 p-1 flex flex-col gap-1">
                    <div class="h-1 bg-gray-300 dark:bg-gray-600 rounded"></div>
                    <div class="h-1 bg-gray-300 dark:bg-gray-600 rounded w-3/4"></div>
                    <div class="flex-1 bg-[var(--brand-color)] rounded opacity-40 mt-auto"></div>
                  </div>
                </div>
                <div>
                  <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">Sidebar Sticky</p>
                  <p class="text-[10px] text-gray-400 dark:text-gray-500 leading-tight">60/40, ringkasan sticky</p>
                </div>
              </label>

            </div>
          </div>

          <!-- Optional Fields -->
          <div class="bg-gray-50 dark:bg-gray-800/40 rounded-xl p-4 space-y-3">
            <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Field Opsional pada Form</p>

            <label class="flex flex-wrap items-center justify-between gap-3 cursor-pointer">
              <div>
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">No. Handphone</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">Tampilkan field nomor HP</p>
              </div>
              <input type="checkbox" name="show_phone" id="tplShowPhone" value="1"
                     class="w-4 h-4 rounded accent-[var(--brand-color)]">
            </label>

            <label class="flex flex-wrap items-center justify-between gap-3 cursor-pointer">
              <div>
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Alamat Pengiriman</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">Tampilkan field alamat (untuk produk fisik)</p>
              </div>
              <input type="checkbox" name="show_address" id="tplShowAddress" value="1"
                     class="w-4 h-4 rounded accent-[var(--brand-color)]">
            </label>

            <label class="flex flex-wrap items-center justify-between gap-3 cursor-pointer">
              <div>
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Catatan Pembelian</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">Tampilkan textarea catatan untuk admin</p>
              </div>
              <input type="checkbox" name="show_notes" id="tplShowNotes" value="1" checked
                     class="w-4 h-4 rounded accent-[var(--brand-color)]">
            </label>

          </div>

          <!-- Footer Badge "Powered by" -->
          <div class="bg-gray-50 dark:bg-gray-800/40 rounded-xl p-4 space-y-3">
            <label class="flex flex-wrap items-center justify-between gap-3 cursor-pointer">
              <div>
                <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Footer Badge &ldquo;Powered by&rdquo;</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Tampilkan badge di bawah form checkout (nama + ikon + copyright).</p>
              </div>
              <input type="checkbox" name="footer_badge_enabled" id="tplFooterBadge" value="1"
                     onchange="document.getElementById('tplFooterBadgeFields').classList.toggle('hidden', !this.checked)"
                     class="w-4 h-4 rounded accent-[var(--brand-color)] shrink-0">
            </label>
            <div id="tplFooterBadgeFields" class="hidden space-y-3 pt-1">
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Nama / Teks Badge</label>
                <input type="text" name="footer_badge_text" id="tplFooterBadgeText" maxlength="100" placeholder="mis. orderonline.id"
                       class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">URL Ikon <span class="font-normal text-gray-400 dark:text-gray-600">(opsional)</span></label>
                <input type="url" name="footer_badge_icon" id="tplFooterBadgeIcon" maxlength="500" placeholder="https://.../logo.png"
                       class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Gambar kecil (tinggi ~16px). Kosongkan bila hanya teks.</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">URL Tautan <span class="font-normal text-gray-400 dark:text-gray-600">(opsional)</span></label>
                <input type="url" name="footer_badge_url" id="tplFooterBadgeUrl" maxlength="500" placeholder="https://orderonline.id"
                       class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Badge jadi bisa diklik (buka tab baru). Kosongkan bila tak perlu link.</p>
              </div>
            </div>
          </div>

          <!-- Custom Thank You Message -->
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
              Pesan Sukses Custom <span class="font-normal text-gray-400 dark:text-gray-600">(opsional)</span>
            </label>
            <textarea name="custom_thank_you_message" id="tplThankYou" rows="2" maxlength="500"
                      class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-none"
                      placeholder="Terima kasih! Pesananmu sedang diproses..."></textarea>
            <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Muncul di halaman konfirmasi setelah checkout berhasil.</p>
          </div>

          <!-- Default Toggle -->
          <label class="flex flex-wrap items-center justify-between gap-3 cursor-pointer py-3 border-t border-gray-100 dark:border-gray-800">
            <div>
              <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">Jadikan Template Default</p>
              <p class="text-xs text-gray-400 dark:text-gray-500">Digunakan untuk produk yang belum punya template khusus</p>
            </div>
            <input type="checkbox" name="is_default" id="tplIsDefault" value="1"
                   class="w-4 h-4 rounded accent-[var(--brand-color)]">
          </label>

        </div>

        <!-- Footer -->
        <div class="flex items-center justify-end gap-2.5 px-5 py-3.5 border-t border-gray-100 dark:border-gray-800 shrink-0 bg-white dark:bg-gray-900 rounded-b-2xl">
          <button type="button" onclick="closeModal()"
                  class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
            Batal
          </button>
          <button type="submit" data-loading data-loading-text="Menyimpan..."
                  class="px-5 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
            Simpan Template
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openModal(tpl) {
  document.getElementById('tplModal').classList.remove('hidden');
  document.body.style.overflow = 'hidden';

  if (tpl) {
    document.getElementById('modalTitle').textContent  = 'Edit Template';
    document.getElementById('tplId').value            = tpl.id || '';
    document.getElementById('tplName').value          = tpl.name || '';
    document.getElementById('tplThankYou').value      = tpl.custom_thank_you_message || '';
    document.getElementById('tplShowPhone').checked   = tpl.show_phone == 1;
    document.getElementById('tplShowAddress').checked = tpl.show_address == 1;
    document.getElementById('tplShowNotes').checked   = tpl.show_notes == 1;
    document.getElementById('tplFooterBadge').checked    = tpl.footer_badge_enabled == 1;
    document.getElementById('tplFooterBadgeText').value  = tpl.footer_badge_text || '';
    document.getElementById('tplFooterBadgeIcon').value  = tpl.footer_badge_icon || '';
    document.getElementById('tplFooterBadgeUrl').value   = tpl.footer_badge_url || '';
    document.getElementById('tplFooterBadgeFields').classList.toggle('hidden', tpl.footer_badge_enabled != 1);
    document.getElementById('tplIsDefault').checked   = tpl.is_default == 1;

    var ltInput = document.querySelector('input[name="layout_type"][value="' + tpl.layout_type + '"]');
    if (ltInput) ltInput.checked = true;
  } else {
    document.getElementById('modalTitle').textContent  = 'Tambah Template';
    document.getElementById('tplId').value            = '';
    document.getElementById('tplName').value          = '';
    document.getElementById('tplThankYou').value      = '';
    document.getElementById('tplShowPhone').checked   = false;
    document.getElementById('tplShowAddress').checked = false;
    document.getElementById('tplShowNotes').checked   = true;
    document.getElementById('tplFooterBadge').checked    = false;
    document.getElementById('tplFooterBadgeText').value  = '';
    document.getElementById('tplFooterBadgeIcon').value  = '';
    document.getElementById('tplFooterBadgeUrl').value   = '';
    document.getElementById('tplFooterBadgeFields').classList.add('hidden');
    document.getElementById('tplIsDefault').checked   = false;
    document.getElementById('lt_standard').checked    = true;
  }
  document.getElementById('tplName').focus();
}

function closeModal() {
  document.getElementById('tplModal').classList.add('hidden');
  document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

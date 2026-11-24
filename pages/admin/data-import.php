<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin(); // menulis massal ke users/orders — khusus admin, bukan staff
require_once __DIR__ . '/../../helpers/sejoli-import.php';

$pageTitle   = 'Import Data';
$topbarTitle = 'Import / Migrasi Data';

$csrf = generateCSRF();

// Jumlah produk aktif (untuk info pencocokan).
$productCount = (int) getDB()->query("SELECT COUNT(*) FROM products WHERE slug <> " . getDB()->quote(SEJOLI_PLACEHOLDER_SLUG))->fetchColumn();

// Pembersihan omzet impor: grup produk/sub-produk migrasi + riwayat batch.
$cleanupGroups  = sejoli_cleanup_groups(getDB());
$cleanupBatches = sejoli_cleanup_batches();
$cleanupOmzet   = 0.0;
foreach ($cleanupGroups as $cg) { $cleanupOmzet += $cg['omzet']; }
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<style>
  /* ── Gaya scoped halaman Import (kotak-tegas, satu-brand) ── */
  .imp-brandtile { background: color-mix(in srgb, var(--brand-color) 14%, transparent); color: var(--brand-color); }

  /* Stepper */
  .imp-step { color: #9ca3af; }
  .imp-step .imp-step-num {
    border: 1.5px solid currentColor; border-radius: 999px;
    width: 1.5rem; height: 1.5rem; display: inline-flex; align-items: center; justify-content: center;
    font-size: .7rem; font-weight: 700;
  }
  .imp-step.is-active { color: var(--brand-color); }
  .imp-step.is-done   { color: var(--brand-color); }
  .imp-step.is-done .imp-step-num { background: var(--brand-color); border-color: var(--brand-color); color: #fff; }
  .imp-step.is-active .imp-step-num { background: color-mix(in srgb, var(--brand-color) 14%, transparent); }

  /* Dropzone */
  .imp-drop {
    border: 1.5px dashed var(--border-c, #d1d5db);
    border-radius: var(--card-radius, .5rem);
    transition: border-color .15s, background .15s;
    cursor: pointer;
  }
  html.dark .imp-drop { --border-c: #374151; }
  .imp-drop:hover, .imp-drop.dragover {
    border-color: var(--brand-color);
    background: color-mix(in srgb, var(--brand-color) 6%, transparent);
  }
  #imp-file { display: none; }

  /* Kartu opsi toggle */
  .imp-opt {
    border: 1px solid #e5e7eb; border-radius: var(--control-radius, .375rem);
    transition: border-color .15s, background .15s, box-shadow .15s;
  }
  html.dark .imp-opt { border-color: #1f2937; }
  .imp-opt:hover { border-color: color-mix(in srgb, var(--brand-color) 45%, #e5e7eb); }
  .imp-opt.is-on {
    border-color: var(--brand-color);
    background: color-mix(in srgb, var(--brand-color) 7%, transparent);
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--brand-color) 35%, transparent);
  }
  .imp-opt input[type="checkbox"] { accent-color: var(--brand-color); }

  /* Chip terkunci */
  .imp-lock { background: color-mix(in srgb, var(--brand-color) 10%, transparent); color: var(--brand-color); }

  /* Hero KPI (omzet) — satu kartu bergradien brand */
  .imp-hero {
    background: linear-gradient(135deg, var(--brand-color) 0%, color-mix(in srgb, var(--brand-color) 68%, #000) 100%);
    color: #fff; border-radius: var(--card-radius, .5rem);
  }
  .imp-kpi {
    border: 1px solid #e5e7eb; border-radius: var(--card-radius, .5rem); background: #fff;
  }
  html.dark .imp-kpi { border-color: #1f2937; background: #111827; }

  /* Progress bar */
  .imp-bar-track { background: color-mix(in srgb, var(--brand-color) 12%, #f3f4f6); }
  html.dark .imp-bar-track { background: #1f2937; }
  .imp-bar-fill { background: linear-gradient(90deg, var(--brand-color), color-mix(in srgb, var(--brand-color) 70%, #fff)); }
</style>
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="max-w-5xl mx-auto space-y-5">

        <!-- Header -->
        <div class="flex items-start justify-between gap-4 flex-wrap">
          <div class="flex items-start gap-3">
            <span class="imp-brandtile shrink-0 w-11 h-11 rounded-xl flex items-center justify-center">
              <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline stroke-linecap="round" stroke-linejoin="round" points="7 10 12 15 17 10"/>
                <line stroke-linecap="round" x1="12" y1="15" x2="12" y2="3"/>
              </svg>
            </span>
            <div>
              <h2 class="text-xl font-bold text-gray-900 dark:text-white">Import / Migrasi Data</h2>
              <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5 max-w-xl">
                Pindahkan user &amp; riwayat transaksi dari platform lain ke Averion, lengkap dengan omzet dan akses produk.
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <span class="imp-lock inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold">
              <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              Senyap total
            </span>
            <span class="imp-lock inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold">
              <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 2v6h6"/><path d="M21 12A9 9 0 0 0 6 5.3L3 8"/><path d="M21 22v-6h-6"/><path d="M3 12a9 9 0 0 0 15 6.7l3-2.7"/></svg>
              Idempoten
            </span>
          </div>
        </div>

        <!-- Stepper -->
        <div class="flex items-center gap-2 text-xs font-medium">
          <div id="step-1" class="imp-step is-active flex items-center gap-2"><span class="imp-step-num">1</span> Pilih file</div>
          <div class="flex-1 h-px bg-gray-200 dark:bg-gray-800"></div>
          <div id="step-2" class="imp-step flex items-center gap-2"><span class="imp-step-num">2</span> Pratinjau</div>
          <div class="flex-1 h-px bg-gray-200 dark:bg-gray-800"></div>
          <div id="step-3" class="imp-step flex items-center gap-2"><span class="imp-step-num">3</span> Import</div>
        </div>

        <!-- Kartu utama -->
        <div class="card-base bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6 space-y-6">

          <div class="flex items-center justify-between gap-3">
            <div>
              <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Sumber data</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Ekspor order dari Sejoli, AksenSnap, atau platform lain dalam format CSV/TSV.</p>
            </div>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">
              <span class="w-1.5 h-1.5 rounded-full" style="background:var(--brand-color)"></span> CSV Transaksi
            </span>
          </div>

          <!-- Dropzone -->
          <div>
            <label id="imp-drop" class="imp-drop flex flex-col items-center justify-center text-center px-6 py-8 gap-2">
              <input id="imp-file" type="file" accept=".csv,.tsv,.txt">
              <span class="imp-brandtile w-10 h-10 rounded-lg flex items-center justify-center mb-1">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><polyline stroke-linecap="round" stroke-linejoin="round" points="16 16 12 12 8 16"/><line stroke-linecap="round" x1="12" y1="12" x2="12" y2="21"/></svg>
              </span>
              <span id="imp-drop-text" class="text-sm font-medium text-gray-700 dark:text-gray-200">Seret file ke sini, atau <span style="color:var(--brand-color)">klik untuk pilih</span></span>
              <span class="text-[11px] text-gray-400">CSV / TSV · maksimal beberapa MB · <?= number_format($productCount) ?> produk siap dicocokkan</span>
            </label>
            <div id="imp-file-chip" class="hidden mt-2 flex items-center gap-2 text-xs">
              <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200 font-medium">
                <svg class="w-3.5 h-3.5" style="color:var(--brand-color)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span id="imp-file-name">—</span>
              </span>
              <button type="button" id="imp-file-clear" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">Ganti</button>
            </div>
            <p class="text-[11px] text-gray-400 mt-2">Kolom dikenali (nama fleksibel, ID/EN): <code class="text-[10px]">order_number/invoice_code, created_at, product, amount, buyer_email/email, buyer_name/name, buyer_wa/phone, status, quantity, affiliate</code>. Kolom <code class="text-[10px]">status</code> (bila ada) otomatis menyaring — hanya transaksi lunas yang diimpor.</p>
          </div>

          <!-- Opsi -->
          <div class="border-t border-gray-100 dark:border-gray-800 pt-5">
            <div class="flex items-center justify-between mb-3">
              <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Opsi Import</h4>
              <div class="flex items-center gap-1.5">
                <span class="imp-lock inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold">Pratinjau wajib</span>
                <span class="imp-lock inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold">Mode senyap dikunci</span>
              </div>
            </div>
            <div id="data-import-opts" class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-stretch">
              <?php
              $toggle = function(string $id, string $label, string $desc, bool $default, string $icon) {
                  ?>
                  <label class="imp-opt is-on flex flex-col gap-2 p-3.5 cursor-pointer select-none" data-opt>
                    <span class="flex items-center justify-between">
                      <span class="w-8 h-8 rounded-lg imp-brandtile flex items-center justify-center">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><?= $icon ?></svg>
                      </span>
                      <input type="checkbox" id="<?= e($id) ?>" <?= $default ? 'checked' : '' ?> class="w-4 h-4 rounded">
                    </span>
                    <span class="block text-sm font-semibold text-gray-800 dark:text-gray-100"><?= e($label) ?></span>
                    <span class="block text-[11px] leading-relaxed text-gray-500 dark:text-gray-400"><?= e($desc) ?></span>
                  </label>
                  <?php
              };
              $toggle('opt-grant', 'Beri akses otomatis', 'Nama produk cocok → member langsung dapat akses lifetime.', true,
                  '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>');
              $toggle('opt-free', 'Ikutkan transaksi Rp0', 'Order gratis/bonus tetap dicatat (tidak menambah omzet).', true,
                  '<line x1="12" y1="1" x2="12" y2="23"/><path stroke-linecap="round" stroke-linejoin="round" d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>');
              $toggle('opt-unmatched', 'Catat produk tak dikenal', 'Order produk belum dibuat dicatat via placeholder tersembunyi.', true,
                  '<path stroke-linecap="round" stroke-linejoin="round" d="M20 7L9 18l-5-5"/><path stroke-linecap="round" stroke-linejoin="round" d="M22 4v6h-6" opacity=".5"/>');
              $toggle('opt-dedup', 'Lewati duplikat konten', 'Skip baris yang identik dgn order impor yang sudah ada (user + produk + nominal + tanggal sama), walau order_number-nya beda. Cegah transaksi menggelembung. Beda tanggal = pembelian berulang, tetap diimpor.', true,
                  '<rect x="9" y="9" width="13" height="13" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>');
              ?>
            </div>
          </div>

          <!-- Tombol -->
          <div class="flex items-center gap-3 border-t border-gray-100 dark:border-gray-800 pt-5">
            <button id="btn-preview" type="button"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition disabled:opacity-50 disabled:cursor-not-allowed">
              <svg id="btn-preview-ico" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <span id="btn-preview-label">Pratinjau</span>
            </button>
            <button id="btn-run" type="button" disabled
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-green-600 hover:opacity-90 transition disabled:opacity-40 disabled:cursor-not-allowed">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
              Jalankan Import
            </button>
            <span id="imp-status" class="text-xs text-gray-500"></span>
          </div>
        </div>

        <!-- Hasil pratinjau -->
        <div id="preview-panel" class="hidden card-base bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6 space-y-5">
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Ringkasan Pratinjau</h3>
            <span class="text-[11px] text-gray-400">— belum ada yang ditulis ke database</span>
          </div>
          <div id="preview-hero" class="grid sm:grid-cols-4 gap-3"></div>
          <div id="preview-detail" class="grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-3 pt-1"></div>
          <div id="preview-unmatched"></div>
        </div>

        <!-- Progress import -->
        <div id="run-panel" class="hidden card-base bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6 space-y-4">
          <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Progres Import</h3>
            <span id="run-pct" class="text-sm font-bold" style="color:var(--brand-color)">0%</span>
          </div>
          <div class="imp-bar-track w-full h-2.5 rounded-full overflow-hidden">
            <div id="run-bar" class="imp-bar-fill h-full transition-all duration-300 rounded-full" style="width:0%"></div>
          </div>
          <div id="run-stats" class="grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-3"></div>
          <div id="run-done" class="hidden flex items-start gap-2.5 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-900 p-3.5">
            <svg class="w-5 h-5 shrink-0 text-green-600 dark:text-green-400 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <p id="run-done-text" class="text-sm font-medium text-green-800 dark:text-green-200"></p>
          </div>
        </div>

        <!-- ─── Sinkronkan akses (produk dibuat menyusul) ─────────────── -->
        <div class="card-base bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6 space-y-5">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Sinkronkan Akses Produk</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed max-w-2xl">
                Untuk transaksi migrasi yang produknya <strong>baru dibuat belakangan</strong>. Nama produk asli sudah tersimpan
                saat impor, jadi <strong>tidak perlu mengunggah ulang file CSV</strong> — cukup buat produk dengan nama yang sama,
                lalu jalankan sinkronisasi. Hanya menyentuh transaksi hasil migrasi; order reguler tidak tersentuh.
              </p>
            </div>
            <span class="imp-lock inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold whitespace-nowrap">Mode senyap</span>
          </div>

          <div class="flex items-center gap-3">
            <button id="btn-sync-preview" type="button"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition disabled:opacity-50 disabled:cursor-not-allowed">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <span id="btn-sync-preview-label">Periksa</span>
            </button>
            <button id="btn-sync-run" type="button" disabled
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-green-600 hover:opacity-90 transition disabled:opacity-40 disabled:cursor-not-allowed">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
              <span id="btn-sync-run-label">Terapkan</span>
            </button>
            <span id="sync-status" class="text-xs text-gray-500"></span>
          </div>

          <div id="sync-stats" class="hidden grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-3"></div>
          <div id="sync-done" class="hidden flex items-start gap-2.5 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-900 p-3.5">
            <svg class="w-5 h-5 shrink-0 text-green-600 dark:text-green-400 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <p id="sync-done-text" class="text-sm font-medium text-green-800 dark:text-green-200"></p>
          </div>
        </div>

        <!-- ─── Bersihkan omzet impor (nol-kan nominal per produk) ─────── -->
        <div class="card-base bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6 space-y-5">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Bersihkan Omzet Impor</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed max-w-2xl">
                Sebagian transaksi migrasi mungkin terbawa <strong>nominal yang keliru</strong> (mis. produk bonus/gratis
                ter-impor bernilai jutaan) sehingga omzet menggelembung. Pilih produk yang nominalnya salah, lalu
                <strong>nol-kan nominalnya</strong> — riwayat transaksi &amp; akses member tetap utuh, hanya angka omzet
                yang dibersihkan. Hanya menyentuh transaksi hasil migrasi; order asli tidak tersentuh.
              </p>
            </div>
            <span class="imp-lock inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold whitespace-nowrap">Bisa dibatalkan</span>
          </div>

          <?php if (!$cleanupGroups): ?>
            <div class="flex items-center gap-2.5 rounded-lg bg-gray-50 dark:bg-gray-800/40 border border-gray-100 dark:border-gray-800 p-4 text-sm text-gray-500 dark:text-gray-400">
              <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
              Belum ada transaksi hasil migrasi yang berbayar. Tidak ada yang perlu dibersihkan.
            </div>
          <?php else: ?>
            <div class="rounded-lg border border-gray-200 dark:border-gray-800 overflow-hidden">
              <div class="flex items-center justify-between gap-3 px-3 py-2 bg-gray-50 dark:bg-gray-800/40 border-b border-gray-100 dark:border-gray-800">
                <label class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500 cursor-pointer select-none shrink-0">
                  <input type="checkbox" id="cl-check-all" class="w-3.5 h-3.5 rounded" style="accent-color:var(--brand-color)">
                  Pilih (<span id="cl-count"><?= count($cleanupGroups) ?></span>)
                </label>
                <span class="text-[11px] text-gray-400 truncate">Total omzet impor: <strong class="text-gray-600 dark:text-gray-300"><?= e(formatRupiah($cleanupOmzet)) ?></strong></span>
              </div>
              <div class="px-3 py-2 border-b border-gray-100 dark:border-gray-800">
                <div class="relative">
                  <svg class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <input id="cl-search" type="text" placeholder="Cari nama produk…" autocomplete="off"
                         style="padding-left:2rem;outline:none"
                         class="w-full pr-3 py-1.5 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-800 dark:text-gray-100 placeholder-gray-400">
                </div>
              </div>
              <div id="cl-list" class="max-h-80 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                <?php foreach ($cleanupGroups as $g): $isChild = $g['group'] === 'placeholder'; ?>
                  <label class="cl-row flex items-center gap-3 px-3 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-800/40 cursor-pointer" data-name="<?= e(mb_strtolower($g['name'])) ?>">
                    <input type="checkbox" class="cl-prod w-4 h-4 rounded shrink-0" value="<?= e($g['key']) ?>"
                           data-omzet="<?= (float) $g['omzet'] ?>" data-orders="<?= (int) $g['n_order'] ?>"
                           style="accent-color:var(--brand-color)">
                    <div class="min-w-0 flex-1">
                      <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-100 truncate"><?= e($g['name']) ?></span>
                        <?php if ($isChild): ?>
                          <span class="shrink-0 inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300" title="Produk di dalam transaksi migrasi (placeholder)">di migrasi</span>
                        <?php endif; ?>
                      </div>
                      <div class="text-[11px] text-gray-400 mt-0.5">
                        <?= number_format($g['n_order']) ?> order · nominal terbesar <?= e(formatRupiah($g['max_nominal'])) ?>
                      </div>
                    </div>
                    <div class="shrink-0 text-right">
                      <div class="text-sm font-semibold tabular-nums text-gray-800 dark:text-gray-100"><?= e(formatRupiah($g['omzet'])) ?></div>
                      <div class="text-[10px] text-gray-400">omzet</div>
                    </div>
                  </label>
                <?php endforeach; ?>
                <div id="cl-empty" class="hidden px-3 py-6 text-center text-xs text-gray-400">Tidak ada produk cocok.</div>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 dark:border-gray-800 pt-4">
              <button id="btn-cl-apply" type="button" disabled
                      class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition disabled:opacity-40 disabled:cursor-not-allowed">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="12" y1="11" x2="12" y2="17"/></svg>
                <span id="btn-cl-apply-label">Nol-kan Nominal</span>
              </button>
              <span id="cl-impact" class="text-xs text-gray-500">Pilih produk untuk melihat dampaknya.</span>
            </div>

            <div id="cl-done" class="hidden flex items-start gap-2.5 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-900 p-3.5">
              <svg class="w-5 h-5 shrink-0 text-green-600 dark:text-green-400 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              <p id="cl-done-text" class="text-sm font-medium text-green-800 dark:text-green-200"></p>
            </div>
          <?php endif; ?>

          <!-- Riwayat pembersihan (undo) -->
          <div id="cl-history" class="<?= $cleanupBatches ? '' : 'hidden' ?>">
            <details class="rounded-lg border border-gray-100 dark:border-gray-800 overflow-hidden">
              <summary class="cursor-pointer flex items-center gap-2 px-3 py-2.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800/40">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 2v6h6"/><path d="M21 12A9 9 0 0 0 6 5.3L3 8"/></svg>
                Riwayat pembersihan (<span id="cl-history-count"><?= count($cleanupBatches) ?></span>) — bisa dibatalkan
              </summary>
              <div id="cl-history-list" class="divide-y divide-gray-100 dark:divide-gray-800">
                <?php foreach ($cleanupBatches as $b): $reverted = !empty($b['reverted_at']); ?>
                  <div class="flex items-center gap-3 px-3 py-2.5" data-batch="<?= e($b['batch']) ?>">
                    <div class="min-w-0 flex-1">
                      <div class="text-xs font-medium text-gray-700 dark:text-gray-200"><?= e($b['created_at']) ?></div>
                      <div class="text-[11px] text-gray-400"><?= number_format($b['orders']) ?> order · <?= e(formatRupiah($b['omzet'])) ?> dinol-kan</div>
                    </div>
                    <?php if ($reverted): ?>
                      <span class="shrink-0 text-[11px] text-gray-400 inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        Sudah dibatalkan
                      </span>
                    <?php else: ?>
                      <button type="button" class="cl-undo shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600 transition" data-batch="<?= e($b['batch']) ?>">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 2v6h6"/><path d="M21 12A9 9 0 0 0 6 5.3L3 8"/></svg>
                        Batalkan
                      </button>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </details>
          </div>
        </div>

      </div>
    </main>
  </div>
</div>

<script>
(function () {
  var base   = <?= json_encode(rtrim(APP_URL, '/')) ?>;
  var csrf   = <?= json_encode($csrf) ?>;
  var fileEl = document.getElementById('imp-file');
  var btnPre = document.getElementById('btn-preview');
  var btnRun = document.getElementById('btn-run');
  var statusEl = document.getElementById('imp-status');
  var token  = null;
  var totalRows = 0;

  // ── Stepper ──
  function setStep(active, done) {
    [1,2,3].forEach(function (n) {
      var el = document.getElementById('step-' + n);
      el.classList.toggle('is-active', n === active);
      el.classList.toggle('is-done', done.indexOf(n) !== -1);
    });
  }
  setStep(1, []);

  // ── Toggle-active visual ──
  document.querySelectorAll('#data-import-opts [data-opt]').forEach(function (lbl) {
    var cb = lbl.querySelector('input[type="checkbox"]');
    cb.addEventListener('change', function () { lbl.classList.toggle('is-on', cb.checked); });
  });

  // ── Dropzone + drag/drop ──
  var drop = document.getElementById('imp-drop');
  var chip = document.getElementById('imp-file-chip');
  var dropText = document.getElementById('imp-drop-text');
  ['dragenter','dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('dragover'); });
  });
  ['dragleave','drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('dragover'); });
  });
  drop.addEventListener('drop', function (e) {
    if (e.dataTransfer.files && e.dataTransfer.files.length) { fileEl.files = e.dataTransfer.files; onFile(); }
  });
  fileEl.addEventListener('change', onFile);
  document.getElementById('imp-file-clear').addEventListener('click', function (e) {
    e.preventDefault(); e.stopPropagation();
    fileEl.value = ''; chip.classList.add('hidden'); dropText.style.opacity = '1';
    btnRun.disabled = true; token = null; setStep(1, []);
  });
  function onFile() {
    if (!fileEl.files.length) return;
    var f = fileEl.files[0];
    document.getElementById('imp-file-name').textContent = f.name + ' · ' + fmtSize(f.size);
    chip.classList.remove('hidden');
    dropText.style.opacity = '.55';
    statusEl.textContent = '';
    setStep(2, [1]);
  }
  function fmtSize(b) {
    if (b >= 1048576) return (b/1048576).toFixed(1) + ' MB';
    if (b >= 1024) return (b/1024).toFixed(1) + ' KB';
    return b + ' B';
  }

  function opts() {
    return {
      grant:     document.getElementById('opt-grant').checked ? '1' : '0',
      free:      document.getElementById('opt-free').checked ? '1' : '0',
      unmatched: document.getElementById('opt-unmatched').checked ? '1' : '0',
      dedup:     document.getElementById('opt-dedup').checked ? '1' : '0',
    };
  }
  function rupiah(n) { return window.fmtMoney(Number(n) || 0); }
  function num(n) { return (Number(n) || 0).toLocaleString('id-ID'); }

  // KPI netral
  function kpi(label, value, sub) {
    return '<div class="imp-kpi p-4">' +
      '<div class="text-[11px] text-gray-500 dark:text-gray-400">' + label + '</div>' +
      '<div class="text-xl font-bold text-gray-900 dark:text-white mt-1">' + value + '</div>' +
      (sub ? '<div class="text-[11px] text-gray-400 mt-0.5">' + sub + '</div>' : '') + '</div>';
  }
  // KPI hero (brand)
  function kpiHero(label, value, sub) {
    return '<div class="imp-hero p-4">' +
      '<div class="text-[11px] opacity-80">' + label + '</div>' +
      '<div class="text-xl font-bold mt-1">' + value + '</div>' +
      (sub ? '<div class="text-[11px] opacity-80 mt-0.5">' + sub + '</div>' : '') + '</div>';
  }
  // Baris detail (definition-list ringkas)
  function dl(label, value, accent) {
    return '<div><div class="text-[11px] text-gray-500 dark:text-gray-400">' + label + '</div>' +
      '<div class="text-sm font-semibold mt-0.5 ' + (accent || 'text-gray-800 dark:text-gray-100') + '">' + value + '</div></div>';
  }

  // ── Pratinjau ──
  btnPre.addEventListener('click', function () {
    if (!fileEl.files.length) { statusEl.textContent = 'Pilih file dulu.'; return; }
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('file', fileEl.files[0]);
    var o = opts();
    fd.append('grant', o.grant); fd.append('free', o.free); fd.append('unmatched', o.unmatched); fd.append('dedup', o.dedup);

    btnPre.disabled = true; btnRun.disabled = true;
    document.getElementById('btn-preview-label').textContent = 'Menghitung...';
    statusEl.textContent = '';

    fetch(base + '/actions/admin/data-import-preview', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btnPre.disabled = false;
        document.getElementById('btn-preview-label').textContent = 'Pratinjau Ulang';
        if (!d.ok) { statusEl.innerHTML = '<span class="text-red-500">' + (d.error || 'gagal') + '</span>'; return; }
        token = d.token; totalRows = d.total_rows;
        renderPreview(d.summary);
        btnRun.disabled = false;
        setStep(3, [1, 2]);
      })
      .catch(function () {
        btnPre.disabled = false;
        document.getElementById('btn-preview-label').textContent = 'Pratinjau';
        statusEl.innerHTML = '<span class="text-red-500">Gagal memproses file.</span>';
      });
  });

  function renderPreview(s) {
    document.getElementById('preview-panel').classList.remove('hidden');

    document.getElementById('preview-hero').innerHTML =
      kpiHero('Total omzet', rupiah(s.omzet), num(s.trx_baru) + ' transaksi baru') +
      kpi('Transaksi diproses', num(s.trx_diproses), num(s.trx_sudah_ada) + ' sudah pernah diimpor') +
      kpi('User unik', num(s.user_unik), num(s.user_tanpa_email) + ' tanpa email (login via WA)') +
      kpi('Produk cocok → akses', num(s.produk_cocok_trx), num(s.produk_tak_cocok_trx) + ' transaksi tak cocok');

    document.getElementById('preview-detail').innerHTML =
      dl('Transaksi gratis (Rp0)', num(s.trx_gratis)) +
      dl('WA invalid', num(s.wa_invalid), s.wa_invalid > 0 ? 'text-amber-600 dark:text-amber-400' : '') +
      dl('Dilewati — gratis', num(s.trx_dilewati_gratis), 'text-gray-400') +
      dl('Dilewati — belum lunas', num(s.trx_dilewati_status || 0), (s.trx_dilewati_status || 0) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400') +
      dl('Dilewati — tak cocok', num(s.trx_dilewati_unmatched), 'text-gray-400');

    var um = document.getElementById('preview-unmatched');
    if (s.produk_tak_cocok && s.produk_tak_cocok.length) {
      var rows = s.produk_tak_cocok.slice(0, 100).map(function (p) {
        return '<tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1.5 pr-3 text-gray-700 dark:text-gray-300">' +
          escapeHtml(p.name) + '</td><td class="py-1.5 text-right tabular-nums text-gray-500">' + num(p.count) + '</td></tr>';
      }).join('');
      um.innerHTML = '<details class="mt-1 rounded-lg border border-gray-100 dark:border-gray-800 overflow-hidden">' +
        '<summary class="cursor-pointer flex items-center gap-2 px-3 py-2.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800/40">' +
        '<span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-bold text-white" style="background:var(--brand-color)">' + s.produk_tak_cocok.length + '</span>' +
        'nama produk belum cocok — transaksinya tetap dicatat, akses dilewati</summary>' +
        '<div class="max-h-72 overflow-y-auto px-3 pb-2"><table class="w-full text-xs"><thead><tr class="text-[10px] uppercase tracking-wide text-gray-400"><th class="text-left py-1.5">Nama produk di CSV</th><th class="text-right py-1.5">Transaksi</th></tr></thead><tbody>' +
        rows + '</tbody></table></div></details>';
    } else { um.innerHTML = ''; }
  }

  function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

  // ── Jalankan import (chunked) ──
  btnRun.addEventListener('click', function () {
    if (!token) return;
    confirmAndRun({
      title: 'Jalankan Import Sekarang?',
      message: 'Data akan ditulis ke database (senyap, tanpa notifikasi). Proses ini idempoten dan aman diulang. Lanjutkan?',
      confirmText: 'Ya, Jalankan',
      variant: 'success'
    }, function () { startRun(); });
  });

  function startRun() {
    btnRun.disabled = true; btnPre.disabled = true;
    document.getElementById('run-panel').classList.remove('hidden');
    document.getElementById('run-done').classList.add('hidden');
    document.getElementById('run-panel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    var totals = { imported: 0, access_reimport: 0, skipped_exists: 0, skipped_dup: 0, skipped_free: 0, skipped_unmatched: 0, skipped_status: 0, skipped_invalid: 0, error: 0, done: 0 };
    runChunk(0, totals);
  }

  function runChunk(offset, totals) {
    var o = opts();
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('token', token);
    fd.append('offset', offset);
    fd.append('grant', o.grant); fd.append('free', o.free); fd.append('unmatched', o.unmatched); fd.append('dedup', o.dedup);

    fetch(base + '/actions/admin/data-import-run', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { statusEl.innerHTML = '<span class="text-red-500">' + (d.error || 'gagal') + '</span>'; btnPre.disabled = false; return; }
        ['imported','access_reimport','skipped_exists','skipped_dup','skipped_free','skipped_unmatched','skipped_status','skipped_invalid','error'].forEach(function (k) {
          totals[k] += (d.counts[k] || 0);
        });
        totals.done = d.next_offset;
        renderRun(totals, d.total);
        if (!d.finished) { runChunk(d.next_offset, totals); }
        else {
          var done = document.getElementById('run-done');
          done.classList.remove('hidden');
          document.getElementById('run-done-text').textContent =
            'Selesai — ' + num(totals.imported) + ' transaksi baru diimpor, ' +
            num(totals.access_reimport) + ' akses retroaktif diberikan' +
            (totals.error > 0 ? ', ' + num(totals.error) + ' error (cek log)' : '') + '.';
          btnPre.disabled = false;
        }
      })
      .catch(function () { statusEl.innerHTML = '<span class="text-red-500">Koneksi terputus saat import.</span>'; btnPre.disabled = false; });
  }

  function renderRun(t, total) {
    var pct = total > 0 ? Math.round(t.done / total * 100) : 100;
    document.getElementById('run-bar').style.width = pct + '%';
    document.getElementById('run-pct').textContent = pct + '%';
    document.getElementById('run-stats').innerHTML =
      dl('Diimpor', num(t.imported), 'text-green-600 dark:text-green-400') +
      dl('Akses retroaktif', num(t.access_reimport), 'text-green-600 dark:text-green-400') +
      dl('Sudah ada (skip)', num(t.skipped_exists), 'text-gray-400') +
      dl('Duplikat konten (skip)', num(t.skipped_dup || 0), 'text-amber-600 dark:text-amber-400') +
      dl('Skip gratis', num(t.skipped_free), 'text-gray-400') +
      dl('Skip belum lunas', num(t.skipped_status || 0), 'text-gray-400') +
      dl('Skip tak cocok', num(t.skipped_unmatched), 'text-gray-400') +
      dl('Skip invalid', num(t.skipped_invalid), 'text-gray-400') +
      dl('Error', num(t.error), t.error > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400');
  }

  // ── Sinkronkan akses (tanpa CSV) ──────────────────────────────────────
  // Loop chunked: apply=0 memeriksa saja, apply=1 menerapkan. Sama-sama
  // memindai order hasil migrasi dari database, bukan dari file.
  var btnSyncPre = document.getElementById('btn-sync-preview');
  var btnSyncRun = document.getElementById('btn-sync-run');
  var syncStatus = document.getElementById('sync-status');

  function syncLoop(apply, afterId, totals, cb) {
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('apply', apply ? '1' : '0');
    fd.append('after_id', afterId);
    fetch(base + '/actions/admin/data-import-sync', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { syncStatus.innerHTML = '<span class="text-red-500">' + (d.error || 'Gagal.') + '</span>'; cb(null); return; }
        ['scanned','relink','akses_baru','sudah','tak_cocok'].forEach(function (k) {
          totals[k] = (totals[k] || 0) + (d.counts[k] || 0);
        });
        renderSync(totals);
        syncStatus.textContent = (apply ? 'Menerapkan… ' : 'Memeriksa… ') + num(totals.scanned) + ' transaksi dipindai';
        if (d.finished) { cb(totals); return; }
        syncLoop(apply, d.last_id, totals, cb);
      })
      .catch(function () { syncStatus.innerHTML = '<span class="text-red-500">Koneksi terputus.</span>'; cb(null); });
  }

  function renderSync(t) {
    var box = document.getElementById('sync-stats');
    box.classList.remove('hidden');
    box.innerHTML =
      dl('Transaksi dipindai', num(t.scanned || 0), 'text-gray-700 dark:text-gray-200') +
      dl('Akses akan/telah dibuat', num(t.akses_baru || 0), 'text-green-600 dark:text-green-400') +
      dl('Ditautkan ke produk', num(t.relink || 0), 'text-green-600 dark:text-green-400') +
      dl('Sudah punya akses', num(t.sudah || 0), 'text-gray-400') +
      dl('Produk belum dibuat', num(t.tak_cocok || 0), t.tak_cocok > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400');
  }

  btnSyncPre.addEventListener('click', function () {
    btnSyncPre.disabled = true; btnSyncRun.disabled = true;
    document.getElementById('sync-done').classList.add('hidden');
    document.getElementById('btn-sync-preview-label').textContent = 'Memeriksa…';
    syncLoop(false, 0, {}, function (t) {
      btnSyncPre.disabled = false;
      document.getElementById('btn-sync-preview-label').textContent = 'Periksa';
      if (!t) return;
      btnSyncRun.disabled = (t.akses_baru || 0) === 0 && (t.relink || 0) === 0;
      syncStatus.textContent = t.akses_baru > 0 || t.relink > 0
        ? 'Siap diterapkan — belum ada yang diubah.'
        : 'Tidak ada yang perlu disinkronkan.';
    });
  });

  btnSyncRun.addEventListener('click', function () {
    btnSyncPre.disabled = true; btnSyncRun.disabled = true;
    document.getElementById('btn-sync-run-label').textContent = 'Menerapkan…';
    syncLoop(true, 0, {}, function (t) {
      btnSyncPre.disabled = false;
      document.getElementById('btn-sync-run-label').textContent = 'Terapkan';
      if (!t) return;
      syncStatus.textContent = '';
      var done = document.getElementById('sync-done');
      done.classList.remove('hidden');
      document.getElementById('sync-done-text').textContent =
        'Selesai — ' + num(t.akses_baru || 0) + ' akses diberikan, ' +
        num(t.relink || 0) + ' transaksi ditautkan ke produknya' +
        ((t.tak_cocok || 0) > 0 ? ', ' + num(t.tak_cocok) + ' masih menunggu produk dibuat' : '') + '.';
    });
  });

  // ── Bersihkan omzet impor (nol-kan nominal per produk) ────────────────
  (function () {
    var applyBtn = document.getElementById('btn-cl-apply');
    if (!applyBtn) return; // kartu kosong (tak ada data impor berbayar)
    var checkAll = document.getElementById('cl-check-all');
    var impactEl = document.getElementById('cl-impact');
    var prods    = Array.prototype.slice.call(document.querySelectorAll('.cl-prod'));

    function selected() { return prods.filter(function (c) { return c.checked; }); }

    function refreshImpact() {
      var sel = selected();
      if (!sel.length) {
        applyBtn.disabled = true;
        impactEl.innerHTML = 'Pilih produk untuk melihat dampaknya.';
        return;
      }
      var omzet = 0, orders = 0;
      sel.forEach(function (c) { omzet += Number(c.dataset.omzet) || 0; orders += Number(c.dataset.orders) || 0; });
      applyBtn.disabled = false;
      impactEl.innerHTML = 'Akan menol-kan <strong>' + rupiah(omzet) + '</strong> dari ' + num(sel.length) +
        ' produk (±' + num(orders) + ' order). Riwayat &amp; akses tetap.';
    }

    // Baris yang sedang tampil (tidak tersaring pencarian).
    function visibleProds() {
      return prods.filter(function (c) {
        var row = c.closest('.cl-row');
        return row && row.style.display !== 'none';
      });
    }
    function syncCheckAll() {
      if (!checkAll) return;
      var vis = visibleProds();
      checkAll.checked = vis.length > 0 && vis.every(function (c) { return c.checked; });
    }

    prods.forEach(function (c) {
      c.addEventListener('change', function () { syncCheckAll(); refreshImpact(); });
    });
    if (checkAll) checkAll.addEventListener('change', function () {
      visibleProds().forEach(function (c) { c.checked = checkAll.checked; });
      refreshImpact();
    });

    // Pencarian nama produk (filter baris, client-side).
    var searchEl = document.getElementById('cl-search');
    var emptyEl  = document.getElementById('cl-empty');
    var countEl  = document.getElementById('cl-count');
    if (searchEl) searchEl.addEventListener('input', function () {
      var q = searchEl.value.trim().toLowerCase();
      var shown = 0;
      prods.forEach(function (c) {
        var row = c.closest('.cl-row');
        if (!row) return;
        var match = !q || (row.getAttribute('data-name') || '').indexOf(q) !== -1;
        row.style.display = match ? '' : 'none';
        if (match) shown++;
      });
      if (emptyEl) emptyEl.classList.toggle('hidden', shown > 0);
      if (countEl) countEl.textContent = shown;
      syncCheckAll();
    });

    applyBtn.addEventListener('click', function () {
      var sel = selected();
      if (!sel.length) return;
      var omzet = 0; sel.forEach(function (c) { omzet += Number(c.dataset.omzet) || 0; });
      confirmAndRun({
        title: 'Nol-kan Nominal Impor?',
        message: 'Omzet sebesar ' + rupiah(omzet) + ' dari ' + num(sel.length) + ' produk akan dinol-kan. ' +
                 'Riwayat transaksi & akses member tetap utuh, dan tindakan ini bisa dibatalkan. Lanjutkan?',
        confirmText: 'Ya, Nol-kan',
        variant: 'danger'
      }, function () { doApply(sel); });
    });

    function doApply(sel) {
      applyBtn.disabled = true;
      document.getElementById('btn-cl-apply-label').textContent = 'Memproses…';
      var fd = new FormData();
      fd.append('csrf_token', csrf);
      sel.forEach(function (c) { fd.append('keys[]', c.value); });
      fetch(base + '/actions/admin/data-import-cleanup-apply', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          document.getElementById('btn-cl-apply-label').textContent = 'Nol-kan Nominal';
          if (!d.ok) { impactEl.innerHTML = '<span class="text-red-500">' + (d.error || 'Gagal.') + '</span>'; applyBtn.disabled = false; return; }
          var done = document.getElementById('cl-done');
          done.classList.remove('hidden');
          document.getElementById('cl-done-text').textContent =
            'Selesai — ' + rupiah(d.omzet) + ' dinol-kan dari ' + num(d.orders) + ' order. Memuat ulang…';
          if (window.showToast) showToast('Omzet impor dibersihkan.', 'success');
          setTimeout(function () { location.reload(); }, 1200);
        })
        .catch(function () {
          document.getElementById('btn-cl-apply-label').textContent = 'Nol-kan Nominal';
          impactEl.innerHTML = '<span class="text-red-500">Koneksi terputus.</span>'; applyBtn.disabled = false;
        });
    }

    // Batalkan (undo) satu batch dari riwayat
    document.querySelectorAll('.cl-undo').forEach(function (btn) {
      btn.addEventListener('click', function () {
        confirmAndRun({
          title: 'Batalkan Pembersihan?',
          message: 'Nominal order pada batch ini akan dikembalikan ke nilai semula. Lanjutkan?',
          confirmText: 'Ya, Batalkan',
          variant: 'info'
        }, function () {
          btn.disabled = true; btn.textContent = 'Memulihkan…';
          var fd = new FormData();
          fd.append('csrf_token', csrf);
          fd.append('batch', btn.dataset.batch);
          fetch(base + '/actions/admin/data-import-cleanup-undo', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              if (!d.ok) { btn.disabled = false; btn.textContent = 'Batalkan'; if (window.showToast) showToast(d.error || 'Gagal.', 'error'); return; }
              if (window.showToast) showToast(num(d.orders) + ' order dipulihkan. Memuat ulang…', 'success');
              setTimeout(function () { location.reload(); }, 1000);
            })
            .catch(function () { btn.disabled = false; btn.textContent = 'Batalkan'; if (window.showToast) showToast('Koneksi terputus.', 'error'); });
        });
      });
    });
  })();
})();
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'AI Chat Widget';
$topbarTitle = 'AI Chat Widget';

// ─── Ambil semua setting ai_chat ───────────────────────────────
$s = [];
try {
    $rows = getDB()->query("SELECT setting_key, setting_value FROM settings WHERE setting_group = 'ai_chat'")->fetchAll();
    foreach ($rows as $r) {
        $s[$r['setting_key']] = $r['setting_value'];
    }
} catch (Exception $e) {
    error_log('ai-chat page load settings error: ' . $e->getMessage());
}
$cfg = fn(string $k, string $d = '') => $s[$k] ?? $d;

$enabled       = $cfg('ai_chat_enabled', '0') === '1';
$showPublic    = $cfg('ai_chat_show_public', '1') === '1';
$showMember    = $cfg('ai_chat_show_member', '0') === '1';
$provider      = $cfg('ai_chat_provider', 'gemini');
$apiKey        = $cfg('ai_chat_api_key', '');
$botName       = $cfg('ai_chat_bot_name', 'Asisten');
$welcomeMsg    = $cfg('ai_chat_welcome_message', 'Halo! Ada yang bisa saya bantu?');
$systemPrompt  = $cfg('ai_chat_system_prompt', '');
$injectProd    = $cfg('ai_chat_inject_products', '1') === '1';
$fallbackWa    = $cfg('ai_chat_fallback_wa', '0') === '1';
$fallbackWaNum = $cfg('ai_chat_fallback_wa_number', '');
$theme         = $cfg('ai_chat_theme', 'classic');
$quickReplies  = json_decode($cfg('ai_chat_quick_replies', '[]'), true);
if (!is_array($quickReplies)) $quickReplies = [];
$quickRepliesStr = implode(', ', $quickReplies);
$colorSource   = $cfg('ai_chat_color_source', 'brand');
$customColor   = $cfg('ai_chat_color', '#6366f1');
$textColor     = $cfg('ai_chat_text_color', 'auto');
$gradient      = $cfg('ai_chat_gradient', '0') === '1';
$brandColor    = getSetting('brand_color', '#6366f1');

generateCSRF();

// Kelas input reusable
$inputCls = 'w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition';
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
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">AI Chat Widget</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
          Floating chatbot berbasis AI untuk halaman publik &amp; member area. Semua request lewat proxy server — API key tidak pernah terekspos ke pengunjung.
        </p>
      </div>

      <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-ai-chat" id="ai-chat-settings-form">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

        <!-- Toast simpan (AJAX) — brand lembut & premium, tidak reload/loncat -->
        <div id="save-toast" style="display:none;opacity:0;transform:translateY(8px)"
             class="fixed bottom-6 right-6 z-[60] flex items-center gap-3 pl-3 pr-5 py-3 rounded-2xl border shadow-xl text-sm font-medium bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-800 text-gray-800 dark:text-gray-100 transition-all duration-300">
          <span id="save-toast-icon" class="w-8 h-8 rounded-full flex items-center justify-center shrink-0"></span>
          <span id="save-toast-msg"></span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

          <!-- ═══════════ KIRI: FORM ═══════════ -->
          <div class="lg:col-span-2 space-y-5">

            <!-- SECTION 1: STATUS & LOKASI -->
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm mb-4">Status &amp; Lokasi Widget</h3>

              <div class="flex flex-wrap items-center justify-between gap-4 p-3.5 rounded-lg bg-gray-50 dark:bg-gray-800/60">
                <div>
                  <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">Aktifkan AI Chat Widget</p>
                  <p id="enabledNotice" class="text-xs mt-0.5 <?= $enabled ? 'text-gray-400 dark:text-gray-500' : 'text-amber-600 dark:text-amber-400' ?>">
                    <?= $enabled ? 'Widget aktif sesuai pilihan lokasi di bawah.' : 'Widget tidak akan muncul di manapun.' ?>
                  </p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                  <input type="hidden" name="ai_chat_enabled" value="0">
                  <input type="checkbox" name="ai_chat_enabled" value="1" id="chkEnabled" <?= $enabled ? 'checked' : '' ?> class="sr-only peer">
                  <div class="w-12 h-7 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                  <div class="absolute left-0.5 top-0.5 w-6 h-6 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                </label>
              </div>

              <div id="locationBox" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4 <?= $enabled ? '' : 'opacity-50 pointer-events-none' ?>">
                <label class="flex items-start gap-3 p-3.5 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] transition">
                  <input type="hidden" name="ai_chat_show_public" value="0">
                  <input type="checkbox" name="ai_chat_show_public" value="1" <?= $showPublic ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 accent-[var(--brand-color)] loc-chk">
                  <span class="flex items-start gap-2.5">
                    <svg class="w-5 h-5 text-gray-400 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                    <span>
                      <span class="block text-sm font-medium text-gray-800 dark:text-gray-200">Halaman publik</span>
                      <span class="block text-xs text-gray-400 dark:text-gray-500">Products, checkout, login, dll</span>
                    </span>
                  </span>
                </label>
                <label class="flex items-start gap-3 p-3.5 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] transition">
                  <input type="hidden" name="ai_chat_show_member" value="0">
                  <input type="checkbox" name="ai_chat_show_member" value="1" <?= $showMember ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 accent-[var(--brand-color)] loc-chk">
                  <span class="flex items-start gap-2.5">
                    <svg class="w-5 h-5 text-gray-400 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>
                      <span class="block text-sm font-medium text-gray-800 dark:text-gray-200">Member area</span>
                      <span class="block text-xs text-gray-400 dark:text-gray-500">Dashboard, class-detail, dll</span>
                    </span>
                  </span>
                </label>
              </div>
            </div>

            <!-- SECTION 2: PROVIDER AI -->
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm mb-4">Provider AI</h3>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="flex items-start gap-3 p-3.5 rounded-lg border cursor-pointer transition <?= $provider === 'gemini' ? 'border-[var(--brand-color)] bg-[var(--brand-color)]/5' : 'border-gray-200 dark:border-gray-700' ?>">
                  <input type="radio" name="ai_chat_provider" value="gemini" <?= $provider === 'gemini' ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 accent-[var(--brand-color)] provider-radio">
                  <span>
                    <span class="block text-sm font-semibold text-gray-800 dark:text-gray-200">Google Gemini</span>
                    <span class="block text-xs text-gray-400 dark:text-gray-500 mt-0.5">Free tier: 1.500 req/hari</span>
                    <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener" class="text-xs text-[var(--brand-color)] hover:underline mt-1 inline-block">Daftar API key ↗</a>
                  </span>
                </label>
                <label class="flex items-start gap-3 p-3.5 rounded-lg border cursor-pointer transition <?= $provider === 'groq' ? 'border-[var(--brand-color)] bg-[var(--brand-color)]/5' : 'border-gray-200 dark:border-gray-700' ?>">
                  <input type="radio" name="ai_chat_provider" value="groq" <?= $provider === 'groq' ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 accent-[var(--brand-color)] provider-radio">
                  <span>
                    <span class="block text-sm font-semibold text-gray-800 dark:text-gray-200">Groq</span>
                    <span class="block text-xs text-gray-400 dark:text-gray-500 mt-0.5">Free tier: 30 req/menit</span>
                    <a href="https://console.groq.com/keys" target="_blank" rel="noopener" class="text-xs text-[var(--brand-color)] hover:underline mt-1 inline-block">Daftar API key ↗</a>
                  </span>
                </label>
              </div>

              <?php
              // Model hanya relevan utk Groq (Gemini dikunci ke gemini-2.0-flash).
              // Ditaruh di sini, tepat di bawah pilihan provider, supaya admin tak
              // perlu mencari-cari saat Groq memensiunkan sebuah model.
              $curModel = function_exists('aiChatModel') ? aiChatModel() : '';
              ?>
              <div class="mt-4 groq-only <?= $provider === 'groq' ? '' : 'hidden' ?>">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Model Groq</label>
                <select name="ai_chat_model"
                        class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <?php foreach (aiChatModelOptions() as $mid => $label): ?>
                  <option value="<?= e($mid) ?>" <?= $curModel === $mid ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1.5">Model lebih besar = jawaban lebih nyambung, tapi memakai lebih banyak kuota token per pesan. Kalau chat sering kena "lagi banyak yang bertanya", turunkan ke model yang lebih kecil.</p>
              </div>

              <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">API Key</label>
                <div class="relative">
                  <input type="password" name="ai_chat_api_key" id="apiKeyInput" value=""
                         autocomplete="new-password"
                         placeholder="<?= $apiKey !== '' ? '•••••••••• tersimpan — isi untuk mengganti' : ($provider === 'groq' ? 'gsk_xxxxxxxxxxxxxxxx' : 'AIzaSyxxxxxxxxxxxxxxxx') ?>"
                         class="w-full pr-10 px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <button type="button" onclick="aiTogglePw('apiKeyInput', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <svg class="w-4 h-4 eye-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="w-4 h-4 eye-hide hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                  </button>
                </div>
                <div class="flex items-center gap-3 mt-3">
                  <button type="button" id="testBtn" onclick="aiTestConnection()"
                          class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition disabled:opacity-50 whitespace-nowrap">Test Koneksi</button>
                  <p id="testResult" class="text-xs hidden"></p>
                </div>
              </div>
            </div>

            <!-- SECTION 3: KONFIGURASI BOT -->
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm mb-4">Konfigurasi Bot</h3>

              <div class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Nama Bot <span class="text-gray-400 font-normal">(maks 30 karakter)</span></label>
                  <input type="text" name="ai_chat_bot_name" id="botNameInput" maxlength="30" value="<?= e($botName) ?>" class="<?= $inputCls ?>">
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Pesan Sambutan</label>
                  <textarea name="ai_chat_welcome_message" rows="3" maxlength="200" class="<?= $inputCls ?>"><?= e($welcomeMsg) ?></textarea>
                </div>
                <div>
                  <div class="flex items-center justify-between mb-1.5">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">System Prompt</label>
                    <button type="button" onclick="useSalesTemplate()"
                            class="text-xs font-semibold px-2.5 py-1 rounded-lg border border-[var(--brand-color)] text-[var(--brand-color)] hover:bg-[var(--brand-color)] hover:text-white transition">
                      Pakai Template Sales Assistant
                    </button>
                  </div>
                  <textarea name="ai_chat_system_prompt" id="system-prompt-textarea"
                            style="min-height:180px;resize:vertical;overflow-y:hidden"
                            placeholder="Kamu adalah asisten toko online yang ramah. Jawab pertanyaan seputar produk, harga, dan cara pembelian. Jika tidak tahu, sarankan hubungi admin."
                            class="<?= $inputCls ?>"><?= e($systemPrompt) ?></textarea>
                  <div class="flex justify-between items-center mt-1">
                    <p class="text-xs text-gray-400 dark:text-gray-500">Instruksikan bot agar sesuai karakter toko kamu. Semakin spesifik, semakin relevan jawabannya.</p>
                    <span id="system-prompt-counter" class="text-xs text-gray-400 flex-shrink-0 ml-3">0 karakter</span>
                  </div>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">FAQ &amp; Kebijakan Toko <span class="font-normal text-gray-400">(pengetahuan AI di luar produk)</span></label>
                  <textarea name="ai_chat_faq" rows="6" style="resize:vertical"
                            placeholder="Contoh:&#10;T: Bagaimana cara pembayaran?&#10;J: Transfer bank / QRIS, akses terbuka otomatis setelah lunas.&#10;T: Apakah bisa refund?&#10;J: Garansi 7 hari uang kembali tanpa syarat."
                            class="<?= $inputCls ?>"><?= e((string) $cfg('ai_chat_faq', '')) ?></textarea>
                  <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Cara akses, pembayaran, refund, garansi, dsb. Tanpa ini AI akan menolak menjawab pertanyaan kebijakan (dia dilarang mengarang).</p>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-4 pt-1">
                  <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Sertakan katalog produk sebagai konteks AI</p>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Nama, harga, link beli, penawaran upsell, catatan AI Knowledge per produk, dan FAQ di atas (maks 30 produk).</p>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="hidden" name="ai_chat_inject_products" value="0">
                    <input type="checkbox" name="ai_chat_inject_products" value="1" <?= $injectProd ? 'checked' : '' ?> class="sr-only peer">
                    <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                    <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                  </label>
                </div>
              </div>
            </div>

            <!-- SECTION 4: FALLBACK WHATSAPP -->
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm mb-4">Fallback WhatsApp</h3>

              <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                  <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Aktifkan fallback ke WhatsApp</p>
                  <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Muncul sebagai tombol "Chat via WhatsApp" saat bot tidak bisa menjawab.</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                  <input type="hidden" name="ai_chat_fallback_wa" value="0">
                  <input type="checkbox" name="ai_chat_fallback_wa" value="1" id="chkFallbackWa" <?= $fallbackWa ? 'checked' : '' ?> class="sr-only peer">
                  <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                  <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                </label>
              </div>

              <div id="waNumberBox" class="mt-4 <?= $fallbackWa ? '' : 'hidden' ?>">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Nomor WhatsApp <span class="text-gray-400 font-normal">(format 628xxxxxxx)</span></label>
                <input type="text" name="ai_chat_fallback_wa_number" value="<?= e($fallbackWaNum) ?>" placeholder="628123456789" class="<?= $inputCls ?>">
              </div>
            </div>

            <!-- SECTION 5: TAMPILAN WIDGET (TEMA) -->
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm mb-1">Tampilan Widget</h3>
              <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Pilih Tema</p>

              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <?php
                $themes = [
                    'classic' => ['Classic Bubble', 'Familiar seperti WhatsApp. Cocok untuk semua toko.'],
                    'minimal' => ['Minimal Clean', 'Bersih & tidak mengganggu. Cocok untuk brand minimalis.'],
                    'elegant' => ['Card Elegant', 'Premium. Tampilkan chip pertanyaan pintas untuk visitor.'],
                ];
                foreach ($themes as $tid => $t):
                ?>
                <label class="theme-card relative flex flex-col gap-2 p-3 rounded-xl border cursor-pointer transition <?= $theme === $tid ? 'border-[var(--brand-color)] ring-1 ring-[var(--brand-color)]' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300' ?>" data-theme-card="<?= $tid ?>">
                  <input type="radio" name="ai_chat_theme" value="<?= $tid ?>" <?= $theme === $tid ? 'checked' : '' ?> class="sr-only theme-radio">
                  <!-- thumbnail mini -->
                  <div class="rounded-lg overflow-hidden border border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 h-20 flex items-center justify-center">
                    <?php if ($tid === 'classic'): ?>
                      <svg viewBox="0 0 80 56" class="w-full h-full"><rect width="80" height="14" fill="var(--brand-color)"/><rect x="8" y="22" width="34" height="8" rx="4" fill="#e5e7eb"/><rect x="38" y="34" width="34" height="8" rx="4" fill="var(--brand-color)"/></svg>
                    <?php elseif ($tid === 'minimal'): ?>
                      <svg viewBox="0 0 80 56" class="w-full h-full"><rect width="80" height="14" fill="#ffffff" stroke="#e5e7eb"/><circle cx="10" cy="7" r="3" fill="var(--brand-color)"/><rect x="8" y="24" width="34" height="7" rx="3.5" fill="#e5e7eb"/><rect x="40" y="36" width="32" height="7" rx="3.5" fill="var(--brand-color)"/></svg>
                    <?php else: ?>
                      <svg viewBox="0 0 80 56" class="w-full h-full"><rect width="80" height="18" fill="#ffffff" stroke="#e5e7eb"/><circle cx="12" cy="9" r="6" fill="var(--brand-color)"/><rect x="6" y="26" width="20" height="7" rx="3.5" fill="var(--brand-color)" opacity="0.2"/><rect x="30" y="26" width="20" height="7" rx="3.5" fill="var(--brand-color)" opacity="0.2"/><rect x="8" y="40" width="30" height="7" rx="3.5" fill="#e5e7eb"/></svg>
                    <?php endif; ?>
                  </div>
                  <span class="text-sm font-semibold text-gray-800 dark:text-gray-200"><?= e($t[0]) ?></span>
                  <span class="text-xs text-gray-400 dark:text-gray-500 leading-snug"><?= e($t[1]) ?></span>
                </label>
                <?php endforeach; ?>
              </div>

              <div id="quickRepliesBox" class="mt-4 <?= $theme === 'elegant' ? '' : 'hidden' ?>">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Quick Reply Chips</label>
                <input type="text" name="ai_chat_quick_replies" value="<?= e($quickRepliesStr) ?>" placeholder="💰 Harga, 📦 Produk, 🎓 Kelas" class="<?= $inputCls ?>">
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Maks 5 chip, masing-masing maks 30 karakter. Dipisah koma. Muncul saat chat dibuka.</p>
              </div>
            </div>

            <!-- SECTION 6: WARNA WIDGET -->
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm mb-4">Warna Widget</h3>

              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Sumber Warna</label>
              <div class="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 p-1 bg-gray-50 dark:bg-gray-800 mb-2">
                <label class="cursor-pointer">
                  <input type="radio" name="ai_chat_color_source" value="brand" <?= $colorSource === 'brand' ? 'checked' : '' ?> class="sr-only color-source-radio peer">
                  <span class="block px-4 py-1.5 rounded-md text-sm font-medium text-gray-600 dark:text-gray-300 peer-checked:bg-white dark:peer-checked:bg-gray-700 peer-checked:text-[var(--brand-color)] peer-checked:shadow-sm transition">Ikuti brand color</span>
                </label>
                <label class="cursor-pointer">
                  <input type="radio" name="ai_chat_color_source" value="custom" <?= $colorSource === 'custom' ? 'checked' : '' ?> class="sr-only color-source-radio peer">
                  <span class="block px-4 py-1.5 rounded-md text-sm font-medium text-gray-600 dark:text-gray-300 peer-checked:bg-white dark:peer-checked:bg-gray-700 peer-checked:text-[var(--brand-color)] peer-checked:shadow-sm transition">Kustom sendiri</span>
                </label>
              </div>

              <div id="customColorBox" class="mt-3 <?= $colorSource === 'custom' ? '' : 'hidden' ?>">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Warna Preset</label>
                <div id="swatchGrid" class="grid grid-cols-8 gap-2 mb-3">
                  <?php
                  $presets = ['#6366f1','#8b5cf6','#ec4899','#ef4444','#f97316','#eab308','#10b981','#3b82f6',
                              '#06b6d4','#14b8a6','#84cc16','#64748b','#1e293b','#7c3aed','#db2777','#0284c7'];
                  foreach ($presets as $pc):
                  ?>
                  <button type="button" class="swatch w-8 h-8 rounded-lg border-2 transition <?= strtolower($customColor) === strtolower($pc) ? 'border-gray-800 dark:border-white scale-110' : 'border-transparent' ?>" style="background:<?= e($pc) ?>" data-color="<?= e($pc) ?>" title="<?= e($pc) ?>"></button>
                  <?php endforeach; ?>
                </div>
                <div class="flex items-center gap-2">
                  <input type="color" id="colorPickerNative" value="<?= e($customColor) ?>" class="w-11 h-11 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer bg-transparent p-0.5">
                  <input type="text" name="ai_chat_color" id="hexInput" value="<?= e($customColor) ?>" maxlength="7" placeholder="#6366f1" class="w-32 px-3 py-2.5 rounded-lg text-sm font-mono border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
                  <span class="text-xs text-gray-400">Hex</span>
                </div>
              </div>

              <div class="mt-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Warna Teks Header</label>
                <div class="space-y-2">
                  <?php
                  $textOpts = ['auto' => 'Auto — otomatis hitam/putih (rekomendasi)', 'white' => 'Selalu putih', 'dark' => 'Selalu gelap (#1f2937)'];
                  foreach ($textOpts as $val => $lbl):
                  ?>
                  <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="radio" name="ai_chat_text_color" value="<?= $val ?>" <?= $textColor === $val ? 'checked' : '' ?> class="w-4 h-4 accent-[var(--brand-color)] text-color-radio">
                    <span class="text-sm text-gray-700 dark:text-gray-300"><?= e($lbl) ?></span>
                  </label>
                  <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1.5">Pilih Auto agar teks selalu terbaca di semua pilihan warna.</p>
              </div>

              <div class="flex flex-wrap items-center justify-between gap-4 mt-5">
                <div>
                  <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Efek Gradient pada Header</p>
                  <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Memberi tampilan lebih premium.</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                  <input type="hidden" name="ai_chat_gradient" value="0">
                  <input type="checkbox" name="ai_chat_gradient" value="1" id="chkGradient" <?= $gradient ? 'checked' : '' ?> class="sr-only peer">
                  <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                  <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                </label>
              </div>
            </div>

            <div class="flex justify-end pt-1">
              <button type="submit" id="save-btn"
                      class="px-6 py-2.5 rounded-lg text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed" style="background:var(--brand-color)">
                Simpan Pengaturan
              </button>
            </div>

          </div>

          <!-- ═══════════ KANAN: LIVE PREVIEW ═══════════ -->
          <div class="lg:col-span-1">
            <div class="lg:sticky lg:top-4 space-y-3">
              <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4">
                <h3 class="font-semibold text-gray-900 dark:text-white text-sm mb-3">Preview Widget</h3>

                <div class="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 p-0.5 bg-gray-50 dark:bg-gray-800 mb-3 text-xs">
                  <button type="button" class="preview-tab px-3 py-1 rounded-md font-medium transition" data-tab="classic">Classic</button>
                  <button type="button" class="preview-tab px-3 py-1 rounded-md font-medium transition" data-tab="minimal">Minimal</button>
                  <button type="button" class="preview-tab px-3 py-1 rounded-md font-medium transition" data-tab="elegant">Elegant</button>
                </div>

                <div class="relative rounded-xl p-4 min-h-[320px]" style="background:#f0f2f5">
                  <div id="previewMount"></div>
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">Preview di-render ulang otomatis saat pengaturan diubah.</p>
              </div>
            </div>
          </div>

        </div>
      </form>

    </main>
  </div>
</div>

<script>
// ─── Helper warna (client-side, cermin dari helpers.php) ──────────
function aiContrast(hex, mode) {
  if (mode === 'white') return '#ffffff';
  if (mode === 'dark')  return '#1f2937';
  hex = hex.replace('#','');
  if (hex.length !== 6) return '#ffffff';
  var r = parseInt(hex.substr(0,2),16)/255, g = parseInt(hex.substr(2,2),16)/255, b = parseInt(hex.substr(4,2),16)/255;
  function lin(c){ return c <= 0.03928 ? c/12.92 : Math.pow((c+0.055)/1.055, 2.4); }
  var lum = 0.2126*lin(r) + 0.7152*lin(g) + 0.0722*lin(b);
  return lum > 0.3 ? '#1f2937' : '#ffffff';
}
function aiShade(hex, pct) {
  hex = hex.replace('#','');
  if (hex.length !== 6) return '#' + hex;
  function ch(i){ var v = parseInt(hex.substr(i,2),16); return Math.max(0, Math.min(255, v + Math.trunc(v*pct/100))); }
  function hx(n){ return ('0'+n.toString(16)).slice(-2); }
  return '#' + hx(ch(0)) + hx(ch(2)) + hx(ch(4));
}
function aiIsHex(v){ return /^#[0-9A-Fa-f]{6}$/.test(v); }

// ─── State getters dari form ──────────────────────────────────────
function aiBaseColor() {
  var src = document.querySelector('input[name="ai_chat_color_source"]:checked').value;
  if (src === 'custom') {
    var h = document.getElementById('hexInput').value;
    return aiIsHex(h) ? h : '#6366f1';
  }
  return <?= json_encode($brandColor) ?>;
}

var previewTheme = <?= json_encode($theme) ?>;

function aiRenderPreview() {
  var base   = aiBaseColor();
  var mode   = document.querySelector('input[name="ai_chat_text_color"]:checked').value;
  var grad   = document.getElementById('chkGradient').checked;
  var txt    = aiContrast(base, mode);
  var dark   = aiShade(base, -22);
  var bg     = grad ? ('linear-gradient(135deg,' + base + ',' + dark + ')') : base;
  var botName = document.getElementById('botNameInput').value || 'Asisten';
  var initial = botName.charAt(0).toUpperCase();
  var theme  = previewTheme;
  var mount  = document.getElementById('previewMount');

  var header;
  if (theme === 'minimal') {
    header = '<div style="background:#fff;border-bottom:1px solid #e5e7eb;border-radius:14px 14px 0 0;padding:10px 12px;display:flex;align-items:center;gap:8px">'
      + '<span style="width:8px;height:8px;border-radius:50%;background:' + base + '"></span>'
      + '<span style="font-weight:600;font-size:12px;color:#1f2937">' + aiEsc(botName) + '</span>'
      + '<span style="margin-left:auto;color:#9ca3af;font-size:14px">&times;</span></div>';
  } else if (theme === 'elegant') {
    header = '<div style="background:#fff;border-bottom:1px solid ' + base + '22;border-radius:14px 14px 0 0;padding:12px;display:flex;align-items:center;gap:10px">'
      + '<div style="width:34px;height:34px;border-radius:50%;background:' + bg + ';display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;color:' + txt + '">' + aiEsc(initial) + '</div>'
      + '<div><div style="font-weight:600;font-size:12px;color:#1f2937">' + aiEsc(botName) + '</div>'
      + '<div style="font-size:10px;color:' + base + '">Powered by AI · Selalu online</div></div></div>';
  } else {
    header = '<div style="background:' + bg + ';border-radius:14px 14px 0 0;padding:10px 12px;display:flex;align-items:center;gap:8px">'
      + '<div style="width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px;color:' + txt + '">' + aiEsc(initial) + '</div>'
      + '<div><div style="font-weight:600;font-size:12px;color:' + txt + '">' + aiEsc(botName) + '</div>'
      + '<div style="font-size:10px;color:' + txt + ';opacity:0.8">● Online</div></div>'
      + '<span style="margin-left:auto;color:' + txt + ';font-size:13px">&times;</span></div>';
  }

  var chips = '';
  if (theme === 'elegant') {
    var qr = (document.querySelector('input[name="ai_chat_quick_replies"]').value || '').split(',').map(function(x){return x.trim();}).filter(Boolean).slice(0,5);
    if (qr.length) {
      chips = '<div style="padding:0 10px 8px;display:flex;flex-wrap:wrap;gap:5px">';
      qr.forEach(function(c){ chips += '<span style="background:' + base + '1a;border:1px solid ' + base + '33;color:' + base + ';border-radius:16px;font-size:11px;padding:3px 9px">' + aiEsc(c) + '</span>'; });
      chips += '</div>';
    }
  }

  var body = '<div style="background:#f9fafb;padding:10px;display:flex;flex-direction:column;gap:6px;min-height:110px">'
    + '<div style="align-self:flex-start;background:#f3f4f6;color:#374151;border-radius:12px 12px 12px 2px;padding:7px 10px;font-size:12px;max-width:80%">Halo! Ada yang bisa saya bantu?</div>'
    + '<div style="align-self:flex-end;background:' + bg + ';color:' + txt + ';border-radius:12px 12px 2px 12px;padding:7px 10px;font-size:12px;max-width:80%">Berapa harga kelasnya?</div>'
    + '<div style="align-self:flex-start;background:#f3f4f6;color:#374151;border-radius:12px 12px 12px 2px;padding:7px 10px;font-size:12px;max-width:80%">Tentu, berikut info harganya...</div>'
    + '</div>';

  var sendBtnStyle = theme === 'minimal'
    ? 'background:' + bg + ';color:' + txt + ';border:none;border-radius:16px;padding:6px 14px;font-size:12px;font-weight:600;cursor:pointer'
    : 'width:30px;height:30px;border-radius:50%;background:' + bg + ';border:none;display:flex;align-items:center;justify-content:center';
  var sendBtnInner = theme === 'minimal' ? 'Kirim'
    : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="' + txt + '" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';

  var input = '<div style="padding:8px 10px;border-top:1px solid #e5e7eb;background:#fff;border-radius:0 0 14px 14px;display:flex;gap:6px;align-items:center">'
    + '<div style="flex:1;border:1px solid #e5e7eb;border-radius:16px;padding:6px 12px;font-size:12px;color:#9ca3af;background:#fff">Tulis pesan...</div>'
    + '<button style="' + sendBtnStyle + '">' + sendBtnInner + '</button></div>';

  var panel = '<div style="width:100%;max-width:280px;border-radius:14px;box-shadow:0 8px 24px rgba(0,0,0,0.15);overflow:hidden;background:#fff">'
    + header + body + chips + input + '</div>';

  var fab = '<div style="display:flex;justify-content:flex-end;margin-top:12px">'
    + '<div style="width:48px;height:48px;border-radius:50%;background:' + bg + ';display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px ' + base + '66">'
    + '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="' + txt + '" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div></div>';

  mount.innerHTML = panel + fab;
}

function aiEsc(str){ var d = document.createElement('div'); d.textContent = str; return d.innerHTML; }

// ─── Toggle password ──────────────────────────────────────────────
function aiTogglePw(id, btn) {
  var inp = document.getElementById(id);
  var show = btn.querySelector('.eye-show'), hide = btn.querySelector('.eye-hide');
  if (inp.type === 'password') { inp.type = 'text'; show.classList.add('hidden'); hide.classList.remove('hidden'); }
  else { inp.type = 'password'; show.classList.remove('hidden'); hide.classList.add('hidden'); }
}

// ─── Test koneksi ─────────────────────────────────────────────────
function aiTestConnection() {
  var btn = document.getElementById('testBtn');
  var out = document.getElementById('testResult');
  var provider = document.querySelector('input[name="ai_chat_provider"]:checked').value;
  var apiKey = document.getElementById('apiKeyInput').value;
  btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Menguji...';
  out.classList.remove('hidden'); out.className = 'text-xs text-gray-400'; out.textContent = 'Menghubungi provider...';

  var body = new URLSearchParams({ csrf_token: <?= json_encode($_SESSION['csrf_token']) ?>, provider: provider, api_key: apiKey });
  fetch(<?= json_encode(APP_URL . '/actions/admin/test-ai-chat') ?>, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body })
    .then(function(r){ return r.json(); })
    .then(function(d){
      btn.disabled = false; btn.textContent = orig;
      if (d.success) { out.className = 'text-xs text-green-600 dark:text-green-400 font-medium'; out.textContent = '✓ ' + d.message; }
      else { out.className = 'text-xs text-red-600 dark:text-red-400 font-medium'; out.textContent = '✗ ' + d.message; }
    })
    .catch(function(){
      btn.disabled = false; btn.textContent = orig;
      out.className = 'text-xs text-red-600 dark:text-red-400 font-medium'; out.textContent = '✗ Gagal menghubungi server.';
    });
}

// ─── Wiring interaksi ─────────────────────────────────────────────
(function() {
  // Toggle enabled → aktif/nonaktif checkbox lokasi
  var chkEnabled = document.getElementById('chkEnabled');
  var locBox = document.getElementById('locationBox');
  var notice = document.getElementById('enabledNotice');
  chkEnabled.addEventListener('change', function() {
    if (this.checked) {
      locBox.classList.remove('opacity-50','pointer-events-none');
      notice.textContent = 'Widget aktif sesuai pilihan lokasi di bawah.';
      notice.className = 'text-xs mt-0.5 text-gray-400 dark:text-gray-500';
    } else {
      locBox.classList.add('opacity-50','pointer-events-none');
      notice.textContent = 'Widget tidak akan muncul di manapun.';
      notice.className = 'text-xs mt-0.5 text-amber-600 dark:text-amber-400';
    }
  });

  // Provider radio → update placeholder API key + highlight card
  document.querySelectorAll('.provider-radio').forEach(function(r){
    r.addEventListener('change', function(){
      document.getElementById('apiKeyInput').placeholder = this.value === 'groq' ? 'gsk_xxxxxxxxxxxxxxxx' : 'AIzaSyxxxxxxxxxxxxxxxx';
      // Pilihan model hanya berlaku utk Groq — Gemini terkunci ke gemini-2.0-flash.
      var isGroq = this.value === 'groq';
      document.querySelectorAll('.groq-only').forEach(function(el){ el.classList.toggle('hidden', !isGroq); });
      document.querySelectorAll('.provider-radio').forEach(function(x){
        var card = x.closest('label');
        if (x.checked) { card.classList.add('border-[var(--brand-color)]'); card.classList.add('bg-[var(--brand-color)]/5'); }
        else { card.classList.remove('border-[var(--brand-color)]'); card.classList.remove('bg-[var(--brand-color)]/5'); card.classList.add('border-gray-200','dark:border-gray-700'); }
      });
    });
  });

  // Fallback WA toggle
  document.getElementById('chkFallbackWa').addEventListener('change', function(){
    document.getElementById('waNumberBox').classList.toggle('hidden', !this.checked);
  });

  // Tema radio → toggle quick replies box + highlight card + preview + tab sync
  document.querySelectorAll('.theme-radio').forEach(function(r){
    r.addEventListener('change', function(){
      previewTheme = this.value;
      document.getElementById('quickRepliesBox').classList.toggle('hidden', this.value !== 'elegant');
      document.querySelectorAll('.theme-card').forEach(function(card){
        var active = card.getAttribute('data-theme-card') === previewTheme;
        card.classList.toggle('border-[var(--brand-color)]', active);
        card.classList.toggle('ring-1', active);
        card.classList.toggle('ring-[var(--brand-color)]', active);
      });
      aiSyncTabs(); aiRenderPreview();
    });
  });

  // Color source
  document.querySelectorAll('.color-source-radio').forEach(function(r){
    r.addEventListener('change', function(){
      document.getElementById('customColorBox').classList.toggle('hidden', this.value !== 'custom');
      aiRenderPreview();
    });
  });

  // Swatch klik → sync picker + hex
  document.querySelectorAll('.swatch').forEach(function(sw){
    sw.addEventListener('click', function(){
      var c = this.getAttribute('data-color');
      document.getElementById('colorPickerNative').value = c;
      document.getElementById('hexInput').value = c;
      aiMarkSwatch(c); aiRenderPreview();
    });
  });
  function aiMarkSwatch(c){
    document.querySelectorAll('.swatch').forEach(function(sw){
      var active = sw.getAttribute('data-color').toLowerCase() === c.toLowerCase();
      sw.classList.toggle('border-gray-800', active);
      sw.classList.toggle('dark:border-white', active);
      sw.classList.toggle('scale-110', active);
      sw.classList.toggle('border-transparent', !active);
    });
  }

  // Native picker → hex
  document.getElementById('colorPickerNative').addEventListener('input', function(){
    document.getElementById('hexInput').value = this.value;
    aiMarkSwatch(this.value); aiRenderPreview();
  });
  // Hex input → picker (validasi)
  document.getElementById('hexInput').addEventListener('input', function(){
    var v = this.value.trim();
    if (aiIsHex(v)) { document.getElementById('colorPickerNative').value = v; aiMarkSwatch(v); aiRenderPreview(); }
  });

  // Text color radio + gradient + bot name → preview
  document.querySelectorAll('.text-color-radio').forEach(function(r){ r.addEventListener('change', aiRenderPreview); });
  document.getElementById('chkGradient').addEventListener('change', aiRenderPreview);
  document.getElementById('botNameInput').addEventListener('input', aiRenderPreview);
  var qrInput = document.querySelector('input[name="ai_chat_quick_replies"]');
  if (qrInput) qrInput.addEventListener('input', aiRenderPreview);

  // Preview tabs → set previewTheme + sync radio tema
  function aiSyncTabs(){
    document.querySelectorAll('.preview-tab').forEach(function(t){
      var active = t.getAttribute('data-tab') === previewTheme;
      t.classList.toggle('bg-white', active);
      t.classList.toggle('dark:bg-gray-700', active);
      t.classList.toggle('text-[var(--brand-color)]', active);
      t.classList.toggle('shadow-sm', active);
      t.classList.toggle('text-gray-500', !active);
    });
  }
  window.aiSyncTabs = aiSyncTabs;
  document.querySelectorAll('.preview-tab').forEach(function(t){
    t.addEventListener('click', function(){
      previewTheme = this.getAttribute('data-tab');
      // sync radio tema di form kiri
      var radio = document.querySelector('.theme-radio[value="' + previewTheme + '"]');
      if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change')); }
      else { aiSyncTabs(); aiRenderPreview(); }
    });
  });

  aiSyncTabs();
  aiRenderPreview();
})();

// Auto-resize textarea System Prompt (tumbuh tak terbatas sesuai konten) + counter
(function () {
  var ta = document.getElementById('system-prompt-textarea');
  if (!ta) return;
  var counter = document.getElementById('system-prompt-counter');

  function resize() {
    ta.style.height = 'auto';
    ta.style.height = ta.scrollHeight + 'px';
  }
  function updateCounter() {
    if (counter) counter.textContent = ta.value.length.toLocaleString('id-ID') + ' karakter';
  }

  resize();          // buka penuh konten yang sudah ada saat load
  updateCounter();   // init hitungan
  ta.addEventListener('input', function () { resize(); updateCounter(); });
})();

// Submit via AJAX — simpan tanpa reload & tanpa loncat ke atas, tampilkan toast
(function () {
  var form      = document.getElementById('ai-chat-settings-form');
  var btn       = document.getElementById('save-btn');
  var toast     = document.getElementById('save-toast');
  var toastMsg  = document.getElementById('save-toast-msg');
  var toastIcon = document.getElementById('save-toast-icon');
  if (!form || !btn) return;

  function hideToast() { toast.style.opacity = '0'; toast.style.transform = 'translateY(8px)'; setTimeout(function(){ toast.style.display = 'none'; }, 300); }
  function showToast(type, message) {
    var ok = type === 'success';
    // Sukses = tint brand lembut (bukan hijau pekat); error = merah lembut.
    toastIcon.style.background = ok ? 'color-mix(in srgb, var(--brand-color) 14%, transparent)' : 'rgba(239,68,68,.12)';
    toastIcon.style.color      = ok ? 'var(--brand-color)' : '#ef4444';
    toastIcon.innerHTML = ok
      ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
      : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    toastMsg.textContent  = message;
    toast.style.display   = 'flex';
    void toast.offsetHeight;      // reflow → transisi jalan
    toast.style.opacity   = '1';
    toast.style.transform = 'translateY(0)';
    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(hideToast, 3000);
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    // Simpan posisi scroll (container = <main>, window sbg cadangan)
    var mainEl     = document.querySelector('main');
    var mainScroll = mainEl ? mainEl.scrollTop : 0;
    var winScroll  = window.scrollY;

    var originalLabel = btn.textContent;
    btn.disabled    = true;
    btn.textContent = 'Menyimpan...';

    fetch(form.action, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new FormData(form)
    })
      .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Respons tidak valid.' }; }); })
      .then(function (data) {
        if (data && data.success) showToast('success', data.message || 'Pengaturan berhasil disimpan.');
        else showToast('error', (data && data.message) || 'Terjadi kesalahan. Coba lagi.');
      })
      .catch(function () { showToast('error', 'Gagal terhubung ke server. Periksa koneksi kamu.'); })
      .finally(function () {
        btn.disabled    = false;
        btn.textContent = originalLabel;
        if (mainEl) mainEl.scrollTop = mainScroll;
        window.scrollTo({ top: winScroll, behavior: 'instant' });
      });
  });
})();
</script>

<script>
// Preset persona Sales Assistant (aiSalesPersonaTemplate di helpers.php).
var SALES_TEMPLATE = <?= json_encode(aiSalesPersonaTemplate()) ?>;
function useSalesTemplate() {
  var ta = document.getElementById('system-prompt-textarea');
  if (!ta) return;
  if (ta.value.trim() !== '' && ta.value.trim() !== SALES_TEMPLATE.trim() && typeof showConfirmModal === 'function') {
    showConfirmModal({
      title: 'Ganti System Prompt?',
      message: 'System prompt yang ada akan diganti dengan template Sales Assistant. Lanjutkan?',
      confirmText: 'Ganti',
      variant: 'warning'
    }).then(function (ok) { if (ok) applySalesTemplate(ta); });
    return;
  }
  applySalesTemplate(ta);
}
function applySalesTemplate(ta) {
  ta.value = SALES_TEMPLATE;
  ta.dispatchEvent(new Event('input')); // sinkronkan counter & auto-height
  ta.focus();
}
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

<?php
// partials/ai-chat-widget.php
// Floating AI Chat widget. Di-include dari partials/footer.php (sebelum </body>).
// Mengandung guard sendiri: aman di-include di semua halaman.
require_once dirname(__DIR__) . '/bootstrap.php';

// ─── LOGIKA TAMPIL/SEMBUNYIKAN ────────────────────────────────
$chatEnabled = getSetting('ai_chat_enabled') === '1';
if (!$chatEnabled) return;

// Mode "force": di-include dari canvas-render.php (bukan footer) untuk sebuah
// Canvas yang mengaktifkan AI Chat per-halaman. Lewati guard URI (canvas publik),
// dan terima produk fokus (salespage) untuk mengarahkan knowledge AI.
$__avChatForce = !empty($GLOBALS['__av_chat_force']);
$__avChatFocus = (int) ($GLOBALS['__av_chat_focus_pid'] ?? 0);

if (!$__avChatForce) {
    $uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    // Normalisasi base path (support instalasi sub-folder mis. /averion).
    $__base = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?? '', '/');
    if ($__base !== '' && str_starts_with($uri, $__base)) {
        $uri = substr($uri, strlen($__base));
    }
    if ($uri === '' || $uri[0] !== '/') $uri = '/' . $uri;

    $isAdminPage  = str_starts_with($uri, '/admin');
    $isMemberPage = str_starts_with($uri, '/member') || str_starts_with($uri, '/dashboard')
                 || str_starts_with($uri, '/profile') || str_starts_with($uri, '/transactions')
                 || str_starts_with($uri, '/affiliate') || str_starts_with($uri, '/downloads')
                 || str_starts_with($uri, '/certificates');
    $isPublicPage = !$isAdminPage && !$isMemberPage;

    $showOnPublic = getSetting('ai_chat_show_public') === '1';
    $showOnMember = getSetting('ai_chat_show_member') === '1';

    if ($isAdminPage) return;                          // JANGAN pernah tampil di admin
    if ($isPublicPage && !$showOnPublic) return;
    if ($isMemberPage && !$showOnMember) return;
}

// ─── KONFIGURASI WARNA ────────────────────────────────────────
$colorSource = getSetting('ai_chat_color_source', 'brand');
$baseColor   = $colorSource === 'custom'
    ? getSetting('ai_chat_color', '#6366f1')
    : getSetting('brand_color', '#6366f1');
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $baseColor)) {
    $baseColor = '#6366f1';
}

$textColorMode   = getSetting('ai_chat_text_color', 'auto');
$headerTextColor = getContrastTextColor($baseColor, $textColorMode);
$colorDark       = shadeHexColor($baseColor, -22);
$useGradient     = getSetting('ai_chat_gradient') === '1';
$bgStyle         = $useGradient ? "linear-gradient(135deg, {$baseColor}, {$colorDark})" : $baseColor;
$colorLight      = hexToRgba($baseColor, 0.1);
$colorLighter    = hexToRgba($baseColor, 0.2);
$shadowColor     = hexToRgba($baseColor, 0.4);

// ─── KONFIGURASI LAIN ─────────────────────────────────────────
$theme           = getSetting('ai_chat_theme', 'classic');
if (!in_array($theme, ['classic', 'minimal', 'elegant'], true)) $theme = 'classic';
$botName         = getSetting('ai_chat_bot_name', 'Asisten');
$welcomeMsg      = getSetting('ai_chat_welcome_message', 'Halo! Ada yang bisa saya bantu?');
$showWaFallback  = getSetting('ai_chat_fallback_wa') === '1';
$waNumber        = getSetting('ai_chat_fallback_wa_number', '');
$quickRepliesRaw = getSetting('ai_chat_quick_replies', '[]');
$quickReplies    = json_decode($quickRepliesRaw, true);
if (!is_array($quickReplies)) $quickReplies = [];
$quickReplies    = array_slice($quickReplies, 0, 5);

$botInitial = strtoupper(mb_substr($botName, 0, 1));
?>
<style>
  #averion-chat-widget {
    --averion-chat-color: <?= e($baseColor) ?>;
    --averion-chat-color-dark: <?= e($colorDark) ?>;
    --averion-chat-text: <?= e($headerTextColor) ?>;
    --averion-chat-bg: <?= e($bgStyle) ?>;
    --averion-chat-light: <?= e($colorLight) ?>;
    --averion-chat-lighter: <?= e($colorLighter) ?>;
    --averion-chat-shadow: <?= e($shadowColor) ?>;
  }
  #averion-chat-trigger {
    position: fixed; bottom: 24px; right: 24px; z-index: 2147483000;
    width: 56px; height: 56px; border-radius: 50%; border: none; cursor: pointer;
    background: var(--averion-chat-bg); color: var(--averion-chat-text);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 16px var(--averion-chat-shadow);
    transition: transform .15s ease, box-shadow .15s ease;
  }
  #averion-chat-trigger:hover { transform: scale(1.06); }
  #averion-chat-trigger .icon-close { display: none; }
  #averion-chat-widget.averion-chat-open #averion-chat-trigger .icon-chat { display: none; }
  #averion-chat-widget.averion-chat-open #averion-chat-trigger .icon-close { display: block; }
  #averion-chat-panel {
    position: fixed; bottom: 92px; right: 24px; z-index: 2147483000;
    width: 370px; max-width: calc(100vw - 32px);
    /* Tinggi proporsional layar (bukan sekadar mengikuti isi) — konsisten & tak
       terlalu pendek, dibatasi agar tak melebihi viewport di layar kecil. */
    height: min(74vh, 640px); max-height: calc(100vh - 120px);
    display: flex; flex-direction: column;
    background: #fff; border-radius: 16px; overflow: hidden;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18);
    opacity: 0; transform: translateY(12px) scale(0.98);
    transition: opacity .18s ease, transform .18s ease;
  }
  #averion-chat-panel.averion-chat-open { opacity: 1; transform: translateY(0) scale(1); }
  #averion-chat-panel.hidden { display: none; }
  .dark #averion-chat-panel { background: #1f2937; }
  #averion-chat-messages::-webkit-scrollbar { width: 6px; }
  #averion-chat-messages::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.15); border-radius: 3px; }
  @keyframes averion-bounce { 0%,80%,100% { transform: translateY(0); } 40% { transform: translateY(-6px); } }
  .averion-typing-dot { width: 6px; height: 6px; border-radius: 50%; background: #9ca3af; display: inline-block; animation: averion-bounce 1.2s infinite ease-in-out; }
  @media (max-width: 420px) {
    #averion-chat-panel { right: 12px; bottom: 84px; }
    #averion-chat-trigger { right: 16px; bottom: 16px; }
  }
</style>

<div id="averion-chat-widget"
     data-theme="<?= e($theme) ?>"
     data-bot-name="<?= e($botName) ?>"
     data-welcome="<?= e($welcomeMsg) ?>"
     data-wa-number="<?= e($waNumber) ?>"
     data-show-wa="<?= $showWaFallback ? '1' : '0' ?>"
     data-quick-replies="<?= e($quickRepliesRaw) ?>"
     data-focus-product="<?= $__avChatFocus > 0 ? (int) $__avChatFocus : '' ?>"
     data-endpoint="<?= e(rtrim(APP_URL, '/') . '/actions/chat/send') ?>">

  <!-- FAB TRIGGER -->
  <button id="averion-chat-trigger" aria-label="Buka chat">
    <svg class="icon-chat" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
    </svg>
    <svg class="icon-close" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
    </svg>
  </button>

  <!-- PANEL -->
  <div id="averion-chat-panel" class="hidden">

    <?php if ($theme === 'minimal'): ?>
    <!-- ── TEMA: minimal ── -->
    <div style="background:#fff;border-bottom:1px solid #e5e7eb;border-radius:16px 16px 0 0;padding:12px 16px;display:flex;align-items:center;gap:10px">
      <span style="width:9px;height:9px;border-radius:50%;background:var(--averion-chat-color);flex-shrink:0"></span>
      <div style="font-weight:600;font-size:13px;color:#1f2937"><?= e($botName) ?></div>
      <button id="averion-chat-close" aria-label="Tutup" style="margin-left:auto;background:none;border:none;cursor:pointer;color:#9ca3af;font-size:18px;line-height:1;padding:0 2px">&times;</button>
    </div>

    <?php elseif ($theme === 'elegant'): ?>
    <!-- ── TEMA: elegant ── -->
    <div style="background:#fff;border-bottom:1px solid var(--averion-chat-lighter);border-radius:16px 16px 0 0;padding:14px 16px;display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:50%;background:var(--averion-chat-bg);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:17px;color:var(--averion-chat-text);flex-shrink:0"><?= e($botInitial) ?></div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;font-size:14px;color:#1f2937"><?= e($botName) ?></div>
        <div style="font-size:11px;color:var(--averion-chat-color)">Powered by AI · Selalu online</div>
      </div>
      <button id="averion-chat-close" aria-label="Tutup" style="background:var(--averion-chat-light);border:none;border-radius:50%;width:28px;height:28px;cursor:pointer;color:var(--averion-chat-color);font-size:15px;display:flex;align-items:center;justify-content:center">&times;</button>
    </div>

    <?php else: ?>
    <!-- ── TEMA: classic ── -->
    <div style="background:var(--averion-chat-bg);border-radius:16px 16px 0 0;padding:12px 16px;display:flex;align-items:center;gap:10px">
      <div style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;color:var(--averion-chat-text);flex-shrink:0"><?= e($botInitial) ?></div>
      <div>
        <div style="font-weight:600;font-size:13px;color:var(--averion-chat-text)"><?= e($botName) ?></div>
        <div style="font-size:11px;color:var(--averion-chat-text);opacity:0.8">● Online</div>
      </div>
      <button id="averion-chat-close" aria-label="Tutup" style="margin-left:auto;background:rgba(255,255,255,0.2);border:none;border-radius:50%;width:28px;height:28px;cursor:pointer;color:var(--averion-chat-text);font-size:14px;display:flex;align-items:center;justify-content:center">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Area pesan (ID sama untuk semua tema) -->
    <div id="averion-chat-messages" style="flex:1 1 auto;min-height:0;overflow-y:auto;padding:12px 14px;display:flex;flex-direction:column;gap:8px;background:#f9fafb"></div>

    <?php if ($theme === 'elegant'): ?>
    <!-- Quick reply chips (elegant) -->
    <div id="averion-chat-chips" style="padding:8px 14px 0;display:flex;flex-wrap:wrap;gap:6px"></div>
    <?php endif; ?>

    <!-- Input -->
    <div style="padding:10px 12px;border-top:1px solid #e5e7eb;background:#fff;border-radius:0 0 16px 16px;display:flex;gap:8px;align-items:flex-end">
      <textarea id="averion-chat-input" rows="1" placeholder="Tulis pesan..." style="flex:1;border:1px solid #e5e7eb;border-radius:20px;padding:8px 14px;font-size:13px;resize:none;outline:none;max-height:80px;line-height:1.4;font-family:inherit;color:#111827;background:#fff"></textarea>
      <?php if ($theme === 'minimal'): ?>
      <button id="averion-chat-send" aria-label="Kirim" style="background:var(--averion-chat-bg);color:var(--averion-chat-text);border:none;border-radius:20px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;flex-shrink:0">Kirim</button>
      <?php else: ?>
      <button id="averion-chat-send" aria-label="Kirim" style="width:36px;height:36px;border-radius:50%;background:var(--averion-chat-bg);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--averion-chat-text)" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
      </button>
      <?php endif; ?>
    </div>

    <!-- Fallback WhatsApp (hidden by default) -->
    <div id="averion-chat-wa" style="display:none;padding:10px 14px;border-top:1px solid #e5e7eb;text-align:center;background:#fff">
      <a id="averion-chat-wa-link" href="#" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;color:#16a34a;font-size:12px;font-weight:500;text-decoration:none">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2z"/></svg>
        Lanjut chat via WhatsApp
      </a>
    </div>

  </div>
</div>

<script src="<?= e(APP_URL) ?>/assets/js/ai-chat.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/ai-chat.js') ?: '1' ?>"></script>

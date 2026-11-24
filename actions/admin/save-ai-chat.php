<?php
// POST /actions/admin/save-ai-chat
// Simpan seluruh konfigurasi AI Chat Widget ke tabel settings (grup ai_chat).
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

// Deteksi request AJAX (dari fetch di halaman ai-chat) → balas JSON, bukan redirect.
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

/**
 * Balas hasil simpan. AJAX → JSON (tanpa reload halaman); non-AJAX → flash + PRG
 * redirect (fallback bila JS mati). Selalu exit.
 */
function aiChatSaveRespond(bool $isAjax, bool $success, string $message, int $httpCode = 200): void
{
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code($httpCode);
        echo json_encode(['success' => $success, 'message' => $message]);
        exit;
    }
    flash($success ? 'success' : 'error', $message, $success ? 'success' : 'error');
    redirect('/admin/ai-chat');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aiChatSaveRespond($isAjax, false, 'Metode tidak diizinkan.', 405);
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    aiChatSaveRespond($isAjax, false, 'Token keamanan tidak valid. Silakan coba lagi.', 422);
}

// Whitelist key yang boleh di-update lewat form ini.
$allowed = [
    'ai_chat_enabled', 'ai_chat_show_public', 'ai_chat_show_member',
    'ai_chat_provider', 'ai_chat_api_key', 'ai_chat_model',
    'ai_chat_bot_name', 'ai_chat_welcome_message', 'ai_chat_system_prompt',
    'ai_chat_inject_products', 'ai_chat_faq',
    'ai_chat_fallback_wa', 'ai_chat_fallback_wa_number',
    'ai_chat_theme', 'ai_chat_quick_replies',
    'ai_chat_color_source', 'ai_chat_color',
    'ai_chat_text_color', 'ai_chat_gradient',
];

// Kumpulkan nilai tersanitasi per key.
$values = [];

// ── Checkbox: '1' bila hadir & truthy, else '0' ──
$checkbox = fn(string $k): string => (isset($_POST[$k]) && $_POST[$k] !== '0' && $_POST[$k] !== '') ? '1' : '0';
$values['ai_chat_enabled']         = $checkbox('ai_chat_enabled');
$values['ai_chat_show_public']     = $checkbox('ai_chat_show_public');
$values['ai_chat_show_member']     = $checkbox('ai_chat_show_member');
$values['ai_chat_inject_products'] = $checkbox('ai_chat_inject_products');
$values['ai_chat_fallback_wa']     = $checkbox('ai_chat_fallback_wa');
$values['ai_chat_gradient']        = $checkbox('ai_chat_gradient');

// ── Enum-terbatas ──
$provider = strtolower(trim($_POST['ai_chat_provider'] ?? ''));
$values['ai_chat_provider'] = in_array($provider, ['gemini', 'groq'], true) ? $provider : 'gemini';

// Model Groq: whitelist ketat — nilai asal bisa bikin chat mati total (400 dari
// provider) tanpa admin tahu sebabnya. Di luar daftar → pakai default.
$model = trim($_POST['ai_chat_model'] ?? '');
$values['ai_chat_model'] = array_key_exists($model, aiChatModelOptions()) ? $model : aiChatModel();

$theme = strtolower(trim($_POST['ai_chat_theme'] ?? ''));
$values['ai_chat_theme'] = in_array($theme, ['classic', 'minimal', 'elegant'], true) ? $theme : 'classic';

$colorSource = strtolower(trim($_POST['ai_chat_color_source'] ?? ''));
$values['ai_chat_color_source'] = in_array($colorSource, ['brand', 'custom'], true) ? $colorSource : 'brand';

$textColor = strtolower(trim($_POST['ai_chat_text_color'] ?? ''));
$values['ai_chat_text_color'] = in_array($textColor, ['auto', 'white', 'dark'], true) ? $textColor : 'auto';

// ── Warna hex ──
$color = trim($_POST['ai_chat_color'] ?? '');
$values['ai_chat_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : '#6366f1';

// ── API key (biarkan apa adanya, hanya trim) ──
$values['ai_chat_api_key'] = trim($_POST['ai_chat_api_key'] ?? '');

// ── Teks dengan batas panjang ──
$values['ai_chat_bot_name']        = mb_substr(trim(strip_tags($_POST['ai_chat_bot_name'] ?? '')), 0, 30);
$values['ai_chat_welcome_message'] = mb_substr(trim(strip_tags($_POST['ai_chat_welcome_message'] ?? '')), 0, 200);
// System prompt: batas sangat longgar (20.000 char) — cukup untuk instruksi panjang.
// Kolom settings.setting_value = MEDIUMTEXT (lihat stifin-install.sql / migrations/update-*.sql).
$values['ai_chat_system_prompt']   = mb_substr(trim(strip_tags($_POST['ai_chat_system_prompt'] ?? '')), 0, 20000);
// FAQ & kebijakan toko: pengetahuan AI di luar produk (buildAiSalesContext).
$values['ai_chat_faq']             = mb_substr(trim(strip_tags($_POST['ai_chat_faq'] ?? '')), 0, 8000);

// ── Nomor WhatsApp: strip non-digit, pastikan diawali 62, maks 15 digit ──
$wa = preg_replace('/[^0-9]/', '', $_POST['ai_chat_fallback_wa_number'] ?? '');
if ($wa !== '') {
    if (str_starts_with($wa, '620'))      $wa = '62' . substr($wa, 3);
    elseif (str_starts_with($wa, '0'))    $wa = '62' . substr($wa, 1);
    elseif (str_starts_with($wa, '8'))    $wa = '62' . $wa;
    elseif (!str_starts_with($wa, '62'))  $wa = '62' . $wa;
    $wa = substr($wa, 0, 15);
}
$values['ai_chat_fallback_wa_number'] = $wa;

// ── Quick replies: string dipisah koma → JSON array (maks 5, tiap 30 char) ──
$qrRaw = $_POST['ai_chat_quick_replies'] ?? '';
$items = [];
foreach (explode(',', $qrRaw) as $chip) {
    $chip = mb_substr(trim(strip_tags($chip)), 0, 30);
    if ($chip !== '') $items[] = $chip;
    if (count($items) >= 5) break;
}
$values['ai_chat_quick_replies'] = json_encode($items, JSON_UNESCAPED_UNICODE);

// ── Upsert semua key ──
try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value, setting_group)
         VALUES (?, ?, 'ai_chat')
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
    );
    foreach ($allowed as $key) {
        // Secret: API key dikirim kosong (masking UI) = tidak diubah → pertahankan lama.
        if ($key === 'ai_chat_api_key' && ($values[$key] ?? '') === '') continue;
        $stmt->execute([$key, $values[$key] ?? '']);
    }
    logActivity('admin', 'update', 'settings', null, 'Update pengaturan AI Chat Widget');
} catch (Exception $e) {
    error_log('save-ai-chat error: ' . $e->getMessage());
    aiChatSaveRespond($isAjax, false, 'Gagal menyimpan pengaturan. Silakan coba lagi.', 422);
}

aiChatSaveRespond($isAjax, true, 'Pengaturan AI Chat berhasil disimpan.');

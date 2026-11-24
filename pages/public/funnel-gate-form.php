<?php
// ════════════════════════════════════════════════════════════════
// Funnel Gate — Form Kualifikasi Publik (standalone, tanpa partials/head).
// Route: /apply/{slug}. Kontak di AWAL + autosave draft + submit ber-skor.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['slug'] ?? '')));
if ($slug === '') { redirect('/'); }

$form = null;
try {
    $st = getDB()->prepare("SELECT * FROM funnel_gate_forms WHERE slug = ? AND status = 'published' LIMIT 1");
    $st->execute([$slug]);
    $form = $st->fetch();
} catch (Exception $e) {
    error_log('Funnel Gate form load error: ' . $e->getMessage());
}
if (!$form) { http_response_code(404); redirect('/'); }

$formId = (int) $form['id'];

// ─── Field ───────────────────────────────────────────────────
$fields = [];
try {
    $fs = getDB()->prepare('SELECT * FROM funnel_gate_fields WHERE form_id = ? ORDER BY sort_order ASC');
    $fs->execute([$formId]);
    $fields = $fs->fetchAll();
} catch (Exception $e) {
    error_log('Funnel Gate fields load error: ' . $e->getMessage());
}
$contact = ['name' => null, 'whatsapp' => null, 'email' => null];
$questions = [];
foreach ($fields as $f) {
    if ($f['field_role'] !== 'none') { $contact[$f['field_role']] = $f; }
    else { $questions[] = $f; }
}

// ─── Session token untuk korelasi draft autosave (server-side) ─
if (!isset($_SESSION['fg_token']) || !is_array($_SESSION['fg_token'])) $_SESSION['fg_token'] = [];
if (empty($_SESSION['fg_token'][$formId])) {
    $_SESSION['fg_token'][$formId] = bin2hex(random_bytes(16));
}

$appName    = getSetting('app_name', 'STIFIn Mulia');
$appFavicon = getSetting('app_favicon', '');
$brandColor = getSetting('brand_color', '#6366f1');
$base       = rtrim(APP_URL, '/');
$csrf       = generateCSRF();

// Footer brand "Powered by …" — tampil di bawah kartu (kedua layout). Mengikuti
// gaya footer form order agar brand terlihat. Pakai nama brand global (app_name).
$fgBrandFooter = '<div class="fgw-powered">'
    . '<div class="fgp-row">'
    . '<span class="fgp-lead">Powered by</span>'
    . '<span class="fgp-mark"><svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></span>'
    . '<span class="fgp-name">' . e($appName) . '</span>'
    . '</div>'
    . '<div class="fgp-copy">Copyright &copy; ' . date('Y') . '</div>'
    . '</div>';

// Radius kartu/kontrol mengikuti tema Averion (kotak-tegas — design language).
require_once __DIR__ . '/../../helpers/theme-config.php';
$themeCfg   = function_exists('getThemeConfig') ? getThemeConfig() : [];
$cardRadius = $themeCfg['--card-radius']    ?? '0.5rem';
$ctlRadius  = $themeCfg['--control-radius'] ?? '0.375rem';

$done       = isset($_GET['done']);
$submitText = trim((string) ($form['submit_text'] ?? '')) !== '' ? $form['submit_text'] : 'Kirim';
$successMsg = trim((string) ($form['success_message'] ?? '')) !== '' ? $form['success_message'] : 'Terima kasih! Jawaban Anda sudah kami terima.';

// ─── Multi Step (wizard) ─────────────────────────────────────
$steps = [];
if (($form['form_mode'] ?? 'single') === 'multi') {
    try {
        $ss = getDB()->prepare('SELECT * FROM funnel_gate_steps WHERE form_id = ? ORDER BY sort_order ASC');
        $ss->execute([$formId]);
        $steps = $ss->fetchAll();
    } catch (Exception $e) { error_log('FG form steps load: ' . $e->getMessage()); }
}
// Self-heal: form_mode='multi' TAPI steps kosong (data lama / migrasi steps tak
// lengkap / step terhapus) DULU jatuh senyap ke layout single-page — membingungkan
// ("form multi kok tampil 1 halaman tanpa langkah"). Sintesis 1 step berisi semua
// field agar SELALU render sebagai wizard. Admin cukup buka editor & Simpan ulang
// untuk membangun kembali langkah yang benar.
if (($form['form_mode'] ?? 'single') === 'multi' && empty($steps)) {
    $steps = [[
        'id'          => 0,
        'title'       => $form['title'],
        'description' => trim((string) ($form['intro'] ?? '')),
        'sort_order'  => 0,
    ]];
    error_log("FG form #{$formId} mode=multi tanpa steps → self-heal 1 step sintetis.");
}
$isWizard = (($form['form_mode'] ?? 'single') === 'multi') && !empty($steps);

// Setelan wizard.
$progressType    = in_array(($form['progress_type'] ?? 'bar'), ['bar', 'number', 'circle', 'none'], true) ? $form['progress_type'] : 'bar';
$stepAnimation   = in_array(($form['step_animation'] ?? 'slide'), ['slide', 'fade', 'none'], true) ? $form['step_animation'] : 'slide';
$nextText        = trim((string) ($form['next_text'] ?? '')) !== '' ? $form['next_text'] : 'Selanjutnya';
$backText        = trim((string) ($form['back_text'] ?? '')) !== '' ? $form['back_text'] : 'Kembali';
$allowBack       = ((int) ($form['allow_back'] ?? 1)) === 1;
$autosaveEnabled = ((int) ($form['autosave_enabled'] ?? 1)) === 1;

// Grup field per step (untuk wizard). Field tanpa step → step pertama.
$stepFields = [];
if ($isWizard) {
    $firstStepId = (int) $steps[0]['id'];
    foreach ($steps as $s) $stepFields[(int) $s['id']] = [];
    foreach ($fields as $f) {
        $sid = (int) ($f['step_id'] ?? 0);
        if (!isset($stepFields[$sid])) $sid = $firstStepId;
        $stepFields[$sid][] = $f;
    }
}

// Render satu field pertanyaan.
function fg_render_field(array $f): string
{
    $key  = $f['field_key'];
    $name = 'answers[' . e($key) . ']';
    $id   = 'fg_' . e($key);
    $req  = ((int) $f['is_required']) === 1;
    $ph   = e((string) ($f['placeholder'] ?? ''));
    $base = 'w-full px-3.5 py-2.5 rounded-lg border border-slate-200 bg-white text-slate-800 text-sm fg-field';
    $reqAttr = $req ? ' data-required="1"' : '';
    $h = '';

    switch ($f['field_type']) {
        case 'textarea':
            $h = '<textarea id="' . $id . '" name="' . $name . '" rows="4" placeholder="' . $ph . '"' . $reqAttr . ' class="' . $base . '"></textarea>';
            break;
        case 'number':
            $h = '<input type="number" id="' . $id . '" name="' . $name . '" placeholder="' . $ph . '"' . $reqAttr . ' class="' . $base . '">';
            break;
        case 'select':
            $opts = json_decode((string) $f['options'], true) ?: [];
            $h = '<select id="' . $id . '" name="' . $name . '"' . $reqAttr . ' class="' . $base . '"><option value="">— Pilih —</option>';
            foreach ($opts as $o) {
                $h .= '<option value="' . e((string) $o['value']) . '">' . e((string) $o['label']) . '</option>';
            }
            $h .= '</select>';
            break;
        case 'radio':
            $opts = json_decode((string) $f['options'], true) ?: [];
            $h = '<div class="space-y-2"' . $reqAttr . '>';
            foreach ($opts as $o) {
                $h .= '<label class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-lg border border-slate-200 cursor-pointer hover:border-gray-300 transition fg-opt">'
                    . '<input type="radio" name="' . $name . '" value="' . e((string) $o['value']) . '" class="fg-field w-4 h-4">'
                    . '<span class="text-sm text-slate-700">' . e((string) $o['label']) . '</span></label>';
            }
            $h .= '</div>';
            break;
        case 'checkbox':
            $opts = json_decode((string) $f['options'], true) ?: [];
            $h = '<div class="space-y-2"' . $reqAttr . '>';
            foreach ($opts as $o) {
                $h .= '<label class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-lg border border-slate-200 cursor-pointer hover:border-gray-300 transition fg-opt">'
                    . '<input type="checkbox" name="answers[' . e($key) . '][]" value="' . e((string) $o['value']) . '" class="fg-field w-4 h-4">'
                    . '<span class="text-sm text-slate-700">' . e((string) $o['label']) . '</span></label>';
            }
            $h .= '</div>';
            break;
        case 'rating':
            $cfg = json_decode((string) $f['options'], true) ?: [];
            $max = (int) ($cfg['max'] ?? 5);
            if ($max < 2) $max = 2; if ($max > 10) $max = 10;
            $h = '<div class="fg-rating flex items-center gap-1.5" data-max="' . $max . '"' . $reqAttr . '>';
            $h .= '<input type="hidden" name="' . $name . '" value="" class="fg-field">';
            for ($i = 1; $i <= $max; $i++) {
                $h .= '<button type="button" data-val="' . $i . '" class="fg-star w-8 h-8 text-slate-300 transition" aria-label="' . $i . '">'
                    . '<svg viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg></button>';
            }
            $h .= '</div>';
            break;
        default: // text / phone / email
            $h = '<input type="text" id="' . $id . '" name="' . $name . '" placeholder="' . $ph . '"' . $reqAttr . ' class="' . $base . '">';
    }
    if (trim((string) ($f['help_text'] ?? '')) !== '') {
        $h .= '<p class="text-xs text-slate-400 mt-1.5">' . e((string) $f['help_text']) . '</p>';
    }
    return $h;
}

// Render satu baris field (label + kontrol) untuk WIZARD — termasuk field kontak.
function fg_render_row(array $f): string
{
    $role = $f['field_role'];
    $req  = ((int) $f['is_required']) === 1;
    $star = $req ? ' <span style="color:#f87171">*</span>' : '';
    $label = '<label class="fgw-label">' . e($f['label']) . $star . '</label>';
    if ($role === 'name') {
        $ctrl = '<input type="text" name="contact_name"' . ($req ? ' data-required="1"' : '') . ' placeholder="Nama Anda" class="fg-field">';
    } elseif ($role === 'whatsapp') {
        $ctrl = '<input type="tel" name="contact_whatsapp"' . ($req ? ' data-required="1"' : '') . ' placeholder="08xxxxxxxxxx" class="fg-field">';
    } elseif ($role === 'email') {
        $ctrl = '<input type="email" name="contact_email"' . ($req ? ' data-required="1"' : '') . ' placeholder="email@contoh.com" class="fg-field">';
    } else {
        $ctrl = fg_render_field($f);
    }
    return '<div class="fgw-row">' . $label . $ctrl . '</div>';
}

// Warna teks kontras di atas brand color (untuk tombol) — luminance sederhana.
function fg_contrast(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    if (strlen($hex) !== 6) return '#ffffff';
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;
    $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    return $lum > 0.6 ? '#0b0b12' : '#ffffff';
}
$onBrand = fg_contrast($brandColor);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($form['title']) ?> — <?= e($appName) ?></title>
  <?php if (!empty($appFavicon)): ?>
  <link rel="icon" href="<?= e($appFavicon) ?>">
  <?php else: ?>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%23<?= e(ltrim($brandColor, '#')) ?>'/><path d='M16 7 L25 25 L7 25 Z' fill='white'/></svg>">
  <?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/tailwind.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/tailwind.css') ?: '1' ?>">
  <style>
    body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
    :root { --fg-brand: <?= e($brandColor) ?>; }
    .fg-field:focus { outline: none; border-color: var(--fg-brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--fg-brand) 18%, transparent); }
    .fg-opt:has(input:checked) { border-color: var(--fg-brand); background: color-mix(in srgb, var(--fg-brand) 6%, transparent); }
    .fg-star.on { color: #f59e0b; }
    .fg-star:hover { color: #fbbf24; }
    .fg-invalid { border-color: #ef4444 !important; }
    .fg-btn { background: var(--fg-brand); }
    .fg-btn:hover { opacity: .92; }

    /* ═══ Wizard (Multi Step) — tema TERANG, kotak-tegas (design language Averion) ═══ */
    :root { --fg-on-brand: <?= e($onBrand) ?>; --fg-card-radius: <?= e($cardRadius) ?>; --fg-ctl-radius: <?= e($ctlRadius) ?>; }
    .fgw-wrap { min-height: 100vh; display: flex; align-items: flex-start; justify-content: center;
      padding: clamp(16px, 4vw, 48px) 16px;
      background: radial-gradient(1100px 560px at 50% -12%, color-mix(in srgb, var(--fg-brand) 8%, #f8fafc), #f1f5f9) fixed; }
    .fgw { width: 100%; max-width: 640px; }
    .fgw-card { background: #ffffff; border: 1px solid #e2e8f0;
      border-radius: var(--fg-card-radius); padding: clamp(20px, 4vw, 34px);
      box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 12px 32px -18px rgba(15,23,42,.18); }
    .fgw-brand { display: flex; align-items: center; gap: .55rem; color: #64748b;
      font-size: 13px; font-weight: 600; margin-bottom: 20px; }
    .fgw-brand .fgw-mark { width: 30px; height: 30px; border-radius: var(--fg-ctl-radius); display: flex; align-items: center;
      justify-content: center; background: var(--fg-brand); color: var(--fg-on-brand); }
    /* Header form (judul + pengantar) — HANYA tampil di langkah pertama (via JS).
       Judul besar & tegas; pengantar dalam kotak bertint transparan (radius kecil). */
    .fgw-head { margin-bottom: 18px; }
    .fgw-formtitle { color: #0f172a; font-size: clamp(18px, 2.4vw, 22px); font-weight: 800;
      letter-spacing: -.015em; line-height: 1.25; }
    .fgw-formintro { color: #475569; font-size: 13.5px; line-height: 1.6; margin-top: 12px; }
    /* Kotak Pengantar — latar transparan bertint brand, radius kecil (tidak pill) */
    .fg-introbox { background: color-mix(in srgb, var(--fg-brand) 5%, transparent);
      border: 1px solid color-mix(in srgb, var(--fg-brand) 12%, transparent);
      border-radius: 10px; padding: 12px 14px; color: #475569; }
    /* Progress */
    .fgw-progress { margin-bottom: 22px; }
    .fgw-bar-track { height: 7px; border-radius: 999px; background: #e2e8f0; overflow: hidden; }
    .fgw-bar-fill { height: 100%; border-radius: 999px; background: var(--fg-brand);
      width: 0; transition: width .45s cubic-bezier(.4,0,.2,1); }
    .fgw-meta { color: #64748b; font-size: 12px; font-weight: 600; letter-spacing: .02em;
      margin-top: 9px; text-transform: uppercase; }
    .fgw-dots { display: flex; align-items: center; gap: 0; }
    .fgw-dot { display: flex; align-items: center; flex: 1; }
    .fgw-dot:last-child { flex: 0 0 auto; }
    .fgw-dot i { width: 30px; height: 30px; border-radius: 999px; display: flex; align-items: center;
      justify-content: center; font-size: 13px; font-weight: 700; flex: 0 0 auto;
      border: 2px solid #cbd5e1; color: #94a3b8;
      background: #fff; transition: all .3s; }
    .fgw-dot b { flex: 1; height: 2px; background: #e2e8f0; margin: 0 4px; transition: background .3s; }
    .fgw-dot.done i, .fgw-dot.active i { border-color: var(--fg-brand); }
    .fgw-dot.active i { background: var(--fg-brand); color: var(--fg-on-brand); box-shadow: 0 0 0 4px color-mix(in srgb, var(--fg-brand) 18%, transparent); }
    .fgw-dot.done i { background: color-mix(in srgb, var(--fg-brand) 14%, transparent); color: var(--fg-brand); }
    .fgw-dot.done b { background: var(--fg-brand); }
    /* Steps */
    .fgw-step { display: none; }
    .fgw-step.active { display: block; }
    .fgw-steptitle { color: #0f172a; font-size: clamp(20px, 3vw, 26px); font-weight: 800; letter-spacing: -.015em; line-height: 1.15; }
    .fgw-stepdesc { color: #64748b; font-size: 14px; margin-top: 7px; line-height: 1.5; }
    .fgw-row { margin-top: 18px; }
    .fgw-label { display: block; color: #334155; font-size: 14px; font-weight: 600; margin-bottom: 8px; }
    .fgw .fg-field { width: 100%; padding: 13px 15px; border-radius: var(--fg-ctl-radius);
      background: #fff; border: 1px solid #cbd5e1; color: #0f172a; font-size: 15px; }
    .fgw .fg-field::placeholder { color: #94a3b8; }
    .fgw .fg-field:focus { outline: none; border-color: var(--fg-brand);
      box-shadow: 0 0 0 3px color-mix(in srgb, var(--fg-brand) 18%, transparent); }
    .fgw select.fg-field option { color: #0f172a; }
    .fgw .fg-opt { display: flex; align-items: center; gap: .65rem; padding: 15px 16px; border-radius: var(--fg-ctl-radius);
      border: 1px solid #cbd5e1; background: #fff; color: #334155;
      cursor: pointer; transition: border-color .15s, background .15s, box-shadow .15s; }
    .fgw .fg-opt:hover { border-color: #94a3b8; }
    .fgw .fg-opt:has(input:checked) { border-color: var(--fg-brand);
      background: color-mix(in srgb, var(--fg-brand) 7%, transparent);
      box-shadow: 0 0 0 1px var(--fg-brand); }
    .fgw .fg-opt span { font-size: 15px; }
    /* Radio/checkbox di dalam opsi: netralkan gaya .fg-field (width:100%+padding+border)
       yang membuatnya melar jadi elips. Kembalikan ke kontrol native + warna brand. */
    .fgw .fg-opt input[type="radio"],
    .fgw .fg-opt input[type="checkbox"] {
      width: 18px; height: 18px; min-width: 18px; flex: 0 0 auto;
      margin: 0; padding: 0; border: 0; border-radius: 0; background: none;
      box-shadow: none; accent-color: var(--fg-brand); cursor: pointer;
      -webkit-appearance: auto; appearance: auto;
    }
    .fgw .fg-opt input[type="radio"]:focus,
    .fgw .fg-opt input[type="checkbox"]:focus { box-shadow: none; outline: none; }
    .fgw .fg-star { color: #cbd5e1; }
    .fgw .fg-star.on { color: #f59e0b; }
    .fgw-nav { display: flex; gap: 12px; margin-top: 30px; }
    .fgw-btn { padding: 14px 22px; border-radius: var(--fg-ctl-radius); font-weight: 700; font-size: 15px; cursor: pointer;
      transition: opacity .15s, transform .05s, background .15s; border: 1px solid transparent; }
    .fgw-btn:active { transform: translateY(1px); }
    .fgw-next, .fgw-submit { flex: 1; background: var(--fg-brand); color: var(--fg-on-brand);
      display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
    .fgw-next:hover, .fgw-submit:hover { opacity: .92; }
    .fgw-back { background: #fff; border-color: #cbd5e1; color: #475569; }
    .fgw-back:hover { border-color: #94a3b8; color: #0f172a; }
    .fgw-err { margin-top: 18px; font-size: 14px; color: #b91c1c; background: #fef2f2;
      border: 1px solid #fecaca; border-radius: var(--fg-ctl-radius); padding: 12px 15px; }
    .fgw-foot { text-align: center; color: #94a3b8; font-size: 12px; margin-top: 16px; }
    .fgw-powered { text-align: center; margin-top: 18px; }
    .fgw-powered .fgp-row { display: inline-flex; align-items: center; gap: 6px; opacity: .85; }
    .fgw-powered .fgp-lead { font-size: 12px; font-weight: 600; color: #94a3b8; }
    .fgw-powered .fgp-mark { color: var(--fg-brand); display: inline-flex; align-items: center; }
    .fgw-powered .fgp-name { font-size: 14px; font-weight: 700; color: #64748b; }
    .fgw-powered .fgp-copy { font-size: 11px; color: #94a3b8; margin-top: 5px; }
    .fgw-done { text-align: center; color: #0f172a; }
    .fgw .fg-invalid { border-color: #ef4444 !important; box-shadow: 0 0 0 3px rgba(239,68,68,.15) !important; }
    @media (max-width: 480px) { .fgw-nav { flex-direction: column-reverse; } .fgw-back { width: 100%; } }
  </style>
</head>
<body class="min-h-screen text-slate-800 antialiased<?= $isWizard ? '' : ' bg-slate-50' ?>">

<?php if ($isWizard): ?>
  <div class="fgw-wrap">
    <div class="fgw">
      <div class="fgw-card">
      <?php if ($done): ?>
        <div class="fgw-done">
          <div style="width:56px;height:56px;margin:0 auto 16px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:color-mix(in srgb, var(--fg-brand) 12%, transparent)">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--fg-brand)" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
          </div>
          <h1 style="font-size:20px;font-weight:800;margin-bottom:8px;color:#0f172a"><?= e($form['title']) ?></h1>
          <p style="color:#475569;font-size:14px;line-height:1.6"><?= nl2br(e($successMsg)) ?></p>
        </div>
      <?php else: ?>
        <div class="fgw-brand">
          <span class="fgw-mark"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V20l-4-2v-5.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg></span>
          <?= e($appName) ?>
        </div>

        <?php if (($form['title'] ?? '') !== '' || trim((string) ($form['intro'] ?? '')) !== ''): ?>
        <div class="fgw-head">
          <h1 class="fgw-formtitle"><?= e($form['title']) ?></h1>
          <?php if (trim((string) ($form['intro'] ?? '')) !== ''): ?>
          <p class="fgw-formintro fg-introbox"><?= nl2br(e((string) $form['intro'])) ?></p>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="fgw-progress" id="fgwProgress" data-type="<?= e($progressType) ?>" data-total="<?= count($steps) ?>"></div>

        <form id="fgApply">
          <input type="hidden" name="form_id" value="<?= $formId ?>">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <?php foreach ($steps as $si => $s): $sid = (int) $s['id']; ?>
          <section class="fgw-step<?= $si === 0 ? ' active' : '' ?>" data-step="<?= $si ?>">
            <h2 class="fgw-steptitle"><?= e($s['title']) ?></h2>
            <?php if (trim((string) ($s['description'] ?? '')) !== ''): ?>
            <p class="fgw-stepdesc"><?= nl2br(e((string) $s['description'])) ?></p>
            <?php endif; ?>
            <?php foreach (($stepFields[$sid] ?? []) as $f): ?>
              <?= fg_render_row($f) ?>
            <?php endforeach; ?>
          </section>
          <?php endforeach; ?>

          <div class="fgw-err" id="fgwError" style="display:none"></div>

          <div class="fgw-nav">
            <?php if ($allowBack): ?>
            <button type="button" class="fgw-btn fgw-back" id="fgwBack" style="display:none"><?= e($backText) ?></button>
            <?php endif; ?>
            <button type="button" class="fgw-btn fgw-next" id="fgwNext"><?= e($nextText) ?>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
            <button type="submit" class="fgw-btn fgw-submit" id="fgwSubmit" style="display:none"><?= e($submitText) ?></button>
          </div>
          <?php if ($autosaveEnabled): ?>
          <p class="fgw-foot">Progress tersimpan otomatis.</p>
          <?php endif; ?>
        </form>
      <?php endif; ?>
      </div>
      <?= $fgBrandFooter ?>
    </div>
  </div>
<?php else: ?>

  <div class="min-h-screen flex items-start justify-center px-4 py-8">
    <div class="w-full max-w-lg">

      <?php if ($done): ?>
      <!-- State sukses -->
      <div class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-200 p-8 text-center">
        <div class="w-14 h-14 mx-auto rounded-full flex items-center justify-center mb-4" style="background:color-mix(in srgb, var(--fg-brand) 12%, transparent)">
          <svg class="w-7 h-7" style="color:var(--fg-brand)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
        </div>
        <h1 class="text-lg font-bold text-slate-900 mb-2"><?= e($form['title']) ?></h1>
        <p class="text-sm text-slate-600 leading-relaxed"><?= nl2br(e($successMsg)) ?></p>
      </div>
      <?php else: ?>

      <!-- Header -->
      <div class="mb-5">
        <div class="flex items-center gap-2.5 mb-3">
          <div class="w-8 h-8 rounded-lg text-white flex items-center justify-center shrink-0" style="background:var(--fg-brand)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V20l-4-2v-5.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
          </div>
          <span class="text-[13px] font-semibold text-slate-500"><?= e($appName) ?></span>
        </div>
        <h1 class="text-2xl font-bold text-slate-900 leading-tight"><?= e($form['title']) ?></h1>
        <?php if (trim((string) ($form['intro'] ?? '')) !== ''): ?>
        <p class="fg-introbox text-sm mt-3 leading-relaxed"><?= nl2br(e((string) $form['intro'])) ?></p>
        <?php endif; ?>
      </div>

      <form id="fgApply" class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-200 p-5 sm:p-6 space-y-5">
        <input type="hidden" name="form_id" value="<?= $formId ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

        <!-- Kontak (di awal) -->
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5"><?= e($contact['name']['label'] ?? 'Nama Lengkap') ?> <span class="text-red-500">*</span></label>
            <input type="text" name="contact_name" data-required="1" placeholder="Nama Anda" class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 bg-white text-slate-800 text-sm fg-field fg-contact">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5"><?= e($contact['whatsapp']['label'] ?? 'Nomor WhatsApp') ?> <span class="text-red-500">*</span></label>
            <input type="tel" name="contact_whatsapp" data-required="1" placeholder="08xxxxxxxxxx" class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 bg-white text-slate-800 text-sm fg-field fg-contact">
          </div>
          <?php if ($contact['email']): $emReq = ((int) $contact['email']['is_required']) === 1; ?>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5"><?= e($contact['email']['label'] ?? 'Email') ?> <?= $emReq ? '<span class="text-red-500">*</span>' : '' ?></label>
            <input type="email" name="contact_email" <?= $emReq ? 'data-required="1"' : '' ?> placeholder="email@contoh.com" class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 bg-white text-slate-800 text-sm fg-field fg-contact">
          </div>
          <?php endif; ?>
        </div>

        <?php if (!empty($questions)): ?>
        <div class="border-t border-slate-100 pt-5 space-y-4">
          <?php foreach ($questions as $q): $req = ((int) $q['is_required']) === 1; ?>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5"><?= e($q['label']) ?> <?= $req ? '<span class="text-red-500">*</span>' : '' ?></label>
            <?= fg_render_field($q) ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div id="fgError" class="hidden text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3.5 py-2.5"></div>

        <button type="submit" id="fgSubmit" class="w-full py-3 rounded-lg text-sm font-semibold text-white fg-btn transition flex items-center justify-center gap-2">
          <span class="fg-btn-label"><?= e($submitText) ?></span>
        </button>
        <p class="text-center text-xs text-slate-400">Progress tersimpan otomatis saat Anda mengisi.</p>
      </form>
      <?php endif; ?>

      <?= $fgBrandFooter ?>
    </div>
  </div>
<?php endif; // isWizard ?>

  <?php if (!$done && !$isWizard): ?>
  <script>
  (function () {
    'use strict';
    var form = document.getElementById('fgApply');
    if (!form) return;
    var base = <?= json_encode($base) ?>;
    var autosaveUrl = base + '/actions/public/funnel-gate-autosave';
    var submitUrl   = base + '/actions/public/submit-funnel-gate';
    var errBox = document.getElementById('fgError');
    var btn = document.getElementById('fgSubmit');
    var finished = false; // true setelah submit sukses → stop autosave/beacon (cegah draft hantu)

    // ── Rating stars ──
    form.querySelectorAll('.fg-rating').forEach(function (r) {
      var hidden = r.querySelector('input[type=hidden]');
      var stars = Array.prototype.slice.call(r.querySelectorAll('.fg-star'));
      function paint(v) { stars.forEach(function (s) { s.classList.toggle('on', parseInt(s.dataset.val, 10) <= v); }); }
      stars.forEach(function (s) {
        s.addEventListener('click', function () { hidden.value = s.dataset.val; paint(parseInt(s.dataset.val, 10)); scheduleSave(); });
      });
    });

    // ── Autosave (debounced) ──
    var saveTimer = null, lastPayload = '';
    function collect() {
      var fd = new FormData(form);
      return fd;
    }
    function scheduleSave() {
      if (finished) return;
      clearTimeout(saveTimer);
      saveTimer = setTimeout(doSave, 1200);
    }
    function doSave() {
      if (finished) return;
      var fd = collect();
      // Jangan simpan bila kontak inti masih kosong (belum ada gunanya).
      if (!(fd.get('contact_name') || '').trim() && !(fd.get('contact_whatsapp') || '').trim()) return;
      var probe = JSON.stringify([...fd.entries()].filter(function (e) { return e[0] !== 'csrf_token'; }));
      if (probe === lastPayload) return; // tak ada perubahan
      lastPayload = probe;
      fd.append('autosave', '1');
      fetch(autosaveUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } }).catch(function () {});
    }
    form.addEventListener('input', scheduleSave, true);
    form.addEventListener('change', scheduleSave, true);
    window.addEventListener('beforeunload', function () {
      if (finished) return; // sudah submit → jangan bikin draft baru
      // Best-effort simpan terakhir via sendBeacon.
      try {
        var fd = collect();
        if (!(fd.get('contact_name') || '').trim() && !(fd.get('contact_whatsapp') || '').trim()) return;
        fd.append('autosave', '1');
        navigator.sendBeacon(autosaveUrl, fd);
      } catch (e) {}
    });

    // ── Validasi klien ringan ──
    function clearInvalid() { form.querySelectorAll('.fg-invalid').forEach(function (el) { el.classList.remove('fg-invalid'); }); errBox.classList.add('hidden'); }
    function firstMissing() {
      var groups = form.querySelectorAll('[data-required="1"]');
      for (var i = 0; i < groups.length; i++) {
        var g = groups[i];
        if (g.matches('input, textarea, select')) {
          if (!String(g.value || '').trim()) return g;
        } else {
          // grup radio/checkbox/rating
          var checked = g.querySelector('input:checked') || g.querySelector('input[type=hidden][value]:not([value=""])');
          var hid = g.querySelector('input[type=hidden]');
          if (hid && String(hid.value || '').trim()) checked = true;
          if (!checked) return g;
        }
      }
      return null;
    }

    // ── Submit ──
    var submitting = false;
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (submitting) return;
      clearInvalid();
      var miss = firstMissing();
      if (miss) {
        miss.classList.add('fg-invalid');
        errBox.textContent = 'Mohon lengkapi semua isian yang wajib (bertanda *).';
        errBox.classList.remove('hidden');
        (miss.scrollIntoView ? miss : form).scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
      submitting = true;
      btn.disabled = true;
      var lbl = btn.querySelector('.fg-btn-label'); if (lbl) lbl.textContent = 'Mengirim…';
      var fd = collect();
      fetch(submitUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.ok && res.redirect) { finished = true; clearTimeout(saveTimer); window.location.href = res.redirect; return; }
          submitting = false; btn.disabled = false;
          if (lbl) lbl.textContent = <?= json_encode($submitText) ?>;
          errBox.textContent = (res && res.message) ? res.message : 'Terjadi kesalahan. Coba lagi.';
          errBox.classList.remove('hidden');
        })
        .catch(function () {
          submitting = false; btn.disabled = false;
          if (lbl) lbl.textContent = <?= json_encode($submitText) ?>;
          errBox.textContent = 'Gagal terhubung. Periksa koneksi Anda.';
          errBox.classList.remove('hidden');
        });
    });
  })();
  </script>
  <?php endif; ?>

  <?php if (!$done && $isWizard): ?>
  <script>
  (function () {
    'use strict';
    var form = document.getElementById('fgApply');
    if (!form) return;
    var base = <?= json_encode($base) ?>;
    var autosaveUrl = base + '/actions/public/funnel-gate-autosave';
    var submitUrl   = base + '/actions/public/submit-funnel-gate';
    var steps   = Array.prototype.slice.call(form.querySelectorAll('.fgw-step'));
    var total   = steps.length;
    var active  = 0;
    var animType  = <?= json_encode($stepAnimation) ?>;
    var allowBack = <?= $allowBack ? 'true' : 'false' ?>;
    var autosaveOn = <?= $autosaveEnabled ? 'true' : 'false' ?>;
    var backBtn = document.getElementById('fgwBack');
    var nextBtn = document.getElementById('fgwNext');
    var subBtn  = document.getElementById('fgwSubmit');
    var errBox  = document.getElementById('fgwError');
    var progEl  = document.getElementById('fgwProgress');
    var headEl  = document.querySelector('.fgw-head'); // judul+pengantar: hanya langkah pertama
    var progType = progEl ? progEl.getAttribute('data-type') : 'none';
    var submitLabel = <?= json_encode($submitText) ?>;
    var finished = false;

    // Rating stars
    form.querySelectorAll('.fg-rating').forEach(function (r) {
      var hidden = r.querySelector('input[type=hidden]');
      var stars = Array.prototype.slice.call(r.querySelectorAll('.fg-star'));
      function paint(v) { stars.forEach(function (s) { s.classList.toggle('on', parseInt(s.dataset.val, 10) <= v); }); }
      stars.forEach(function (s) { s.addEventListener('click', function () { hidden.value = s.dataset.val; paint(parseInt(s.dataset.val, 10)); scheduleSave(); }); });
    });

    function buildProgress() {
      if (!progEl || progType === 'none') { if (progEl) progEl.style.display = 'none'; return; }
      if (progType === 'bar') {
        var pct = Math.round(((active + 1) / total) * 100);
        progEl.innerHTML = '<div class="fgw-bar-track"><div class="fgw-bar-fill" style="width:' + pct + '%"></div></div><div class="fgw-meta">Langkah ' + (active + 1) + ' dari ' + total + '</div>';
      } else if (progType === 'number') {
        progEl.innerHTML = '<div class="fgw-meta" style="text-transform:none;font-size:13px">Langkah ' + (active + 1) + ' dari ' + total + '</div>';
      } else if (progType === 'circle') {
        var h = '<div class="fgw-dots">';
        for (var i = 0; i < total; i++) {
          var cls = i < active ? 'done' : (i === active ? 'active' : '');
          h += '<div class="fgw-dot ' + cls + '"><i>' + (i < active ? '✓' : (i + 1)) + '</i>' + (i < total - 1 ? '<b></b>' : '') + '</div>';
        }
        progEl.innerHTML = h + '</div>';
      }
    }

    function animate(el, dir) {
      if (animType === 'none') return;
      var frames = (animType === 'fade')
        ? [{ opacity: 0 }, { opacity: 1 }]
        : [{ transform: 'translateX(' + (dir === 'back' ? '-28px' : '28px') + ')', opacity: 0 }, { transform: 'translateX(0)', opacity: 1 }];
      try { el.animate(frames, { duration: 320, easing: 'cubic-bezier(.4,0,.2,1)' }); } catch (e) {}
    }

    function updateNav() {
      if (backBtn) backBtn.style.display = (allowBack && active > 0) ? '' : 'none';
      var last = active === total - 1;
      if (nextBtn) nextBtn.style.display = last ? 'none' : '';
      if (subBtn)  subBtn.style.display  = last ? '' : 'none';
    }

    function showStep(i, dir) {
      steps.forEach(function (s, idx) { s.classList.toggle('active', idx === i); });
      if (headEl) headEl.style.display = (i === 0) ? '' : 'none'; // judul+pengantar hanya di langkah pertama
      active = i;
      animate(steps[i], dir);
      updateNav();
      buildProgress();
      if (errBox) errBox.style.display = 'none';
      try { (form.closest('.fgw-card') || form).scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
    }

    function firstMissingIn(section) {
      var groups = section.querySelectorAll('[data-required="1"]');
      for (var i = 0; i < groups.length; i++) {
        var g = groups[i];
        if (g.matches('input, textarea, select')) { if (!String(g.value || '').trim()) return g; }
        else {
          var checked = g.querySelector('input:checked');
          var hid = g.querySelector('input[type=hidden]');
          if (hid && String(hid.value || '').trim()) checked = true;
          if (!checked) return g;
        }
      }
      return null;
    }
    function clearInvalid() { form.querySelectorAll('.fg-invalid').forEach(function (el) { el.classList.remove('fg-invalid'); }); if (errBox) errBox.style.display = 'none'; }
    function validateStep(i) {
      clearInvalid();
      var miss = firstMissingIn(steps[i]);
      if (miss) {
        miss.classList.add('fg-invalid');
        errBox.textContent = 'Mohon lengkapi isian wajib sebelum lanjut.';
        errBox.style.display = '';
        try { miss.scrollIntoView({ behavior: 'smooth', block: 'center' }); if (miss.focus) miss.focus(); } catch (e) {}
        return false;
      }
      return true;
    }

    if (nextBtn) nextBtn.addEventListener('click', function () { if (!validateStep(active)) return; if (active < total - 1) { showStep(active + 1, 'fwd'); doSaveNow(); } });
    if (backBtn) backBtn.addEventListener('click', function () { if (active > 0) showStep(active - 1, 'back'); });

    // Autosave
    var saveTimer = null, lastPayload = '';
    function collect() { return new FormData(form); }
    function shouldSave(fd) { return (fd.get('contact_name') || '').trim() || (fd.get('contact_whatsapp') || '').trim(); }
    function scheduleSave() { if (!autosaveOn || finished) return; clearTimeout(saveTimer); saveTimer = setTimeout(doSave, 1200); }
    function doSave() {
      if (!autosaveOn || finished) return;
      var fd = collect(); if (!shouldSave(fd)) return;
      var probe = JSON.stringify([...fd.entries()].filter(function (e) { return e[0] !== 'csrf_token'; }));
      if (probe === lastPayload) return; lastPayload = probe;
      fd.append('autosave', '1');
      fetch(autosaveUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } }).catch(function () {});
    }
    function doSaveNow() { clearTimeout(saveTimer); doSave(); }
    if (autosaveOn) {
      form.addEventListener('input', scheduleSave, true);
      form.addEventListener('change', scheduleSave, true);
      window.addEventListener('beforeunload', function () {
        if (finished) return;
        try { var fd = collect(); if (!shouldSave(fd)) return; fd.append('autosave', '1'); navigator.sendBeacon(autosaveUrl, fd); } catch (e) {}
      });
    }

    // Submit (Enter di step non-akhir = maju; hanya kirim di step terakhir)
    var submitting = false;
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (submitting) return;
      if (active < total - 1) { if (validateStep(active)) { showStep(active + 1, 'fwd'); doSaveNow(); } return; }
      if (!validateStep(active)) return;
      submitting = true;
      if (subBtn) { subBtn.disabled = true; subBtn.textContent = 'Mengirim…'; }
      var fd = collect();
      fetch(submitUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.ok && res.redirect) { finished = true; clearTimeout(saveTimer); window.location.href = res.redirect; return; }
          submitting = false; if (subBtn) { subBtn.disabled = false; subBtn.textContent = submitLabel; }
          errBox.textContent = (res && res.message) ? res.message : 'Terjadi kesalahan. Coba lagi.';
          errBox.style.display = '';
        })
        .catch(function () {
          submitting = false; if (subBtn) { subBtn.disabled = false; subBtn.textContent = submitLabel; }
          errBox.textContent = 'Gagal terhubung. Periksa koneksi Anda.';
          errBox.style.display = '';
        });
    });

    // Init
    showStep(0, 'fwd');
  })();
  </script>
  <?php endif; ?>
</body>
</html>

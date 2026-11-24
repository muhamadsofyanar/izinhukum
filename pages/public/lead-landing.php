<?php
// ════════════════════════════════════════════════════════════════
// Lead Magnet — Opt-in Page Publik (standalone, tanpa partials/head)
// Route: /lp/{slug}. Tangkap Nama/WA/Email → actions/public/submit-lead.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['slug'] ?? '')));
if ($slug === '') {
    redirect('/');
}

$magnet = null;
try {
    $stmt = getDB()->prepare(
        "SELECT * FROM lead_magnets WHERE slug = ? AND status = 'published' LIMIT 1"
    );
    $stmt->execute([$slug]);
    $magnet = $stmt->fetch();
} catch (Exception $e) {
    error_log('Lead landing load error: ' . $e->getMessage());
}
if (!$magnet) {
    http_response_code(404);
    redirect('/');
}

$appName    = getSetting('app_name', 'STIFIn Mulia');
$appFavicon = getSetting('app_favicon', '');
$brandColor = getSetting('brand_color', '#6366f1');
$base       = rtrim(APP_URL, '/');

$csrf      = generateCSRF();
$submitUrl = $base . '/actions/public/submit-lead';

// Link "Login" membawa tujuan kembali ke form ini (REQUEST_URI sudah termasuk
// base path, cocok dgn raw header() di actions/auth/login.php).
$selfPath  = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
$loginUrl  = $base . '/login?redirect=' . rawurlencode($selfPath);

$badge     = trim($magnet['badge_text'] ?? '') !== '' ? $magnet['badge_text'] : 'FREE ACCESS';
$cta       = trim($magnet['cta_text'] ?? '') !== '' ? $magnet['cta_text'] : 'Free Access';
$headline  = trim($magnet['headline'] ?? '') !== '' ? $magnet['headline'] : $magnet['title'];
$thumb     = trim($magnet['thumbnail'] ?? '');
$thumbUrl  = $thumb !== '' ? ($base . '/' . ltrim($thumb, '/')) : '';

$submitted = isset($_GET['submitted']);

// ─── Tripwire: penawaran produk berbayar opsional di state thank-you ──────
// Lead sudah tertangkap sebelum ini. Tombol menuju checkout produk (Product
// Bumps produk itu tampil otomatis di sana) — reuse engine checkout, nol duplikasi.
$tripwire = null; $twUrl = ''; $twThumb = '';
if ($submitted && !empty($magnet['tripwire_product_id'])) {
    try {
        $twq = getDB()->prepare(
            "SELECT id, name, slug, price, original_price, thumbnail, checkout_mode, short_description
             FROM products WHERE id = ? AND status = 'active' LIMIT 1"
        );
        $twq->execute([(int) $magnet['tripwire_product_id']]);
        $tripwire = $twq->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('lead tripwire load: ' . $e->getMessage());
    }
    if ($tripwire) {
        // Arahkan ke HALAMAN PRODUK (landing) dulu, bukan langsung checkout —
        // pengunjung melihat detail & penawaran penuh sebelum memutuskan beli.
        $twUrl = $base . '/products/' . rawurlencode((string) $tripwire['slug']);
        $tt = trim((string) ($tripwire['thumbnail'] ?? ''));
        if ($tt !== '') {
            $twThumb = preg_match('#^https?://#i', $tt) ? $tt : ($base . '/' . ltrim($tt, '/'));
        }
    }
}
$formFlash = getFlash('lead_form');
$formError = ($formFlash && $formFlash['type'] === 'error') ? $formFlash['message'] : '';

// ─── Status user: sudah login? sudah klaim magnet ini? ───────
$isAuthed       = isLoggedIn();
$authName       = '';
$needWa         = false;   // akun login tapi tanpa nomor → minta WA saja
$alreadyClaimed = false;
if ($isAuthed) {
    $authUser = null;
    try {
        $u = getDB()->prepare('SELECT id, name, email, phone FROM users WHERE id = ? LIMIT 1');
        $u->execute([(int) ($_SESSION['user_id'] ?? 0)]);
        $authUser = $u->fetch() ?: null;
    } catch (Exception $e) {
        $authUser = null;
    }
    if ($authUser) {
        $authName = trim((string) $authUser['name']);
        $aWa      = lead_normalize_wa((string) ($authUser['phone'] ?? ''));
        $aEmail   = (string) ($authUser['email'] ?? '');
        $needWa   = ($aWa === '');
        try {
            $params = [(int) $magnet['id'], (int) $authUser['id']];
            $cond   = 'promoted_user_id = ?';
            if ($aWa !== '')    { $cond .= ' OR whatsapp = ?'; $params[] = $aWa; }
            if ($aEmail !== '') { $cond .= ' OR email = ?';    $params[] = $aEmail; }
            $c = getDB()->prepare("SELECT id FROM leads WHERE lead_magnet_id = ? AND ($cond) LIMIT 1");
            $c->execute($params);
            $alreadyClaimed = (bool) $c->fetchColumn();
        } catch (Exception $e) {
            $alreadyClaimed = false;
        }
    } else {
        $isAuthed = false; // sesi rusak → perlakukan sebagai anonim
    }
}

// ─── Pending OTP: anonim yg baru submit ke magnet ber-bonus ATAU ber-konten (promote→user) ──
$pendingOtp = false;
$pendingPhoneMasked = '';
if (!$isAuthed && isset($_SESSION['lead_pending']) && is_array($_SESSION['lead_pending'])
    && ($_SESSION['lead_pending']['magnet_slug'] ?? '') === $magnet['slug']
    && ((int) ($magnet['bonus_membership_level_id'] ?? 0) > 0
        || lead_magnet_has_content((int) $magnet['id']))) {
    $pendingOtp = true;
    $pp = preg_replace('/\D/', '', (string) ($_SESSION['lead_pending']['phone'] ?? ''));
    $pendingPhoneMasked = strlen($pp) >= 7 ? substr($pp, 0, 4) . '••••' . substr($pp, -3) : $pp;
}

// Pixel per-magnet (angka, pisah koma)
$pixelIds = array_filter(array_map('trim', explode(',', (string) ($magnet['fb_pixel_ids'] ?? ''))));
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($magnet['title']) ?> — <?= e($appName) ?></title>
  <?php if (!empty($appFavicon)): ?>
  <link rel="icon" href="<?= e($appFavicon) ?>">
  <?php else: ?>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%23<?= e(ltrim($brandColor, '#')) ?>'/><path d='M16 7 L25 25 L7 25 Z' fill='white'/></svg>">
  <?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
  <!-- Tailwind compiled lokal (tanpa CDN) — regenerate: tools/build-tailwind.php -->
  <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/tailwind.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/tailwind.css') ?: '1' ?>">
  <style>
    body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
    .lm-field:focus { outline: none; border-color: #1e293b; }
  </style>
  <?php if (!empty($pixelIds)): ?>
  <!-- Meta Pixel (per lead magnet) -->
  <script>
  !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
  n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
  document,'script','https://connect.facebook.net/en_US/fbevents.js');
  <?php foreach ($pixelIds as $pid): ?>
  fbq('init','<?= e($pid) ?>');
  <?php endforeach; ?>
  fbq('track','PageView');
  <?php if ($submitted): ?>fbq('track','Lead');<?php endif; ?>
  </script>
  <?php endif; ?>
<link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/mulia-pages.css?v=1">
</head>
<body class="mulia mulia-lead min-h-screen bg-gradient-to-b from-indigo-50 via-slate-50 to-white text-slate-800 antialiased">

  <div class="min-h-screen flex items-start sm:items-center justify-center px-4 py-6">
    <div class="w-full max-w-sm">
      <div class="bg-white rounded-lg shadow-lg shadow-slate-200/60 ring-1 ring-slate-200 p-5">

        <!-- Header -->
        <div class="flex items-center gap-2.5 mb-4">
          <div class="w-8 h-8 rounded-md bg-slate-900 text-white flex items-center justify-center shrink-0">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
          </div>
          <span class="inline-flex items-center px-2 py-0.5 rounded border border-slate-200 text-[10px] font-semibold tracking-wide text-slate-500 uppercase"><?= e($badge) ?></span>
          <span class="text-[13px] font-semibold text-slate-700 truncate"><?= e($magnet['title']) ?></span>
        </div>

        <?php /* Saat thank-you DENGAN penawaran tripwire: sembunyikan gambar &
                 judul lead magnet agar fokus ke penawaran (bump offer). */ ?>
        <?php if (!($submitted && $tripwire)): ?>
        <?php if ($thumbUrl !== ''): ?>
        <!-- Hero -->
        <div class="rounded-md overflow-hidden ring-1 ring-slate-200 mb-4">
          <img src="<?= e($thumbUrl) ?>" alt="<?= e($magnet['title']) ?>" class="w-full h-auto block">
        </div>
        <?php endif; ?>

        <!-- Info card -->
        <div class="flex items-start gap-2.5 rounded-md bg-slate-50 ring-1 ring-slate-200 p-3 mb-4">
          <div class="w-8 h-8 rounded-md bg-white ring-1 ring-slate-200 flex items-center justify-center shrink-0">
            <svg class="w-4 h-4 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12.01l8.73-5.05M12 22.08V12"/></svg>
          </div>
          <div class="min-w-0">
            <h1 class="text-[13px] font-bold text-slate-900 leading-snug"><?= e($headline) ?></h1>
            <?php if (trim($magnet['subheadline'] ?? '') !== ''): ?>
            <p class="text-xs text-slate-500 mt-0.5 leading-snug"><?= e($magnet['subheadline']) ?></p>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($submitted && $pendingOtp): ?>
        <!-- ─── State: Verifikasi WhatsApp (promote lead anonim → user) ─── -->
        <div class="py-1" id="leadOtpBox"
             data-otp-req="<?= e($base) ?>/actions/public/lead-request-otp"
             data-otp-ver="<?= e($base) ?>/actions/public/lead-verify-otp"
             data-csrf="<?= e($csrf) ?>">
          <div class="w-12 h-12 mx-auto rounded-md bg-green-100 flex items-center justify-center mb-3">
            <svg class="w-6 h-6 text-green-600" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2z"/></svg>
          </div>
          <h2 class="text-base font-bold text-slate-900 mb-1 text-center">Satu langkah lagi 🎉</h2>
          <p class="text-[13px] text-slate-500 leading-relaxed mb-4 text-center">Verifikasi WhatsApp <strong class="text-slate-700"><?= e($pendingPhoneMasked) ?></strong> untuk mengaktifkan akses bonus.</p>

          <div id="lo-step1">
            <div id="lo-err1" class="hidden mb-2 rounded-md bg-red-50 border border-red-100 text-red-700 text-[13px] px-3 py-2"></div>
            <button type="button" id="lo-send"
                    class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 rounded-md text-white text-sm font-semibold bg-gradient-to-r from-slate-900 to-slate-700 hover:from-slate-800 hover:to-slate-600 transition">
              Kirim Kode OTP via WhatsApp
            </button>
          </div>

          <div id="lo-step2" class="hidden">
            <input type="text" id="lo-code" maxlength="6" inputmode="numeric" placeholder="000000"
                   class="lm-field w-full text-center text-2xl tracking-[0.5em] font-bold bg-transparent border border-slate-200 rounded-md py-2.5 text-slate-900 placeholder-slate-300 transition mb-1">
            <p class="text-xs text-slate-400 text-center mb-2">Kode dikirim ke WhatsApp kamu · berlaku 10 menit</p>
            <div id="lo-err2" class="hidden mb-2 rounded-md bg-red-50 border border-red-100 text-red-700 text-[13px] px-3 py-2"></div>
            <button type="button" id="lo-verify"
                    class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 rounded-md text-white text-sm font-semibold bg-gradient-to-r from-slate-900 to-slate-700 hover:from-slate-800 hover:to-slate-600 transition">
              Verifikasi &amp; Aktifkan Akses
            </button>
            <div class="text-center mt-2">
              <button type="button" id="lo-resend" class="text-xs text-slate-400 hover:text-slate-600 transition">Kirim ulang kode</button>
            </div>
          </div>
        </div>

        <?php elseif ($submitted): ?>
        <!-- ─── State: Thank You ─── -->
        <div class="text-center py-1">
          <div class="w-12 h-12 mx-auto rounded-md bg-green-100 flex items-center justify-center mb-3">
            <svg class="w-6 h-6 text-green-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
          </div>
          <h2 class="text-base font-bold text-slate-900 mb-1">Pendaftaran Berhasil! 🎉</h2>
          <p class="text-[13px] text-slate-500 leading-relaxed whitespace-pre-line mb-4">
            <?= e(trim($magnet['thankyou_message'] ?? '') !== '' ? $magnet['thankyou_message'] : 'Terima kasih sudah mendaftar. Akses & informasi selanjutnya akan kami kirimkan melalui WhatsApp/email Anda. Cek pesan masuk Anda ya!') ?>
          </p>
          <?php if ($tripwire): ?>
          <!-- ─── Tripwire offer — gaya kartu Product Bump (amber dashed) ─── -->
          <?php if (trim($magnet['tripwire_headline'] ?? '') !== ''): ?>
          <p class="text-sm font-bold text-slate-900 mb-2 text-center"><?= e($magnet['tripwire_headline']) ?></p>
          <?php endif; ?>
          <div class="text-left rounded-lg border-2 border-dashed border-amber-200 bg-amber-50/50 p-3 mb-3">
            <div class="flex items-start gap-3">
              <?php if ($twThumb !== ''): ?>
              <img src="<?= e($twThumb) ?>" alt="<?= e($tripwire['name']) ?>" class="w-16 h-16 rounded-lg object-cover shrink-0">
              <?php endif; ?>
              <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-amber-800 leading-snug"><?= e($tripwire['name']) ?></p>
                <?php if (trim($tripwire['short_description'] ?? '') !== ''): ?>
                <p class="text-xs text-gray-500 mt-0.5 leading-relaxed line-clamp-2"><?= e($tripwire['short_description']) ?></p>
                <?php endif; ?>
                <p class="text-sm font-bold text-gray-900 mt-1"><?= e(formatRupiah((float) $tripwire['price'])) ?><?php if ((float) $tripwire['original_price'] > (float) $tripwire['price']): ?> <span class="ml-1 text-xs text-gray-400 line-through font-normal"><?= e(formatRupiah((float) $tripwire['original_price'])) ?></span><?php endif; ?></p>
              </div>
            </div>
            <a href="<?= e($twUrl) ?>" class="inline-flex items-center justify-center gap-2 w-full px-5 py-2.5 rounded-md bg-slate-900 text-white text-sm font-semibold hover:bg-slate-800 transition mt-3">
              Ambil Penawaran →
            </a>
          </div>
          <a href="<?= e($loginUrl) ?>" class="text-[13px] text-slate-500 hover:text-slate-700 underline">Nanti saja, masuk ke akun</a>
          <?php else: ?>
          <a href="<?= e($loginUrl) ?>" class="inline-flex items-center justify-center gap-2 w-full px-5 py-2.5 rounded-md bg-slate-900 text-white text-sm font-semibold hover:bg-slate-800 transition">
            Masuk ke Akun
          </a>
          <?php endif; ?>
        </div>

        <?php elseif ($isAuthed && $alreadyClaimed): ?>
        <!-- ─── State: Sudah Mengakses (cegah dobel) ─── -->
        <div class="text-center py-1">
          <div class="w-12 h-12 mx-auto rounded-md bg-indigo-100 flex items-center justify-center mb-3">
            <svg class="w-6 h-6 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
          </div>
          <h2 class="text-base font-bold text-slate-900 mb-1">Kamu sudah punya akses ✓</h2>
          <p class="text-[13px] text-slate-500 leading-relaxed mb-4">Kamu sudah mengakses lead magnet ini sebelumnya. Tidak perlu mendaftar lagi.</p>
          <a href="<?= e($base) ?>/dashboard" class="inline-flex items-center justify-center gap-2 w-full px-5 py-2.5 rounded-md bg-slate-900 text-white text-sm font-semibold hover:bg-slate-800 transition">
            Buka Dashboard
          </a>
        </div>

        <?php elseif ($isAuthed): ?>
        <!-- ─── State: Sudah Login → tinggal klik Free Access ─── -->
        <?php if ($formError !== ''): ?>
        <div class="mb-3 rounded-md bg-red-50 border border-red-100 text-red-700 text-[13px] px-3 py-2.5"><?= e($formError) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= e($submitUrl) ?>">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="lead_magnet_id" value="<?= (int) $magnet['id'] ?>">
          <input type="hidden" name="slug" value="<?= e($magnet['slug']) ?>">

          <?php if ($needWa): ?>
          <div>
            <input type="tel" name="whatsapp" required inputmode="numeric" maxlength="20" placeholder="Nomor WhatsApp"
                   class="lm-field w-full bg-transparent border-0 border-b border-slate-200 px-0.5 py-2.5 text-sm text-slate-900 placeholder-slate-400 transition">
          </div>
          <p class="text-xs text-slate-400 mt-1 mb-1">Lengkapi nomor WhatsApp untuk menerima akses.</p>
          <?php endif; ?>

          <div class="pt-1">
            <button type="submit"
                    class="group w-full inline-flex items-center justify-center gap-2 px-5 py-3 rounded-md text-white text-sm font-semibold bg-gradient-to-r from-slate-900 to-slate-700 shadow-md shadow-slate-900/15 hover:from-slate-800 hover:to-slate-600 transition">
              <?= e($cta) ?>
              <svg class="w-4 h-4 transition-transform group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
          </div>
        </form>
        <?php if ($authName !== ''): ?>
        <p class="mt-3 text-center text-xs text-slate-400">Masuk sebagai <span class="font-medium text-slate-500"><?= e($authName) ?></span></p>
        <?php endif; ?>

        <?php else: ?>
        <!-- ─── State: Anonim → form lengkap ─── -->
        <?php if ($formError !== ''): ?>
        <div class="mb-3 rounded-md bg-red-50 border border-red-100 text-red-700 text-[13px] px-3 py-2.5"><?= e($formError) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= e($submitUrl) ?>">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="lead_magnet_id" value="<?= (int) $magnet['id'] ?>">
          <input type="hidden" name="slug" value="<?= e($magnet['slug']) ?>">

          <div>
            <input type="text" name="name" required maxlength="120" placeholder="Nama Lengkap"
                   class="lm-field w-full bg-transparent border-0 border-b border-slate-200 px-0.5 py-2.5 text-sm text-slate-900 placeholder-slate-400 transition">
          </div>
          <div>
            <input type="tel" name="whatsapp" required inputmode="numeric" maxlength="20" placeholder="Nomor WhatsApp"
                   class="lm-field w-full bg-transparent border-0 border-b border-slate-200 px-0.5 py-2.5 text-sm text-slate-900 placeholder-slate-400 transition">
          </div>
          <div>
            <input type="email" name="email" maxlength="160" placeholder="Email Aktif"
                   class="lm-field w-full bg-transparent border-0 border-b border-slate-200 px-0.5 py-2.5 text-sm text-slate-900 placeholder-slate-400 transition">
          </div>

          <div class="pt-4">
            <button type="submit"
                    class="group w-full inline-flex items-center justify-center gap-2 px-5 py-3 rounded-md text-white text-sm font-semibold bg-gradient-to-r from-slate-900 to-slate-700 shadow-md shadow-slate-900/15 hover:from-slate-800 hover:to-slate-600 transition">
              <?= e($cta) ?>
              <svg class="w-4 h-4 transition-transform group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
          </div>
        </form>

        <!-- Login card -->
        <div class="mt-4 flex items-center justify-between gap-3 rounded-md ring-1 ring-slate-200 bg-slate-50/60 px-3 py-2.5">
          <div class="flex items-center gap-2 text-[13px] text-slate-500">
            <span class="w-6 h-6 rounded bg-white ring-1 ring-slate-200 flex items-center justify-center">
              <svg class="w-3.5 h-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            Sudah punya akun?
          </div>
          <a href="<?= e($loginUrl) ?>" class="px-3.5 py-1.5 rounded-md border border-slate-200 text-[13px] font-semibold text-slate-700 hover:bg-white transition">Login</a>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-center gap-1.5 text-[11px] text-slate-400">
          <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Data anda aman &amp; terenkripsi
        </div>

      </div>
    </div>
  </div>

  <script>
  (function () {
    var box = document.getElementById('leadOtpBox');
    if (!box) return;
    var reqUrl = box.dataset.otpReq, verUrl = box.dataset.otpVer, csrf = box.dataset.csrf;
    var s1 = document.getElementById('lo-step1'), s2 = document.getElementById('lo-step2');
    var sendBtn = document.getElementById('lo-send'), verifyBtn = document.getElementById('lo-verify'), resendBtn = document.getElementById('lo-resend');
    var codeInp = document.getElementById('lo-code');

    function showErr(id, msg, html) {
      var el = document.getElementById(id);
      if (html) { el.innerHTML = msg; } else { el.textContent = msg; }
      el.classList.remove('hidden');
    }
    function hideErr(id) { document.getElementById(id).classList.add('hidden'); }

    async function sendCode(btn) {
      hideErr('lo-err1'); hideErr('lo-err2');
      btn.disabled = true; var t = btn.textContent; btn.textContent = 'Mengirim...';
      try {
        var r = await fetch(reqUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'csrf_token=' + encodeURIComponent(csrf) });
        var d = await r.json();
        if (d.success) { s1.classList.add('hidden'); s2.classList.remove('hidden'); codeInp.focus(); }
        else { showErr(s2.classList.contains('hidden') ? 'lo-err1' : 'lo-err2', d.message || 'Gagal mengirim kode.'); }
      } catch (e) { showErr('lo-err1', 'Gangguan koneksi, coba lagi.'); }
      finally { btn.disabled = false; btn.textContent = t; }
    }

    async function verifyCode() {
      hideErr('lo-err2');
      var code = (codeInp.value || '').trim();
      if (code.length !== 6) { showErr('lo-err2', 'Masukkan 6 digit kode.'); return; }
      verifyBtn.disabled = true; var t = verifyBtn.textContent; verifyBtn.textContent = 'Memverifikasi...';
      try {
        var r = await fetch(verUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'csrf_token=' + encodeURIComponent(csrf) + '&code=' + encodeURIComponent(code) });
        var d = await r.json();
        if (d.success) { window.location.href = d.redirect_to; return; }
        if (d.login_url) { showErr('lo-err2', (d.message || '') + ' <a href="' + d.login_url + '" class="underline font-semibold">Login</a>', true); }
        else { showErr('lo-err2', d.message || 'Kode salah.'); }
        codeInp.value = ''; codeInp.focus();
      } catch (e) { showErr('lo-err2', 'Gangguan koneksi, coba lagi.'); }
      finally { verifyBtn.disabled = false; verifyBtn.textContent = t; }
    }

    sendBtn.addEventListener('click', function () { sendCode(sendBtn); });
    if (resendBtn) resendBtn.addEventListener('click', function () { sendCode(resendBtn); });
    verifyBtn.addEventListener('click', verifyCode);
    codeInp.addEventListener('input', function () { this.value = this.value.replace(/\D/g, ''); if (this.value.length === 6) verifyCode(); });
  })();
  </script>
</body>
</html>

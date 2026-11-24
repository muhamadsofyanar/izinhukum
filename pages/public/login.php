<?php
require_once __DIR__ . '/../../bootstrap.php';

// Tujuan kembali pasca-login (mis. dari halaman publik /lp/{slug} yang tidak
// memanggil requireLogin). Validasi sama dengan actions/auth/login.php: harus
// path internal, bukan halaman auth, tanpa '//' (cegah open-redirect).
$redirectParam = (string) ($_GET['redirect'] ?? '');
$redirectOk    = isSafeRedirectPath($redirectParam);
if ($redirectOk) {
    $_SESSION['login_redirect'] = $redirectParam; // dikonsumsi oleh login action
}

if (isLoggedIn()) {
    // Sudah login & ada tujuan valid → langsung antar (raw header: param sudah
    // termasuk base path, jangan lewat redirect() agar tidak dobel-prefix).
    if ($redirectOk) {
        unset($_SESSION['login_redirect']);
        header('Location: ' . $redirectParam);
        exit;
    }
    redirect(isAdmin() ? '/admin' : '/dashboard');
}

// Teruskan tujuan ke alur alternatif. Google pakai key sendiri (google_oauth_intended)
// dengan path TANPA base path (redirect() helper menambah base lagi); WA OTP membaca
// login_redirect (sudah termasuk base) lewat verify-otp.
$basePath = rtrim((string) parse_url(APP_URL, PHP_URL_PATH), '/');
$redirectNoBase = $redirectParam;
if ($redirectOk && $basePath !== '' && str_starts_with($redirectNoBase, $basePath . '/')) {
    $redirectNoBase = substr($redirectNoBase, strlen($basePath));
}
$googleQuery = $redirectOk ? ('?intended=' . rawurlencode($redirectNoBase)) : '';
$otpQuery    = $redirectOk ? ('?redirect=' . rawurlencode($redirectParam)) : '';

$csrf       = generateCSRF();
$pageTitle  = 'Login';
$appName    = getSetting('app_name', 'STIFIn Mulia');
$appLogo    = getSetting('app_logo', '');
$brandColor = getSetting('brand_color', '#6366f1');

$googleEnabled = getSetting('google_oauth_enabled', '0') === '1'
              && !empty(getSetting('google_oauth_client_id', ''));

$loginError = getFlash('login_error');
$authInfo   = getFlash('auth_info');
$success    = getFlash('success');
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="min-h-screen bg-gray-50 dark:bg-gray-950 flex items-center justify-center p-4">

  <!-- Background premium: pola dot tipis + aura brand lembut -->
  <div aria-hidden="true" class="fixed inset-0 z-0 overflow-hidden pointer-events-none">
    <div class="absolute inset-0" style="background-image:radial-gradient(rgba(100,116,139,.14) 1px,transparent 1px);background-size:22px 22px;"></div>
    <div class="absolute -top-28 -left-24 w-[26rem] h-[26rem] rounded-full blur-3xl" style="background:radial-gradient(circle,color-mix(in srgb,var(--brand-color) 16%,transparent),transparent 70%);"></div>
    <div class="absolute -bottom-28 -right-24 w-[30rem] h-[30rem] rounded-full blur-3xl" style="background:radial-gradient(circle,color-mix(in srgb,var(--brand-color) 12%,transparent),transparent 70%);"></div>
  </div>
  <style>
    .au-input{transition:border-color .15s ease,box-shadow .15s ease,background-color .15s ease;}
    .au-input:focus{outline:none;border-color:var(--brand-color);box-shadow:0 0 0 3px color-mix(in srgb,var(--brand-color) 22%,transparent);}
    .au-btn{transition:transform .15s ease,box-shadow .15s ease,opacity .15s ease,background-color .15s ease,border-color .15s ease;}
    .au-btn:hover{transform:translateY(-1px);} .au-btn:active{transform:translateY(0);}
    .au-arrow{transition:transform .2s ease;} .au-btn:hover .au-arrow{transform:translateX(3px);}
    .au-ico{color:color-mix(in srgb,var(--brand-color) 55%,#94a3b8);}
  </style>

  <div class="relative z-10 w-full max-w-[380px]">

    <!-- Header -->
    <div class="text-center mb-6">
      <?php if (!empty($appLogo)): ?>
        <img src="<?= e($appLogo) ?>" alt="<?= e($appName) ?>" class="h-9 mx-auto mb-3 object-contain">
      <?php else: ?>
        <div class="w-10 h-10 rounded-xl flex items-center justify-center mx-auto mb-3" style="background:<?= e($brandColor) ?>">
          <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
          </svg>
        </div>
      <?php endif; ?>
      <h1 class="text-base font-semibold text-gray-900 dark:text-white"><?= e($appName) ?></h1>
      <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Masuk ke akun kamu</p>
    </div>

    <!-- Flash messages (outside card so they don't bump card height) -->
    <?php if ($loginError): ?>
    <div class="mb-3 flex items-start gap-2.5 px-3.5 py-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-100 dark:border-red-800/50 text-red-700 dark:text-red-400 text-sm">
      <svg class="w-4 h-4 mt-px shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <?= e($loginError['message']) ?>
    </div>
    <?php endif; ?>
    <?php if ($authInfo): ?>
    <div class="mb-3 flex items-start gap-2.5 px-3.5 py-3 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800/50 text-blue-700 dark:text-blue-400 text-sm">
      <svg class="w-4 h-4 mt-px shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <?= e($authInfo['message']) ?>
    </div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="mb-3 flex items-start gap-2.5 px-3.5 py-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-100 dark:border-green-800/50 text-green-700 dark:text-green-400 text-sm">
      <svg class="w-4 h-4 mt-px shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
      </svg>
      <?= e($success['message']) ?>
    </div>
    <?php endif; ?>

    <!-- Form card -->
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 shadow-sm p-6">
      <form action="<?= e(APP_URL) ?>/actions/auth/login" method="POST" class="space-y-3.5" data-loading>
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

        <div>
          <label for="email" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Email</label>
          <div class="relative">
            <svg class="au-ico absolute left-3 top-1/2 -translate-y-1/2 w-[18px] h-[18px] pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
            <input type="email" id="email" name="email" required autocomplete="email"
                   value="<?= e($_GET['email'] ?? '') ?>"
                   placeholder="nama@email.com"
                   class="au-input w-full h-11 pl-10 pr-3.5 rounded-lg text-sm bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-600 focus:bg-white dark:focus:bg-gray-800">
          </div>
        </div>

        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label for="password" class="block text-xs font-medium text-gray-600 dark:text-gray-400">Password</label>
            <a href="<?= e(APP_URL) ?>/forgot-password" class="text-xs font-medium hover:underline" style="color:<?= e($brandColor) ?>">Lupa password?</a>
          </div>
          <div class="relative">
            <svg class="au-ico absolute left-3 top-1/2 -translate-y-1/2 w-[18px] h-[18px] pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" id="password" name="password" required autocomplete="current-password"
                   placeholder="••••••••"
                   class="au-input w-full h-11 pl-10 pr-10 rounded-lg text-sm bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-600 focus:bg-white dark:focus:bg-gray-800">
            <button type="button" onclick="togglePw('password', this)"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
              <svg class="w-[18px] h-[18px] eye-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
              </svg>
              <svg class="w-[18px] h-[18px] eye-hide hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/>
              </svg>
            </button>
          </div>
        </div>

        <button type="submit" data-loading-text="Masuk..."
                class="au-btn w-full h-11 rounded-lg text-sm font-semibold text-white inline-flex items-center justify-center gap-2 mt-0.5"
                style="background:<?= e($brandColor) ?>;box-shadow:0 6px 16px -6px color-mix(in srgb,<?= e($brandColor) ?> 65%,transparent);">
          Masuk
          <svg class="au-arrow w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
      </form>
    </div>

    <?php $hasGoogle = $googleEnabled;
          $hasWa = (getSetting('wa_otp_login_enabled', '0') === '1' && !empty(getSetting('wa_otp_provider', ''))); ?>
    <?php if ($hasGoogle || $hasWa): ?>
    <!-- Divider tunggal -->
    <div class="flex items-center gap-3 my-4">
      <div class="flex-1 h-px bg-gray-200 dark:bg-gray-800"></div>
      <span class="text-xs text-gray-400 dark:text-gray-500">atau lanjut dengan</span>
      <div class="flex-1 h-px bg-gray-200 dark:bg-gray-800"></div>
    </div>

    <div class="space-y-2.5">
      <?php if ($hasGoogle): ?>
      <a href="<?= e(APP_URL) ?>/auth/google<?= e($googleQuery) ?>"
         class="au-btn flex items-center justify-center gap-2.5 w-full h-11 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24">
          <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
          <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
          <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
          <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
        </svg>
        Lanjutkan dengan Google
      </a>
      <?php endif; ?>
      <?php if ($hasWa): ?>
      <a href="<?= e(APP_URL) ?>/login-otp<?= e($otpQuery) ?>"
         class="au-btn flex items-center justify-center gap-2 w-full h-11 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">
        <svg class="w-[18px] h-[18px] shrink-0 text-green-600" viewBox="0 0 24 24" fill="currentColor">
          <path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm5.51 14.17c-.23.65-1.36 1.25-1.87 1.32-.48.07-1.08.1-1.74-.11-.4-.13-.92-.3-1.58-.59-2.78-1.2-4.59-4-4.73-4.19-.14-.19-1.13-1.5-1.13-2.86 0-1.36.71-2.03.97-2.31.25-.27.55-.34.73-.34.18 0 .37 0 .53.01.17.01.4-.06.62.48.23.55.78 1.91.85 2.05.07.14.11.3.02.49-.09.19-.14.3-.27.46-.14.16-.29.36-.41.48-.14.14-.28.29-.12.57.16.27.71 1.17 1.52 1.9 1.04.94 1.92 1.23 2.19 1.37.27.14.43.12.59-.07.16-.19.68-.79.86-1.06.18-.27.36-.22.6-.13.25.09 1.58.74 1.85.88.27.13.45.2.51.31.07.12.07.66-.16 1.31z"/>
        </svg>
        Login dengan WhatsApp
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Footer links -->
    <p class="text-center text-sm text-gray-500 dark:text-gray-400 mt-5">
      Belum punya akun?
      <a href="<?= e(APP_URL) ?>/register<?= e($otpQuery) ?>" class="font-medium hover:underline" style="color:<?= e($brandColor) ?>">Daftar sekarang</a>
    </p>

    <div class="flex justify-center mt-4">
      <?php include __DIR__ . '/../../partials/dark-toggle.php'; ?>
    </div>

  </div>

<script>
function focusInput(el, color) {
    el.style.borderColor = color;
    el.style.boxShadow   = '0 0 0 3px ' + color + '22';
}
function blurInput(el) {
    el.style.borderColor = '';
    el.style.boxShadow   = '';
}
function togglePw(id, btn) {
    const inp  = document.getElementById(id);
    const show = btn.querySelector('.eye-show');
    const hide = btn.querySelector('.eye-hide');
    if (inp.type === 'password') {
        inp.type = 'text';
        show.classList.add('hidden');
        hide.classList.remove('hidden');
    } else {
        inp.type = 'password';
        show.classList.remove('hidden');
        hide.classList.add('hidden');
    }
}
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

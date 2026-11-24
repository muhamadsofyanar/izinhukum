<?php
require_once __DIR__ . '/../../bootstrap.php';

if (isLoggedIn()) {
    redirect(isAdmin() ? '/admin' : '/dashboard');
}

// Anti-bot: catat waktu render form. Dicek di actions/auth/register.php — manusia
// mengisi form beberapa detik; bot mem-POST hampir seketika.
$_SESSION['reg_form_ts'] = time();

// Simpan referral code dari URL ke session
if (!empty($_GET['ref'])) {
    $refCode = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($_GET['ref'])));
    if (strlen($refCode) >= 4) {
        $_SESSION['ref_code'] = $refCode;
    }
}

// Tujuan kembali pasca-daftar (mis. dari halaman produk mode "login" → checkout).
// Validasi sama dengan login: harus path internal, bukan halaman auth, tanpa '//'
// (cegah open-redirect). Dikonsumsi oleh actions/auth/register.php.
$redirectParam = (string) ($_GET['redirect'] ?? '');
$redirectOk    = isSafeRedirectPath($redirectParam);
if ($redirectOk) {
    $_SESSION['login_redirect'] = $redirectParam;
}

// Teruskan tujuan (redirect) ke alur alternatif — mirror login.php.
// Google pakai ?intended (path TANPA base; redirect() menambah base lagi);
// link ke /login pakai ?redirect (path lengkap, termasuk base).
$basePath       = rtrim((string) parse_url(APP_URL, PHP_URL_PATH), '/');
$redirectNoBase = $redirectParam;
if ($redirectOk && $basePath !== '' && str_starts_with($redirectNoBase, $basePath . '/')) {
    $redirectNoBase = substr($redirectNoBase, strlen($basePath));
}
$googleQuery    = $redirectOk ? ('?intended=' . rawurlencode($redirectNoBase)) : '';
$loginLinkQuery = $redirectOk ? ('?redirect=' . rawurlencode($redirectParam)) : '';

$csrf         = generateCSRF();
$pageTitle    = 'Daftar';
$appName      = getSetting('app_name', 'STIFIn Mulia');
$appLogo      = getSetting('app_logo', '');
$brandColor   = getSetting('brand_color', '#6366f1');
$refCodeValue = $_SESSION['ref_code'] ?? '';

$googleEnabled = getSetting('google_oauth_enabled', '0') === '1'
              && !empty(getSetting('google_oauth_client_id', ''));

$regError = getFlash('register_error');
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
      <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
        <?= !empty($refCodeValue) ? 'Kamu diundang via kode referral' : 'Buat akun baru, gratis!' ?>
      </p>
    </div>

    <!-- Flash error -->
    <?php if ($regError): ?>
    <div class="mb-3 flex items-start gap-2.5 px-3.5 py-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-100 dark:border-red-800/50 text-red-700 dark:text-red-400 text-sm">
      <svg class="w-4 h-4 mt-px shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <?= e($regError['message']) ?>
    </div>
    <?php endif; ?>

    <!-- Form card -->
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 shadow-sm p-6">

      <form action="<?= e(APP_URL) ?>/actions/auth/register" method="POST" class="space-y-3.5" data-loading>
        <input type="hidden" name="csrf_token"    value="<?= e($csrf) ?>">
        <input type="hidden" name="referral_code" value="<?= e($refCodeValue) ?>">
        <?php /* Honeypot anti-bot — TERSEMBUNYI dari manusia (off-screen + aria-hidden +
                 tabindex/-autocomplete). Bot yang mengisi semua field akan mengisinya →
                 ditolak di server (actions/auth/register.php). Jangan hapus / jangan beri
                 label yang terlihat / jangan pakai type=hidden (bot melewati hidden). */ ?>
        <div aria-hidden="true" style="position:absolute;left:-9999px;top:0;height:0;width:0;overflow:hidden">
          <label>Biarkan kosong<input type="text" name="website" tabindex="-1" autocomplete="off" value=""></label>
        </div>

        <!-- Nama -->
        <div>
          <label for="name" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Nama Lengkap</label>
          <div class="relative">
            <svg class="au-ico absolute left-3 top-1/2 -translate-y-1/2 w-[18px] h-[18px] pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input type="text" id="name" name="name" required minlength="2" maxlength="100"
                   autocomplete="name" placeholder="Nama kamu"
                   value="<?= e($_POST['name'] ?? '') ?>"
                   class="au-input w-full h-11 pl-10 pr-3.5 rounded-lg text-sm bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-600 focus:bg-white dark:focus:bg-gray-800">
          </div>
        </div>

        <!-- Email -->
        <div>
          <label for="email" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Email</label>
          <div class="relative">
            <svg class="au-ico absolute left-3 top-1/2 -translate-y-1/2 w-[18px] h-[18px] pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
            <input type="email" id="email" name="email" required
                   autocomplete="email" placeholder="nama@email.com"
                   value="<?= e($_POST['email'] ?? '') ?>"
                   class="au-input w-full h-11 pl-10 pr-3.5 rounded-lg text-sm bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-600 focus:bg-white dark:focus:bg-gray-800">
          </div>
        </div>

        <!-- Password -->
        <div>
          <label for="password" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Password</label>
          <div class="relative">
            <svg class="au-ico absolute left-3 top-1/2 -translate-y-1/2 w-[18px] h-[18px] pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" id="password" name="password" required minlength="8"
                   autocomplete="new-password" placeholder="Min. 8 karakter"
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

        <!-- Konfirmasi Password -->
        <div>
          <label for="confirm_password" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Konfirmasi Password</label>
          <div class="relative">
            <svg class="au-ico absolute left-3 top-1/2 -translate-y-1/2 w-[18px] h-[18px] pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><polyline points="9.5 16.5 11 18 14.5 14.5"/></svg>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8"
                   autocomplete="new-password" placeholder="Ulangi password"
                   class="au-input w-full h-11 pl-10 pr-3.5 rounded-lg text-sm bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-600 focus:bg-white dark:focus:bg-gray-800">
          </div>
        </div>

        <?php if (!empty($refCodeValue)): ?>
        <!-- Referral badge -->
        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-800/60 border border-gray-100 dark:border-gray-700/50 text-xs text-gray-500 dark:text-gray-400">
          <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
            <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
          </svg>
          Referral: <strong class="text-gray-700 dark:text-gray-200"><?= e($refCodeValue) ?></strong>
        </div>
        <?php endif; ?>

        <button type="submit" data-loading-text="Mendaftarkan..."
                class="au-btn w-full h-11 rounded-lg text-sm font-semibold text-white inline-flex items-center justify-center gap-2 mt-0.5"
                style="background:<?= e($brandColor) ?>;box-shadow:0 6px 16px -6px color-mix(in srgb,<?= e($brandColor) ?> 65%,transparent);">
          Daftar Sekarang
          <svg class="au-arrow w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
      </form>
    </div>

    <?php if ($googleEnabled): ?>
    <!-- Divider tunggal + Google (di luar kartu, mirror login) -->
    <div class="flex items-center gap-3 my-4">
      <div class="flex-1 h-px bg-gray-200 dark:bg-gray-800"></div>
      <span class="text-xs text-gray-400 dark:text-gray-500">atau lanjut dengan</span>
      <div class="flex-1 h-px bg-gray-200 dark:bg-gray-800"></div>
    </div>
    <a href="<?= e(APP_URL) ?>/auth/google<?= e($googleQuery) ?>"
       class="au-btn flex items-center justify-center gap-2.5 w-full h-11 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">
      <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24">
        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
      </svg>
      Daftar dengan Google
    </a>
    <?php endif; ?>

    <!-- Footer links -->
    <p class="text-center text-sm text-gray-500 dark:text-gray-400 mt-5">
      Sudah punya akun?
      <a href="<?= e(APP_URL) ?>/login<?= e($loginLinkQuery) ?>" class="font-medium hover:underline" style="color:<?= e($brandColor) ?>">Masuk di sini</a>
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

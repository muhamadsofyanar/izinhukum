<?php
require_once __DIR__ . '/../../bootstrap.php';

if (isLoggedIn()) {
    redirect(isAdmin() ? '/admin' : '/dashboard');
}

$csrf       = generateCSRF();
$pageTitle  = 'Lupa Password';
$appName    = getSetting('app_name', 'STIFIn Mulia');
$appLogo    = getSetting('app_logo', '');
$brandColor = getSetting('brand_color', '#6366f1');

$error   = getFlash('forgot_error');
$success = getFlash('forgot_success');
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="min-h-screen bg-gray-50 dark:bg-gray-950 flex items-center justify-center p-4">

  <div aria-hidden="true" class="fixed inset-0 z-0 overflow-hidden pointer-events-none">
    <div class="absolute inset-0" style="background-image:radial-gradient(rgba(100,116,139,.14) 1px,transparent 1px);background-size:22px 22px;"></div>
    <div class="absolute -top-28 -left-24 w-[26rem] h-[26rem] rounded-full blur-3xl" style="background:radial-gradient(circle,color-mix(in srgb,var(--brand-color) 16%,transparent),transparent 70%);"></div>
    <div class="absolute -bottom-28 -right-24 w-[30rem] h-[30rem] rounded-full blur-3xl" style="background:radial-gradient(circle,color-mix(in srgb,var(--brand-color) 12%,transparent),transparent 70%);"></div>
  </div>
  <style>
    .au-input{transition:border-color .15s ease,box-shadow .15s ease,background-color .15s ease;}
    .au-input:focus{outline:none;border-color:var(--brand-color);box-shadow:0 0 0 3px color-mix(in srgb,var(--brand-color) 22%,transparent);}
    .au-btn{transition:transform .15s ease,box-shadow .15s ease,opacity .15s ease;}
    .au-btn:hover{transform:translateY(-1px);} .au-btn:active{transform:translateY(0);}
    .au-ico{color:color-mix(in srgb,var(--brand-color) 55%,#94a3b8);}
  </style>

  <div class="relative z-10 w-full max-w-[380px]">

    <div class="text-center mb-6">
      <?php if (!empty($appLogo)): ?>
        <img src="<?= e($appLogo) ?>" alt="<?= e($appName) ?>" class="h-9 mx-auto mb-3 object-contain">
      <?php else: ?>
        <div class="w-10 h-10 rounded-xl flex items-center justify-center mx-auto mb-3" style="background:<?= e($brandColor) ?>">
          <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
        </div>
      <?php endif; ?>
      <h1 class="text-base font-semibold text-gray-900 dark:text-white">Lupa Password</h1>
      <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Masukkan email terdaftar — link reset dikirim ke sana</p>
    </div>

    <?php if ($error): ?>
    <div class="mb-3 flex items-start gap-2.5 px-3.5 py-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-100 dark:border-red-800/50 text-red-700 dark:text-red-400 text-sm">
      <svg class="w-4 h-4 mt-px shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <?= e($error['message']) ?>
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

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 shadow-sm p-6">
      <form action="<?= e(APP_URL) ?>/actions/auth/forgot-password" method="POST" class="space-y-3.5" data-loading>
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <div>
          <label for="email" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Email</label>
          <div class="relative">
            <svg class="au-ico absolute left-3 top-1/2 -translate-y-1/2 w-[18px] h-[18px] pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
            <input type="email" id="email" name="email" required autocomplete="email"
                   placeholder="nama@email.com"
                   class="au-input w-full h-11 pl-10 pr-3.5 rounded-lg text-sm bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-600 focus:bg-white dark:focus:bg-gray-800">
          </div>
        </div>
        <button type="submit" data-loading-text="Mengirim..."
                class="au-btn w-full h-11 rounded-lg text-sm font-semibold text-white inline-flex items-center justify-center gap-2 mt-0.5"
                style="background:<?= e($brandColor) ?>;box-shadow:0 6px 16px -6px color-mix(in srgb,<?= e($brandColor) ?> 65%,transparent);">
          Kirim Link Reset
        </button>
      </form>
    </div>

    <p class="text-center text-sm text-gray-500 dark:text-gray-400 mt-5">
      Ingat password kamu?
      <a href="<?= e(APP_URL) ?>/login" class="font-medium hover:underline" style="color:<?= e($brandColor) ?>">Kembali ke login</a>
    </p>
  </div>
</body>
</html>

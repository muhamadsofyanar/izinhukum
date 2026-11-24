<?php
require_once __DIR__ . '/../../bootstrap.php';

// Halaman tantangan 2FA — hanya bermakna bila ada login yang menggantung.
if (isLoggedIn()) {
    redirect(isAdmin() ? '/admin' : '/dashboard');
}
$pending = $_SESSION['totp_pending'] ?? null;
if (!is_array($pending) || (int) ($pending['expires'] ?? 0) < time()) {
    unset($_SESSION['totp_pending']);
    flash('login_error', 'Sesi verifikasi kedaluwarsa. Silakan login ulang.', 'error');
    redirect('/login');
}

$csrf       = generateCSRF();
$pageTitle  = 'Verifikasi 2 Langkah';
$appName    = getSetting('app_name', 'STIFIn Mulia');
$appLogo    = getSetting('app_logo', '');
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="min-h-screen bg-gray-50 dark:bg-gray-950 flex flex-col items-center justify-center py-12 px-4">

  <!-- Background premium: pola dot tipis + aura brand lembut (konsisten login) -->
  <div aria-hidden="true" class="fixed inset-0 z-0 overflow-hidden pointer-events-none">
    <div class="absolute inset-0" style="background-image:radial-gradient(rgba(100,116,139,.14) 1px,transparent 1px);background-size:22px 22px;"></div>
    <div class="absolute -top-28 -left-24 w-[26rem] h-[26rem] rounded-full blur-3xl" style="background:radial-gradient(circle,color-mix(in srgb,var(--brand-color) 16%,transparent),transparent 70%);"></div>
    <div class="absolute -bottom-28 -right-24 w-[30rem] h-[30rem] rounded-full blur-3xl" style="background:radial-gradient(circle,color-mix(in srgb,var(--brand-color) 12%,transparent),transparent 70%);"></div>
  </div>
  <style>
    .au-input{transition:border-color .15s ease,box-shadow .15s ease;}
    .au-input:focus{outline:none;border-color:var(--brand-color);box-shadow:0 0 0 3px color-mix(in srgb,var(--brand-color) 22%,transparent);}
  </style>

  <div class="relative z-10 w-full max-w-sm">

    <div class="text-center mb-8">
      <?php if (!empty($appLogo)): ?>
        <img src="<?= e($appLogo) ?>" alt="<?= e($appName) ?>" class="h-10 mx-auto mb-4 object-contain">
      <?php else: ?>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-1"><?= e($appName) ?></h1>
      <?php endif; ?>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-xl shadow-gray-200/50 dark:shadow-black/30 p-6">

      <div class="w-12 h-12 mx-auto rounded-2xl bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)] flex items-center justify-center mb-4">
        <svg class="w-6 h-6 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>
        </svg>
      </div>
      <h2 class="text-lg font-bold text-gray-900 dark:text-white text-center">Verifikasi 2 Langkah</h2>
      <p class="text-sm text-gray-500 dark:text-gray-400 text-center mt-1 mb-5 leading-relaxed">
        Masukkan kode 6 digit dari aplikasi authenticator kamu, atau salah satu backup code.
      </p>

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <form method="POST" action="<?= e(APP_URL) ?>/actions/auth/verify-2fa" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <div>
          <input type="text" name="code" required autofocus autocomplete="one-time-code"
                 inputmode="text" maxlength="9" placeholder="123456 atau XXXX-XXXX"
                 class="au-input w-full px-4 py-3 rounded-xl text-center text-lg font-semibold tracking-[0.3em] border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-300 dark:placeholder-gray-600 placeholder:tracking-normal placeholder:text-sm placeholder:font-normal">
        </div>
        <label class="flex items-center gap-2.5 cursor-pointer">
          <input type="checkbox" name="trust_device" value="1"
                 class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-[var(--brand-color)] focus:ring-[var(--brand-color)]">
          <span class="text-sm text-gray-600 dark:text-gray-400">Ingat perangkat ini 30 hari</span>
        </label>
        <button type="submit" data-loading data-loading-text="Memverifikasi..."
                class="w-full py-3 rounded-xl text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
          Verifikasi &amp; Masuk
        </button>
      </form>

      <p class="text-xs text-gray-400 dark:text-gray-600 text-center mt-5 leading-relaxed">
        Kehilangan akses aplikasi authenticator? Gunakan salah satu backup code
        yang kamu simpan saat mengaktifkan 2FA.
      </p>
    </div>

    <p class="text-center mt-6">
      <a href="<?= e(APP_URL) ?>/login" class="text-sm text-gray-500 dark:text-gray-400 hover:text-[var(--brand-color)] transition">&larr; Kembali ke login</a>
    </p>
  </div>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

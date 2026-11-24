<?php
require_once __DIR__ . '/../../bootstrap.php';

// Tujuan kembali pasca-login (mis. dari /lp/{slug}). Validasi sama dgn login.php:
// path internal, bukan halaman auth, tanpa '//'. Disimpan ke login_redirect yang
// dikonsumsi actions/auth/verify-otp.php saat OTP sukses.
$redirectParam = (string) ($_GET['redirect'] ?? '');
$redirectOk    = isSafeRedirectPath($redirectParam);
if ($redirectOk) {
    $_SESSION['login_redirect'] = $redirectParam;
}

if (isLoggedIn()) {
    if ($redirectOk) {
        unset($_SESSION['login_redirect']);
        header('Location: ' . $redirectParam);
        exit;
    }
    redirect(isAdmin() ? '/admin' : '/dashboard');
}

$otpEnabled  = getSetting('wa_otp_login_enabled', '0') === '1';
$otpProvider = getSetting('wa_otp_provider', '');

if (!$otpEnabled || empty($otpProvider)) {
    flash('error', 'Login via WhatsApp belum tersedia saat ini.', 'error');
    redirect('/login');
}

$csrf       = generateCSRF();
$pageTitle  = 'Login via WhatsApp';
$appName    = getSetting('app_name', 'STIFIn Mulia');
$appLogo    = getSetting('app_logo', '');
$brandColor = getSetting('brand_color', '#6366f1');
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="min-h-screen bg-gray-50 dark:bg-gray-950 flex flex-col items-center justify-center py-12 px-4">

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

  <div class="relative z-10 w-full max-w-sm">

    <!-- Logo / Brand -->
    <div class="text-center mb-8">
      <?php if (!empty($appLogo)): ?>
        <img src="<?= e($appLogo) ?>" alt="<?= e($appName) ?>" class="h-10 mx-auto mb-4 object-contain">
      <?php else: ?>
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl mb-4" style="background:<?= e($brandColor) ?>">
          <svg class="w-7 h-7 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
          </svg>
        </div>
      <?php endif; ?>
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white"><?= e($appName) ?></h1>
      <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Masuk dengan kode OTP WhatsApp</p>
    </div>

    <!-- Flash -->
    <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">

      <!-- WA icon header -->
      <div class="flex items-center gap-3 mb-5 pb-5 border-b border-gray-100 dark:border-gray-800">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0" style="background:linear-gradient(135deg,#25D366,#128C7E);box-shadow:0 4px 12px -3px rgba(37,211,102,.55);">
          <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm5.51 14.17c-.23.65-1.36 1.25-1.87 1.32-.48.07-1.08.1-1.74-.11-.4-.13-.92-.3-1.58-.59-2.78-1.2-4.59-4-4.73-4.19-.14-.19-1.13-1.5-1.13-2.86 0-1.36.71-2.03.97-2.31.25-.27.55-.34.73-.34.18 0 .37 0 .53.01.17.01.4-.06.62.48.23.55.78 1.91.85 2.05.07.14.11.3.02.49-.09.19-.14.3-.27.46-.14.16-.29.36-.41.48-.14.14-.28.29-.12.57.16.27.71 1.17 1.52 1.9 1.04.94 1.92 1.23 2.19 1.37.27.14.43.12.59-.07.16-.19.68-.79.86-1.06.18-.27.36-.22.6-.13.25.09 1.58.74 1.85.88.27.13.45.2.51.31.07.12.07.66-.16 1.31z"/>
          </svg>
        </div>
        <div>
          <p class="text-sm font-semibold text-gray-900 dark:text-white">Login via WhatsApp</p>
          <p class="text-xs text-gray-400 dark:text-gray-500">Masuk cepat tanpa password</p>
        </div>
      </div>

      <!-- STEP 1: Input nomor WA -->
      <div id="otp-step-1">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Nomor WhatsApp</label>
        <div class="relative mb-1">
          <svg class="au-ico absolute left-3 top-1/2 -translate-y-1/2 w-[18px] h-[18px] pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          <input type="text" id="otp-phone-input"
                 placeholder="08123456789 atau 628123456789"
                 inputmode="numeric"
                 class="au-input w-full h-11 pl-10 pr-3.5 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400">
        </div>
        <p class="text-xs text-gray-400 dark:text-gray-500 mb-4">Nomor harus terdaftar di akun kamu</p>

        <div id="otp-step1-error" class="hidden mb-4 px-3 py-2.5 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-sm text-red-700 dark:text-red-400"></div>

        <button type="button" id="otp-request-btn" onclick="requestOTP()"
                class="au-btn w-full h-11 text-sm font-semibold text-white rounded-lg inline-flex items-center justify-center gap-2"
                style="background:<?= e($brandColor) ?>;box-shadow:0 6px 16px -6px color-mix(in srgb,<?= e($brandColor) ?> 65%,transparent);">
          <span id="otp-req-label">Kirim Kode OTP</span>
          <svg class="au-arrow w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
      </div>

      <!-- STEP 2: Input kode OTP (hidden saat load) -->
      <div id="otp-step-2" class="hidden">
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
          Kode OTP dikirim ke <span id="otp-phone-display" class="font-semibold text-gray-900 dark:text-white"></span>
        </p>

        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Kode OTP (6 digit)</label>
        <input type="text" id="otp-code-input"
               maxlength="6" inputmode="numeric" placeholder="000000"
               class="au-input w-full px-3.5 py-3 text-center text-2xl tracking-[0.5em] font-bold rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-300 mb-1">
        <p class="text-xs text-gray-400 dark:text-gray-500 mb-4">Berlaku 10 menit</p>

        <div id="otp-step2-error" class="hidden mb-4 px-3 py-2.5 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-sm text-red-700 dark:text-red-400"></div>

        <button type="button" id="otp-verify-btn" onclick="verifyOTP()"
                class="au-btn w-full h-11 text-sm font-semibold text-white rounded-lg inline-flex items-center justify-center gap-2 mb-3"
                style="background:<?= e($brandColor) ?>;box-shadow:0 6px 16px -6px color-mix(in srgb,<?= e($brandColor) ?> 65%,transparent);">
          <span id="otp-ver-label">Verifikasi &amp; Masuk</span>
          <svg class="au-arrow w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>

        <div class="flex items-center justify-center gap-3 text-xs text-gray-400">
          <button type="button" onclick="backToStep1()" class="hover:text-gray-600 dark:hover:text-gray-300 transition">Ganti nomor</button>
          <span>·</span>
          <button type="button" id="otp-resend-btn" onclick="requestOTP()" class="hover:text-gray-600 dark:hover:text-gray-300 transition">Kirim ulang</button>
        </div>
      </div>

    </div>

    <!-- Kembali ke login email -->
    <p class="mt-5 text-center text-sm text-gray-500 dark:text-gray-400">
      <a href="<?= e(APP_URL) ?>/login<?= $redirectOk ? '?redirect=' . e(rawurlencode($redirectParam)) : '' ?>" class="hover:underline">← Kembali ke login email/password</a>
    </p>

    <!-- Dark mode toggle -->
    <div class="mt-6 flex justify-center">
      <?php include __DIR__ . '/../../partials/dark-toggle.php'; ?>
    </div>

  </div>

<script>
const OTP_BASE = '<?= e(APP_URL) ?>';
const csrfToken = '<?= e($csrf) ?>';
let currentPhone = '';

function showError(elId, msg) {
    const el = document.getElementById(elId);
    el.textContent = msg;
    el.classList.remove('hidden');
}
function hideError(elId) {
    document.getElementById(elId).classList.add('hidden');
}

async function requestOTP() {
    const phone = document.getElementById('otp-phone-input').value.trim();
    const btn   = document.getElementById('otp-request-btn');
    hideError('otp-step1-error');

    if (!phone || phone.replace(/[^0-9]/g, '').length < 9) {
        showError('otp-step1-error', 'Masukkan nomor WhatsApp yang valid.');
        return;
    }

    btn.disabled = true;
    document.getElementById('otp-req-label').textContent = 'Mengirim...';

    try {
        const res  = await fetch(OTP_BASE + '/actions/auth/request-otp', {
            method:  'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body:    'phone=' + encodeURIComponent(phone) + '&csrf_token=' + encodeURIComponent(csrfToken),
        });
        const data = await res.json();

        if (data.success) {
            currentPhone = phone;
            document.getElementById('otp-phone-display').textContent = phone;
            document.getElementById('otp-step-1').classList.add('hidden');
            document.getElementById('otp-step-2').classList.remove('hidden');
            document.getElementById('otp-code-input').focus();
        } else {
            showError('otp-step1-error', data.message || 'Terjadi kesalahan, coba lagi.');
        }
    } catch (e) {
        showError('otp-step1-error', 'Terjadi kesalahan koneksi, coba lagi.');
    } finally {
        btn.disabled = false;
        document.getElementById('otp-req-label').textContent = 'Kirim Kode OTP';
    }
}

async function verifyOTP() {
    const code = document.getElementById('otp-code-input').value.trim();
    const btn  = document.getElementById('otp-verify-btn');
    hideError('otp-step2-error');

    if (code.length !== 6) {
        showError('otp-step2-error', 'Masukkan 6 digit kode OTP.');
        return;
    }

    btn.disabled = true;
    document.getElementById('otp-ver-label').textContent = 'Memverifikasi...';

    try {
        const res  = await fetch(OTP_BASE + '/actions/auth/verify-otp', {
            method:  'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body:    'phone=' + encodeURIComponent(currentPhone)
                   + '&code=' + encodeURIComponent(code)
                   + '&csrf_token=' + encodeURIComponent(csrfToken),
        });
        const data = await res.json();

        if (data.success) {
            window.location.href = data.redirect_to;
        } else {
            showError('otp-step2-error', data.message || 'Kode tidak valid.');
            document.getElementById('otp-code-input').value = '';
            document.getElementById('otp-code-input').focus();
        }
    } catch (e) {
        showError('otp-step2-error', 'Terjadi kesalahan koneksi, coba lagi.');
    } finally {
        btn.disabled = false;
        document.getElementById('otp-ver-label').textContent = 'Verifikasi & Masuk';
    }
}

function backToStep1() {
    document.getElementById('otp-step-2').classList.add('hidden');
    document.getElementById('otp-step-1').classList.remove('hidden');
    document.getElementById('otp-code-input').value = '';
    hideError('otp-step2-error');
}

// Auto-submit saat 4 digit lengkap
document.getElementById('otp-code-input').addEventListener('input', function () {
    this.value = this.value.replace(/[^0-9]/g, '');
    if (this.value.length === 6) verifyOTP();
});

// Enter key di step 1
document.getElementById('otp-phone-input').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') requestOTP();
});
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

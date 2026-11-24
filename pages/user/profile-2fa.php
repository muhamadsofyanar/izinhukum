<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

// 2FA hanya relevan untuk akun admin/staff (gerbang login hanya memeriksa mereka).
if (!in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    redirect('/profile');
}

$userId = (int) $_SESSION['user_id'];
$pdo    = getDB();
$stmt   = $pdo->prepare('SELECT name, email, totp_enabled, totp_backup_codes FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) redirect('/profile');

$enabled     = !empty($user['totp_enabled']);
$setupSecret = (string) ($_SESSION['totp_setup_secret'] ?? '');
$newCodes    = $_SESSION['totp_new_backup_codes'] ?? null;
unset($_SESSION['totp_new_backup_codes']); // hanya tampil sekali

$backupLeft = 0;
if ($enabled) {
    $arr = json_decode((string) ($user['totp_backup_codes'] ?? ''), true);
    $backupLeft = is_array($arr) ? count($arr) : 0;
}

$trustedCount = 0;
if ($enabled) {
    try {
        $t = $pdo->prepare('SELECT COUNT(*) FROM user_trusted_devices WHERE user_id = ? AND expires_at > NOW()');
        $t->execute([$userId]);
        $trustedCount = (int) $t->fetchColumn();
    } catch (Exception $e) {}
}

$appName    = getSetting('app_name', 'STIFIn Mulia');
$otpauthUri = $setupSecret !== '' ? totpOtpauthUri($setupSecret, (string) $user['email'], $appName) : '';
$secretPretty = $setupSecret !== '' ? implode(' ', str_split($setupSecret, 4)) : '';

$csrf        = generateCSRF();
$pageTitle   = 'Verifikasi 2 Langkah (2FA)';
$topbarTitle = 'Verifikasi 2 Langkah';
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="max-w-2xl mx-auto">

        <nav class="flex items-center gap-2 text-sm text-gray-400 dark:text-gray-600 mb-5">
          <a href="<?= e(APP_URL) ?>/profile" class="hover:text-[var(--brand-color)] transition">Profil Saya</a>
          <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
          <span class="text-gray-600 dark:text-gray-400">Verifikasi 2 Langkah</span>
        </nav>

        <div class="mb-5">
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Verifikasi 2 Langkah (2FA)</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Lapisan keamanan ekstra untuk login admin — kode 6 digit dari aplikasi authenticator.</p>
        </div>

        <?php if (is_array($newCodes)): ?>
        <!-- Backup codes — HANYA tampil sekali setelah aktivasi -->
        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/40 rounded-2xl p-5 mb-5">
          <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <div class="flex-1">
              <p class="font-semibold text-amber-800 dark:text-amber-300 text-sm">Simpan backup codes ini SEKARANG</p>
              <p class="text-xs text-amber-700 dark:text-amber-400 mt-1 leading-relaxed">
                Masing-masing hanya bisa dipakai sekali untuk login saat kamu kehilangan akses aplikasi authenticator.
                Halaman ini tidak akan menampilkannya lagi.
              </p>
              <div id="backupCodes" class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-3 font-mono text-sm">
                <?php foreach ($newCodes as $c): ?>
                <span class="px-2 py-1.5 rounded-lg bg-white dark:bg-gray-900 border border-amber-200 dark:border-amber-800/40 text-center text-gray-800 dark:text-gray-200"><?= e($c) ?></span>
                <?php endforeach; ?>
              </div>
              <button type="button" onclick="copyBackupCodes(this)"
                      class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-amber-800 dark:text-amber-300 bg-white dark:bg-gray-900 border border-amber-300 dark:border-amber-700 hover:border-amber-500 transition">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                Salin Semua
              </button>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($enabled): ?>
        <!-- ═══ STATUS: AKTIF ═══ -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-5">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-green-50 dark:bg-green-900/30 flex items-center justify-center shrink-0">
              <svg class="w-5 h-5 text-green-600 dark:text-green-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
            </div>
            <div class="flex-1">
              <p class="font-semibold text-gray-900 dark:text-white text-sm">2FA Aktif</p>
              <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">
                Backup code tersisa: <strong class="text-gray-600 dark:text-gray-300"><?= $backupLeft ?></strong>
                &middot; Perangkat terpercaya: <strong class="text-gray-600 dark:text-gray-300"><?= $trustedCount ?></strong>
              </p>
            </div>
            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold bg-green-50 text-green-600 dark:bg-green-900/30 dark:text-green-400">Aktif</span>
          </div>
        </div>

        <?php if ($trustedCount > 0): ?>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-5">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p class="font-semibold text-gray-900 dark:text-white text-sm">Perangkat Terpercaya</p>
              <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Perangkat yang di-skip dari kode 2FA selama 30 hari.</p>
            </div>
            <form id="forget-devices" method="POST" action="<?= e(APP_URL) ?>/actions/user/two-factor">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="forget_devices">
              <button type="button"
                      onclick="confirmAndSubmit('forget-devices',{title:'Lupakan Semua Perangkat?',message:'Semua perangkat akan diminta kode 2FA lagi saat login berikutnya.',confirmText:'Ya, Lupakan',variant:'warning'})"
                      class="px-3.5 py-2 rounded-lg text-xs font-semibold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition whitespace-nowrap">
                Lupakan Semua
              </button>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-red-100 dark:border-red-900/40 shadow-sm overflow-hidden">
          <div class="px-5 py-3.5 border-b border-red-100 dark:border-red-900/40 bg-red-50/50 dark:bg-red-900/10">
            <h3 class="font-semibold text-red-700 dark:text-red-400 text-sm">Nonaktifkan 2FA</h3>
          </div>
          <form method="POST" action="<?= e(APP_URL) ?>/actions/user/two-factor" class="p-5 space-y-3">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="disable">
            <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
              Login kamu kembali hanya dengan password (kurang aman). Masukkan password akun untuk konfirmasi.
            </p>
            <input type="password" name="password" required placeholder="Password akun"
                   class="w-full px-3 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-red-400 focus:border-transparent outline-none transition">
            <button type="submit" data-loading data-loading-text="Memproses..."
                    class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-red-600 hover:bg-red-700 transition">
              Nonaktifkan 2FA
            </button>
          </form>
        </div>

        <?php elseif ($setupSecret !== ''): ?>
        <!-- ═══ SETUP: scan / masukkan kunci → konfirmasi kode ═══ -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-5">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Langkah 1 — Tambahkan ke Aplikasi Authenticator</h3>
          </div>
          <div class="p-5 space-y-4">
            <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
              Buka <strong>Google Authenticator</strong> (atau Authy / Microsoft Authenticator) &rarr;
              tombol <strong>+</strong> &rarr; pilih <strong>&ldquo;Masukkan kunci penyiapan&rdquo;</strong> (Enter a setup key),
              lalu isi:
            </p>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-800">
              <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div>
                  <p class="text-xs text-gray-400 dark:text-gray-600">Nama akun</p>
                  <p class="text-sm font-medium text-gray-800 dark:text-gray-200"><?= e($appName) ?> (<?= e($user['email']) ?>)</p>
                </div>
              </div>
              <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div class="min-w-0">
                  <p class="text-xs text-gray-400 dark:text-gray-600">Kunci penyiapan (time-based)</p>
                  <p id="setupKey" class="text-sm font-mono font-semibold text-gray-900 dark:text-white tracking-wider break-all" data-key="<?= e($setupSecret) ?>"><?= e($secretPretty) ?></p>
                </div>
                <button type="button" onclick="copySetupKey(this)"
                        class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                  <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                  Salin
                </button>
              </div>
            </div>

            <!-- QR (progressive enhancement — tampil bila pustaka qrcode tersedia) -->
            <div id="qrWrap" class="hidden text-center">
              <p class="text-xs text-gray-400 dark:text-gray-600 mb-2">atau scan QR ini:</p>
              <div id="qrBox" class="inline-block p-3 rounded-xl bg-white border border-gray-200"></div>
            </div>

            <p class="text-xs text-gray-400 dark:text-gray-600 leading-relaxed">
              Membuka halaman ini di HP? <a href="<?= e($otpauthUri) ?>" class="text-[var(--brand-color)] font-medium hover:underline">Ketuk di sini</a>
              untuk menambahkan langsung ke aplikasi authenticator.
            </p>
          </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Langkah 2 — Konfirmasi Kode</h3>
          </div>
          <form method="POST" action="<?= e(APP_URL) ?>/actions/user/two-factor" class="p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="confirm">
            <p class="text-sm text-gray-600 dark:text-gray-300">Masukkan kode 6 digit yang sedang tampil di aplikasi:</p>
            <input type="text" name="code" required autocomplete="one-time-code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
                   placeholder="000000"
                   class="w-full max-w-[200px] px-4 py-3 rounded-xl text-center text-lg font-semibold tracking-[0.3em] border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-300 dark:placeholder-gray-600 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
            <div class="flex items-center gap-2">
              <button type="submit" data-loading data-loading-text="Memverifikasi..."
                      class="px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
                Aktifkan 2FA
              </button>
              <a href="<?= e(APP_URL) ?>/profile/2fa" class="px-4 py-2.5 rounded-xl text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Batal</a>
            </div>
          </form>
        </div>

        <?php else: ?>
        <!-- ═══ STATUS: BELUM AKTIF ═══ -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm p-6">
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 rounded-2xl bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)] flex items-center justify-center shrink-0">
              <svg class="w-6 h-6 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>
              </svg>
            </div>
            <div class="flex-1">
              <h3 class="font-semibold text-gray-900 dark:text-white">Amankan login kamu dengan 2FA</h3>
              <p class="text-sm text-gray-500 dark:text-gray-400 mt-1.5 leading-relaxed">
                Setelah aktif, login butuh password <em>plus</em> kode 6 digit dari aplikasi
                authenticator (Google Authenticator, Authy, Microsoft Authenticator, dll).
                Password bocor pun akun tetap aman.
              </p>
              <ul class="text-sm text-gray-500 dark:text-gray-400 mt-3 space-y-1.5">
                <li class="flex items-center gap-2"><svg class="w-4 h-4 text-green-500 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>Bekerja offline — tanpa SMS/WA</li>
                <li class="flex items-center gap-2"><svg class="w-4 h-4 text-green-500 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>8 backup code untuk keadaan darurat</li>
                <li class="flex items-center gap-2"><svg class="w-4 h-4 text-green-500 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>Opsi &ldquo;ingat perangkat 30 hari&rdquo;</li>
              </ul>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/user/two-factor" class="mt-5">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="start">
                <button type="submit" data-loading data-loading-text="Menyiapkan..."
                        class="px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
                  Mulai Aktivasi 2FA
                </button>
              </form>
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </main>
  </div>
</div>

<script>
function copySetupKey(btn) {
  const key = document.getElementById('setupKey')?.dataset.key || '';
  navigator.clipboard.writeText(key).then(() => {
    const old = btn.innerHTML;
    btn.innerHTML = 'Tersalin!';
    setTimeout(() => { btn.innerHTML = old; }, 1500);
  });
}
function copyBackupCodes(btn) {
  const codes = Array.from(document.querySelectorAll('#backupCodes span')).map(s => s.textContent.trim()).join('\n');
  navigator.clipboard.writeText(codes).then(() => {
    const old = btn.innerHTML;
    btn.innerHTML = 'Tersalin!';
    setTimeout(() => { btn.innerHTML = old; }, 1500);
  });
}
// QR opsional: render bila pustaka qrcode-generator tersedia di assets/js.
(function () {
  const wrap = document.getElementById('qrWrap');
  if (!wrap) return;
  const s = document.createElement('script');
  s.src = '<?= e(APP_URL) ?>/assets/js/qrcode-generator.js';
  s.onload = function () {
    if (typeof qrcode !== 'function') return;
    try {
      const qr = qrcode(0, 'M');
      qr.addData(<?= json_encode($otpauthUri) ?>);
      qr.make();
      document.getElementById('qrBox').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0 });
      wrap.classList.remove('hidden');
    } catch (e) { /* biarkan fallback kunci manual */ }
  };
  s.onerror = function () { /* pustaka tidak ada — kunci manual tetap jalan */ };
  document.head.appendChild(s);
})();
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

$pageTitle   = 'Profil Saya';
$topbarTitle = 'Profil';

$userId = (int) $_SESSION['user_id'];
$user   = null;
$memberLevel = null;
$nextLevel   = null;
$totalSpent  = 0.0;

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT id, name, email, phone, avatar, role, google_id, password, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    // Total belanja untuk progress bar
    $spendStmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE user_id = ? AND status = 'paid'");
    $spendStmt->execute([$userId]);
    $totalSpent = (float) $spendStmt->fetchColumn();

    // Level saat ini dan berikutnya
    $memberLevel = getMembershipLevel($userId);
    if ($memberLevel) {
        $nextStmt = $pdo->prepare(
            'SELECT * FROM membership_levels WHERE min_spend > ? ORDER BY min_spend ASC LIMIT 1'
        );
        $nextStmt->execute([(float)$memberLevel['min_spend']]);
        $nextLevel = $nextStmt->fetch() ?: null;
    }
} catch (Exception $e) {
    error_log('Profile load error: ' . $e->getMessage());
}

if (!$user) {
    flash('error', 'Gagal memuat profil.', 'error');
    redirect('/dashboard');
}

// User yang mendaftar via Google dan belum pernah set password lokal.
$isGoogleUser = !empty($user['google_id']) && empty($user['password']);

generateCSRF();
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

        <div class="mb-5">
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Profil Saya</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Kelola informasi akun kamu</p>
        </div>

        <?php if ($isGoogleUser): ?>
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800/40 rounded-xl p-4 mb-5">
          <div class="flex items-center gap-2.5">
            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24">
              <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
              <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
              <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
              <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            <p class="text-sm text-blue-700 dark:text-blue-300">
              Kamu login via Google. Kamu bisa membuat password di bawah agar bisa login juga dengan email.
            </p>
          </div>
        </div>
        <?php endif; ?>

        <!-- Avatar + Info -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-5 flex items-center gap-4">
          <?php if ($user['avatar']): ?>
          <img src="<?= e($user['avatar']) ?>" alt="" class="w-16 h-16 rounded-full object-cover shrink-0">
          <?php else: ?>
          <div class="w-16 h-16 rounded-full bg-[color-mix(in_srgb,var(--brand-color)_15%,transparent)] flex items-center justify-center text-2xl font-bold text-[var(--brand-color)] shrink-0">
            <?= e(strtoupper(substr($user['name'], 0, 1))) ?>
          </div>
          <?php endif; ?>
          <div>
            <p class="font-bold text-gray-900 dark:text-white"><?= e($user['name']) ?></p>
            <p class="text-sm text-gray-500 dark:text-gray-400"><?= e($user['email']) ?></p>
            <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">
              Bergabung <?= e(date('d F Y', strtotime($user['created_at']))) ?>
            </p>
          </div>
        </div>

        <!-- Membership Level Card -->
        <?php if ($memberLevel): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-5">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Membership Level</h3>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold"
                  style="background-color:<?= e($memberLevel['color']) ?>18; color:<?= e($memberLevel['color']) ?>; border:1.5px solid <?= e($memberLevel['color']) ?>40">
              <span class="w-1.5 h-1.5 rounded-full" style="background-color:<?= e($memberLevel['color']) ?>"></span>
              <?= e($memberLevel['name']) ?>
            </span>
          </div>
          <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400 mb-1.5">
            <span>Total Belanja: <strong class="text-gray-900 dark:text-white"><?= e(formatRupiah($totalSpent)) ?></strong></span>
            <?php if ($nextLevel): ?>
            <span>Menuju <?= e($nextLevel['name']) ?>: <?= e(formatRupiah(max(0, (float)$nextLevel['min_spend'] - $totalSpent))) ?> lagi</span>
            <?php else: ?>
            <span class="text-green-600 dark:text-green-400 font-medium">Level Tertinggi!</span>
            <?php endif; ?>
          </div>
          <?php if ($nextLevel): ?>
          <?php
            $levelMin  = (float) $memberLevel['min_spend'];
            $levelMax  = (float) $nextLevel['min_spend'];
            $progress  = $levelMax > $levelMin ? min(100, ($totalSpent - $levelMin) / ($levelMax - $levelMin) * 100) : 100;
          ?>
          <div class="w-full h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500"
                 style="width:<?= round($progress) ?>%; background-color:<?= e($memberLevel['color']) ?>"></div>
          </div>
          <p class="text-xs text-gray-400 dark:text-gray-600 mt-1.5">
            Belanja <?= e(formatRupiah(max(0, (float)$nextLevel['min_spend'] - $totalSpent))) ?> lagi untuk naik ke level
            <strong style="color:<?= e($nextLevel['color']) ?>"><?= e($nextLevel['name']) ?></strong>
          </p>
          <?php else: ?>
          <div class="w-full h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
            <div class="h-full w-full rounded-full" style="background-color:<?= e($memberLevel['color']) ?>"></div>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Form Update Profil -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-5">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Informasi Dasar</h3>
          </div>
          <form method="POST" action="<?= e(APP_URL) ?>/actions/user/update-profile" class="p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update_info">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  Nama Lengkap <span class="text-red-500">*</span>
                </label>
                <input type="text" name="name" required maxlength="100"
                       value="<?= e($user['name']) ?>"
                       class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
              </div>
              <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Email</label>
                <input type="email" value="<?= e($user['email']) ?>" disabled
                       class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-gray-800/60 text-gray-400 dark:text-gray-500 cursor-not-allowed">
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Email tidak dapat diubah.</p>
              </div>
              <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  No. Telepon <span class="text-xs text-gray-400 font-normal">(opsional)</span>
                </label>
                <input type="tel" name="phone" maxlength="20"
                       value="<?= e($user['phone'] ?? '') ?>"
                       placeholder="08xx-xxxx-xxxx"
                       class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
              </div>
            </div>

            <div class="flex justify-end pt-2 border-t border-gray-100 dark:border-gray-800">
              <button type="submit" data-loading data-loading-text="Menyimpan..."
                      class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
                Simpan Perubahan
              </button>
            </div>
          </form>
        </div>

        <!-- Form Ganti Password -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm"><?= $isGoogleUser ? 'Buat Password' : 'Ganti Password' ?></h3>
          </div>
          <form method="POST" action="<?= e(APP_URL) ?>/actions/user/update-profile" class="p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="change_password">

            <?php if (!$isGoogleUser): ?>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Password Saat Ini <span class="text-red-500">*</span>
              </label>
              <div class="relative">
                <input type="password" name="current_password" required id="currentPw"
                       class="w-full px-3.5 py-2.5 pr-10 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                <button type="button" onclick="togglePw('currentPw', this)"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition">
                  <svg class="w-4 h-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                  </svg>
                </button>
              </div>
            </div>
            <?php endif; ?>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Password Baru <span class="text-red-500">*</span>
              </label>
              <div class="relative">
                <input type="password" name="new_password" required minlength="8" id="newPw"
                       class="w-full px-3.5 py-2.5 pr-10 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                       placeholder="Minimal 8 karakter">
                <button type="button" onclick="togglePw('newPw', this)"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition">
                  <svg class="w-4 h-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                  </svg>
                </button>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Konfirmasi Password Baru <span class="text-red-500">*</span>
              </label>
              <input type="password" name="confirm_password" required minlength="8" id="confirmPw"
                     class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                     placeholder="Ulangi password baru">
            </div>

            <div class="flex justify-end pt-2 border-t border-gray-100 dark:border-gray-800">
              <button type="submit" data-loading data-loading-text="Mengubah..."
                      class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
                Ganti Password
              </button>
            </div>
          </form>
        </div>

        <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)):
            $totpOn = false;
            try {
                $tq = $pdo->prepare('SELECT totp_enabled FROM users WHERE id = ? LIMIT 1');
                $tq->execute([(int) $_SESSION['user_id']]);
                $totpOn = (bool) $tq->fetchColumn();
            } catch (Exception $e) {} // kolom belum ada (pra-migrasi) — sembunyikan status
        ?>
        <!-- Verifikasi 2 Langkah (khusus admin/staff) -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mt-5">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Keamanan — Verifikasi 2 Langkah</h3>
          </div>
          <div class="p-5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 min-w-0">
              <div class="w-10 h-10 rounded-xl <?= $totpOn ? 'bg-green-50 dark:bg-green-900/30' : 'bg-gray-100 dark:bg-gray-800' ?> flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 <?= $totpOn ? 'text-green-600 dark:text-green-400' : 'text-gray-400' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>
                </svg>
              </div>
              <div class="min-w-0">
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">2FA (Google Authenticator)</p>
                <p class="text-xs text-gray-400 dark:text-gray-600 truncate"><?= $totpOn ? 'Aktif — login butuh kode 6 digit.' : 'Belum aktif — amankan login admin kamu.' ?></p>
              </div>
            </div>
            <a href="<?= e(APP_URL) ?>/profile/2fa"
               class="shrink-0 px-4 py-2 rounded-lg text-sm font-semibold <?= $totpOn ? 'text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700' : 'text-white bg-brand hover:opacity-90' ?> transition">
              <?= $totpOn ? 'Kelola' : 'Aktifkan' ?>
            </a>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </main>
  </div>
</div>

<script>
function togglePw(id, btn) {
  const input = document.getElementById(id);
  const isText = input.type === 'text';
  input.type = isText ? 'password' : 'text';
  const icon = btn.querySelector('.eye-icon');
  icon.innerHTML = isText
    ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
    : '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
}
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

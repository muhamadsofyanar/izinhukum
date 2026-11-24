<?php
// Admin — Halaman Dukung (Bayar Fleksibel Fase 2). Satu tempat untuk: (1) pengaturan
// halaman /dukung (aktif, slug, bio, social, tampil-nominal); (2) moderasi pesan
// Supporters Wall (sembunyikan/tampilkan). Simpan langsung via setSetting (grup
// 'support'), pola meniru pages/admin/social-proof.php.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pdo = getDB();

// Reserved slug yang tak boleh dipakai halaman dukung (samakan dgn coreRoutes router).
$RESERVED = ['admin', 'dashboard', 'login', 'register', 'logout', 'checkout', 'products',
             'member', 'affiliate', 'api', 'assets', 'actions', 'pages', 'uploads',
             'webhook', 'webhooks', 'cron', 'go', 'r', 'auth', 'transactions', 'profile',
             'login-otp', 'license-expired', 'lp', 'bundles', 'invoice', 'certificate',
             'certificates', 'downloads', 'download', 'bantuan', 'tickets', 'order'];

// ═══ POST HANDLER (PRG) ═════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        flash('sup', 'Token keamanan tidak valid.', 'error');
        redirect('/admin/support-page');
    }
    $action = $_POST['action'] ?? '';

    // ── Moderasi: sembunyikan / tampilkan pesan ─────────────────
    if ($action === 'toggle_message') {
        $oid  = (int) ($_POST['order_id'] ?? 0);
        $hide = ($_POST['hide'] ?? '1') === '1' ? 1 : 0;
        if ($oid > 0) {
            try {
                $pdo->prepare("UPDATE orders SET supporter_message_hidden = ? WHERE id = ?")
                    ->execute([$hide, $oid]);
                flash('sup', $hide ? 'Pesan disembunyikan dari halaman.' : 'Pesan ditampilkan kembali.', 'success');
            } catch (Throwable $e) {
                error_log('support moderate: ' . $e->getMessage());
                flash('sup', 'Gagal memperbarui pesan.', 'error');
            }
        }
        redirect('/admin/support-page');
    }

    // ── Simpan pengaturan ───────────────────────────────────────
    if ($action === 'save_support') {
        // Slug: lowercase [a-z0-9-], fallback 'dukung', tolak reserved.
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string) ($_POST['support_page_slug'] ?? ''))));
        $slug = trim($slug, '-');
        if ($slug === '' || in_array($slug, $RESERVED, true)) {
            if (in_array($slug, $RESERVED, true)) {
                flash('sup', 'Slug "' . e($slug) . '" sudah dipakai sistem. Pilih slug lain.', 'error');
                redirect('/admin/support-page');
            }
            $slug = 'dukung';
        }
        $bio       = mb_substr(trim(strip_tags((string) ($_POST['support_bio'] ?? ''))), 0, 500);
        $badgeText = mb_substr(trim(strip_tags((string) ($_POST['support_badge_text'] ?? ''))), 0, 40);
        $headline  = mb_substr(trim(strip_tags((string) ($_POST['support_headline'] ?? ''))), 0, 120);

        // Social: simpan hanya URL http(s) valid; selain itu kosongkan.
        $sanitizeUrl = function ($v): string {
            $v = trim((string) $v);
            if ($v === '') return '';
            if (!preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
            return filter_var($v, FILTER_VALIDATE_URL) ? mb_substr($v, 0, 255) : '';
        };

        try {
            setSetting('support_page_enabled', isset($_POST['support_page_enabled']) ? '1' : '0', 'support');
            setSetting('support_page_slug', $slug, 'support');
            setSetting('support_badge_text', $badgeText, 'support');
            setSetting('support_headline', $headline, 'support');
            setSetting('support_bio', $bio, 'support');
            setSetting('support_show_amount', isset($_POST['support_show_amount']) ? '1' : '0', 'support');
            setSetting('support_social_instagram', $sanitizeUrl($_POST['support_social_instagram'] ?? ''), 'support');
            setSetting('support_social_youtube', $sanitizeUrl($_POST['support_social_youtube'] ?? ''), 'support');
            setSetting('support_social_tiktok', $sanitizeUrl($_POST['support_social_tiktok'] ?? ''), 'support');
            setSetting('support_social_website', $sanitizeUrl($_POST['support_social_website'] ?? ''), 'support');
            flash('sup', 'Pengaturan Halaman Dukung disimpan.', 'success');
        } catch (Throwable $e) {
            error_log('support save: ' . $e->getMessage());
            flash('sup', 'Gagal menyimpan pengaturan.', 'error');
        }
        redirect('/admin/support-page');
    }

    redirect('/admin/support-page');
}

// ═══ GET DISPLAY ════════════════════════════════════════════════
$pageTitle   = 'Halaman Dukung';
$topbarTitle = 'Halaman Dukung';

$g = fn(string $k, string $d = ''): string => (string) getSetting($k, $d);
$enabled    = $g('support_page_enabled', '0') === '1';
$slug       = $g('support_page_slug', 'dukung') ?: 'dukung';
$showAmount = $g('support_show_amount', '1') === '1';

// Jumlah produk flexible yang ditandai tampil di halaman.
$tipCount = 0;
try {
    $tipCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM products WHERE pricing_type='flexible' AND show_on_support=1 AND status='active'"
    )->fetchColumn();
} catch (Throwable $e) { error_log('support tip count: ' . $e->getMessage()); }

// Daftar moderasi: pesan pendukung (tampil & tersembunyi).
$msgs = [];
try {
    $st = $pdo->query(
        "SELECT o.id, o.total, o.created_at, o.supporter_message, o.supporter_anonymous,
                o.supporter_message_hidden, u.name AS buyer_name
         FROM orders o
         JOIN users u        ON u.id = o.user_id
         JOIN order_items oi ON oi.order_id = o.id AND oi.is_bump = 0
         JOIN products p     ON p.id = oi.product_id
         WHERE o.status = 'paid' AND p.pricing_type = 'flexible' AND p.show_on_support = 1
           AND o.supporter_message IS NOT NULL AND o.supporter_message <> ''
         GROUP BY o.id
         ORDER BY o.created_at DESC
         LIMIT 100"
    );
    $msgs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { error_log('support msgs: ' . $e->getMessage()); }

$publicUrl = rtrim(APP_URL, '/') . '/' . $slug;

generateCSRF();
$inputCls = 'w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition';
$labelCls = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5';
$cardCls  = 'bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm';
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php
      if (($f = getFlash('sup')) !== null) {
          $isErr = ($f['type'] ?? '') === 'error';
          $wrap  = $isErr
              ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300'
              : 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300';
          echo '<div class="mb-4 px-4 py-3 rounded-xl border text-sm font-medium ' . $wrap . '">' . e($f['message']) . '</div>';
      }
      ?>

      <div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
        <div>
          <div class="flex items-center gap-3">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Halaman Dukung</h2>
            <?php if ($enabled): ?>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">AKTIF</span>
            <?php else: ?>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">OFF</span>
            <?php endif; ?>
          </div>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Halaman "bayar seikhlasnya" turnkey dengan Supporters Wall. Kartu tip = produk Bayar Fleksibel yang ditandai tampil di sini.</p>
        </div>
        <?php if ($enabled): ?>
        <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Lihat halaman
        </a>
        <?php endif; ?>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        <!-- Pengaturan -->
        <form method="POST" action="<?= e(APP_URL) ?>/admin/support-page" class="lg:col-span-2 space-y-5">
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="save_support">

          <div class="<?= $cardCls ?> p-5 space-y-4">
            <label class="flex items-start gap-3 cursor-pointer">
              <div class="relative mt-0.5 shrink-0">
                <input type="checkbox" name="support_page_enabled" value="1" class="sr-only peer" <?= $enabled ? 'checked' : '' ?>>
                <div class="w-10 h-5 bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] rounded-full transition-colors"></div>
                <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
              </div>
              <div>
                <span class="text-sm text-gray-700 dark:text-gray-300 font-medium">Aktifkan Halaman Dukung</span>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Saat aktif, halaman dapat diakses publik di URL di bawah.</p>
              </div>
            </label>

            <div>
              <label class="<?= $labelCls ?>">Slug halaman</label>
              <div class="flex items-center gap-2">
                <span class="text-sm text-gray-400 dark:text-gray-500 shrink-0"><?= e(rtrim(APP_URL, '/')) ?>/</span>
                <input type="text" name="support_page_slug" value="<?= e($slug) ?>" class="<?= $inputCls ?>" placeholder="dukung">
              </div>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Huruf kecil, angka, tanda hubung. Kosong = "dukung".</p>
            </div>

            <div>
              <label class="<?= $labelCls ?>">Judul halaman</label>
              <input type="text" name="support_headline" value="<?= e($g('support_headline', '')) ?>" maxlength="120" class="<?= $inputCls ?>" placeholder="Dukung karya <?= e(getSetting('app_name', 'STIFIn Mulia')) ?>">
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Judul besar di halaman. Kosong = &ldquo;Dukung karya <?= e(getSetting('app_name', 'STIFIn Mulia')) ?>&rdquo;.</p>
            </div>

            <div>
              <label class="<?= $labelCls ?>">Teks badge</label>
              <input type="text" name="support_badge_text" value="<?= e($g('support_badge_text', 'Halaman Dukungan')) ?>" maxlength="40" class="<?= $inputCls ?>" placeholder="Halaman Dukungan">
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Label kecil berikon di atas judul. Contoh: "Dukung Kreator", "Traktir Saya". Kosong = sembunyikan badge.</p>
            </div>

            <div>
              <label class="<?= $labelCls ?>">Bio / deskripsi singkat</label>
              <textarea name="support_bio" rows="3" maxlength="500" class="<?= $inputCls ?> resize-none" placeholder="Ceritakan singkat tentang karyamu — muncul di bawah judul halaman."><?= e($g('support_bio', '')) ?></textarea>
            </div>

            <label class="flex items-start gap-3 cursor-pointer">
              <div class="relative mt-0.5 shrink-0">
                <input type="checkbox" name="support_show_amount" value="1" class="sr-only peer" <?= $showAmount ? 'checked' : '' ?>>
                <div class="w-10 h-5 bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] rounded-full transition-colors"></div>
                <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
              </div>
              <div>
                <span class="text-sm text-gray-700 dark:text-gray-300 font-medium">Tampilkan nominal di Supporters Wall</span>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Matikan untuk menyembunyikan rupiah tiap pendukung.</p>
              </div>
            </label>
          </div>

          <div class="<?= $cardCls ?> p-5 space-y-4">
            <div class="text-sm font-semibold text-gray-900 dark:text-white">Tautan sosial <span class="text-xs font-normal text-gray-400">(opsional)</span></div>
            <?php
            $socialFields = [
                'support_social_instagram' => ['Instagram', 'https://instagram.com/namamu'],
                'support_social_youtube'   => ['YouTube', 'https://youtube.com/@namamu'],
                'support_social_tiktok'    => ['TikTok', 'https://tiktok.com/@namamu'],
                'support_social_website'   => ['Website', 'https://situsmu.com'],
            ];
            foreach ($socialFields as $key => [$lbl, $ph]): ?>
            <div>
              <label class="<?= $labelCls ?>"><?= e($lbl) ?></label>
              <input type="text" name="<?= e($key) ?>" value="<?= e($g($key, '')) ?>" class="<?= $inputCls ?>" placeholder="<?= e($ph) ?>">
            </div>
            <?php endforeach; ?>
          </div>

          <div>
            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white hover:opacity-95 transition" style="background: var(--brand-color);">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
              Simpan pengaturan
            </button>
          </div>
        </form>

        <!-- Sisi kanan: status + moderasi -->
        <div class="space-y-5">
          <div class="<?= $cardCls ?> p-5">
            <div class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Kartu tip aktif</div>
            <div class="flex items-baseline gap-2">
              <span class="text-3xl font-bold text-gray-900 dark:text-white"><?= (int) $tipCount ?></span>
              <span class="text-sm text-gray-500 dark:text-gray-400">produk fleksibel</span>
            </div>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">Tandai produk sebagai kartu tip lewat <a href="<?= e(APP_URL) ?>/admin/products" class="underline hover:text-[var(--brand-color)]">Produk</a> → pilih tipe harga <span class="font-medium">Bayar Fleksibel</span> → centang "Tampilkan di Halaman Dukung".</p>
          </div>

          <div class="<?= $cardCls ?> p-5">
            <div class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Moderasi pesan</div>
            <?php if (!$msgs): ?>
              <p class="text-xs text-gray-400 dark:text-gray-500">Belum ada pesan pendukung.</p>
            <?php else: ?>
              <div class="space-y-3 max-h-[28rem] overflow-y-auto -mr-2 pr-2">
                <?php foreach ($msgs as $m):
                  $dn     = supporterDisplayName($m['buyer_name'] ?? null, (int) $m['supporter_anonymous'] === 1);
                  $hidden = (int) $m['supporter_message_hidden'] === 1;
                ?>
                <div class="rounded-lg border border-gray-200 dark:border-gray-800 p-3 <?= $hidden ? 'opacity-60' : '' ?>">
                  <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-semibold text-gray-700 dark:text-gray-300 truncate"><?= e($dn) ?></span>
                    <?php if ($hidden): ?><span class="text-[10px] font-semibold text-gray-400 uppercase">Tersembunyi</span><?php endif; ?>
                  </div>
                  <p class="text-xs text-gray-600 dark:text-gray-400 mt-1 leading-relaxed break-words"><?= e((string) $m['supporter_message']) ?></p>
                  <form method="POST" action="<?= e(APP_URL) ?>/admin/support-page" class="mt-2">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="toggle_message">
                    <input type="hidden" name="order_id" value="<?= (int) $m['id'] ?>">
                    <input type="hidden" name="hide" value="<?= $hidden ? '0' : '1' ?>">
                    <button type="submit" class="text-[11px] font-medium <?= $hidden ? 'text-[var(--brand-color)]' : 'text-red-500' ?> hover:underline">
                      <?= $hidden ? 'Tampilkan kembali' : 'Sembunyikan' ?>
                    </button>
                  </form>
                </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

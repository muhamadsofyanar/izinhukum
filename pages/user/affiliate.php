<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

$pageTitle   = 'Program Afiliasi';
$topbarTitle = 'Affiliate';

$userId = (int) $_SESSION['user_id'];

// Admin/staff: selalu boleh melihat & mengambil link afiliasinya sendiri — mereka
// bukan "pembeli", jadi mode "buyers" tak boleh mengunci link milik mereka.
$isAffPrivileged = in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true);

// Pagar: program afiliasi mati → halaman tak dapat diakses (akses langsung /affiliate
// pun ditolak). Data & saldo lama tetap aman di DB; hanya permukaan member yang ditutup.
if (!affiliateProgramEnabled()) {
    flash('info', 'Program afiliasi sedang tidak aktif.');
    redirect('/dashboard');
}

// Mode "buyers": member yang belum pernah punya order paid belum bisa memakai
// program affiliate — tampilkan penjelasan + ajakan beli, bukan link. Admin/staff
// dikecualikan (link afiliasi milik mereka selalu tersedia).
if (!$isAffPrivileged && !affiliateEligibleUser($userId)) {
    include __DIR__ . '/../../partials/head.php';
    ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <div class="max-w-lg mx-auto mt-10">
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm p-8 text-center">
          <div class="w-12 h-12 rounded-2xl bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center mx-auto mb-4">
            <svg class="w-6 h-6 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13.828 10.172a4 4 0 0 0-5.656 0l-4 4a4 4 0 1 0 5.656 5.656l1.102-1.101"/><path d="M10.172 13.828a4 4 0 0 0 5.656 0l4-4a4 4 0 0 0-5.656-5.656l-1.1 1.1"/></svg>
          </div>
          <h2 class="text-lg font-bold text-gray-900 dark:text-white">Program Afiliasi</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Link affiliate tersedia setelah kamu membeli salah satu produk. Setelah pembelian pertamamu lunas, halaman ini otomatis terbuka dan kamu bisa mulai mendapat komisi.</p>
          <a href="<?= e(APP_URL) ?>/member" class="inline-flex items-center gap-2 mt-5 px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Lihat Produk</a>
        </div>
      </div>
    </main>
  </div>
</div>
    <?php
    include __DIR__ . '/../../partials/footer.php';
    exit;
}

$affiliate    = null;
$referrals    = [];
$affMagnets   = [];
$commissions  = [];
$totalEarned  = 0.0;
$totalPending = 0.0;
$refLink      = '';
$partnerCount = 0;   // jumlah produk Partner (komisi afiliat 100%) utk kartu Link Katalog Partner
$lbEnabled     = false;   // leaderboard affiliate (toggle admin)
$leaderboard   = [];      // top 10 bulan ini
$myRank        = null;    // peringkat user ini (null = belum ada komisi bln ini)
$myEarnedMonth = 0.0;     // komisi user ini bulan ini
$lbTotal       = 0;       // total peserta bulan ini

try {
    $pdo  = getDB();

    // Self-heal: pastikan baris affiliates ada (akun admin dari installer / user lama
    // bisa hanya punya users.affiliate_code tanpa baris affiliates → link kosong).
    $affiliate = ensureAffiliateRow($pdo, $userId);

    if ($affiliate) {
        $refLink = rtrim(APP_URL, '/') . '/register?ref=' . $affiliate['code'];

        // Averion Partner: tampilkan kartu Link Katalog Partner bila ada >=1 produk yang
        // memberi KOMISI EFEKTIF 100% ke member ini (resolveCommissionRate — mencakup nilai
        // kolom produk, commission_rule, level, maupun resell). Pakai rate efektif (bukan
        // kolom mentah) agar cocok dgn 100% yg tampil di "Link Affiliasi per Produk".
        try {
            $affUserId = (int) ($affiliate['user_id'] ?? $userId);
            $candPartner = $pdo->query("SELECT id FROM products WHERE status = 'active' AND (affiliate_commission > 0 OR EXISTS (SELECT 1 FROM commission_rules cr WHERE cr.status = 'active' AND cr.product_id = products.id))")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($candPartner as $pidPartner) {
                if (resolveCommissionRate($affUserId, (int) $pidPartner) >= 100) { $partnerCount = 1; break; }
            }
        } catch (Throwable $e) { $partnerCount = 0; }

        $totalEarned  = (float) $affiliate['total_earned'];

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM commissions
             WHERE affiliate_id = ? AND status = 'pending'"
        );
        $stmt->execute([$affiliate['id']]);
        $totalPending = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT r.*, u.name AS referred_name, u.email AS referred_email, u.created_at AS registered_at
             FROM referrals r
             JOIN users u ON r.referred_user_id = u.id
             WHERE r.affiliate_id = ?
             ORDER BY r.created_at DESC
             LIMIT 20"
        );
        $stmt->execute([$affiliate['id']]);
        $referrals = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT c.*, o.order_number, o.total AS order_total
             FROM commissions c
             JOIN orders o ON c.order_id = o.id
             WHERE c.affiliate_id = ?
             ORDER BY c.created_at DESC
             LIMIT 20"
        );
        $stmt->execute([$affiliate['id']]);
        $commissions = $stmt->fetchAll();

        // ── Leads dari link affiliate ini (v1.2.125: leads.affiliate_id). Kontak penuh
        //    ditampilkan — prospek ini hasil kerja affiliate sendiri. ──
        $myLeads = [];
        try {
            $stmt = $pdo->prepare(
                "SELECT l.name, l.whatsapp, l.email, l.status, l.created_at, lm.title AS magnet_title
                 FROM leads l
                 JOIN lead_magnets lm ON lm.id = l.lead_magnet_id
                 WHERE l.affiliate_id = ?
                 ORDER BY l.created_at DESC
                 LIMIT 100"
            );
            $stmt->execute([$affiliate['id']]);
            $myLeads = $stmt->fetchAll();
        } catch (Throwable $e) { /* kolom affiliate_id belum ada (pra-migrasi) → kosong */ }

        // ── Payout / pencairan (dihitung DI ATAS agar saldo bisa ditonjolkan di
        //    header — aksi utama affiliate). Markup form/modal tetap di bawah. ──
        $approvedBalance = 0.0; $pendingPayouts = []; $payoutHistory = [];
        try {
            $b = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE affiliate_id = ? AND status = 'approved'");
            $b->execute([$affiliate['id']]); $approvedBalance = (float) $b->fetchColumn();
            $pp = $pdo->prepare("SELECT * FROM payout_requests WHERE affiliate_id = ? AND status IN ('pending','processing') ORDER BY created_at DESC");
            $pp->execute([$affiliate['id']]); $pendingPayouts = $pp->fetchAll();
            $hh = $pdo->prepare("SELECT * FROM payout_requests WHERE affiliate_id = ? AND status IN ('paid','rejected') ORDER BY created_at DESC LIMIT 10");
            $hh->execute([$affiliate['id']]); $payoutHistory = $hh->fetchAll();
        } catch (Exception $e) { error_log('affiliate payout load: ' . $e->getMessage()); }
        $minPayout = (float) getSetting('min_payout_amount', '50000');
        $hasBank   = trim((string) ($affiliate['bank_name'] ?? '')) !== ''
                   && trim((string) ($affiliate['bank_account_no'] ?? '')) !== '';

        // ── Leaderboard bulan ini (hanya jika diaktifkan admin) ──
        // Ranking komisi yang dibuat bulan berjalan (status != rejected). Privasi:
        // nama peserta lain disamarkan & nominalnya TIDAK ditampilkan; hanya posisi
        // & komisi milik sendiri yang terlihat.
        if (getSetting('affiliate_leaderboard_enabled', '0') === '1') {
            $lbEnabled  = true;
            $monthStart = date('Y-m-01 00:00:00');
            $monthEnd   = date('Y-m-01 00:00:00', strtotime('first day of next month'));
            $lbStmt = $pdo->prepare(
                "SELECT a.id AS affiliate_id, u.name, SUM(c.amount) AS earned
                 FROM commissions c
                 JOIN affiliates a ON a.id = c.affiliate_id AND a.status = 'active'
                 JOIN users u      ON u.id = a.user_id
                 WHERE c.status <> 'rejected' AND c.created_at >= ? AND c.created_at < ?
                 GROUP BY a.id, u.name
                 ORDER BY earned DESC, a.id ASC"
            );
            $lbStmt->execute([$monthStart, $monthEnd]);
            $ranked  = $lbStmt->fetchAll();
            $lbTotal = count($ranked);
            foreach ($ranked as $i => $row) {
                if ((int) $row['affiliate_id'] === (int) $affiliate['id']) {
                    $myRank        = $i + 1;
                    $myEarnedMonth = (float) $row['earned'];
                    break;
                }
            }
            $leaderboard = array_slice($ranked, 0, 10);
        }
    }

    // Produk aktif untuk link affiliasi per produk.
    // HANYA tampilkan produk yang komisinya di-set SPESIFIK per produk:
    //   - products.affiliate_commission > 0, ATAU
    //   - ada commission_rule aktif khusus produk itu (product_id = p.id;
    //     mencakup rule model resell yg pakai required_product_id).
    // Fallback rate global/afiliasi (setting affiliate_commission_rate) sengaja
    // TIDAK dihitung sbg penanda "produk affiliate" di daftar link ini — pemilik
    // memakai kolom komisi per-produk sebagai saklar promosi (produk tanpa komisi
    // di-set = tidak dimunculkan). Pembayaran komisi tetap ikut resolveCommissionRate.
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.slug, p.price, p.thumbnail, p.landing_page_url
         FROM products p
         WHERE p.status = 'active'
           AND (
             p.affiliate_commission > 0
             OR EXISTS (
                  SELECT 1 FROM commission_rules cr
                  WHERE cr.status = 'active' AND cr.product_id = p.id
             )
           )
         ORDER BY p.sort_order ASC, p.created_at ASC"
    );
    $stmt->execute();
    $products = $stmt->fetchAll();

    // Komisi per produk untuk affiliate INI — role-aware. resolveCommissionRate()
    // sudah mempertimbangkan commission_rules per membership-level (role) affiliate,
    // lalu fallback ke kolom produk. Precompute di sini agar view tetap rapi.
    $affLevelName = null;
    try {
        $lv = $pdo->prepare(
            "SELECT ml.name FROM users u
             LEFT JOIN membership_levels ml ON ml.id = u.membership_level_id
             WHERE u.id = ? LIMIT 1"
        );
        $lv->execute([$userId]);
        $affLevelName = $lv->fetchColumn() ?: null;
    } catch (Throwable $e) { $affLevelName = null; }

    // Hitung komisi per produk untuk affiliate INI, lalu SARING: produk yang rate-nya
    // 0 untuk affiliate ini tidak ditampilkan (hindari kartu tanpa badge yang
    // membingungkan). Kasus rate 0: produk tanpa komisi di-set saat opsi "produk tanpa
    // komisi = tanpa komisi" nyala, ATAU rule khusus produk BERSYARAT kepemilikan
    // (resell) yang tak dipenuhi affiliate ini. Daftar = produk yang benar-benar
    // memberi komisi untuknya.
    $__visibleProducts = [];
    foreach ($products as $__p) {
        $rate = function_exists('resolveCommissionRate')
            ? (float) resolveCommissionRate($userId, (int) $__p['id']) : 0.0;
        if ($rate <= 0) continue;
        $__p['commission_rate']   = $rate;
        $__p['commission_amount'] = ((float) $__p['price'] > 0)
            ? round((float) $__p['price'] * $rate / 100, 2) : 0.0;
        $__visibleProducts[] = $__p;
    }
    $products = $__visibleProducts;
    unset($__visibleProducts);

    // Lead magnet yang boleh diafiliasikan (opt-in per item + sudah published).
    // Fail-safe: kolom affiliate_enabled mungkin belum ada (pra-migrasi) → lewati.
    try {
        $lmStmt = $pdo->query(
            "SELECT id, title, slug, thumbnail
             FROM lead_magnets
             WHERE status = 'published' AND affiliate_enabled = 1
             ORDER BY created_at DESC"
        );
        $affMagnets = $lmStmt->fetchAll();
    } catch (Throwable $e) {
        $affMagnets = [];
    }

    // Canvas LP tertaut per lead magnet (opsional) — link affiliate yang lebih kaya.
    // Query TERPISAH & fail-soft: kolom linked_lead_magnet_id mungkin belum ada
    // (pra-migrasi 1.2.114) → lead magnet tetap tampil, hanya tanpa link Canvas.
    if ($affMagnets) {
        try {
            $ids    = array_map(static fn($m) => (int) $m['id'], $affMagnets);
            $ph     = implode(',', array_fill(0, count($ids), '?'));
            $cvStmt = $pdo->prepare(
                "SELECT linked_lead_magnet_id, slug FROM canvas_pages
                 WHERE linked_lead_magnet_id IN ($ph) AND is_active = 1
                 ORDER BY id ASC"
            );
            $cvStmt->execute($ids);
            $cvMap = [];
            foreach ($cvStmt->fetchAll() as $cv) {
                $lmId = (int) $cv['linked_lead_magnet_id'];
                if (!isset($cvMap[$lmId])) $cvMap[$lmId] = $cv['slug']; // Canvas pertama (id terkecil)
            }
            foreach ($affMagnets as &$m) { $m['canvas_slug'] = $cvMap[(int) $m['id']] ?? null; }
            unset($m);
        } catch (Throwable $e) { /* pra-migrasi → tanpa link Canvas */ }
    }

} catch (Exception $e) {
    error_log('Affiliate page error: ' . $e->getMessage());
}

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

      <div class="mb-5">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Program Afiliasi</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Bagikan link referral dan dapatkan komisi</p>
      </div>

      <?php if (!$affiliate): ?>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm py-14 text-center">
        <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        <p class="text-sm text-gray-400 dark:text-gray-600">Program afiliasi belum tersedia untuk akun ini.</p>
      </div>
      <?php else: ?>

      <!-- Stat Cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
        <div class="bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-200 dark:border-gray-800 shadow-sm">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Komisi</p>
          <p class="text-xl font-bold text-gray-900 dark:text-white mt-2 truncate"><?= e(formatRupiah($totalEarned)) ?></p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-200 dark:border-gray-800 shadow-sm">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Pending</p>
          <p class="text-xl font-bold text-yellow-600 dark:text-yellow-400 mt-2 truncate"><?= e(formatRupiah($totalPending)) ?></p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-200 dark:border-gray-800 shadow-sm">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Referral</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white mt-2"><?= count($referrals) ?></p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-200 dark:border-gray-800 shadow-sm">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Rate Default</p>
          <?php
            // Rate default EFEKTIF (global live) — bukan snapshot basi affiliates.commission_rate.
            // Komisi per produk bisa berbeda (lihat daftar "Link Affiliasi per Produk").
            $defaultRate = function_exists('affiliateDefaultCommissionRate')
                ? affiliateDefaultCommissionRate()
                : (float) getSetting('affiliate_commission_rate', '10');
          ?>
          <p class="text-2xl font-bold text-[var(--brand-color)] mt-2"><?= number_format($defaultRate, 1) ?>%</p>
        </div>
      </div>

      <!-- Hero: saldo siap dicairkan + aksi utama (ditonjolkan di atas) -->
      <div class="mb-5 p-5 rounded-xl border border-[var(--brand-color)]/25 bg-[color-mix(in_srgb,var(--brand-color)_7%,transparent)] flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
          <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Saldo Siap Dicairkan</p>
          <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= e(formatRupiah($approvedBalance)) ?></p>
          <p class="text-xs text-gray-400 mt-1">Komisi yang sudah disetujui admin &middot; Min. <?= e(formatRupiah($minPayout)) ?></p>
        </div>
        <?php if ($approvedBalance >= $minPayout && empty($pendingPayouts)): ?>
        <button type="button" onclick="document.getElementById('modal-payout').classList.remove('hidden')" class="shrink-0 whitespace-nowrap px-5 py-2.5 text-white text-sm font-semibold rounded-xl hover:opacity-90 transition" style="background-color:var(--brand-color)">Ajukan Pencairan &rarr;</button>
        <?php elseif (!empty($pendingPayouts)): ?>
        <span class="shrink-0 text-xs px-3 py-2 bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 rounded-xl font-medium">Ada request pending</span>
        <?php elseif (!$hasBank): ?>
        <a href="#rekening-pencairan" class="shrink-0 text-xs px-3 py-2 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 rounded-xl font-medium hover:opacity-90 transition">Lengkapi rekening dulu &darr;</a>
        <?php else: ?>
        <span class="shrink-0 text-xs text-gray-400">Kumpulkan komisi hingga min. <?= e(formatRupiah($minPayout)) ?></span>
        <?php endif; ?>
      </div>

      <?php if (empty($referrals) && empty($myLeads) && empty($commissions)): ?>
      <!-- Onboarding 3 langkah (affiliate baru — belum ada aktivitas) -->
      <div class="mb-5 p-5 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm">
        <p class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Mulai hasilkan komisi dalam 3 langkah</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <?php
          $steps = [
              ['1', 'Bagikan link afiliasimu', 'Salin link produk atau lead magnet di bawah, sebar ke audiensmu.'],
              ['2', 'Prospek daftar / beli', 'Setiap yang mendaftar atau membeli lewat linkmu tercatat otomatis.'],
              ['3', 'Cairkan komisimu', 'Setelah komisi disetujui admin, ajukan pencairan ke rekeningmu.'],
          ];
          foreach ($steps as $st): ?>
          <div class="flex gap-3">
            <div class="w-7 h-7 shrink-0 rounded-full text-white flex items-center justify-center text-xs font-bold" style="background:var(--brand-color)"><?= $st[0] ?></div>
            <div class="min-w-0">
              <p class="text-sm font-medium text-gray-800 dark:text-gray-200"><?= $st[1] ?></p>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"><?= $st[2] ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($lbEnabled): ?>
      <!-- Leaderboard Affiliate (bulan ini) -->
      <?php
      // Samarkan nama peserta lain: nama depan + inisial nama belakang (Budi S.).
      $maskName = function (string $name): string {
          $name = trim($name);
          if ($name === '') return 'Affiliate';
          $parts = preg_split('/\s+/', $name);
          if (count($parts) > 1) {
              return $parts[0] . ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.';
          }
          return $parts[0];
      };
      $rankCls = function (int $r): string {
          return $r === 1 ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'
               : ($r === 2 ? 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300'
               : ($r === 3 ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400'
               : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'));
      };
      ?>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm mb-5 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-2.5">
          <div class="w-9 h-9 rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center shrink-0">
            <svg class="w-4 h-4 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
          </div>
          <div class="min-w-0">
            <p class="text-sm font-semibold text-gray-900 dark:text-white">Leaderboard Affiliate</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Peringkat komisi bulan ini &middot; <?= e(date('F Y')) ?></p>
          </div>
        </div>

        <?php if (empty($leaderboard)): ?>
        <div class="py-10 text-center text-sm text-gray-400 dark:text-gray-600">Belum ada komisi bulan ini — jadilah yang pertama!</div>
        <?php else: ?>
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($leaderboard as $i => $row): $rank = $i + 1; $isMe = (int) $row['affiliate_id'] === (int) $affiliate['id']; ?>
          <div class="flex items-center gap-3 px-5 py-3 <?= $isMe ? 'bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)]' : '' ?>">
            <span class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold shrink-0 <?= $rankCls($rank) ?>"><?= $rank ?></span>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">
                <?= $isMe ? 'Kamu' : e($maskName((string) $row['name'])) ?>
                <?php if ($isMe): ?><span class="text-xs text-[var(--brand-color)] font-semibold">(kamu)</span><?php endif; ?>
              </p>
            </div>
            <?php if ($isMe): ?>
            <span class="text-sm font-bold text-[var(--brand-color)] shrink-0"><?= e(formatRupiah((float) $row['earned'])) ?></span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if ($myRank !== null && $myRank > 10): ?>
        <div class="flex items-center gap-3 px-5 py-3 border-t border-gray-100 dark:border-gray-800 bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)]">
          <span class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold shrink-0 bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400"><?= (int) $myRank ?></span>
          <div class="flex-1 min-w-0"><p class="text-sm font-medium text-gray-800 dark:text-gray-200">Kamu <span class="text-xs text-[var(--brand-color)] font-semibold">(kamu)</span></p></div>
          <span class="text-sm font-bold text-[var(--brand-color)] shrink-0"><?= e(formatRupiah($myEarnedMonth)) ?></span>
        </div>
        <?php elseif ($myRank === null): ?>
        <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-800 text-xs text-gray-400 dark:text-gray-600 text-center">Kamu belum ada komisi bulan ini — mulai bagikan link referralmu!</div>
        <?php endif; ?>

        <div class="px-5 py-2.5 border-t border-gray-100 dark:border-gray-800 text-[11px] text-gray-400 dark:text-gray-600 text-center"><?= (int) $lbTotal ?> affiliate berkomisi bulan ini &middot; nominal peserta lain disembunyikan demi privasi</div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Link Referral & Custom Link — satu card accordion (default tertutup) -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm mb-5 overflow-hidden">
        <button type="button" onclick="toggleAffProd(this)"
                class="w-full flex items-center gap-3 px-5 py-4 text-left hover:bg-gray-50 dark:hover:bg-gray-800/40 transition focus:outline-none">
          <div class="w-9 h-9 rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center shrink-0">
            <svg class="w-4 h-4 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
          </div>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-gray-900 dark:text-white">Link Referral &amp; Custom Link</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Link referral, kode afiliasi, dan custom link pendek.</p>
          </div>
          <?php if (!empty($affiliate['custom_slug'])): ?>
          <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">
            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Custom aktif
          </span>
          <?php endif; ?>
          <svg class="aff-chevron w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </button>

        <!-- Isi (tertutup default) -->
        <div class="aff-links hidden px-5 pb-5 pt-1 border-t border-gray-100 dark:border-gray-800 space-y-5">

          <!-- Link Referral -->
          <div>
            <h4 class="font-semibold text-gray-900 dark:text-white text-sm mb-1">Link Referral Kamu</h4>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Bagikan link ini ke teman. Kamu dapat komisi setiap mereka bertransaksi.</p>
            <div class="flex items-center gap-2">
              <div class="flex-1 min-w-0 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3.5 py-2.5">
                <p class="text-sm text-gray-700 dark:text-gray-300 font-mono truncate" id="refLinkText"><?= e($refLink) ?></p>
              </div>
              <button onclick="copyToClipboard('<?= e($refLink) ?>', this, 'Tersalin!')"
                      class="shrink-0 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition whitespace-nowrap">
                Salin Link
              </button>
            </div>
            <div class="mt-3 flex items-center gap-3 flex-wrap">
              <span class="text-xs text-gray-500 dark:text-gray-400">Kode afiliasi:</span>
              <span class="font-mono text-sm font-bold text-[var(--brand-color)] bg-[color-mix(in_srgb,var(--brand-color)_8%,transparent)] px-2.5 py-1 rounded-lg"><?= e($affiliate['code']) ?></span>
              <button onclick="copyToClipboard('<?= e($affiliate['code']) ?>', this, 'Disalin!')"
                      class="text-xs text-gray-400 hover:text-[var(--brand-color)] transition font-medium">Salin kode</button>
            </div>
          </div>

          <!-- Custom Link -->
          <div class="pt-5 border-t border-gray-100 dark:border-gray-800">
            <h4 class="font-semibold text-gray-900 dark:text-white text-sm mb-1">Custom Link</h4>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Buat link pendek yang mudah diingat.</p>
            <?php if (!empty($affiliate['custom_slug'])): ?>
            <?php $customLink = rtrim(APP_URL, '/') . '/r/' . $affiliate['custom_slug']; ?>
            <div class="flex items-center gap-2 mb-3">
              <div class="flex-1 min-w-0 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3.5 py-2.5">
                <p class="text-sm text-gray-700 dark:text-gray-300 font-mono truncate"><?= e($customLink) ?></p>
              </div>
              <button onclick="copyToClipboard('<?= e($customLink) ?>', this, 'Tersalin!')"
                      class="shrink-0 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition whitespace-nowrap">Salin</button>
            </div>
            <?php endif; ?>
            <form method="POST" action="<?= e(APP_URL) ?>/actions/user/save-affiliate-slug">
              <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
              <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5"><?= empty($affiliate['custom_slug']) ? 'Buat custom slug' : 'Ubah slug' ?></label>
              <div class="flex items-center gap-2">
                <div class="flex-1 flex items-center bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden focus-within:ring-2 focus-within:ring-[var(--brand-color)] transition">
                  <!-- Mobile: prefix URL panjang memakan lebar input → tampilkan '…/r/' saja -->
                  <span class="pl-3.5 pr-1 text-xs text-gray-400 dark:text-gray-500 whitespace-nowrap shrink-0 select-none"><span class="hidden sm:inline"><?= e(rtrim(APP_URL, '/')) ?></span><span class="sm:hidden">…</span>/r/</span>
                  <input type="text" name="custom_slug" id="customSlugInput" value="<?= e($affiliate['custom_slug'] ?? '') ?>"
                         placeholder="nama-kamu" maxlength="50"
                         oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9-]/g,'')"
                         class="flex-1 pr-3 py-2.5 bg-transparent text-sm text-gray-900 dark:text-white focus:outline-none font-mono min-w-0">
                </div>
                <button type="submit" class="shrink-0 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition whitespace-nowrap">Simpan</button>
              </div>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1.5">Hanya huruf kecil, angka, dan dash. Minimal 3 karakter.</p>
            </form>
          </div>

          <?php if ($partnerCount > 0):
            $partnerKey  = !empty($affiliate['custom_slug']) ? $affiliate['custom_slug'] : $affiliate['code'];
            $partnerLink = rtrim(APP_URL, '/') . '/partner/' . $partnerKey;
          ?>
          <!-- Link Katalog Partner (etalase produk komisi 100%) -->
          <div class="pt-5 border-t border-gray-100 dark:border-gray-800">
            <h4 class="font-semibold text-gray-900 dark:text-white text-sm mb-1">Link Katalog Partner</h4>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Satu link berisi etalase produk Partner. Setiap pembelian lewat link ini otomatis jadi komisi kamu.</p>
            <div class="flex items-center gap-2">
              <div class="flex-1 min-w-0 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3.5 py-2.5">
                <p class="text-sm text-gray-700 dark:text-gray-300 font-mono truncate"><?= e($partnerLink) ?></p>
              </div>
              <button onclick="copyToClipboard('<?= e($partnerLink) ?>', this, 'Tersalin!')"
                      class="shrink-0 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition whitespace-nowrap">Salin</button>
            </div>
            <a href="<?= e($partnerLink) ?>" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1 mt-2.5 text-xs font-medium text-[var(--brand-color)] hover:underline">
              Lihat etalase
              <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 3h7v7"/><path d="M10 14 21 3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>
            </a>
          </div>
          <?php endif; ?>

        </div>
      </div>

      <!-- Link Affiliasi per Produk — accordion ringkas + cari + scroll -->
      <?php if (!empty($products)): ?>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-5">
        <div class="flex items-start justify-between gap-3 mb-4 flex-wrap">
          <div>
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Link Affiliasi per Produk</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
              Klik produk untuk membuka link. Tiap produk punya <strong>Landing Page</strong> &amp; <strong>Checkout</strong>.
            </p>
            <?php if (!empty($affLevelName)): ?>
            <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1 inline-flex items-center gap-1">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 6L9 17l-5-5"/></svg>
              Komisi mengikuti level akunmu: <strong><?= e($affLevelName) ?></strong>
            </p>
            <?php endif; ?>
          </div>
          <span class="shrink-0 text-xs font-medium text-gray-400 dark:text-gray-500 bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded-lg"><?= count($products) ?> produk</span>
        </div>

        <?php if (count($products) > 4): ?>
        <div class="relative mb-3">
          <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="search" id="affProdSearch" placeholder="Cari produk…" autocomplete="off"
                 oninput="filterAffProducts(this.value)"
                 class="w-full pl-9 pr-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
        </div>
        <?php endif; ?>

        <div id="affProdList" class="space-y-2 <?= count($products) > 6 ? 'max-h-[26rem] overflow-y-auto pr-1 -mr-1' : '' ?>">
          <?php foreach ($products as $product): ?>
          <?php
            $hasLandingPage = !empty($product['landing_page_url']);
            $landingLink    = rtrim(APP_URL, '/') . '/go/' . $affiliate['code'] . '/' . $product['slug'];
            $checkoutLink   = rtrim(APP_URL, '/') . '/checkout?product_id=' . (int)$product['id'] . '&ref=' . $affiliate['code'];
          ?>
          <div class="aff-prod border border-gray-100 dark:border-gray-800 rounded-lg overflow-hidden" data-name="<?= e(strtolower($product['name'])) ?>">
            <!-- Header (toggle) -->
            <button type="button" onclick="toggleAffProd(this)"
                    class="w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-gray-50 dark:hover:bg-gray-800/40 transition focus:outline-none">
              <?php if (!empty($product['thumbnail'])): ?>
              <img src="<?= e($product['thumbnail']) ?>" alt="" class="w-9 h-9 rounded-lg object-cover shrink-0">
              <?php else: ?>
              <div class="w-9 h-9 rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center shrink-0">
                <svg class="w-4 h-4 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/></svg>
              </div>
              <?php endif; ?>
              <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-gray-900 dark:text-white truncate"><?= e($product['name']) ?></p>
                <div class="flex items-center gap-2 flex-wrap mt-0.5">
                  <span class="text-xs text-gray-400 dark:text-gray-500"><?= (float)$product['price'] > 0 ? e(formatRupiah((float)$product['price'])) : 'Gratis' ?></span>
                  <?php
                    $commRate = (float) ($product['commission_rate'] ?? 0);
                    $commAmt  = (float) ($product['commission_amount'] ?? 0);
                    if ($commAmt > 0 || $commRate > 0):
                  ?>
                  <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] font-semibold text-emerald-700 dark:text-emerald-400" style="background:rgba(16,185,129,.12)" title="Komisi untukmu">
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    <?php if ($commAmt > 0): ?>
                      Komisi <?= e(formatRupiah($commAmt)) ?> · <?= rtrim(rtrim(number_format($commRate, 2, ',', '.'), '0'), ',') ?>%
                    <?php else: ?>
                      Komisi <?= rtrim(rtrim(number_format($commRate, 2, ',', '.'), '0'), ',') ?>%
                    <?php endif; ?>
                  </span>
                  <?php endif; ?>
                </div>
              </div>
              <?php if ($hasLandingPage): ?>
              <span class="shrink-0 inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400" title="Ada landing page">
                <svg class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                LP
              </span>
              <?php endif; ?>
              <svg class="aff-chevron w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </button>

            <!-- Links (collapsed) -->
            <div class="aff-links hidden px-3 pb-3 pt-1 border-t border-gray-100 dark:border-gray-800 space-y-2.5">
              <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5 flex items-center gap-1.5">
                  <svg class="w-3.5 h-3.5 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                  Landing Page <span class="text-gray-400 dark:text-gray-600 font-normal"><?= $hasLandingPage ? '— domain eksternal' : '— fallback halaman produk' ?></span>
                </p>
                <div class="flex items-center gap-2">
                  <input type="text" readonly value="<?= e($landingLink) ?>" onclick="this.select()"
                         class="flex-1 px-3 py-2 text-xs border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 font-mono min-w-0">
                  <button onclick="copyLink(this, '<?= e($landingLink) ?>')" class="shrink-0 px-3 py-2 text-xs font-semibold rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] text-[var(--brand-color)] hover:bg-[color-mix(in_srgb,var(--brand-color)_20%,transparent)] transition whitespace-nowrap">Salin</button>
                </div>
              </div>
              <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5 flex items-center gap-1.5">
                  <svg class="w-3.5 h-3.5 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                  Checkout <span class="text-gray-400 dark:text-gray-600 font-normal">— langsung ke halaman beli</span>
                </p>
                <div class="flex items-center gap-2">
                  <input type="text" readonly value="<?= e($checkoutLink) ?>" onclick="this.select()"
                         class="flex-1 px-3 py-2 text-xs border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 font-mono min-w-0">
                  <button onclick="copyLink(this, '<?= e($checkoutLink) ?>')" class="shrink-0 px-3 py-2 text-xs font-semibold rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] text-[var(--brand-color)] hover:bg-[color-mix(in_srgb,var(--brand-color)_20%,transparent)] transition whitespace-nowrap">Salin</button>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <p id="affNoResult" class="hidden text-center text-xs text-gray-400 dark:text-gray-600 py-4">Tidak ada produk yang cocok.</p>
        </div>
      </div>
      <?php endif; ?>

      <!-- Link Afiliasi Lead Magnet (opt-in per lead magnet) -->
      <?php if (!empty($affMagnets)): ?>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-5">
        <div class="flex items-center gap-2 mb-1">
          <svg class="w-4 h-4 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 11l19-9-9 19-2-8-8-2z"/></svg>
          <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Link Afiliasi Lead Magnet (Gratis)</h3>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Bagikan lead magnet gratis ini. Kamu dapat komisi bila prospek yang kamu bawa <strong>membeli penawaran lanjutan</strong> (dalam masa aktif cookie referral).</p>
        <div class="space-y-2 <?= count($affMagnets) > 6 ? 'max-h-[26rem] overflow-y-auto pr-1 -mr-1' : '' ?>">
          <?php foreach ($affMagnets as $lm): ?>
          <?php $lmLink = rtrim(APP_URL, '/') . '/lp/' . $lm['slug'] . '?ref=' . $affiliate['code']; ?>
          <div class="border border-gray-100 dark:border-gray-800 rounded-lg p-3">
            <div class="flex items-center gap-3 mb-2">
              <?php if (!empty($lm['thumbnail'])): ?>
              <img src="<?= e($lm['thumbnail']) ?>" alt="" class="w-9 h-9 rounded-lg object-cover shrink-0">
              <?php else: ?>
              <div class="w-9 h-9 rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center shrink-0">
                <svg class="w-4 h-4 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 11l19-9-9 19-2-8-8-2z"/></svg>
              </div>
              <?php endif; ?>
              <p class="text-sm font-semibold text-gray-900 dark:text-white truncate min-w-0 flex-1"><?= e($lm['title']) ?></p>
            </div>
            <div class="flex items-center gap-2">
              <input type="text" readonly value="<?= e($lmLink) ?>" onclick="this.select()"
                     class="flex-1 px-3 py-2 text-xs border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 font-mono min-w-0">
              <button onclick="copyLink(this, '<?= e($lmLink) ?>')" class="shrink-0 px-3 py-2 text-xs font-semibold rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] text-[var(--brand-color)] hover:bg-[color-mix(in_srgb,var(--brand-color)_20%,transparent)] transition whitespace-nowrap">Salin</button>
            </div>
            <?php if (!empty($lm['canvas_slug'])): ?>
            <?php $lmCanvasLink = rtrim(APP_URL, '/') . '/' . $lm['canvas_slug'] . '?ref=' . $affiliate['code']; ?>
            <div class="mt-2 pt-2 border-t border-dashed border-gray-100 dark:border-gray-800">
              <p class="text-[11px] font-medium text-[var(--brand-color)] mb-1 flex items-center gap-1">
                <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                Landing page lengkap (Canvas)
              </p>
              <div class="flex items-center gap-2">
                <input type="text" readonly value="<?= e($lmCanvasLink) ?>" onclick="this.select()"
                       class="flex-1 px-3 py-2 text-xs border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 font-mono min-w-0">
                <button onclick="copyLink(this, '<?= e($lmCanvasLink) ?>')" class="shrink-0 px-3 py-2 text-xs font-semibold rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] text-[var(--brand-color)] hover:bg-[color-mix(in_srgb,var(--brand-color)_20%,transparent)] transition whitespace-nowrap">Salin</button>
              </div>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Aktivitas Afiliasi: Komisi / Referral / Leads dalam SATU card ber-tab -->
      <style>
        .aff-tab{border-color:transparent;color:#6b7280}
        .dark .aff-tab{color:#9ca3af}
        .aff-tab:hover{color:#374151}
        .dark .aff-tab:hover{color:#e5e7eb}
        .aff-tab.is-active{border-color:var(--brand-color);color:var(--brand-color)}
      </style>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="flex border-b border-gray-100 dark:border-gray-800 overflow-x-auto">
          <button type="button" data-afftab="komisi" onclick="affTab('komisi')" class="aff-tab is-active px-5 py-3 text-sm font-semibold whitespace-nowrap border-b-2 -mb-px transition-colors">Riwayat Komisi <span class="text-xs font-normal opacity-60">(<?= count($commissions) ?>)</span></button>
          <button type="button" data-afftab="referral" onclick="affTab('referral')" class="aff-tab px-5 py-3 text-sm font-semibold whitespace-nowrap border-b-2 -mb-px transition-colors">Daftar Referral <span class="text-xs font-normal opacity-60">(<?= count($referrals) ?>)</span></button>
          <button type="button" data-afftab="leads" onclick="affTab('leads')" class="aff-tab px-5 py-3 text-sm font-semibold whitespace-nowrap border-b-2 -mb-px transition-colors">Leads dari Kamu <span class="text-xs font-normal opacity-60">(<?= count($myLeads) ?>)</span></button>
        </div>

        <!-- Panel: Riwayat Komisi -->
        <div class="aff-tabpanel" data-afftab-panel="komisi">
          <?php if (empty($commissions)): ?>
          <div class="py-10 text-center">
            <p class="text-xs text-gray-400 dark:text-gray-600">Belum ada komisi</p>
          </div>
          <?php else: ?>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($commissions as $comm): ?>
            <div class="flex items-center gap-3 px-5 py-3">
              <div class="flex-1 min-w-0">
                <p class="text-xs font-mono font-bold text-[var(--brand-color)]"><?= e($comm['order_number']) ?></p>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">
                  <?= e(date('d M Y', strtotime($comm['created_at']))) ?>
                  &middot; <?= number_format((float)$comm['rate'], 1) ?>% dari <?= e(formatRupiah((float)$comm['order_total'])) ?>
                </p>
              </div>
              <div class="text-right shrink-0">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><?= e(formatRupiah((float)$comm['amount'])) ?></p>
                <?= renderStatusBadge($comm['status'], 'commission') ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Panel: Daftar Referral -->
        <div class="aff-tabpanel hidden" data-afftab-panel="referral">
          <?php if (empty($referrals)): ?>
          <div class="py-10 text-center">
            <p class="text-xs text-gray-400 dark:text-gray-600">Belum ada referral</p>
            <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Bagikan link di atas untuk mulai!</p>
          </div>
          <?php else: ?>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($referrals as $ref): ?>
            <div class="flex items-center gap-3 px-5 py-3">
              <div class="w-8 h-8 rounded-full bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center text-xs font-bold text-[var(--brand-color)] shrink-0">
                <?= e(strtoupper(substr($ref['referred_name'], 0, 1))) ?>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate"><?= e($ref['referred_name']) ?></p>
                <p class="text-xs text-gray-400 dark:text-gray-600"><?= e(date('d M Y', strtotime($ref['registered_at']))) ?></p>
              </div>
              <span class="shrink-0 inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= $ref['status'] === 'purchased' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' ?>">
                <?= $ref['status'] === 'purchased' ? 'Bertransaksi' : 'Daftar' ?>
              </span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Panel: Leads dari Kamu -->
        <div class="aff-tabpanel hidden" data-afftab-panel="leads">
          <?php if (empty($myLeads)): ?>
          <div class="py-10 text-center">
            <p class="text-xs text-gray-400 dark:text-gray-600">Belum ada lead dari link kamu</p>
            <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Bagikan link afiliasi lead magnet di atas untuk mulai!</p>
          </div>
          <?php else: ?>
          <div class="divide-y divide-gray-100 dark:divide-gray-800" style="max-height:520px;overflow-y:auto">
            <?php foreach ($myLeads as $ld):
              $lst = $ld['status'] ?? 'new';
              $badge = $lst === 'customer'
                     ? ['Customer', 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400']
                     : ($lst === 'engaged'
                        ? ['Terlibat', 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400']
                        : ['Baru', 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400']);
              $waDigits  = preg_replace('/[^0-9]/', '', (string) $ld['whatsapp']);
              // Pesan followup siap-pakai (bisa diedit affiliate sebelum kirim di WA).
              $leadFirst = trim((string) ($ld['name'] ?? ''));
              $leadFirst = $leadFirst !== '' ? explode(' ', $leadFirst)[0] : '';
              $waMsg     = 'Halo' . ($leadFirst !== '' ? ' ' . $leadFirst : '')
                         . ', saya lihat kamu tertarik dengan ' . (string) ($ld['magnet_title'] ?? 'penawaran kami')
                         . '. Ada yang bisa saya bantu?';
            ?>
            <div class="flex items-start gap-3 px-5 py-3">
              <div class="w-8 h-8 rounded-full bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center text-xs font-bold text-[var(--brand-color)] shrink-0 mt-0.5">
                <?= e(strtoupper(substr(($ld['name'] ?: '?'), 0, 1))) ?>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate"><?= e($ld['name']) ?></p>
                <p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?= e($ld['whatsapp']) ?><?php if (!empty($ld['email'])): ?> &middot; <?= e($ld['email']) ?><?php endif; ?></p>
                <p class="text-[11px] text-gray-400 dark:text-gray-600 truncate"><?= e($ld['magnet_title']) ?> &middot; <?= e(date('d M Y H:i', strtotime($ld['created_at']))) ?></p>
              </div>
              <div class="shrink-0 flex flex-col items-end gap-1.5">
                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= $badge[1] ?>"><?= $badge[0] ?></span>
                <?php if ($waDigits !== ''): ?>
                <a href="https://wa.me/<?= e($waDigits) ?>?text=<?= rawurlencode($waMsg) ?>" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold text-white hover:opacity-90 transition" style="background:#25D366" title="Follow up via WhatsApp">
                  <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.149-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0 0 20.885 3.488"/></svg>
                  Chat WA
                </a>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div><!-- /card aktivitas ber-tab -->

        <!-- Saldo & tombol pencairan sudah ditonjolkan di header atas. -->
        <p class="mt-8 mb-3 text-sm font-semibold text-gray-900 dark:text-white">Pencairan Komisi</p>

        <!-- Rekening Pencairan (SELALU tampil — simpan sekali, dipakai tiap payout) -->
        <div id="rekening-pencairan" class="scroll-mt-6 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <div class="min-w-0">
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Rekening Pencairan</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Simpan rekening tujuan komisi. Otomatis dipakai saat mengajukan pencairan.</p>
            </div>
            <?php if ($hasBank): ?>
            <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">
              <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Tersimpan
            </span>
            <?php endif; ?>
          </div>
          <form method="POST" action="<?= e(APP_URL) ?>/actions/user/save-affiliate-bank" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Nama Bank</label>
              <input type="text" name="bank_name" required maxlength="100" value="<?= e($affiliate['bank_name'] ?? '') ?>"
                     placeholder="BCA / Mandiri / dll"
                     class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Nomor Rekening</label>
              <input type="text" name="bank_account_no" required maxlength="50" value="<?= e($affiliate['bank_account_no'] ?? '') ?>"
                     inputmode="numeric" oninput="this.value=this.value.replace(/[^0-9]/g,'')" placeholder="1234567890"
                     class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Nama Pemilik</label>
              <input type="text" name="bank_account_name" required maxlength="100" value="<?= e($affiliate['bank_account_name'] ?? '') ?>"
                     placeholder="Sesuai buku tabungan"
                     class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
            </div>
            <div class="sm:col-span-3 flex justify-end">
              <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition"><?= $hasBank ? 'Perbarui Rekening' : 'Simpan Rekening' ?></button>
            </div>
          </form>
        </div>

        <?php if (!empty($payoutHistory)): ?>
        <div class="mt-4">
          <p class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Riwayat Pencairan</p>
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 overflow-hidden">
            <div class="overflow-x-auto"><table class="w-full text-sm"><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($payoutHistory as $ph): ?>
              <tr>
                <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white"><?= e(formatRupiah((float)$ph['amount'])) ?></td>
                <td class="px-4 py-2.5 text-xs text-gray-500"><?= e($ph['bank_name']) ?> <span class="font-mono"><?= e($ph['bank_account_no']) ?></span></td>
                <td class="px-4 py-2.5"><span class="text-xs px-2 py-0.5 rounded-full <?= $ph['status']==='paid'?'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400':'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400' ?>" title="<?= e($ph['admin_note'] ?? '') ?>"><?= $ph['status']==='paid'?'Dibayar':'Ditolak' ?></span></td>
                <td class="px-4 py-2.5 text-right"><?php if ($ph['status']==='paid' && !empty($ph['proof_path'])): ?><a href="<?= e(UPLOAD_URL . $ph['proof_path']) ?>" target="_blank" class="text-xs text-[var(--brand-color)] hover:underline">Lihat Bukti</a><?php else: ?><span class="text-xs text-gray-300">—</span><?php endif; ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody></table></div>
          </div>
        </div>
        <?php endif; ?>

        <div id="modal-payout" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
          <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-md p-6">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-5"><h3 class="text-base font-semibold text-gray-900 dark:text-white">Ajukan Pencairan</h3><button onclick="document.getElementById('modal-payout').classList.add('hidden')" class="text-gray-400">✕</button></div>
            <form method="POST" action="<?= e(APP_URL) ?>/actions/user/submit-payout-request" class="space-y-3">
              <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
              <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Jumlah Pencairan</label><input type="text" readonly value="<?= e(formatRupiah($approvedBalance)) ?>" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white font-semibold cursor-not-allowed"><p class="text-xs text-gray-400 mt-1">Seluruh saldo komisi dicairkan sekaligus.</p></div>
              <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Nama Bank</label><input type="text" name="bank_name" required value="<?= e($affiliate['bank_name'] ?? '') ?>" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
              <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Nomor Rekening</label><input type="text" name="bank_account_no" required value="<?= e($affiliate['bank_account_no'] ?? '') ?>" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono"></div>
              <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Nama Pemilik Rekening</label><input type="text" name="bank_account_name" required value="<?= e($affiliate['bank_account_name'] ?? '') ?>" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
              <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Catatan (opsional)</label><textarea name="note" rows="2" class="w-full px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></textarea></div>
              <div class="flex gap-2 pt-2"><button type="button" onclick="document.getElementById('modal-payout').classList.add('hidden')" class="flex-1 py-2.5 border border-gray-200 dark:border-gray-700 text-sm font-medium rounded-xl">Batal</button><button type="submit" class="flex-1 py-2.5 text-white text-sm font-semibold rounded-xl hover:opacity-90" style="background-color:var(--brand-color)">Ajukan</button></div>
            </form>
          </div>
        </div>

      <?php endif; ?>

    </main>
  </div>
</div>
<script>
// Accordion link produk — buka/tutup link per produk (dashboard tak memanjang).
function toggleAffProd(btn) {
  var links = btn.nextElementSibling;
  var chev  = btn.querySelector('.aff-chevron');
  var open  = links.classList.contains('hidden');
  links.classList.toggle('hidden', !open);
  if (chev) chev.classList.toggle('rotate-180', open);
}
// Tab aktivitas afiliasi (Komisi / Referral / Leads) — toggle panel client-side.
function affTab(key) {
  document.querySelectorAll('.aff-tabpanel').forEach(function (p) {
    p.classList.toggle('hidden', p.getAttribute('data-afftab-panel') !== key);
  });
  document.querySelectorAll('.aff-tab').forEach(function (t) {
    t.classList.toggle('is-active', t.getAttribute('data-afftab') === key);
  });
}
// Filter produk berdasarkan nama (real-time).
function filterAffProducts(q) {
  q = (q || '').toLowerCase().trim();
  var any = false;
  document.querySelectorAll('#affProdList .aff-prod').forEach(function (el) {
    var match = !q || (el.dataset.name || '').indexOf(q) !== -1;
    el.classList.toggle('hidden', !match);
    if (match) any = true;
  });
  var nr = document.getElementById('affNoResult');
  if (nr) nr.classList.toggle('hidden', any);
}
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

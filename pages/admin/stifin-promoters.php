<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
require_once __DIR__ . '/../../helpers/stifin-promoters.php';

header('Cache-Control: no-store');
$pageTitle = $topbarTitle = 'Promotor Cabang';
$branchCode = stifinPromoterBranchCode();
$promoters = [];
$directoryError = '';
try {
    $promoters = stifinBranchPromoters($branchCode);
} catch (RuntimeException $error) {
    $directoryError = $error->getMessage();
}
$message = '';
$actionError = '';
if (($_GET['export'] ?? '') === 'csv' && $directoryError === '') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="promotor-cabang.csv"');
    $out = fopen('php://output','w');
    fputcsv($out,['Kode','Nama','Area','Provinsi','Lembaga','Status','Masa aktif']);
    foreach ($promoters as $p) {
        $fields = [$p['code'],$p['name'],$p['area'],$p['province'],$p['institution'],$p['membership'],$p['validity']];
        fputcsv($out,array_map(static fn($v) => preg_match('/^[\s]*[=+\-@]/u',(string)$v) ? "'".$v : $v,$fields));
    }
    fclose($out); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $directoryError === '') {
    try {
        if (!validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Token keamanan tidak valid. Muat ulang halaman.');
        }
        if (isset($_POST['balance'])) {
            $code = (string) $_POST['balance'];
            if (!in_array($code, array_column($promoters, 'code'), true)) {
                throw new RuntimeException('Kode promotor tidak terdaftar di cabang ini.');
            }
            $balance = stifinPromoterBalance($code);
            $message = $code . ' · Voucher ' . $balance['voucher'] . ' · Free ' . ($balance['free'] ?? '—');
        } elseif (isset($_POST['link']) && is_string($_POST['link'])) {
            $linked = stifinLinkExistingPromoter(getDB(), $branchCode, $promoters, $_POST['link']);
            $message = $linked
                ? 'Akun lokal berhasil ditautkan. Saldo STIFIn tersedia di menu Voucher Tes.'
                : 'Akun tidak dapat ditautkan. Periksa apakah email unik, akun anggota aktif, dan kode belum terhubung.';
        } elseif (isset($_POST['import'])) {
            $codes = is_array($_POST['codes'] ?? null) ? $_POST['codes'] : [];
            if (!$codes) {
                throw new RuntimeException('Pilih akun promotor yang akan diimpor.');
            }
            $result = stifinPromoterImport(getDB(), $branchCode, $promoters, $codes);
            $message = $result['created'] . ' akun dibuat, ' . $result['skipped'] . ' dilewati. '
                . 'Promotor dapat membuat password melalui halaman Lupa Password.';
        }
    } catch (PDOException $error) {
        error_log('STIFIn promoter database error: ' . $error->getCode());
        $actionError = 'Impor database terhenti. Periksa migrasi tabel akun promotor, lalu coba lagi.';
    } catch (RuntimeException $error) {
        $actionError = $error->getMessage();
    }
}
$linkedCodes = null;
if ($promoters) {
    try {
        $query = getDB()->prepare("SELECT a.promoter_code FROM stifin_promoter_accounts a
            JOIN users u ON u.id = a.user_id AND u.role = 'user' AND u.status = 'active'
            WHERE a.branch_code = ?");
        $query->execute([$branchCode]);
        $linkedCodes = array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN), true);
    } catch (PDOException $error) {
        error_log('STIFIn promoter account status unavailable');
    }
}
$search = is_string($_GET['q'] ?? null) ? stifinPromoterText($_GET['q'], 120) : '';
$filter = $_GET['status'] ?? '';
if (!in_array($filter, ['aktif', 'perlu-akun'], true) || ($filter === 'perlu-akun' && $linkedCodes === null)) {
    $filter = '';
}
$visiblePromoters = [];
$activeCount = $linkedCount = $needsAccountCount = 0;
foreach ($promoters as $promoter) {
    $active = $promoter['active'] === true;
    $linked = isset($linkedCodes[$promoter['code']]);
    $needsAccount = $linkedCodes !== null && $active && !$linked;
    $activeCount += (int) $active;
    $linkedCount += (int) $linked;
    $needsAccountCount += (int) $needsAccount;
    if (($filter === 'aktif' && !$active) || ($filter === 'perlu-akun' && !$needsAccount)) {
        continue;
    }
    $matches = implode(' ', [$promoter['name'], $promoter['code'], $promoter['area'], $promoter['institution']]);
    if ($search === '' || mb_stripos($matches, $search) !== false) {
        $visiblePromoters[] = $promoter;
    }
}
include __DIR__ . '/../../partials/head.php';
?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <div class="space-y-4 text-gray-900 dark:text-white">
        <h1 class="text-2xl font-bold">Promotor Cabang</h1>
        <?php if ($branchCode !== ''): ?>
          <p>Kode cabang <strong><?= e($branchCode) ?></strong></p>
        <?php endif; ?>
        <?php if ($message !== ''): ?><p role="status" class="rounded-lg border px-4 py-3" style="border-color:var(--brand-color)"><?= e($message) ?></p><?php endif; ?>
        <?php if ($actionError !== ''): ?><p role="alert"><?= e($actionError) ?></p><?php endif; ?>
        <?php if ($directoryError !== ''): ?>
          <p role="alert"><?= e($directoryError) ?></p>
        <?php elseif ($promoters === []): ?>
          <p>API STIFIn belum mengembalikan promotor untuk cabang ini.</p>
        <?php else: ?>
          <p><?= count($promoters) ?> promotor dari STIFIn · <?= $activeCount ?> aktif
            <?php if ($linkedCodes !== null): ?>
              · <?= $linkedCount ?> punya akun Mulia aktif · <?= $needsAccountCount ?> promotor aktif belum punya akses Mulia
            <?php endif; ?>
          </p>
          <?php if ($linkedCodes === null): ?><p>Status akun Mulia sedang tidak tersedia.</p><?php endif; ?>
          <form method="get" class="flex flex-wrap items-end gap-3">
            <label>Cari promotor
              <input class="block rounded border p-2 dark:bg-gray-950" name="q" value="<?= e($search) ?>"
                     placeholder="Nama, kode, area, lembaga" maxlength="120">
            </label>
            <label>Status
              <select class="block rounded border p-2 dark:bg-gray-950" name="status">
                <option value="">Semua</option>
                <option value="aktif" <?= $filter === 'aktif' ? 'selected' : '' ?>>Aktif di STIFIn</option>
                <?php if ($linkedCodes !== null): ?>
                  <option value="perlu-akun" <?= $filter === 'perlu-akun' ? 'selected' : '' ?>>Aktif tanpa akses Mulia</option>
                <?php endif; ?>
              </select>
            </label>
            <button class="rounded border p-2">Tampilkan</button>
            <a class="underline" href="<?= e(APP_URL) ?>/admin/promotor-cabang">Hapus filter</a>
          </form>
          <p><?= count($visiblePromoters) ?> promotor ditampilkan.</p>
          <div class="flex flex-wrap gap-3"><a class="rounded border p-2" href="?export=csv">Unduh direktori CSV</a><button type="button" class="rounded border p-2" onclick="window.print()">Cetak / PDF</button></div>
          <form method="post" class="space-y-3">
          <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm">Pilih promotor aktif yang ingin diberi akun. Email yang sudah terdaftar, ganda, atau tidak valid akan dilewati.</p>
            <button type="submit" name="import" value="1" class="rounded-lg px-4 py-2 font-semibold text-white"
                    style="background-color:var(--brand-color,#123d30)">Impor akun terpilih</button>
          </div>
          <div class="overflow-x-auto rounded-xl border bg-white dark:bg-gray-900">
            <table class="w-full text-left text-sm">
              <thead>
                <tr>
                  <th scope="col" class="p-3">Pilih</th>
                  <th scope="col" class="p-3">Nama</th>
                  <th scope="col" class="p-3">Kode promotor</th>
                  <th scope="col" class="p-3">Area</th>
                  <th scope="col" class="p-3">Provinsi</th>
                  <th scope="col" class="p-3">Status di STIFIn</th>
                  <th scope="col" class="p-3">Email</th>
                  <th scope="col" class="p-3">Lembaga</th>
                  <th scope="col" class="p-3">Masa aktif</th>
                  <th scope="col" class="p-3">Voucher</th>
                  <th scope="col" class="p-3">Akun lokal</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$visiblePromoters): ?>
                  <tr><td colspan="11" class="p-3">Tidak ada promotor yang cocok dengan filter.</td></tr>
                <?php endif; ?>
                <?php foreach ($visiblePromoters as $promoter): $linked = isset($linkedCodes[$promoter['code']]); ?>
                  <tr class="border-t">
                    <td class="p-3">
                      <?php if (!$linked && $promoter['active'] === true && filter_var($promoter['email'], FILTER_VALIDATE_EMAIL)): ?>
                        <input type="checkbox" name="codes[]" value="<?= e($promoter['code']) ?>" aria-label="Pilih <?= e($promoter['name']) ?>">
                      <?php endif; ?>
                    </td>
                    <td class="p-3"><?= e($promoter['name']) ?></td>
                    <td class="p-3"><?= e($promoter['code']) ?></td>
                    <td class="p-3"><?= e($promoter['area'] ?: '—') ?></td>
                    <td class="p-3"><?= e($promoter['province'] ?: '—') ?></td>
                    <td class="p-3"><?= $promoter['active'] === null ? '—' : ($promoter['active'] ? 'Aktif' : 'Nonaktif') ?></td>
                    <td class="p-3"><?= e($promoter['email'] ?: '—') ?></td>
                    <td class="p-3"><?= e($promoter['institution'] ?: '—') ?></td>
                    <td class="p-3"><?= e($promoter['validity'] ?: '—') ?></td>
                    <td class="p-3"><button class="rounded-lg border px-3 py-1.5 font-medium whitespace-nowrap" type="submit" name="balance" value="<?= e($promoter['code']) ?>">Cek saldo</button></td>
                    <td class="p-3">
                      <?php if ($linked): ?>
                        <span>Aktif di Mulia</span>
                      <?php elseif ($promoter['active'] === true && filter_var($promoter['email'], FILTER_VALIDATE_EMAIL)): ?>
                        <button class="rounded-lg border px-3 py-1.5 whitespace-nowrap" type="submit" name="link"
                                value="<?= e($promoter['code']) ?>"
                                onclick="return confirm('Tautkan promotor ini ke akun anggota aktif dengan email yang sama?')">Tautkan akun</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          </form>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>
</body>
</html>

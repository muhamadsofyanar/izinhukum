<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
require_once __DIR__ . '/../../helpers/stifin-promoters.php';

header('Cache-Control: no-store');
$pageTitle = $topbarTitle = 'Kantor Virtual Cabang';
$branch = stifinPromoterBranchCode();
$cards = [
    [
        'Pendaftaran menunggu jadwal', 'Peserta & jadwal', '/admin/peserta-tes',
        'SELECT COUNT(*) FROM stifin_test_registrations WHERE branch_code = ? AND status = ?',
        [$branch, 'registered'],
    ],
    [
        'Tes terjadwal', 'Lihat peserta', '/admin/peserta-tes',
        'SELECT COUNT(*) FROM stifin_test_registrations WHERE branch_code = ? AND status = ?',
        [$branch, 'scheduled'],
    ],
    [
        'Voucher perlu diperiksa', 'Periksa antrean voucher', '/admin/voucher-tes',
        "SELECT COUNT(*) FROM stifin_voucher_queue WHERE transfer_branch = ? AND transfer_status = 'review'",
        [$branch],
    ],
    [
        'Tes selesai di Mulia', 'Lihat laporan cabang', '/admin/laporan-cabang',
        'SELECT COUNT(*) FROM stifin_test_registrations WHERE branch_code = ? AND status = ?',
        [$branch, 'completed'],
    ],
    [
        'Promotor dengan akses aktif', 'Kelola promotor', '/admin/promotor-cabang',
        "SELECT COUNT(*) FROM stifin_promoter_accounts a JOIN users u ON u.id = a.user_id
         WHERE a.branch_code = ? AND u.status = 'active' AND u.role = 'user'",
        [$branch],
    ],
    [
        'Stok voucher lokal', 'Kelola stok', '/admin/voucher-tes',
        'SELECT COUNT(*) FROM stifin_voucher_stock WHERE queue_id IS NULL',
        [],
    ],
];
$modules = [
    ['Hasil & rekap tes', 'Buka hasil resmi yang tersedia di Mulia dan filter menurut bulan.', '/admin/data-resmi-cabang'],
    ['Promotor Cabang', 'Temukan promotor, cek saldo, dan kelola penautan akun.', '/admin/promotor-cabang'],
    ['Peserta & jadwal', 'Daftarkan peserta dan perbarui jadwal serta status tes di Mulia.', '/admin/peserta-tes'],
    ['Voucher & mutasi', 'Lihat mutasi resmi yang sudah masuk ke Mulia.', '/admin/data-resmi-cabang?laporan=voucher-cabang'],
];
$metrics = [];
foreach ($cards as [$label, $action, $url, $sql, $params]) {
    $count = null;
    if (!$params || $branch !== '') {
        try {
            $statement = getDB()->prepare($sql);
            $statement->execute($params);
            $count = (int) $statement->fetchColumn();
        } catch (Throwable $error) {
            error_log('STIFIn office metric unavailable');
        }
    }
    $metrics[] = [$label, $action, $url, $count];
}

include __DIR__ . '/../../partials/head.php';
?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
    <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
        <?php include __DIR__ . '/../../partials/topbar.php'; ?>
        <main class="branch-office flex-1 overflow-y-auto p-4 sm:p-6">
            <div class="mx-auto max-w-6xl space-y-6">
                <header class="rounded-2xl bg-emerald-950 p-5 text-white sm:p-7">
                    <h1 class="text-2xl font-bold sm:text-3xl">Kantor Virtual Cabang</h1>
                    <p class="mt-2 text-emerald-100"><?= e($branch ?: 'Kode cabang belum dikonfigurasi') ?></p>
                </header>
                <section class="space-y-3" aria-labelledby="office-work-heading">
                    <h2 id="office-work-heading" class="text-lg font-bold">Operasional cabang</h2>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <?php foreach ($metrics as [$label, $action, $url, $count]): ?>
                            <a class="block rounded-xl border bg-white p-4 hover:border-emerald-600 focus-visible:outline focus-visible:outline-2 dark:bg-gray-900"
                               href="<?= e(APP_URL . $url) ?>">
                                <h3 class="text-sm font-medium"><?= e($label) ?></h3>
                                <p class="mt-2 text-2xl font-bold"><?= $count === null ? 'Belum tersedia' : number_format($count, 0, ',', '.') ?></p>
                                <p class="mt-2 text-sm underline"><?= e($action) ?></p>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
                <nav aria-labelledby="office-modules-heading" class="space-y-3">
                    <h2 id="office-modules-heading" class="text-lg font-bold">Laporan dan pengelolaan</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <?php foreach ($modules as [$label, $description, $url]): ?>
                            <a class="block rounded-xl border bg-white p-4 hover:border-emerald-600 focus-visible:outline focus-visible:outline-2 dark:bg-gray-900"
                               href="<?= e(APP_URL . $url) ?>">
                                <span class="font-semibold underline"><?= e($label) ?></span>
                                <span class="mt-1 block text-sm"><?= e($description) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="flex flex-wrap gap-4 text-sm underline">
                        <a href="<?= e(APP_URL) ?>/admin/products">Workshop &amp; produk</a>
                        <a href="<?= e(APP_URL) ?>/admin/users">Pengguna</a>
                        <a href="<?= e(APP_URL) ?>/admin/transactions">Keuangan dan transaksi</a>
                        <a href="<?= e(APP_URL) ?>/admin/kajian">Kajian</a>
                    </div>
                </nav>
            </div>
        </main>
    </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

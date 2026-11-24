<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
require_once __DIR__ . '/../../helpers/stifin-promoters.php';
require_once __DIR__ . '/../../helpers/stifin-official-reports.php';

header('Cache-Control: no-store');
$pageTitle = $topbarTitle = 'Data Resmi App Cabang';
$source = 'https://cabang.stifin.or.id';
$reports = [
    'hasil' => ['Hasil tes resmi', '/RepTes', 'hasil'],
    'rekap' => ['Rekap tes resmi', '/RekTes', 'jumlah_tes'],
    'voucher-pusat' => ['Transaksi voucher Pusat', '/TranVoucher', 'jumlah_voucher'],
    'voucher-cabang' => ['Transaksi voucher Cabang', '/TranVoucherCab', 'jumlah_voucher'],
];
$type = $_GET['laporan'] ?? 'hasil';
if (!is_string($type) || !isset($reports[$type])) {
    $type = 'hasil';
}
[$label, $path, $column] = $reports[$type];
$base = APP_URL . '/admin/data-resmi-cabang?laporan=' . $type;
$branch = stifinPromoterBranchCode();
if (!preg_match('/\A[A-Z0-9]{2,12}-CAB-[A-Z0-9]{1,8}\z/', $branch) || $branch === 'XXX-CAB-00') {
    $branch = '';
}
$month = $_GET['bulan'] ?? date('Y-m');
if (!is_string($month) || !preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $month)) {
    $month = date('Y-m');
}
$page = filter_var($_GET['halaman'] ?? 1, FILTER_VALIDATE_INT, ['options' => [
    'min_range' => 1, 'max_range' => 1000000,
]]) ?: 1;
$csrf = generateCSRF();
$allMonths = $type === 'hasil' && ($_GET['semua'] ?? '') === '1';
$search = $type === 'hasil' && is_string($_GET['q'] ?? null) ? stifinPromoterText($_GET['q'], 120) : '';
$listUrl = $base . '&bulan=' . $month . ($allMonths ? '&semua=1' : '') . ($search !== '' ? '&q=' . rawurlencode($search) : '');
$history = null;
if ($type === 'hasil' && $branch !== '') {
    try {
        $jobQuery = getDB()->prepare('SELECT * FROM stifin_official_history WHERE branch_code = ?');
        $jobQuery->execute([$branch]);
        $history = $jobQuery->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $error) {
        error_log('STIFIn history status unavailable');
    }
}

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="template-' . $type . '.csv"');
    echo "referensi,tanggal,kode_promotor,$column\n";
    exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_string($token) || !validateCSRF($token) || $branch === '') {
            throw new InvalidArgumentException('Sesi atau konfigurasi cabang belum siap.');
        }
        $operation = $_POST['operation'] ?? '';
        if ($type === 'hasil' && in_array($operation, ['start-history', 'retry-history'], true)) {
            if ($operation === 'start-history') {
                $from = $_POST['mulai'] ?? null;
                $until = $_POST['sampai'] ?? null;
                foreach ([$from, $until] as $period) {
                    if (!is_string($period) || !preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $period)) {
                        throw new InvalidArgumentException('Pilih bulan awal dan akhir yang valid.');
                    }
                }
                if ($from > $until || $until > date('Y-m')) {
                    throw new InvalidArgumentException('Periksa urutan periode. Bulan akhir tidak boleh di masa depan.');
                }
                $schedule = getDB()->prepare("INSERT INTO stifin_official_history (branch_code, next_month, end_month, try_range)
                    VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE
                    next_month = IF(status = 'pending', next_month, VALUES(next_month)),
                    end_month = IF(status = 'pending', end_month, VALUES(end_month)),
                    try_range = IF(status = 'pending', try_range, 1),
                    attempts = IF(status = 'pending', attempts, 0),
                    months_done = IF(status = 'pending', months_done, 0),
                    results_seen = IF(status = 'pending', results_seen, 0),
                    retry_at = IF(status = 'pending', retry_at, NOW()), status = 'pending'");
                $schedule->execute([$branch, $from . '-01', $until . '-01']);
            } else {
                getDB()->prepare("UPDATE stifin_official_history SET status = 'pending', attempts = 0, retry_at = NOW()
                    WHERE branch_code = ? AND status = 'failed'")->execute([$branch]);
            }
            header('Location: ' . $base . '&bulan=' . $month . '&semua=1', true, 303);
            exit;
        }
        if ($operation === 'sync-result' && $type === 'hasil') {
            $count = stifinSyncOfficialMonth(getDB(), $branch, new DateTimeImmutable($month . '-01'));
            $_SESSION['stifin_result_sync'] = ['month' => $month, 'count' => $count];
            header('Location: ' . $base . '&bulan=' . $month, true, 303);
            exit;
        }
        $file = $_FILES['csv'] ?? [];
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024
            || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('Pilih CSV dengan ukuran maksimal 2 MB.');
        }
        $stream = fopen($file['tmp_name'], 'rb');
        if ($stream === false) {
            throw new RuntimeException('Upload could not be opened.');
        }
        try {
            $rows = stifinOfficialCsvRows($stream, $column);
        } finally {
            fclose($stream);
        }
        stifinSaveOfficialRows(getDB(), $branch, $type, $rows);
        $firstMonth = substr(min(array_column($rows, 1)), 0, 7);
        $lastMonth = substr(max(array_column($rows, 1)), 0, 7);
        if ($firstMonth === $lastMonth) {
            $month = $firstMonth;
        }
        header('Location: ' . $base . '&bulan=' . $month . '&impor=' . count($rows), true, 303);
        exit;
    } catch (InvalidArgumentException $error) {
        http_response_code(422);
        $message = $error->getMessage();
    } catch (Throwable $error) {
        $message = in_array($_POST['operation'] ?? null, ['sync-result', 'start-history', 'retry-history'], true)
            ? 'Sinkronisasi gagal. Periksa koneksi API dan status migrasi.'
            : 'Impor gagal. Periksa konfigurasi database dan status migrasi.';
        error_log('STIFIn official CSV import failed');
    }
}

$syncNotice = null;
if ($type === 'hasil') {
    $lastSync = $_SESSION['stifin_result_sync'] ?? null;
    unset($_SESSION['stifin_result_sync']);
    if (is_array($lastSync) && $lastSync['month'] === $month) {
        $syncNotice = $lastSync['count'];
    }
}
$rows = null;
$derivedRecap = [];
if ($branch !== '') {
    try {
        $start = new DateTimeImmutable($month . '-01');
        $params = [$branch, $type];
        $where = 'branch_code = ? AND report_type = ?';
        if (!$allMonths) {
            $where .= ' AND report_date >= ? AND report_date < ?';
            $params[] = $start->format('Y-m-d');
            $params[] = $start->modify('+1 month')->format('Y-m-d');
        }
        if ($search !== '') {
            $where .= ' AND (client_name LIKE ? OR COALESCE(official_ref, source_ref) LIKE ? OR client_phone LIKE ? OR client_email LIKE ? OR promoter_code LIKE ?)';
            array_push($params, ...array_fill(0, 5, '%' . $search . '%'));
        }
        $offset = ($page - 1) * 100;
        $query = getDB()->prepare('SELECT COALESCE(official_ref, source_ref) AS display_ref, report_date, promoter_code, report_value, imported_at,
            period_only, sent_date, client_name, client_phone, client_email FROM stifin_official_report_rows WHERE ' . $where .
            ' ORDER BY report_date DESC, source_ref LIMIT 101 OFFSET ' . $offset);
        $query->execute($params);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        if ($type === 'rekap' && $page === 1 && !$rows) {
            $summary = getDB()->prepare(
                "SELECT promoter_code, COUNT(*) AS test_count FROM stifin_official_report_rows
                 WHERE branch_code = ? AND report_type = 'hasil' AND report_date >= ? AND report_date < ?
                 GROUP BY promoter_code ORDER BY promoter_code"
            );
            $summary->execute([$branch, $start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d')]);
            $derivedRecap = $summary->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $error) {
        error_log('STIFIn official report query failed');
    }
}
include __DIR__ . '/../../partials/head.php';
?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
    <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
        <?php include __DIR__ . '/../../partials/topbar.php'; ?>
        <main class="flex-1 overflow-y-auto p-5 lg:p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-bold">Data Resmi App Cabang</h1>
                <a class="rounded-lg border px-3 py-2 text-sm" href="<?= e(APP_URL) ?>/admin/kantor-cabang">Kantor Virtual Cabang</a>
            </div>
            <nav class="flex flex-wrap gap-2" aria-label="Jenis laporan">
                <?php foreach ($reports as $key => [$name]): ?>
                    <a class="rounded-lg border px-3 py-2 text-sm font-semibold <?= $key === $type
                           ? 'bg-emerald-900 text-white' : 'bg-white dark:bg-gray-900' ?>" <?= $key === $type ? 'aria-current="page"' : '' ?>
                       href="<?= e(APP_URL . '/admin/data-resmi-cabang?laporan=' . $key . '&bulan=' . $month) ?>"><?= e($name) ?></a>
                <?php endforeach; ?>
            </nav>
            <section class="rounded-xl border p-5 bg-white dark:bg-gray-900 space-y-3">
                <h2 class="font-bold"><?= e($label) ?> · <?= e($branch ?: 'Cabang belum dikonfigurasi') ?></h2>
                <?php if ($type === 'hasil' && $branch !== ''): ?>
                    <form method="post" action="<?= e($base . '&bulan=' . $month) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <button class="rounded-lg bg-emerald-900 px-4 py-2 text-white" name="operation" value="sync-result">Sinkronkan hasil tes <?= e($month) ?></button>
                    </form>
                    <?php if ($syncNotice !== null): ?>
                        <p role="status"><?= (int) $syncNotice ?> hasil diterima dari STIFIn untuk <?= e($month) ?>.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p>Admin dapat memperbarui laporan dengan impor CSV.</p>
                <?php endif; ?>
                <div class="flex flex-wrap gap-4">
                    <a class="underline" href="<?= e($source . $path) ?>" target="_blank" rel="noopener noreferrer">Buka laporan di App Cabang</a>
                    <a class="underline" href="<?= e($base . '&template=1') ?>">Unduh template CSV</a>
                </div>
                <?php if ($branch !== ''): ?>
                    <form method="post" enctype="multipart/form-data" action="<?= e($base . '&bulan=' . $month) ?>" class="flex flex-wrap items-end gap-3">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <label>File CSV<input class="block" type="file" name="csv" accept=".csv,text/csv" required></label>
                        <button class="border rounded p-2">Impor CSV</button>
                    </form>
                    <p class="text-sm">Gunakan referensi resmi yang stabil dan tanggal YYYY-MM-DD. Referensi yang sama memperbarui catatan sebelumnya. Untuk hasil tes, masukkan kode hasil tanpa nama peserta.</p>
                <?php endif; ?>
                <?php if ($message !== ''): ?>
                    <p role="alert"><?= e($message) ?></p>
                <?php elseif (is_string($_GET['impor'] ?? null) && ctype_digit($_GET['impor'])): ?>
                    <p role="status"><?= (int) $_GET['impor'] ?> catatan diproses. Pilih bulan untuk melihat data yang diimpor.</p>
                <?php endif; ?>
            </section>
            <?php if ($type === 'hasil' && $history !== null): ?>
                <section class="rounded-xl border p-5 bg-white dark:bg-gray-900 space-y-3">
                    <h2 class="font-bold">Tarik riwayat hasil tes</h2>
                    <p>Mulia mencoba mengambil satu rentang sekaligus. Jika perlu, proses dilanjutkan per bulan di latar belakang.</p>
                    <?php if ($history): ?>
                        <p role="status">
                            <?= (int) $history['months_done'] ?> bulan selesai · <?= (int) $history['results_seen'] ?> hasil diterima
                            <?php if ($history['status'] === 'pending'): ?> · Berikutnya <?= e(substr($history['next_month'], 0, 7)) ?>
                            <?php elseif ($history['status'] === 'failed'): ?> · Terhenti pada <?= e(substr($history['next_month'], 0, 7)) ?>
                            <?php else: ?> · Selesai<?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <?php if (($history['status'] ?? '') === 'pending'): ?>
                        <a class="underline" href="<?= e($listUrl) ?>">Muat ulang status</a>
                    <?php elseif (($history['status'] ?? '') === 'failed'): ?>
                        <form method="post" action="<?= e($listUrl) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <button class="rounded-lg border px-3 py-2" name="operation" value="retry-history">Lanjutkan dari bulan yang gagal</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= e($listUrl) ?>" class="flex flex-wrap items-end gap-3">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <label>Bulan awal <input class="block rounded border p-2" type="month" name="mulai" min="2000-01" value="2020-10" required></label>
                            <label>Bulan akhir <input class="block rounded border p-2" type="month" name="sampai" max="<?= e(date('Y-m')) ?>" value="<?= e(date('Y-m')) ?>" required></label>
                            <button class="rounded-lg border px-3 py-2" name="operation" value="start-history">Mulai sinkron riwayat</button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <section class="rounded-xl border p-5 bg-white dark:bg-gray-900 overflow-x-auto space-y-3">
                <form method="get" class="flex flex-wrap items-end gap-3">
                    <input type="hidden" name="laporan" value="<?= e($type) ?>">
                    <label>Bulan <input class="border rounded p-2 dark:bg-gray-950" type="month" name="bulan" value="<?= e($month) ?>"></label>
                    <?php if ($type === 'hasil'): ?>
                        <label><input type="checkbox" name="semua" value="1" <?= $allMonths ? 'checked' : '' ?>> Semua periode</label>
                        <label>Cari klien <input class="block rounded border p-2 dark:bg-gray-950" type="search" name="q" value="<?= e($search) ?>" placeholder="Nama, kontak, atau referensi" maxlength="120"></label>
                    <?php endif; ?>
                    <button class="border rounded p-2">Tampilkan</button>
                </form>
                <?php if ($rows === null): ?>
                    <p>Laporan belum tersedia. Periksa konfigurasi cabang dan status migrasi.</p>
                <?php elseif ($derivedRecap): ?>
                    <h2 class="font-bold">Ringkasan dari hasil tes yang diimpor</h2>
                    <p><?= array_sum(array_column($derivedRecap, 'test_count')) ?> tes tercatat pada <?= e($month) ?>. Cakupan mengikuti hasil yang sudah diimpor.</p>
                    <table class="w-full text-left">
                        <thead><tr><th>Promotor</th><th>Jumlah tes</th></tr></thead>
                        <tbody>
                        <?php foreach ($derivedRecap as $summaryRow): ?>
                            <tr class="border-t">
                                <td><?= e($summaryRow['promoter_code'] ?: 'Tidak tercantum') ?></td>
                                <td><?= (int) $summaryRow['test_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php elseif (!$rows): ?>
                    <?php if ($page > 1): ?>
                        <p>Tidak ada catatan pada halaman ini.</p>
                    <?php else: ?>
                        <p>Belum ada catatan yang cocok dengan filter ini.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <table class="w-full text-left">
                        <thead><tr><th>Referensi</th><th>Tanggal / periode</th><th>Promotor</th><?php if ($type === 'hasil'): ?><th>Tanggal kirim</th><th>Klien</th><th>Kontak</th><?php endif; ?><th><?= e(ucfirst(str_replace('_', ' ', $column))) ?></th><th>Diimpor</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($rows, 0, 100) as $row): ?>
                            <tr class="border-t">
                                <td><?= e($row['display_ref']) ?></td>
                                <td><?= e((int) $row['period_only'] ? substr($row['report_date'], 0, 7) : $row['report_date']) ?></td>
                                <td><?= e($row['promoter_code']) ?></td>
                                <?php if ($type === 'hasil'): ?>
                                    <td><?= e($row['sent_date'] ?? '—') ?></td>
                                    <td><?= e($row['client_name'] ?: '—') ?></td>
                                    <td><span class="block"><?= e($row['client_phone'] ?: '—') ?></span>
                                        <?php if ($row['client_email']): ?><a class="underline" href="mailto:<?= e($row['client_email']) ?>"><?= e($row['client_email']) ?></a><?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td><?= e($row['report_value'] === '' ? 'Belum tersedia' : $row['report_value']) ?></td>
                                <td><?= e($row['imported_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php if ($rows !== null && ($page > 1 || count($rows) > 100)): ?>
                    <nav class="flex gap-4" aria-label="Halaman laporan">
                        <?php if ($page > 1): ?>
                            <a class="underline" href="<?= e($listUrl . '&halaman=' . ($page - 1)) ?>">Sebelumnya</a>
                        <?php endif; ?>
                        <span>Halaman <?= $page ?></span>
                        <?php if (count($rows) > 100 && $page < 1000000): ?>
                            <a class="underline" href="<?= e($listUrl . '&halaman=' . ($page + 1)) ?>">Berikutnya</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>
            <section class="rounded-xl border p-5 bg-white dark:bg-gray-900 space-y-2">
                <h2 class="font-bold">Akun resmi</h2>
                <p>Pengaturan akun cabang dan password promotor menggunakan sesi App Cabang.</p>
                <div class="flex flex-wrap gap-4">
                    <a class="underline" href="<?= e($source . '/EditPass') ?>" target="_blank" rel="noopener noreferrer">Ubah password akun Cabang</a>
                    <a class="underline" href="<?= e($source . '/UserPro') ?>" target="_blank" rel="noopener noreferrer">Pengelolaan password promotor</a>
                </div>
            </section>
        </main>
    </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

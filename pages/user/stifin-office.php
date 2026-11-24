<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();
require_once __DIR__ . '/../../helpers/stifin-promoters.php';
header('Cache-Control: no-store');
$branch = stifinPromoterBranchCode();
$code = stifinLinkedPromoterCode(getDB(), (int)$_SESSION['user_id']);
if ($code === null) { redirect('/dashboard'); }
$pageTitle = $topbarTitle = 'STIFIn Saya';
$states = ['registered'=>'Terdaftar','scheduled'=>'Terjadwal','completed'=>'Selesai','cancelled'=>'Dibatalkan'];
$rows = []; $error = '';
$profile = null; $balance = null; $apiError = '';
try {
    foreach (stifinBranchPromoters($branch) as $p) { if ($p['code'] === $code) { $profile = $p; break; } }
    if ($profile === null) { $apiError = 'Profil resmi belum ditemukan dalam direktori cabang.'; }
    elseif (($_GET['saldo'] ?? '') === '1') { $balance = stifinPromoterBalance($code); }
} catch (Throwable $e) { $apiError = 'Informasi dari pusat sementara tidak tersedia.'; }
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf_token'] ?? null) || !validateCSRF($_POST['csrf_token'])) { throw new RuntimeException('Token tidak valid.'); }
        $name = is_string($_POST['participant_name'] ?? null) ? trim($_POST['participant_name']) : '';
        if ($name === '' || mb_strlen($name) > 160) { throw new RuntimeException('Isi nama peserta dengan benar.'); }
        $date = is_string($_POST['scheduled_at'] ?? null) ? $_POST['scheduled_at'] : '';
        $parsed = $date === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$date);
        if ($date !== '' && (!$parsed || $parsed->format('Y-m-d\TH:i') !== $date)) { throw new RuntimeException('Jadwal tidak valid.'); }
        $stmt = getDB()->prepare('INSERT INTO stifin_test_registrations (branch_code,participant_name,promoter_code,scheduled_at,status,created_by) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$branch,$name,$code,$parsed ? $parsed->format('Y-m-d H:i:s') : null,$parsed ? 'scheduled' : 'registered',(int)$_SESSION['user_id']]);
        redirect('/member/stifin-saya');
    }
    $stmt = getDB()->prepare('SELECT participant_name,scheduled_at,status FROM stifin_test_registrations WHERE branch_code=? AND promoter_code=? ORDER BY id DESC LIMIT 200');
    $stmt->execute([$branch,$code]); $rows = $stmt->fetchAll();
} catch (Throwable $e) { $error = 'Catatan tes belum tersedia.'; }
$reportMonth = $_GET['bulan_hasil'] ?? date('Y-m');
if (!is_string($reportMonth) || !preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $reportMonth)) {
    $reportMonth = date('Y-m');
}
$reportRows = null;
if ($profile !== null && $profile['active'] === true) {
    try {
        $monthStart = new DateTimeImmutable($reportMonth . '-01');
        $reportQuery = getDB()->prepare(
            "SELECT COALESCE(official_ref, source_ref) AS display_ref, report_date, report_value, period_only, COUNT(*) OVER () AS total_rows
             FROM stifin_official_report_rows
             WHERE branch_code = ? AND promoter_code = ? AND report_type = 'hasil'
               AND report_date >= ? AND report_date < ?
             ORDER BY report_date DESC, source_ref LIMIT 100"
        );
        $reportQuery->execute([$branch, $code, $monthStart->format('Y-m-d'), $monthStart->modify('+1 month')->format('Y-m-d')]);
        $reportRows = $reportQuery->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $error) {
        error_log('STIFIn promoter results unavailable');
    }
}
include __DIR__ . '/../../partials/head.php';
?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased"><div class="flex h-screen overflow-hidden"><?php include __DIR__ . '/../../partials/sidebar-user.php'; ?><div class="flex-1 flex flex-col overflow-hidden lg:ml-60"><?php include __DIR__ . '/../../partials/topbar.php'; ?><main class="flex-1 overflow-y-auto p-5 lg:p-6 space-y-4">
<section class="rounded-xl p-5 text-white" style="background:#123d30"><h1 class="text-2xl font-bold">STIFIn Saya</h1><p><?= e($code) ?> · <?= e($branch) ?></p></section>
<section class="rounded-xl border bg-white dark:bg-gray-900 p-5 space-y-3"><h2 class="font-bold">Profil Promotor STIFIn Pusat</h2><?php if ($apiError): ?><p role="alert"><?= e($apiError) ?></p><?php endif; ?><?php if ($profile): ?><dl class="grid gap-3 sm:grid-cols-2"><?php foreach (['name'=>'Nama','institution'=>'Lembaga','area'=>'Area','province'=>'Provinsi','membership'=>'Status akun','validity'=>'Masa aktif'] as $key=>$label): ?><div><dt class="text-sm"><?= e($label) ?></dt><dd class="font-semibold"><?= e($profile[$key] ?: '—') ?></dd></div><?php endforeach; ?><div><dt>Keaktifan</dt><dd><?= $profile['active'] === null ? '—' : ($profile['active'] ? 'Aktif' : 'Nonaktif') ?></dd></div></dl><?php endif; ?><?php if ($balance !== null): ?><p>Voucher: <strong><?= (int)$balance['voucher'] ?></strong> · Free: <strong><?= e((string)($balance['free'] ?? '—')) ?></strong></p><?php endif; ?><a class="underline" href="?saldo=1">Perbarui saldo pusat</a></section>
<div class="flex flex-wrap gap-3"><a class="rounded border p-3" href="<?= e(APP_URL) ?>/member/voucher-tes?cek_saldo=1">Saldo &amp; riwayat voucher</a><a class="rounded border p-3" href="<?= e(APP_URL) ?>/transactions">Transaksi saya</a><a class="rounded border p-3" href="<?= e(APP_URL) ?>/kajian">Kajian</a><a class="rounded border p-3" href="<?= e(APP_URL) ?>/katalog">Workshop</a></div>
<form method="post" class="rounded-xl border bg-white dark:bg-gray-900 p-5 space-y-3"><h2 class="font-bold">Daftarkan peserta tes lokal</h2><p>Catatan ini masuk ke dashboard admin cabang. Pastikan peserta menyetujui pencatatan datanya.</p><input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>"><label>Nama peserta<input name="participant_name" maxlength="160" required class="block w-full border rounded p-2 dark:bg-gray-950"></label><label>Jadwal (opsional)<input type="datetime-local" name="scheduled_at" class="block border rounded p-2 dark:bg-gray-950"></label><button class="rounded px-4 py-2 text-white" style="background:#123d30">Daftarkan</button></form>
<h2 class="font-bold">Peserta dan jadwal saya</h2><p>Catatan lokal cabang, bukan hasil tes resmi pusat.</p><?php if ($error): ?><p role="alert"><?= e($error) ?></p><?php else: ?><div class="rounded-xl border overflow-x-auto bg-white dark:bg-gray-900"><table class="w-full text-left text-sm"><thead><tr><th class="p-3">Peserta</th><th>Jadwal</th><th>Status</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr class="border-t"><td class="p-3"><?= e($row['participant_name']) ?></td><td><?= e($row['scheduled_at'] ?? '—') ?></td><td><?= e($states[$row['status']] ?? '—') ?></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="3" class="p-3">Belum ada catatan.</td></tr><?php endif; ?></tbody></table></div><?php endif; ?>
<section class="rounded-xl border bg-white dark:bg-gray-900 p-5 space-y-3">
    <h2 class="font-bold">Hasil &amp; rekap tes saya</h2>
    <?php if ($profile === null || $profile['active'] !== true): ?>
        <p>Akses hasil tersedia setelah status promotor aktif terverifikasi di direktori STIFIn.</p>
    <?php else: ?>
        <form method="get" action="<?= e(APP_URL) ?>/member/stifin-saya" class="flex flex-wrap items-end gap-3">
            <label>Bulan hasil
                <input type="month" name="bulan_hasil" value="<?= e($reportMonth) ?>"
                       class="block rounded border p-2 dark:bg-gray-950">
            </label>
            <button class="rounded border p-2">Tampilkan</button>
        </form>
        <?php if ($reportRows === null): ?>
            <p>Laporan sedang tidak tersedia.</p>
        <?php elseif (!$reportRows): ?>
            <p>Mulia belum memiliki hasil untuk kode promotor Anda pada bulan ini.</p>
        <?php else: $totalResults = (int) $reportRows[0]['total_rows']; ?>
            <p><?= $totalResults ?> hasil tercatat dari laporan resmi yang sudah masuk ke Mulia.</p>
            <?php if ($totalResults > 100): ?><p>Menampilkan 100 hasil terbaru.</p><?php endif; ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead><tr><th>Referensi</th><th>Tanggal / periode</th><th>Hasil</th></tr></thead>
                    <tbody>
                    <?php foreach ($reportRows as $result): ?>
                        <tr class="border-t">
                            <td><?= e($result['display_ref']) ?></td>
                            <td><?= e((int) $result['period_only'] ? substr($result['report_date'], 0, 7) : $result['report_date']) ?></td>
                            <td><?= e($result['report_value']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
</main></div></div><?php include __DIR__ . '/../../partials/footer.php'; ?>

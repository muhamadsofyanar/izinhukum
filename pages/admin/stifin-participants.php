<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
require_once __DIR__ . '/../../helpers/stifin-promoters.php';
header('Cache-Control: no-store');
$pageTitle = $topbarTitle = 'Peserta & Jadwal Tes';
$branch = stifinPromoterBranchCode();
$states = ['registered'=>'Terdaftar','scheduled'=>'Terjadwal','completed'=>'Selesai','cancelled'=>'Dibatalkan'];
$ready = false; $rows = []; $promoters = []; $error = '';
try {
    if ($branch === '') { throw new RuntimeException('Kode cabang belum dikonfigurasi.'); }
    $db = getDB();
    $stmt = $db->prepare('SELECT a.promoter_code FROM stifin_promoter_accounts a JOIN users u ON u.id=a.user_id WHERE a.branch_code=? AND u.status=\'active\' AND u.role=\'user\' ORDER BY a.promoter_code');
    $stmt->execute([$branch]); $promoters = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $db->prepare('SELECT * FROM stifin_test_registrations WHERE branch_code=? ORDER BY id DESC LIMIT 200');
    $stmt->execute([$branch]); $rows = $stmt->fetchAll(); $ready = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCSRF((string)($_POST['csrf_token'] ?? ''))) { throw new RuntimeException('Token keamanan tidak valid.'); }
        $name = trim((string)($_POST['participant_name'] ?? ''));
        $promoter = (string)($_POST['promoter_code'] ?? '');
        $status = (string)($_POST['status'] ?? '');
        $reference = trim((string)($_POST['official_reference'] ?? ''));
        $date = trim((string)($_POST['scheduled_at'] ?? ''));
        $parsed = $date === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $date);
        if ($date !== '' && (!$parsed || $parsed->format('Y-m-d\TH:i') !== $date)) { throw new RuntimeException('Jadwal tidak valid.'); }
        if ($name === '' || mb_strlen($name)>160 || !in_array($promoter,$promoters,true) || !isset($states[$status]) || mb_strlen($reference)>120) { throw new RuntimeException('Periksa nama, promotor tertaut, status dan referensi.'); }
        if ($status === 'scheduled' && !$parsed) { throw new RuntimeException('Isi jadwal tes.'); }
        if ($status === 'completed' && $reference === '') { throw new RuntimeException('Isi referensi resmi untuk mencatat tes selesai.'); }
        $values = [$name,$promoter,$parsed ? $parsed->format('Y-m-d H:i:s') : null,$status,$reference ?: null];
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE stifin_test_registrations SET participant_name=?, promoter_code=?, scheduled_at=?, status=?, official_reference=? WHERE id=? AND branch_code=?');
            $stmt->execute([...$values,$id,$branch]);
        } else {
            $stmt = $db->prepare('INSERT INTO stifin_test_registrations (participant_name,promoter_code,scheduled_at,status,official_reference,branch_code,created_by) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([...$values,$branch,(int)$_SESSION['user_id']]);
        }
        redirect('/admin/peserta-tes');
    }
} catch (PDOException $e) { $error = 'Modul data belum siap. Jalankan migrasi kantor cabang atau hubungi pengurus teknis.'; error_log('STIFIn registration database error: '.$e->getCode()); }
catch (RuntimeException $e) { $error = $e->getMessage(); }
$csrf = generateCSRF();
include __DIR__ . '/../../partials/head.php';
?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased"><div class="flex h-screen overflow-hidden">
<?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
<div class="flex-1 flex flex-col overflow-hidden lg:ml-60"><?php include __DIR__ . '/../../partials/topbar.php'; ?>
<main class="flex-1 overflow-y-auto p-5 lg:p-6 space-y-5">
<h1 class="text-2xl font-bold">Peserta &amp; Jadwal Tes</h1><p>Catatan internal cabang <?= e($branch) ?>. Tidak menerbitkan atau mengubah hasil tes resmi pusat.</p>
<?php if ($error): ?><p role="alert" class="rounded border p-3"><?= e($error) ?></p><?php endif; ?>
<?php if ($ready): ?>
<?php if (!$promoters): ?><p>Tautkan promotor aktif di menu Promotor Cabang sebelum mencatat peserta.</p><?php else: ?>
<?php $edit = null; foreach ($rows as $row) { if ((int)$row['id'] === (int)($_GET['edit'] ?? 0)) { $edit = $row; break; } } ?>
<form method="post" class="grid gap-3 sm:grid-cols-2 rounded-xl border bg-white dark:bg-gray-900 p-5">
<h2 class="font-bold sm:col-span-2"><?= $edit ? 'Ubah catatan' : 'Tambah peserta' ?></h2>
<input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<label>Nama peserta<input required maxlength="160" name="participant_name" class="block w-full border rounded p-2 dark:bg-gray-950" value="<?= e($edit['participant_name'] ?? '') ?>"></label>
<label>Promotor<select required name="promoter_code" class="block w-full border rounded p-2 dark:bg-gray-950"><option value="">Pilih promotor</option><?php foreach ($promoters as $p): ?><option value="<?= e($p) ?>" <?= ($edit['promoter_code'] ?? '') === $p ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?></select></label>
<label>Jadwal<input type="datetime-local" name="scheduled_at" class="block w-full border rounded p-2 dark:bg-gray-950" value="<?= e(isset($edit['scheduled_at']) ? str_replace(' ','T',substr($edit['scheduled_at'],0,16)) : '') ?>"></label>
<label>Status<select name="status" class="block w-full border rounded p-2 dark:bg-gray-950"><?php foreach ($states as $key=>$label): ?><option value="<?= e($key) ?>" <?= ($edit['status'] ?? 'registered') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label>Referensi resmi (jika selesai)<input maxlength="120" name="official_reference" class="block w-full border rounded p-2 dark:bg-gray-950" value="<?= e($edit['official_reference'] ?? '') ?>"></label>
<div class="sm:col-span-2"><button class="rounded px-4 py-2 text-white" style="background:#123d30">Simpan catatan</button> <a href="<?= e(APP_URL) ?>/admin/peserta-tes" class="underline">Batal</a></div></form>
<?php endif; ?>
<div class="overflow-x-auto rounded-xl border bg-white dark:bg-gray-900"><table class="w-full text-left text-sm"><thead><tr><th class="p-3">Peserta</th><th>Promotor</th><th>Jadwal</th><th>Status</th><th>Referensi</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr class="border-t"><td class="p-3"><?= e($row['participant_name']) ?></td><td><?= e($row['promoter_code']) ?></td><td><?= e($row['scheduled_at'] ?? '—') ?></td><td><?= e($states[$row['status']] ?? '—') ?></td><td><?= e($row['official_reference'] ?? '—') ?></td><td><a class="underline" href="<?= e(APP_URL) ?>/admin/peserta-tes?edit=<?= (int)$row['id'] ?>">Ubah</a></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="p-3">Belum ada peserta terdaftar.</td></tr><?php endif; ?>
</tbody></table></div><p class="text-sm">Menampilkan hingga 200 catatan terakhir.</p>
<?php endif; ?></main></div></div><?php include __DIR__ . '/../../partials/footer.php'; ?>

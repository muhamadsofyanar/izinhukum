<?php
/**
 * Hapus massal pengguna (dari halaman /admin/users).
 *
 * Aman by design — memakai ulang kebijakan delete-user.php:
 *   - Punya transaksi berbayar  → TIDAK dihapus, di-`banned` (jaga data keuangan).
 *   - Tanpa transaksi berbayar  → dihapus permanen (freebie / lead-magnet / Rp0).
 * Dilewati (tidak disentuh): akun sendiri & admin/staff (role != user). Affiliate TIDAK
 * dilewati; TAPI affiliate yang PUNYA DATA KOMISI (komisi/pencairan/earned) dilindungi
 * (di-banned, bukan dihapus) demi menjaga catatan keuangan afiliasi.
 *
 * Penghapusan user freebie (TUJUAN: bersihkan pendaftar gratisan):
 *   - Relasi akses (member_access/lead magnet, enrollment, sertifikat, poin, lisensi
 *     via order, dll.) ikut terhapus via FK ON DELETE CASCADE — jadi "akses apa pun
 *     di dalamnya" TIDAK menghalangi penghapusan.
 *   - Freebie lead-magnet biasanya punya order Rp0 'paid' → masuk kategori "punya
 *     order" → secara default DILINDUNGI (banned). Untuk benar-benar menghapusnya,
 *     admin mencentang "Hapus permanen meski punya order Rp0" (force_delete_zero_total);
 *     user dgn transaksi > Rp0 TETAP tak pernah tersentuh.
 *   - Tiap penghapusan diisolasi SAVEPOINT per-user → bila SATU user gagal (anomali
 *     relasi non-cascade), hanya user itu dilewati; dulu per-batch 500 → 1 gagal =
 *     499 ikut ter-rollback diam-diam.
 * Sebelum eksekusi: menulis cadangan CSV daftar terdampak ke storage/deleted-users/.
 *
 * Input POST:
 *   - ids                  : daftar id dipisah koma (mode seleksi manual)
 *   - select_all_matching  : '1' → derive ulang id dari filter (search/role/status/spent/level)
 */
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('users')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/users');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/users');
}

@set_time_limit(0);

$currentId = (int) ($_SESSION['user_id'] ?? 0);
$selectAll = ($_POST['select_all_matching'] ?? '') === '1';
// Override eksplisit (opt-in, default OFF di UI): hapus permanen meski user punya
// order berstatus paid, TAPI HANYA bila total order-nya persis Rp0 (mis. artefak
// migrasi lama / produk gratis). User dengan order bernilai > Rp0 (transaksi asli)
// SELALU tetap dilindungi (banned, bukan dihapus) — flag ini TIDAK PERNAH
// membuka celah untuk itu, terlepas dari nilai flag.
$forceDeleteZeroTotal = ($_POST['force_delete_zero_total'] ?? '') === '1';

try {
    $pdo = getDB();

    // ─── Kumpulkan kandidat id ─────────────────────────────────────
    $ids = [];
    if ($selectAll) {
        // Derive ulang dari filter aktif (logika identik /admin/users).
        $search  = trim($_POST['search'] ?? '');
        $roleF   = $_POST['role']   ?? '';
        $statusF = $_POST['status'] ?? '';
        $spentF  = $_POST['spent']  ?? '';
        $levelF  = (int) ($_POST['level'] ?? 0);
        if (!in_array($roleF,   ['user', 'admin'],              true)) $roleF   = '';
        if (!in_array($statusF, ['active', 'inactive', 'banned'], true)) $statusF = '';
        if (!in_array($spentF,  ['zero', 'noorder'],            true)) $spentF  = '';

        $where = ['1=1'];
        $params = [];
        if ($search !== '') {
            $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($roleF   !== '') { $where[] = 'u.role = ?';   $params[] = $roleF; }
        if ($statusF !== '') { $where[] = 'u.status = ?'; $params[] = $statusF; }
        if ($spentF === 'zero') {
            $where[] = "COALESCE((SELECT SUM(o2.total) FROM orders o2 WHERE o2.user_id = u.id AND o2.status = 'paid'), 0) = 0";
        } elseif ($spentF === 'noorder') {
            $where[] = "NOT EXISTS (SELECT 1 FROM orders o2 WHERE o2.user_id = u.id)";
        }
        if ($levelF > 0) { $where[] = 'u.membership_level_id = ?'; $params[] = $levelF; }
        $sql = 'SELECT u.id FROM users u WHERE ' . implode(' AND ', $where);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $raw = (string) ($_POST['ids'] ?? '');
        foreach (explode(',', $raw) as $v) {
            $v = (int) trim($v);
            if ($v > 0) $ids[] = $v;
        }
    }

    $ids = array_values(array_unique($ids));
    if (!$ids) {
        flash('error', 'Tidak ada pengguna yang dipilih.', 'error');
        redirect('/admin/users');
    }

    $isAdminUser = isAdmin();
    $deleted = 0; $deletedZero = 0; $banned = 0; $skipped = 0; $failed = 0; $licRemoved = 0;
    $snapshot = [];   // baris untuk cadangan CSV

    // ─── Proses per batch (cascade ribuan baris → hindari lock panjang) ─
    foreach (array_chunk($ids, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $info = $pdo->prepare(
            "SELECT u.id, u.name, u.email, COALESCE(u.phone,'') AS phone, u.role, u.status, u.created_at,
                    (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id AND o.status = 'paid') AS paid_cnt,
                    COALESCE((SELECT SUM(o.total) FROM orders o WHERE o.user_id = u.id AND o.status = 'paid'), 0) AS paid_total,
                    EXISTS(SELECT 1 FROM affiliates a WHERE a.user_id = u.id) AS is_aff,
                    EXISTS(SELECT 1 FROM affiliates a2 WHERE a2.user_id = u.id AND (
                        a2.total_earned > 0 OR a2.total_withdrawn > 0
                        OR EXISTS(SELECT 1 FROM commissions c WHERE c.affiliate_id = a2.id)
                        OR EXISTS(SELECT 1 FROM payout_requests pr WHERE pr.affiliate_id = a2.id))) AS aff_earns
             FROM users u WHERE u.id IN ($ph)"
        );
        $info->execute($chunk);
        $rows = $info->fetchAll(PDO::FETCH_ASSOC);

        $pdo->beginTransaction();
        try {
            $delStmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $banStmt = $pdo->prepare("UPDATE users SET status = 'banned' WHERE id = ?");
            $delLic  = $pdo->prepare('DELETE FROM product_licenses WHERE user_id = ?');

            // Hapus satu user dgn isolasi SAVEPOINT. Umumnya product_licenses ikut
            // CASCADE via order_id; pre-delete ini hanya jaring untuk anomali langka
            // (lisensi ber-user_id ini tapi order_id milik order lain → FK NO ACTION).
            // Gagal → rollback user ini saja, batch lain tetap jalan. Return [ok, lic_removed].
            $deleteOne = function (int $uid) use ($pdo, $delLic, $delStmt): array {
                $pdo->exec('SAVEPOINT sp_u');
                try {
                    $delLic->execute([$uid]);
                    $lic = $delLic->rowCount();
                    $delStmt->execute([$uid]);
                    $pdo->exec('RELEASE SAVEPOINT sp_u');
                    return [true, $lic];
                } catch (Throwable $e) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT sp_u');
                    error_log('bulk-delete-users: gagal hapus uid ' . $uid . ': ' . $e->getMessage());
                    return [false, 0];
                }
            };

            foreach ($rows as $u) {
                $uid = (int) $u['id'];

                // Guard: akun sendiri & admin/staff → dilewati. Affiliate TIDAK lagi
                // dilewati otomatis (tiap user baru otomatis punya baris affiliate);
                // yang dilindungi hanya affiliate yang PUNYA DATA KOMISI (cek di bawah).
                if ($uid === $currentId)               { $skipped++; continue; }
                if (($u['role'] ?? 'user') !== 'user') { $skipped++; continue; }

                $hasPaid   = (int) $u['paid_cnt'] > 0;
                $paidTotal = (float) $u['paid_total'];
                $affEarns  = (int) ($u['aff_earns'] ?? 0) === 1;

                if ($hasPaid && $forceDeleteZeroTotal && $paidTotal <= 0.0) {
                    // Override eksplisit: PUNYA order paid, tapi totalnya persis Rp0
                    // (mis. migrasi lama/produk gratis) — admin sudah centang opsi ini.
                    [$okD, $lic] = $deleteOne($uid);
                    if ($okD) { $deleted++; $deletedZero++; $licRemoved += $lic;
                        $aksi = 'dihapus (force, order Rp0)' . ($lic ? " +$lic lisensi" : ''); }
                    else { $failed++; $aksi = 'GAGAL hapus (relasi terkait)'; }
                } elseif ($hasPaid) {
                    // Order bernilai > Rp0 (atau flag tidak dicentang) → SELALU dilindungi.
                    if ($u['status'] !== 'banned') { $banStmt->execute([$uid]); }
                    $banned++;
                    $aksi = 'banned (punya transaksi)';
                } elseif ($affEarns) {
                    // Affiliate yang sudah PUNYA DATA KOMISI (komisi/pencairan/earned) →
                    // JANGAN hapus; tandai banned demi menjaga catatan keuangan afiliasi.
                    if ($u['status'] !== 'banned') { $banStmt->execute([$uid]); }
                    $banned++;
                    $aksi = 'banned (affiliate berkomisi)';
                } else {
                    // Freebie / Rp0 / tak pernah order → hapus (akses ikut CASCADE).
                    [$okD, $lic] = $deleteOne($uid);
                    if ($okD) { $deleted++; $licRemoved += $lic;
                        $aksi = 'dihapus' . ($lic ? " +$lic lisensi" : ''); }
                    else { $failed++; $aksi = 'GAGAL hapus (relasi terkait)'; }
                }
                $snapshot[] = [$uid, $u['name'], $u['email'], $u['phone'], $u['role'], $u['created_at'], $aksi];
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('bulk-delete-users chunk error: ' . $e->getMessage());
            // lanjut batch berikutnya; batch ini tidak terhitung.
        }
    }

    // ─── Cadangan CSV daftar terdampak ─────────────────────────────
    if ($snapshot) {
        $dir = __DIR__ . '/../../storage/deleted-users';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $fname = 'deleted-users-' . date('Ymd-His') . '.csv';
        $fp = @fopen($dir . '/' . $fname, 'w');
        if ($fp) {
            fputcsv($fp, ['ID', 'Nama', 'Email', 'Telepon', 'Role', 'Terdaftar', 'Aksi']);
            foreach ($snapshot as $row) { fputcsv($fp, $row); }
            fclose($fp);
            // Tautan unduh ditampilkan sebagai banner di /admin/users (flash di-escape, tak bisa berisi link).
            $_SESSION['bulk_delete_backup'] = $fname;
        }
    }

    logActivity('admin', 'user.bulk_delete', 'user', 0,
        "Hapus massal — dihapus: $deleted (force order-Rp0: $deletedZero), banned: $banned, "
        . "dilewati: $skipped, gagal: $failed, lisensi dibersihkan: $licRemoved"
        . ($forceDeleteZeroTotal ? ' [force_delete_zero_total AKTIF]' : ''));

    $parts = [];
    if ($deleted - $deletedZero > 0) $parts[] = ($deleted - $deletedZero) . ' dihapus';
    if ($deletedZero) $parts[] = "$deletedZero dihapus (force, order Rp0)";
    if ($banned)  $parts[] = "$banned di-banned (punya transaksi)";
    if ($skipped) $parts[] = "$skipped dilewati (admin/akun sendiri)";
    if ($failed)  $parts[] = "$failed gagal (relasi terkait — cek log)";
    $msgType = $failed > 0 ? 'info' : 'success';
    flash($msgType, 'Selesai: ' . (implode(', ', $parts) ?: 'tidak ada perubahan') . '.', $msgType);

} catch (Throwable $e) {
    error_log('bulk-delete-users error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menghapus pengguna massal.', 'error');
}

redirect('/admin/users');

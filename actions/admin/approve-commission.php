<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('affiliates')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/commissions');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/commissions');
}

$commissionId = (int) ($_POST['commission_id'] ?? 0);
$action       = $_POST['action'] ?? '';

if (!$commissionId || !in_array($action, ['approve', 'mark_paid'], true)) {
    flash('error', 'Request tidak valid.', 'error');
    redirect('/admin/commissions');
}

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT id, status, amount, affiliate_id FROM commissions WHERE id = ? LIMIT 1');
    $stmt->execute([$commissionId]);
    $comm = $stmt->fetch();

    if (!$comm) {
        flash('error', 'Komisi tidak ditemukan.', 'error');
        redirect('/admin/commissions');
    }

    if ($action === 'approve') {
        $pdo->beginTransaction();
        // Guard status di query (atomic) — cegah aksi ganda saat double-submit.
        $stmt = $pdo->prepare("UPDATE commissions SET status = 'approved', approved_at = NOW() WHERE id = ? AND status = 'pending'");
        $stmt->execute([$commissionId]);
        if ($stmt->rowCount() === 1) {
            // Selaras dgn jalur otomatis (onOrderPaid approve pending): total_earned =
            // akumulasi komisi yang di-APPROVE (dipakai dashboard affiliate & Leaderboard).
            // Tanpa ini, approve manual tak menaikkan total_earned → angka understate.
            $pdo->prepare("UPDATE affiliates SET total_earned = total_earned + ? WHERE id = ?")
                ->execute([(float) $comm['amount'], (int) $comm['affiliate_id']]);
            $pdo->commit();
            flash('success', 'Komisi berhasil disetujui.');
        } else {
            $pdo->rollBack();
            flash('error', 'Aksi tidak valid untuk status komisi saat ini.', 'error');
        }
    } elseif ($action === 'mark_paid') {
        $pdo->beginTransaction();

        // Flip approved→paid secara atomic. Hanya bila BARIS INI yang benar-benar
        // berubah (rowCount=1) kita menambah total_withdrawn — mencegah double-pay
        // pada klik/submit ganda atau request bersamaan.
        $stmt = $pdo->prepare("UPDATE commissions SET status = 'paid', paid_at = NOW() WHERE id = ? AND status = 'approved'");
        $stmt->execute([$commissionId]);

        if ($stmt->rowCount() === 1) {
            // Catat sebagai dana yang sudah dicairkan. total_earned TIDAK diutak-atik —
            // itu akumulasi komisi seumur hidup (di-increment saat komisi dibuat) yang
            // dipakai dashboard affiliate & Leaderboard. Menghitung ulang ke SUM(paid)
            // akan merusak nilai tersebut.
            $pdo->prepare("UPDATE affiliates SET total_withdrawn = total_withdrawn + ? WHERE id = ?")
                ->execute([(float) $comm['amount'], (int) $comm['affiliate_id']]);
            $pdo->commit();
            flash('success', 'Komisi ditandai sebagai dibayar.');
        } else {
            $pdo->rollBack();
            flash('error', 'Aksi tidak valid untuk status komisi saat ini.', 'error');
        }
    } else {
        flash('error', 'Aksi tidak valid untuk status komisi saat ini.', 'error');
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Approve commission error: ' . $e->getMessage());
    flash('error', 'Gagal memproses komisi.', 'error');
}

redirect('/admin/commissions');

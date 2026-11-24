<?php
// ════════════════════════════════════════════════════════════════
// Edit Transaksi (admin). Ubah data pembeli (nama/email/WA), harga,
// affiliate, dan status sebuah order dari halaman detail transaksi.
// Sensitif uang → guard ketat + pipeline bersama.
//  - Status: changeOrderStatus() (pipeline lengkap; dijalankan setelah commit).
//  - Harga: set total; komisi BELUM-DIBAYAR dihitung ulang dari total baru.
//  - Affiliate: ganti/hapus → reverse komisi belum-dibayar + buat pending baru;
//    DIBLOKIR bila order sudah punya komisi berstatus 'paid'.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin(); // hanya admin (bukan staff) — edit menyentuh uang & identitas

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/admin/transactions');
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/transactions');
}

$orderNumber = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($_POST['order_number'] ?? '')));
$back = $orderNumber !== '' ? '/admin/transactions/' . $orderNumber : '/admin/transactions';

$buyerName  = trim(strip_tags($_POST['buyer_name'] ?? ''));
$buyerEmail = strtolower(trim($_POST['buyer_email'] ?? ''));
$buyerWa    = preg_replace('/[^0-9]/', '', $_POST['buyer_wa'] ?? '');
$newTotal   = round((float) ($_POST['price'] ?? -1), 2); // -1 = tak dikirim
$newStatus  = in_array($_POST['status'] ?? '', ['pending', 'paid', 'failed', 'cancelled'], true) ? $_POST['status'] : '';
$affiliateId = isset($_POST['affiliate_id']) ? (int) $_POST['affiliate_id'] : -1; // -1 = tak diubah, 0 = hapus

try {
    $pdo = getDB();

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();
    if (!$order) { flash('error', 'Order tidak ditemukan.', 'error'); redirect('/admin/transactions'); }
    $orderId = (int) $order['id'];
    $userId  = (int) $order['user_id'];

    // Validasi email (bila diisi & berubah) — wajib valid & UNIK antar akun lain.
    if ($buyerEmail !== '') {
        if (!filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Format email tidak valid.', 'error'); redirect($back);
        }
        $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $dup->execute([$buyerEmail, $userId]);
        if ($dup->fetch()) {
            flash('error', 'Email sudah dipakai akun lain. Pakai email berbeda.', 'error'); redirect($back);
        }
    }
    if ($buyerName === '') { flash('error', 'Nama pembeli tidak boleh kosong.', 'error'); redirect($back); }
    if ($newTotal < 0) $newTotal = (float) $order['total']; // tak dikirim → pertahankan

    // Komisi saat ini + status (untuk guard & reverse).
    $comStmt = $pdo->prepare("SELECT id, affiliate_id, amount, rate, status FROM commissions WHERE order_id = ?");
    $comStmt->execute([$orderId]);
    $commissions = $comStmt->fetchAll();
    $hasPaidCommission = false;
    $currentAffId = 0;
    foreach ($commissions as $c) {
        if ($c['status'] === 'paid') $hasPaidCommission = true;
        if (in_array($c['status'], ['pending', 'approved'], true)) $currentAffId = (int) $c['affiliate_id'];
    }

    // Resolusi affiliate baru (bila diubah).
    $affiliateChange = ($affiliateId !== -1) && ($affiliateId !== $currentAffId);
    $newAff = null;
    if ($affiliateChange && $affiliateId > 0) {
        $a = $pdo->prepare("SELECT id, code, user_id FROM affiliates WHERE id = ? AND status = 'active' LIMIT 1");
        $a->execute([$affiliateId]);
        $newAff = $a->fetch();
        if (!$newAff) { flash('error', 'Affiliate tidak valid / tidak aktif.', 'error'); redirect($back); }
        if ((int) $newAff['user_id'] === $userId) {
            flash('error', 'Tidak bisa assign komisi ke akun pembeli sendiri.', 'error'); redirect($back);
        }
    }
    // Guard: ganti/hapus affiliate diblokir bila sudah ada komisi DIBAYAR.
    if ($affiliateChange && $hasPaidCommission) {
        flash('error', 'Affiliate tidak dapat diubah: order ini sudah punya komisi yang DIBAYAR.', 'error'); redirect($back);
    }

    $priceChanged = abs($newTotal - (float) $order['total']) > 0.001;

    // Produk utama (non-bump) untuk perhitungan komisi baru.
    $mp = $pdo->prepare("SELECT product_id FROM order_items WHERE order_id = ? AND is_bump = 0 ORDER BY id LIMIT 1");
    $mp->execute([$orderId]);
    $mainProductId = (int) $mp->fetchColumn();

    $pdo->beginTransaction();

    // 1) Data pembeli (akun users) — berlaku global utk akun tsb.
    $pdo->prepare('UPDATE users SET name = ?, email = COALESCE(NULLIF(?, ""), email), phone = ? WHERE id = ?')
        ->execute([$buyerName, $buyerEmail, $buyerWa, $userId]);

    // 2) Harga → total (rincian disederhanakan ke total ini). Sinkron item tunggal & payment.
    if ($priceChanged) {
        $pdo->prepare('UPDATE orders SET total = ?, subtotal = ?, discount = 0, setup_fee = 0, updated_at = NOW() WHERE id = ?')
            ->execute([$newTotal, $newTotal, $orderId]);
        $pdo->prepare('UPDATE payments SET amount = ? WHERE order_id = ?')->execute([$newTotal, $orderId]);
        $cntItem = (int) $pdo->query("SELECT COUNT(*) FROM order_items WHERE order_id = $orderId")->fetchColumn();
        if ($cntItem === 1) {
            $pdo->prepare('UPDATE order_items SET price = ?, quantity = 1 WHERE order_id = ?')->execute([$newTotal, $orderId]);
        }
    }

    // 3) Affiliate: ganti/hapus (reverse komisi belum-dibayar + buat pending baru).
    if ($affiliateChange) {
        // Reverse yang approved (kurangi total_earned) lalu tandai rejected pending+approved.
        $appr = $pdo->prepare("SELECT affiliate_id, SUM(amount) AS amt FROM commissions WHERE order_id = ? AND status = 'approved' GROUP BY affiliate_id");
        $appr->execute([$orderId]);
        foreach ($appr->fetchAll() as $rev) {
            $pdo->prepare("UPDATE affiliates SET total_earned = GREATEST(0, total_earned - ?) WHERE id = ?")
                ->execute([(float) $rev['amt'], (int) $rev['affiliate_id']]);
        }
        $pdo->prepare("UPDATE commissions SET status = 'rejected' WHERE order_id = ? AND status IN ('pending','approved')")
            ->execute([$orderId]);

        if ($newAff) {
            $pdo->prepare("UPDATE orders SET referral_code = ? WHERE id = ?")->execute([$newAff['code'], $orderId]);
            if (function_exists('orderRecordCommission')) {
                orderRecordCommission($pdo, (int) $newAff['id'], $orderId, $mainProductId, $newTotal, $newTotal <= 0);
            }
        } else {
            $pdo->prepare("UPDATE orders SET referral_code = NULL WHERE id = ?")->execute([$orderId]);
        }
    } elseif ($priceChanged) {
        // Tanpa ganti affiliate tapi harga berubah → hitung ulang komisi BELUM-dibayar
        // (pending/approved) dari total baru; sesuaikan total_earned utk yang approved.
        foreach ($commissions as $c) {
            if (!in_array($c['status'], ['pending', 'approved'], true)) continue;
            $newAmt = round(((float) $newTotal * (float) $c['rate']) / 100, 2);
            if ($c['status'] === 'approved') {
                $delta = $newAmt - (float) $c['amount'];
                $pdo->prepare("UPDATE affiliates SET total_earned = GREATEST(0, total_earned + ?) WHERE id = ?")
                    ->execute([$delta, (int) $c['affiliate_id']]);
            }
            $pdo->prepare("UPDATE commissions SET amount = ? WHERE id = ?")->execute([$newAmt, (int) $c['id']]);
        }
    }

    $pdo->commit();

    // 4) Status — via pipeline bersama (transaksi sendiri + efek pasca-commit).
    //    Dijalankan SETELAH edit lain commit, agar referral_code/harga sudah final
    //    saat onOrderPaid berjalan (mis. approve komisi pending yg baru dibuat).
    $statusMsg = '';
    if ($newStatus !== '' && $newStatus !== $order['status']) {
        $fresh = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $fresh->execute([$orderId]);
        $res = changeOrderStatus($pdo, $fresh->fetch(), $newStatus);
        $statusMsg = ($res['ok'] && $res['changed']) ? ' Status → ' . $newStatus . '.' : '';
    }

    logActivity('admin', 'transaction.edit', 'order', $orderId,
        'Edit order ' . $orderNumber
        . ($priceChanged ? ' harga=' . $newTotal : '')
        . ($affiliateChange ? ' affiliate=' . ($newAff ? $newAff['code'] : 'dihapus') : '')
        . ($newStatus !== '' ? ' status=' . $newStatus : ''));

    flash('success', 'Transaksi ' . $orderNumber . ' berhasil diperbarui.' . $statusMsg);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('edit-transaction error: ' . $e->getMessage());
    flash('error', 'Gagal memperbarui transaksi.', 'error');
}

redirect($back);

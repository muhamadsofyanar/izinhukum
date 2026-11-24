<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('coupons')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/coupons');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/coupons');
}

$couponId          = (int) ($_POST['coupon_id'] ?? 0);
$code              = strtoupper(preg_replace('/[^A-Z0-9-]/', '', trim($_POST['code'] ?? '')));
$type              = $_POST['type'] ?? '';
$value             = (float) ($_POST['value'] ?? 0);
$minPurchase       = max(0, (float) ($_POST['min_purchase'] ?? 0));
$maxDiscountRaw    = trim($_POST['max_discount'] ?? '');
$maxDiscount       = ($maxDiscountRaw !== '' && (float)$maxDiscountRaw > 0) ? (float)$maxDiscountRaw : null;
$usageLimitRaw     = trim($_POST['usage_limit'] ?? '');
$usageLimit        = ($usageLimitRaw !== '' && (int)$usageLimitRaw > 0) ? (int)$usageLimitRaw : null;
$applicableProduct = (int) ($_POST['applicable_product_id'] ?? 0) ?: null;
$status            = $_POST['status'] ?? 'active';
$startsAtRaw       = trim($_POST['starts_at'] ?? '');
$expiresAtRaw      = trim($_POST['expires_at'] ?? '');
$startsAt          = $startsAtRaw !== '' ? date('Y-m-d H:i:s', strtotime($startsAtRaw)) : null;
$expiresAt         = $expiresAtRaw !== '' ? date('Y-m-d H:i:s', strtotime($expiresAtRaw)) : null;

// ─── Validasi ─────────────────────────────────────────────────
if (strlen($code) < 2 || strlen($code) > 50) {
    flash('error', 'Kode kupon harus 2–50 karakter.', 'error');
    redirect('/admin/coupons');
}

if (!in_array($type, ['percentage', 'fixed'], true)) {
    flash('error', 'Tipe kupon tidak valid.', 'error');
    redirect('/admin/coupons');
}

if ($value <= 0) {
    flash('error', 'Nilai diskon harus lebih dari 0.', 'error');
    redirect('/admin/coupons');
}

if ($type === 'percentage' && $value > 100) {
    flash('error', 'Diskon persentase tidak boleh lebih dari 100%.', 'error');
    redirect('/admin/coupons');
}

if ($maxDiscount !== null && $maxDiscount <= 0) {
    flash('error', 'Maks. diskon harus lebih dari 0 jika diisi.', 'error');
    redirect('/admin/coupons');
}

if ($startsAt && $expiresAt && $expiresAt <= $startsAt) {
    flash('error', 'Tanggal berakhir harus setelah tanggal mulai.', 'error');
    redirect('/admin/coupons');
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

try {
    $pdo = getDB();

    // Cek duplikat kode (kecuali milik sendiri saat edit)
    $dup = $pdo->prepare(
        'SELECT id FROM coupons WHERE code = ? AND id != ?'
    );
    $dup->execute([$code, $couponId]);
    if ($dup->fetch()) {
        flash('error', 'Kode kupon "' . $code . '" sudah digunakan.', 'error');
        redirect('/admin/coupons');
    }

    // Cek applicable product valid jika diisi
    if ($applicableProduct !== null) {
        $pCheck = $pdo->prepare('SELECT id FROM products WHERE id = ? AND status = "active"');
        $pCheck->execute([$applicableProduct]);
        if (!$pCheck->fetch()) {
            $applicableProduct = null;
        }
    }

    if ($couponId > 0) {
        // UPDATE
        $stmt = $pdo->prepare(
            'UPDATE coupons SET
               code = ?, type = ?, value = ?, min_purchase = ?, max_discount = ?,
               usage_limit = ?, applicable_product_id = ?, status = ?,
               starts_at = ?, expires_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $code, $type, $value, $minPurchase, $maxDiscount,
            $usageLimit, $applicableProduct, $status,
            $startsAt, $expiresAt, $couponId,
        ]);
        flash('success', 'Kupon berhasil diperbarui.');
    } else {
        // INSERT
        $stmt = $pdo->prepare(
            'INSERT INTO coupons
               (code, type, value, min_purchase, max_discount, usage_limit, applicable_product_id, status, starts_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $code, $type, $value, $minPurchase, $maxDiscount,
            $usageLimit, $applicableProduct, $status,
            $startsAt, $expiresAt,
        ]);
        flash('success', 'Kupon berhasil dibuat.');
    }

} catch (Exception $e) {
    error_log('Save coupon error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menyimpan kupon.', 'error');
}

redirect('/admin/coupons');

<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/commission-rules');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/commission-rules');
}

$ruleId    = (int) ($_POST['rule_id']             ?? 0);
$productId = ($_POST['product_id'] ?? '') !== '' ? (int) $_POST['product_id'] : null;
$levelId   = ($_POST['membership_level_id'] ?? '') !== '' ? (int) $_POST['membership_level_id'] : null;
$reqProdId = ($_POST['required_product_id'] ?? '') !== '' ? (int) $_POST['required_product_id'] : null;
$rate      = (float) ($_POST['commission_rate']   ?? 0);
$priority  = max(0, (int) ($_POST['priority']     ?? 0));
$status    = isset($_POST['status']) && $_POST['status'] === 'active' ? 'active' : 'inactive';

$isEdit = $ruleId > 0;

// Validate
if ($rate < 0 || $rate > 100) {
    flash('error', 'Rate komisi harus antara 0 dan 100.', 'error');
    redirect('/admin/commission-rules');
}

try {
    $pdo = getDB();

    // Pastikan product/level exist jika dipilih
    if ($productId !== null) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM products WHERE id = ?");
        $chk->execute([$productId]);
        if ((int) $chk->fetchColumn() === 0) {
            flash('error', 'Produk tidak ditemukan.', 'error');
            redirect('/admin/commission-rules');
        }
    }
    if ($levelId !== null) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM membership_levels WHERE id = ?");
        $chk->execute([$levelId]);
        if ((int) $chk->fetchColumn() === 0) {
            flash('error', 'Level membership tidak ditemukan.', 'error');
            redirect('/admin/commission-rules');
        }
    }
    if ($reqProdId !== null) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM products WHERE id = ?");
        $chk->execute([$reqProdId]);
        if ((int) $chk->fetchColumn() === 0) {
            flash('error', 'Produk syarat tidak ditemukan.', 'error');
            redirect('/admin/commission-rules');
        }
    }

    if ($isEdit) {
        $pdo->prepare(
            "UPDATE commission_rules
             SET product_id = ?, membership_level_id = ?, required_product_id = ?, commission_rate = ?,
                 priority = ?, status = ?
             WHERE id = ?"
        )->execute([$productId, $levelId, $reqProdId, $rate, $priority, $status, $ruleId]);

        logActivity('admin', 'commission_rule.update', 'commission_rule', $ruleId,
            'Rule diperbarui: rate=' . $rate . '%');
        flash('success', 'Rule komisi berhasil diperbarui.');
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO commission_rules (product_id, membership_level_id, required_product_id, commission_rate, priority, status)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$productId, $levelId, $reqProdId, $rate, $priority, $status]);
        $ruleId = (int) $pdo->lastInsertId();

        logActivity('admin', 'commission_rule.create', 'commission_rule', $ruleId,
            'Rule baru: rate=' . $rate . '%');
        flash('success', 'Rule komisi berhasil ditambahkan.');
    }

} catch (Exception $e) {
    error_log('Save commission rule error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan rule komisi.', 'error');
}

redirect('/admin/commission-rules');

<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('email_sequences')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/email-sequences'); }

$id           = (int) ($_POST['id'] ?? 0);
$name         = trim($_POST['name'] ?? '');
$triggerEvent = in_array($_POST['trigger_event'] ?? '', ['order_paid','member_access_activated','user_register','waitlist_joined','booking_paid','lead_captured'], true) ? $_POST['trigger_event'] : 'order_paid';
$status       = in_array($_POST['status'] ?? '', ['active','inactive'], true) ? $_POST['status'] : 'inactive';

// ─── Target & Kecualikan produk (junction email_sequence_products) ──
$targetMode = ($_POST['target_mode'] ?? 'all') === 'specific' ? 'specific' : 'all';
$asIds = function ($arr) {
    $out = array_filter(array_map('intval', (array) $arr), fn($v) => $v > 0);
    return array_values(array_unique($out));
};
$targetProducts  = $targetMode === 'specific' ? $asIds($_POST['target_products'] ?? []) : [];
$excludeProducts = $asIds($_POST['exclude_products'] ?? []);
// exclude tak boleh tumpang tindih dengan include (kontradiktif).
if ($targetProducts) $excludeProducts = array_values(array_diff($excludeProducts, $targetProducts));

if (strlen($name) < 3) { flash('error', 'Nama sequence minimal 3 karakter.', 'error'); redirect('/admin/email-sequences'); }

$pdo = getDB();

// Validasi id produk memang ada (hindari FK error saat insert junction).
$validIds = [];
$allWanted = array_merge($targetProducts, $excludeProducts);
if ($allWanted) {
    $in = implode(',', array_fill(0, count($allWanted), '?'));
    $vs = $pdo->prepare("SELECT id FROM products WHERE id IN ($in)");
    $vs->execute($allWanted);
    $validIds = array_map('intval', $vs->fetchAll(PDO::FETCH_COLUMN));
}
$targetProducts  = array_values(array_intersect($targetProducts, $validIds));
$excludeProducts = array_values(array_intersect($excludeProducts, $validIds));

try {
    $pdo->beginTransaction();

    if ($id) {
        // trigger_product_id di-NULL-kan: target kini dikelola junction (kolom lama = fallback saja).
        $pdo->prepare("UPDATE email_sequences SET name=?, trigger_event=?, trigger_product_id=NULL, provider='mailketing', status=?, updated_at=NOW() WHERE id=?")
            ->execute([$name, $triggerEvent, $status, $id]);
        $seqId = $id;
        logActivity('admin', 'email_sequence.update', 'email_sequence', $id, 'Update sequence: ' . $name);
    } else {
        $pdo->prepare("INSERT INTO email_sequences (name, trigger_event, trigger_product_id, provider, status) VALUES (?, ?, NULL, 'mailketing', ?)")
            ->execute([$name, $triggerEvent, $status]);
        $seqId = (int) $pdo->lastInsertId();
        logActivity('admin', 'email_sequence.create', 'email_sequence', $seqId, 'Buat sequence: ' . $name);
    }

    // Rebuild junction produk.
    $pdo->prepare("DELETE FROM email_sequence_products WHERE sequence_id=?")->execute([$seqId]);
    $ins = $pdo->prepare("INSERT IGNORE INTO email_sequence_products (sequence_id, product_id, kind) VALUES (?, ?, ?)");
    foreach ($targetProducts  as $pid) { $ins->execute([$seqId, $pid, 'include']); }
    foreach ($excludeProducts as $pid) { $ins->execute([$seqId, $pid, 'exclude']); }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('save-email-sequence error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan sequence.', 'error');
    redirect('/admin/email-sequences');
}

flash('success', 'Sequence berhasil disimpan.');
redirect('/admin/email-sequences');

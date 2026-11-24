<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('integrations')) { flash('error', 'Tanpa akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/webhooks'); }

$id     = (int) ($_POST['id'] ?? 0);
$name   = trim($_POST['name'] ?? '');
$url    = trim($_POST['url'] ?? '');
$event  = ($_POST['event'] ?? 'order.paid') === 'order.paid' ? 'order.paid' : 'order.paid';
$active = ($_POST['is_active'] ?? '1') === '0' ? 0 : 1;
$regen  = !empty($_POST['regenerate_secret']);
$custom = trim($_POST['secret'] ?? ''); // secret manual (mis. sudah dipegang dev app tujuan)

if (strlen($name) < 2) { flash('error', 'Nama minimal 2 karakter.', 'error'); redirect('/admin/webhooks'); }
if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) { flash('error', 'URL tujuan tidak valid (harus http/https).', 'error'); redirect('/admin/webhooks'); }
// Secret manual (bila diisi): 16–80 char (kolom secret varchar(80)), tanpa spasi — cukup kuat utk HMAC.
if ($custom !== '' && !preg_match('/^\S{16,80}$/', $custom)) {
    flash('error', 'Secret manual tidak valid: 16–80 karakter, tanpa spasi.', 'error');
    redirect('/admin/webhooks');
}

try {
    $pdo = getDB();
    // Secret manual menang; kalau kosong & regen → acak baru; kalau kosong tanpa regen → biarkan lama.
    $newSecret = $custom !== '' ? $custom : bin2hex(random_bytes(24)); // 48 char acak
    if ($id > 0) {
        if ($custom !== '' || $regen) {
            $pdo->prepare("UPDATE outgoing_webhooks SET name=?, url=?, event=?, is_active=?, secret=?, updated_at=NOW() WHERE id=?")->execute([$name,$url,$event,$active,$newSecret,$id]);
        } else {
            $pdo->prepare("UPDATE outgoing_webhooks SET name=?, url=?, event=?, is_active=?, updated_at=NOW() WHERE id=?")->execute([$name,$url,$event,$active,$id]);
        }
        logActivity('admin', 'webhook.update', 'outgoing_webhooks', $id, 'Update webhook: ' . $name);
        $secretMsg = $custom !== '' ? ' Secret manual dipasang.' : ($regen ? ' Secret baru dibuat — pasang ulang di aplikasi tujuan.' : '');
        flash('success', 'Webhook disimpan.' . $secretMsg);
    } else {
        $pdo->prepare("INSERT INTO outgoing_webhooks (name, url, secret, event, is_active) VALUES (?,?,?,?,?)")->execute([$name,$url,$newSecret,$event,$active]);
        logActivity('admin', 'webhook.create', 'outgoing_webhooks', (int)$pdo->lastInsertId(), 'Buat webhook: ' . $name);
        flash('success', 'Webhook dibuat.' . ($custom !== '' ? ' Secret manual dipasang.' : ' Salin secret-nya untuk dipasang di aplikasi tujuan.'));
    }
} catch (Throwable $e) { error_log('save-webhook: ' . $e->getMessage()); flash('error', 'Gagal menyimpan webhook.', 'error'); }
redirect('/admin/webhooks');

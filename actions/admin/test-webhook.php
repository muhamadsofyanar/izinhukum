<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('integrations')) { flash('error', 'Tanpa akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/webhooks'); }
$id = (int) ($_POST['id'] ?? 0);
try {
    $pdo = getDB();
    $w = $pdo->prepare("SELECT * FROM outgoing_webhooks WHERE id=? LIMIT 1"); $w->execute([$id]); $wh = $w->fetch();
    if (!$wh) { flash('error', 'Webhook tidak ditemukan.', 'error'); redirect('/admin/webhooks'); }
    $ts = time();
    // Kirim kode program NYATA bila ada produk ber-external_program_code aktif — supaya
    // tes juga memverifikasi mapping produk di aplikasi tujuan (bukan sekadar koneksi);
    // fallback 'TEST' bila belum ada. Flag test:true tetap dikirim → aplikasi tujuan
    // TIDAK memberi akses, hanya mengonfirmasi endpoint+secret cocok.
    $progCode = 'TEST';
    try {
        $pc = $pdo->query("SELECT external_program_code FROM products WHERE external_program_code IS NOT NULL AND external_program_code <> '' AND status = 'active' ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($pc) $progCode = (string) $pc;
    } catch (Throwable $e) { /* fallback TEST */ }
    $body = json_encode(['event'=>$wh['event'],'status'=>'active','email'=>'test@averion.local','name'=>'Test Averion','phone'=>'','program_code'=>$progCode,'order_id'=>'TEST-'.$ts,'timestamp'=>$ts,'test'=>true], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $sig = 'sha256=' . hash_hmac('sha256', $body, (string)$wh['secret']);
    [$code,$resp,$ok] = webhookHttpPost((string)$wh['url'], $body, ['Content-Type: application/json','X-Averion-Event: '.$wh['event'],'X-Averion-Timestamp: '.$ts,'X-Averion-Signature: '.$sig]);
    if ($ok) flash('success', 'Tes berhasil — HTTP '.$code.'. Aplikasi tujuan menerima & merespon OK.');
    else flash('error', 'Tes gagal — HTTP '.$code.'. Respon: '.mb_substr((string)$resp,0,180), 'error');
} catch (Throwable $e) { error_log('test-webhook: '.$e->getMessage()); flash('error', 'Gagal mengirim tes.', 'error'); }
redirect('/admin/webhooks');

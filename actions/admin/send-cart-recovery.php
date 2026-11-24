<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('abandoned_carts')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/abandoned-carts'); }

$orderId = (int) ($_POST['order_id'] ?? 0);
$pdo = getDB();
$stmt = $pdo->prepare(
    "SELECT o.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone, oi.product_name
     FROM orders o JOIN users u ON u.id=o.user_id JOIN order_items oi ON oi.order_id=o.id AND oi.is_bump=0
     WHERE o.id=? AND o.status='pending' GROUP BY o.id LIMIT 1"
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) { flash('error', 'Order tidak ditemukan / bukan pending.', 'error'); redirect('/admin/abandoned-carts'); }
if ((int) $order['recovery_remind_stage'] >= 3) { flash('warning', 'Order ini sudah menerima 3 pengingat (maksimum).', 'warning'); redirect('/admin/abandoned-carts'); }
$optSt = $pdo->prepare('SELECT email_opt_out FROM users WHERE id=?');
$optSt->execute([(int) $order['user_id']]);
if ($order['user_email'] && (int) $optSt->fetchColumn() === 1
    && getSetting('abandoned_cart_channel', 'email') === 'email') {
    flash('warning', 'Penerima berhenti berlangganan email.', 'warning'); redirect('/admin/abandoned-carts');
}

$channel  = getSetting('abandoned_cart_channel', 'email');
$provider = resolveAbandonedCartProvider($channel);
if ($provider === '') { flash('error', 'Belum ada provider WhatsApp yang aktif. Aktifkan dulu di Pengaturan → Integrasi.', 'error'); redirect('/admin/abandoned-carts'); }
$recipient = $channel === 'whatsapp' ? ($order['user_phone'] ?? '') : $order['user_email'];
if (empty($recipient)) { flash('error', 'User tidak punya kontak untuk channel ' . $channel . '.', 'error'); redirect('/admin/abandoned-carts'); }

// Template sesuai tahap reminder berikutnya (0,1,2 → #1,#2,#3), fallback ke dasar.
$template = abandonedCartTemplateForStage((int) $order['recovery_remind_stage'], $channel);
if (!$template) { flash('error', 'Template "abandoned_cart" belum aktif. Aktifkan di menu Notifikasi.', 'error'); redirect('/admin/abandoned-carts'); }

$checkoutUrl = rtrim(APP_URL, '/') . '/transactions/' . $order['order_number'];
$ph = ['name' => $order['user_name'], 'product_name' => $order['product_name'], 'total' => formatRupiah((float) $order['total']),
       'order_number' => $order['order_number'], 'checkout_url' => $checkoutUrl, 'app_name' => getSetting('app_name', 'STIFIn Mulia')];
$subject = renderPlaceholders($template['subject'] ?? '', $ph, $channel);
$body    = renderPlaceholders($template['body'] ?? '', $ph, $channel);
if ($channel === 'email' && function_exists('emailLayout')) $body = emailLayout($subject, $body, 'info');
if ($channel === 'email') $body = sequenceApplyUnsubscribeFooter($body, (string) $recipient);

require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
$adapter = NotificationDispatcher::getAdapterPublic($provider);
if (!$adapter) { flash('error', 'Provider tidak valid.', 'error'); redirect('/admin/abandoned-carts'); }

// Klaim per-tahap (konsisten dgn worker auto): naikkan stage, maks 3. Mencegah
// manual + auto mengirim dobel untuk tahap yang sama.
$claim = $pdo->prepare("UPDATE orders SET recovery_remind_stage = recovery_remind_stage + 1, recovery_sent_at=NOW()
                        WHERE id=? AND status='pending' AND recovery_remind_stage < 3");
$claim->execute([$orderId]);
if ($claim->rowCount() !== 1) {
    flash('warning', 'Order sudah lunas, sudah 3x, atau pengingatnya sedang diproses.', 'warning');
    redirect('/admin/abandoned-carts');
}
$check = $pdo->prepare('SELECT status FROM orders WHERE id=?');
$check->execute([$orderId]);
if ($check->fetchColumn() !== 'pending') {
    flash('warning', 'Order sudah berubah status.', 'warning'); redirect('/admin/abandoned-carts');
}

$res  = $adapter->send($recipient, $subject, $body);
$ok   = !empty($res['success']);
$resp = is_array($res['raw_response'] ?? null) ? json_encode($res['raw_response']) : (string) ($res['raw_response'] ?? $res['message'] ?? '');

$pdo->prepare("INSERT INTO abandoned_cart_logs (order_id, user_id, channel, provider, recipient, status, response) VALUES (?, ?, ?, ?, ?, ?, ?)")
    ->execute([$orderId, (int) $order['user_id'], $channel, $provider, $recipient, $ok ? 'sent' : 'failed', $resp]);
$pdo->prepare("INSERT INTO notification_logs (event_key, channel, provider, recipient, status, response, created_at) VALUES ('abandoned_cart', ?, ?, ?, ?, ?, NOW())")
    ->execute([$channel, $provider, $recipient, $ok ? 'sent' : 'failed', $resp]);

logActivity('admin', 'abandoned_cart.manual_send', 'order', $orderId, 'Manual recovery: ' . $order['order_number']);
$ok ? flash('success', 'Recovery reminder dikirim ke ' . $recipient . '.') : flash('error', 'Gagal kirim: ' . ($res['message'] ?? 'unknown'), 'error');
redirect('/admin/abandoned-carts');

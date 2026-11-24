<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('affiliates')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/payouts'); }

$payoutId = (int) ($_POST['payout_id'] ?? 0);
$reason   = trim($_POST['reason'] ?? '');
if (!$payoutId || $reason === '') { flash('error', 'Alasan penolakan wajib diisi.', 'error'); redirect('/admin/payouts'); }

$pdo = getDB();
$stmt = $pdo->prepare("SELECT pr.*, u.name, u.email, u.phone FROM payout_requests pr JOIN affiliates a ON a.id=pr.affiliate_id JOIN users u ON u.id=a.user_id WHERE pr.id=? AND pr.status IN ('pending','processing')");
$stmt->execute([$payoutId]);
$payout = $stmt->fetch();
if (!$payout) { flash('error', 'Payout tidak ditemukan.', 'error'); redirect('/admin/payouts'); }

// Guard status atomik (cegah menimpa payout yang keburu di-"paid" oleh admin lain).
$upd = $pdo->prepare("UPDATE payout_requests SET status='rejected', admin_note=?, processed_by=?, processed_at=NOW() WHERE id=? AND status IN ('pending','processing')");
$upd->execute([$reason, (int) $_SESSION['user_id'], $payoutId]);
if ($upd->rowCount() !== 1) { flash('info', 'Payout sudah diproses.', 'info'); redirect('/admin/payouts'); }
logActivity('admin', 'payout.reject', 'payout_request', $payoutId, 'Tolak payout ' . $payout['name']);

require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
NotificationDispatcher::trigger('payout_rejected', [
    'name' => $payout['name'], 'email' => $payout['email'], 'amount' => formatRupiah((float) $payout['amount']), 'reason' => $reason,
    'affiliate_url' => baseUrl('affiliate'),
], $payout['email'], $payout['phone'] ?? '');

flash('success', 'Payout request ditolak.');
redirect('/admin/payouts');

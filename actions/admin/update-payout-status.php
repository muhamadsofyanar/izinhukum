<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('affiliates')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/payouts'); }

$payoutId = (int) ($_POST['payout_id'] ?? 0);
$pdo = getDB();
$stmt = $pdo->prepare("SELECT pr.*, u.name, u.email, u.phone FROM payout_requests pr JOIN affiliates a ON a.id=pr.affiliate_id JOIN users u ON u.id=a.user_id WHERE pr.id=? AND pr.status='pending'");
$stmt->execute([$payoutId]);
$payout = $stmt->fetch();
if (!$payout) { flash('error', 'Payout tidak ditemukan / sudah diproses.', 'error'); redirect('/admin/payouts'); }

// Guard status atomik (cegah menimpa payout yang keburu diproses admin lain).
$upd = $pdo->prepare("UPDATE payout_requests SET status='processing', processed_by=?, processed_at=NOW() WHERE id=? AND status='pending'");
$upd->execute([(int) $_SESSION['user_id'], $payoutId]);
if ($upd->rowCount() !== 1) { flash('info', 'Payout sudah diproses.', 'info'); redirect('/admin/payouts'); }
logActivity('admin', 'payout.processing', 'payout_request', $payoutId, 'Mark processing payout ' . $payout['name']);

require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
NotificationDispatcher::trigger('payout_processing', [
    'name' => $payout['name'], 'email' => $payout['email'], 'amount' => formatRupiah((float) $payout['amount']),
    'bank_name' => $payout['bank_name'], 'bank_account_no' => $payout['bank_account_no'], 'bank_account_name' => $payout['bank_account_name'],
    'affiliate_url' => baseUrl('affiliate'),
], $payout['email'], $payout['phone'] ?? '');

flash('success', 'Status payout → Diproses. Notifikasi terkirim.');
redirect('/admin/payouts');

<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('abandoned_carts')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/abandoned-carts'); }

setSetting('abandoned_cart_enabled', (($_POST['abandoned_cart_enabled'] ?? '0') === '1') ? '1' : '0', 'marketing');
setSetting('abandoned_cart_delay_hours', (string) max(1, min(72, (int) ($_POST['abandoned_cart_delay_hours'] ?? 1))), 'marketing');
setSetting('abandoned_cart_gap2_hours', (string) max(1, min(168, (int) ($_POST['abandoned_cart_gap2_hours'] ?? 24))), 'marketing');
setSetting('abandoned_cart_gap3_hours', (string) max(1, min(168, (int) ($_POST['abandoned_cart_gap3_hours'] ?? 48))), 'marketing');
$channel = in_array($_POST['abandoned_cart_channel'] ?? '', ['email', 'whatsapp'], true) ? $_POST['abandoned_cart_channel'] : 'email';
setSetting('abandoned_cart_channel', $channel, 'marketing');

logActivity('admin', 'abandoned_cart.settings_update', 'settings', null, 'Update pengaturan abandoned cart');
flash('success', 'Pengaturan abandoned cart disimpan.');
redirect('/admin/abandoned-carts');

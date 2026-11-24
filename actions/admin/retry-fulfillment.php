<?php
/**
 * Coba lagi satu pesanan lisensi yang tertunda/gagal.
 * Aman diulang: vendor idempoten per nomor order (order yang sama tak akan pernah
 * menerbitkan lisensi kedua), jadi tombol ini tak bisa merugikan.
 */
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if (!function_exists('vfAvailable') || !vfAvailable()) {
    redirect('/admin/integrations');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Request tidak valid.', 'error');
    redirect('/admin/license-fulfillments');
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    redirect('/admin/license-fulfillments');
}

try {
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT status, order_number FROM license_fulfillments WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();

    if (!$row) {
        flash('error', 'Antrean tidak ditemukan.', 'error');
        redirect('/admin/license-fulfillments');
    }
    if ($row['status'] === 'done') {
        flash('success', 'Lisensi order ini sudah terbit — tak perlu dicoba lagi.');
        redirect('/admin/license-fulfillments');
    }

    // Reset percobaan supaya tidak langsung menyerah lagi karena backoff/limit lama.
    $pdo->prepare(
        "UPDATE license_fulfillments
            SET status='pending', attempts=0, next_attempt_at=NOW(), last_error=NULL
          WHERE id = ?"
    )->execute([$id]);

    // Kirim SEKARANG (jangan menunggu cron) — admin menekan tombol ini justru
    // karena ingin tahu hasilnya sekarang.
    processVendorFulfillments(1);

    $after = $pdo->prepare("SELECT status, license_key, last_error FROM license_fulfillments WHERE id = ?");
    $after->execute([$id]);
    $now = $after->fetch() ?: [];

    if (($now['status'] ?? '') === 'done') {
        flash('success', 'Berhasil — lisensi terbit: ' . ($now['license_key'] ?? '') . '. Pembeli sudah bisa melihatnya di Lisensi Saya.');
    } elseif (($now['status'] ?? '') === 'failed') {
        flash('error', 'Masih gagal: ' . ($now['last_error'] ?? 'penyebab tak diketahui'), 'error');
    } else {
        flash('success', 'Sudah diantre ulang — akan dicoba lagi otomatis.');
    }
    logActivity('admin', 'fulfillment.retry', 'license_fulfillment', $id, 'Coba terbitkan ulang: ' . $row['order_number']);
} catch (Exception $e) {
    error_log('retry-fulfillment error: ' . $e->getMessage());
    flash('error', 'Gagal memproses ulang antrean.', 'error');
}

redirect('/admin/license-fulfillments');

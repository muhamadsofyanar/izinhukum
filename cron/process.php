<?php
// ─── Cron Processor Averion ───────────────────────────────────────
// Jalankan periodik (mis. tiap 5 menit) untuk memproses:
//   1. Email Sequence (drip) yang sudah jatuh tempo
//   2. Abandoned Cart recovery
//   3. Order Expiry (auto-cancel order pending kedaluwarsa)
//
// Cara pakai:
//   • CLI / cron server:  php /path/ke/averion/cron/process.php
//   • URL (cPanel cron / uptime):  https://domain/cron/process?token=CRON_SECRET
//
// Keamanan: via URL wajib token = setting `cron_secret`. Via CLI bebas token.

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../helpers/update-backups.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $secret = (string) getSetting('cron_secret', '');
    $token  = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
    if ($secret === '' || !hash_equals($secret, $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden — token cron tidak valid.']);
        exit;
    }
    header('Content-Type: application/json');
}

if (getenv('DARMUTIARA_CONTAINER_CRON') === '1') {
    $cronLock = acquireContainerCronLock();
    if ($cronLock === null) {
        echo json_encode(['status' => 'busy']);
        exit;
    }
}

@set_time_limit(0); // drain broadcast bisa berjalan lama; jangan kena batas eksekusi

$out = ['ran_at' => date('c')];

// 0. Detak jantung — indikator "cron terakhir jalan" di dashboard & system-health.
try { cronHeartbeat('cron'); } catch (Throwable $e) { error_log('cron heartbeat: ' . $e->getMessage()); }

// Tambahkan kontak order dan pembayaran ke list Mailketing.
processMailketingQueue(40);

// 1. Email Sequence
try {
    $out['email_sequence'] = processDueSequenceSends(100);
} catch (Throwable $e) {
    $out['email_sequence'] = ['error' => $e->getMessage()];
    error_log('cron email_sequence: ' . $e->getMessage());
}

// 2. Abandoned Cart
try {
    $out['abandoned_cart'] = processAbandonedCarts(100);
} catch (Throwable $e) {
    $out['abandoned_cart'] = ['error' => $e->getMessage()];
    error_log('cron abandoned_cart: ' . $e->getMessage());
}

// 2b. Broadcast — kirim latar. Drain berjangka-waktu (maks ~90s/putaran cron) agar
//     background KUAT walau semua tab admin ditutup (dulu cuma 50/5mnt ≈ terlalu lambat).
//     Lock-guarded: bila pump halaman sedang memegang lock (processed=0), cron mengalah
//     (break) agar tak spin. Total cron << interval 5mnt → tak overlap.
try {
    $bc = ['sent' => 0, 'failed' => 0, 'runs' => 0, 'status' => null];
    $bcStart = time();
    while (time() - $bcStart < 90) {
        $r = processBroadcastQueue(50);
        $bc['runs']++;
        $bc['sent']   += (int) ($r['sent'] ?? 0);
        $bc['failed'] += (int) ($r['failed'] ?? 0);
        $bc['status']  = $r['status'] ?? null;
        if (empty($r['campaign_id']))            break; // tak ada campaign sending
        if ((int) ($r['processed'] ?? 0) === 0)  break; // 0 diproses (locked pump / selesai) → mengalah
        if (($r['status'] ?? '') === 'sent')     break; // campaign ini selesai
    }
    $out['broadcast'] = $bc;
} catch (Throwable $e) {
    $out['broadcast'] = ['error' => $e->getMessage()];
    error_log('cron broadcast: ' . $e->getMessage());
}

// 2c. Integrasi Webhook Keluar — kirim antrean webhook order.paid ber-HMAC + retry.
try {
    $out['webhook'] = processWebhookQueue(50);
} catch (Throwable $e) {
    $out['webhook'] = ['error' => $e->getMessage()];
    error_log('cron webhook: ' . $e->getMessage());
}

// 3. Order Expiry (auto-cancel) — logika dipusatkan di helper processExpiredOrders().
try {
    $out['order_expiry'] = ['cancelled' => processExpiredOrders()];
} catch (Throwable $e) {
    $out['order_expiry'] = ['error' => $e->getMessage()];
    error_log('cron order_expiry: ' . $e->getMessage());
}

// 4. Reminder Renewal (langganan) — H-3 & H-1 sebelum akses berjangka kedaluwarsa.
try {
    $out['renewal_reminder'] = processRenewalReminders(200);
} catch (Throwable $e) {
    $out['renewal_reminder'] = ['error' => $e->getMessage()];
    error_log('cron renewal_reminder: ' . $e->getMessage());
}

// 4b. Lisensi Vendor — kirim antrean penerbitan/perpanjangan + retry bila vendor
//     sempat tak bisa dihubungi. No-op bila integrasi tidak diaktifkan.
try {
    $out['vendor_fulfillment'] = processVendorFulfillments(50);
} catch (Throwable $e) {
    $out['vendor_fulfillment'] = ['error' => $e->getMessage()];
    error_log('cron vendor_fulfillment: ' . $e->getMessage());
}

if (stifinVoucherTransferEnabled() && getenv('STIFIN_CONTAINER_VOUCHER_WORKER') !== '1') {
    try {
        stifinProcessVoucherTransfers(getDB());
    } catch (Throwable $e) {
        error_log('[stifin-voucher] Antrean transfer belum bisa diproses.');
    }
}

// 5. Waitlist Launch — auto-launch produk terjadwal + blast notif ke pendaftar.
try {
    $out['waitlist_launch'] = processWaitlistLaunches(100);
} catch (Throwable $e) {
    $out['waitlist_launch'] = ['error' => $e->getMessage()];
    error_log('cron waitlist_launch: ' . $e->getMessage());
}

// 6. Booking Fee — buat order pelunasan utk DP paid + hanguskan yang lewat deadline.
try {
    $out['booking_orders'] = processBookingOrders(50);
} catch (Throwable $e) {
    $out['booking_orders'] = ['error' => $e->getMessage()];
    error_log('cron booking_orders: ' . $e->getMessage());
}

// 7. Reminder Pelunasan Booking — H-2 & H-1 sebelum due_at order settlement.
try {
    $out['settlement_reminder'] = processSettlementReminders(100);
} catch (Throwable $e) {
    $out['settlement_reminder'] = ['error' => $e->getMessage()];
    error_log('cron settlement_reminder: ' . $e->getMessage());
}

// 8. Auto-Backup Database — harian, retensi otomatis.
try {
    $out['db_backup'] = processDbBackups();
} catch (Throwable $e) {
    $out['db_backup'] = ['error' => $e->getMessage()];
    error_log('cron db_backup: ' . $e->getMessage());
}

// 8b. Retensi snapshot update — satu folder per giliran, penghapusan dibatasi.
try {
    $root = dirname(__DIR__);
    $out['update_backups'] = processUpdateBackups(getDB(), $root, [
        UPDATE_BACKUP_PATH,
        $root . '/storage/update-backups',
    ]);
} catch (Throwable $e) {
    $out['update_backups'] = ['error' => 'Pembersihan snapshot gagal.'];
    error_log('cron update_backups: ' . $e->getMessage());
}

// 9. Housekeeping harian — pangkas notification_logs >90 hari + trusted device 2FA kedaluwarsa.
try {
    $out['health_housekeeping'] = processHealthHousekeeping();
} catch (Throwable $e) {
    $out['health_housekeeping'] = ['error' => $e->getMessage()];
    error_log('cron health_housekeeping: ' . $e->getMessage());
}

// 10. Auto-retry notifikasi gagal — isu jaringan sesaat sembuh sendiri tanpa
//     admin harus klik "Kirim Ulang" manual di Kesehatan Sistem.
try {
    $out['notification_retry'] = processNotificationRetries(20);
} catch (Throwable $e) {
    $out['notification_retry'] = ['error' => $e->getMessage()];
    error_log('cron notification_retry: ' . $e->getMessage());
}

echo $isCli ? (json_encode($out, JSON_PRETTY_PRINT) . "\n") : json_encode($out);

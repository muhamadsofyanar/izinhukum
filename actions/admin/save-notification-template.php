<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/notifications');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/notifications');
}

$mode = $_POST['mode'] ?? 'save_template';

// ─── Mode: Simpan API Key ────────────────────────────────────
if ($mode === 'save_keys') {
    try {
        $pdo = getDB();
        // Hanya update key yang benar-benar ada di POST request ini.
        // Ini mencegah form Email menghapus key WA dan sebaliknya,
        // karena setiap tab hanya mengirim field miliknya sendiri.
        $allowed = [
            'mailketing_api_key', 'mailketing_sender_email', 'mailketing_sender_name',
            'mailketing_list_order', 'mailketing_list_paid',
            'fonte_api_key', 'onesender_api_key', 'onesender_api_url', 'starsender_api_key', 'dripsender_api_key',
            'kirimdev_api_key', 'kirimdev_phone_number_id',
            'kirimdev_otp_template_name', 'kirimdev_otp_method', 'kirimdev_otp_template_lang',
            'averionrelay_api_key', 'averionrelay_api_url',
            'wa_sender_number', 'wa_otp_login_enabled', 'wa_otp_provider', 'wa_otp_message',
            'fonte_enabled', 'onesender_enabled', 'starsender_enabled', 'dripsender_enabled', 'kirimdev_enabled', 'averionrelay_enabled',
            // Telegram Alerts (channel internal admin) — token=secret (preserve-on-empty),
            // chat_id + toggle master & per-jenis alert internal.
            'telegram_bot_token', 'telegram_chat_id', 'telegram_enabled',
            'telegram_alert_order_failed', 'telegram_alert_admin_login',
        ];
        $keys = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $_POST)) {
                $keys[$k] = trim($_POST[$k]);
            }
        }
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        // Field secret (API key) yang dikirim KOSONG = tidak diubah — masking di UI
        // mengirim value kosong bila admin tak menggantinya → JANGAN timpa nilai lama.
        $secretKeys = ['mailketing_api_key', 'fonte_api_key', 'onesender_api_key', 'starsender_api_key', 'dripsender_api_key', 'kirimdev_api_key', 'averionrelay_api_key', 'telegram_bot_token'];
        foreach ($keys as $k => $v) {
            if ($v === '' && in_array($k, $secretKeys, true)) continue;
            $stmt->execute([$k, $v]);
        }
        $ok = true;
    } catch (Exception $e) {
        error_log('Save notification keys error: ' . $e->getMessage());
        $ok = false;
    }
    // Redirect kembali ke halaman asal (integrations atau notifications)
    $allowedPaths = ['/admin/notifications', '/admin/integrations'];
    $redirectBack = trim($_POST['redirect_back'] ?? '');
    $redirectPath = '/admin/notifications';
    if ($redirectBack !== '') {
        $parsedPath = parse_url($redirectBack, PHP_URL_PATH) ?: '';
        if (in_array($parsedPath, $allowedPaths, true)) {
            $redirectPath = $redirectBack;
        }
    }
    saveResult($ok, $ok ? 'Pengaturan berhasil disimpan.' : 'Gagal menyimpan pengaturan.', $redirectPath);
}

// ─── Mode: Test Kirim ────────────────────────────────────────
if ($mode === 'test_send') {
    require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
    require_once __DIR__ . '/../../notifications/adapters/MailketingAdapter.php';
    require_once __DIR__ . '/../../notifications/adapters/FonteAdapter.php';

    $testProvider  = trim($_POST['test_provider']  ?? '');
    $testRecipient = trim($_POST['test_recipient'] ?? '');

    if (empty($testRecipient)) {
        flash('error', 'Masukkan alamat penerima untuk test.', 'error');
        redirect('/admin/notifications');
    }

    $adapter = match ($testProvider) {
        'mailketing' => new MailketingAdapter(),
        'fonte'      => new FonteAdapter(),
        default      => null,
    };

    if (!$adapter) {
        flash('error', 'Provider tidak valid.', 'error');
        redirect('/admin/notifications');
    }

    $result = $adapter->send(
        $testRecipient,
        '[TEST] Notifikasi Averion',
        'Ini adalah pesan test dari Averion. Jika kamu menerima pesan ini, konfigurasi notifikasi sudah berhasil.'
    );

    try {
        getDB()->prepare(
            "INSERT INTO notification_logs (event_key, channel, provider, recipient, status, response, created_at)
             VALUES ('test_send', ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $testProvider === 'mailketing' ? 'email' : 'whatsapp',
            $testProvider,
            $testRecipient,
            $result['success'] ? 'sent' : 'failed',
            $result['message'],
        ]);
    } catch (Exception $e) {
        error_log('Test send log error: ' . $e->getMessage());
    }

    if ($result['success']) {
        flash('success', 'Test berhasil: ' . $result['message']);
    } else {
        flash('error', 'Test gagal: ' . $result['message'], 'error');
    }
    redirect('/admin/notifications');
}

// ─── Mode: Hapus Template ────────────────────────────────────
if ($mode === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        flash('error', 'ID tidak valid.', 'error');
        redirect('/admin/notifications');
    }
    try {
        $stmt = getDB()->prepare("DELETE FROM notification_templates WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            flash('success', 'Template berhasil dihapus.');
        } else {
            flash('error', 'Template tidak ditemukan.', 'error');
        }
    } catch (Exception $e) {
        error_log('Delete notification template error: ' . $e->getMessage());
        flash('error', 'Gagal menghapus template.', 'error');
    }
    redirect('/admin/notifications');
}

// ─── Mode: Simpan Template (default) ─────────────────────────
$id        = (int) ($_POST['id'] ?? 0);
$eventKey  = trim($_POST['event_key'] ?? '');
$channel   = trim($_POST['channel']   ?? '');
$provider  = trim($_POST['provider']  ?? '');
$subject   = trim($_POST['subject']   ?? '');
$body      = trim($_POST['body']      ?? '');
$isActive  = (int) (!empty($_POST['is_active']));

// Harus sinkron dengan $validEvents di pages/admin/notifications.php — kalau sebuah
// event dipicu kode tapi tak terdaftar di sini, templatenya tampil di panel TAPI
// gagal disimpan ("Event tidak valid"). Itu terjadi pada 6 event booking/waitlist/
// renewal sejak fiturnya dirilis. Tambah event baru = tambah di KEDUA file.
$validEventKeys = [
    'register', 'order_created', 'payment_success', 'access_activated',
    'order_cancelled',
    'public_checkout_welcome', 'access_link', 'abandoned_cart', 'abandoned_cart_2', 'abandoned_cart_3',
    'commission_approved', 'payout_processing', 'payout_paid', 'payout_rejected',
    'lead_captured', 'lead_admin_notify', 'payment_admin_notify',
    'renewal_reminder', 'renewal_expired', 'license_fulfillment_failed',
    'booking_confirmed', 'settlement_reminder', 'booking_expired',
    'waitlist_joined', 'waitlist_launch',
];
$validChannels  = ['email', 'whatsapp', 'telegram'];
$validProviders = ['mailketing', 'fonte', 'onesender', 'starsender', 'dripsender', 'kirimdev', 'averionrelay', 'telegram'];

$errors = [];
if (!in_array($eventKey, $validEventKeys, true)) $errors[] = 'Event tidak valid.';
if (!in_array($channel,  $validChannels,  true)) $errors[] = 'Channel tidak valid.';
if (!in_array($provider, $validProviders, true)) $errors[] = 'Provider tidak valid.';
if (empty($body))                                 $errors[] = 'Isi pesan tidak boleh kosong.';

if (!empty($errors)) {
    flash('error', implode(' ', $errors), 'error');
    redirect('/admin/notifications');
}

try {
    $pdo = getDB();

    if ($id > 0) {
        $pdo->prepare(
            "UPDATE notification_templates
             SET event_key = ?, channel = ?, provider = ?, subject = ?, body = ?, is_active = ?, updated_at = NOW()
             WHERE id = ?"
        )->execute([$eventKey, $channel, $provider, $subject ?: null, $body, $isActive, $id]);
        flash('success', 'Template berhasil diperbarui.');
    } else {
        $pdo->prepare(
            "INSERT INTO notification_templates (event_key, channel, provider, subject, body, is_active)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               provider = VALUES(provider), subject = VALUES(subject),
               body = VALUES(body), is_active = VALUES(is_active), updated_at = NOW()"
        )->execute([$eventKey, $channel, $provider, $subject ?: null, $body, $isActive]);
        flash('success', 'Template berhasil disimpan.');
    }

} catch (Exception $e) {
    error_log('Save notification template error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan template.', 'error');
}

redirect('/admin/notifications');

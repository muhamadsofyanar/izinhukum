<?php

require_once __DIR__ . '/adapters/MailketingAdapter.php';
require_once __DIR__ . '/adapters/FonteAdapter.php';
require_once __DIR__ . '/adapters/OneSenderAdapter.php';
require_once __DIR__ . '/adapters/StarSenderAdapter.php';
require_once __DIR__ . '/adapters/DripSenderAdapter.php';
require_once __DIR__ . '/adapters/KirimdevAdapter.php';
require_once __DIR__ . '/adapters/AverionRelayAdapter.php';
require_once __DIR__ . '/adapters/TelegramAdapter.php';

class NotificationDispatcher {

    /**
     * Event transaksional yg didedup per order (via {{order_number}}). Tiap order
     * hanya menerima notif ini SEKALI per channel+penerima. Event marketing/berulang
     * (broadcast, email_sequence, abandoned_cart, renewal_reminder, dst) SENGAJA tak
     * masuk daftar ini karena memang boleh terkirim berkali-kali.
     */
    public const DEDUP_EVENTS = [
        'order_created', 'payment_success', 'access_activated',
        'payment_admin_notify', 'order_failed_admin', 'affiliate_new_order', 'register',
    ];

    /**
     * Trigger notifikasi berdasarkan event_key.
     * Cari semua template aktif untuk event ini, render placeholder, kirim via adapter yang sesuai.
     */
    public static function trigger(
        string $eventKey,
        array  $data,
        string $recipientEmail = '',
        string $recipientPhone = '',
        string $onlyChannel = '',   // '' = semua channel; 'email'|'whatsapp'|'telegram' = kirim 1 channel saja (Kirim Ulang manual)
        bool   $force = false        // true = lewati guard dedup (Kirim Ulang manual oleh admin)
    ): void {
        try {
            // Inject variabel global ke semua event supaya template bisa pakai
            // {{app_name}}, {{brand_color}}, {{app_url}} tanpa harus dikirim manual
            // tiap pemanggilan. Data spesifik event tetap menang (override global).
            $globalData = [
                'app_name'    => getSetting('app_name', 'STIFIn Mulia'),
                'brand_color' => getSetting('brand_color', '#6366f1'),
                'app_url'     => rtrim((string) (defined('APP_URL') ? APP_URL : getSetting('app_url', '')), '/'),
            ];
            $data = array_merge($globalData, $data);

            $pdo  = getDB();
            $stmt = $pdo->prepare(
                "SELECT * FROM notification_templates WHERE event_key = ? AND is_active = 1"
            );
            $stmt->execute([$eventKey]);
            $templates = $stmt->fetchAll();

            foreach ($templates as $template) {
                if ($template['channel'] === 'whatsapp' && !$force
                    && in_array($eventKey, ['order_created', 'public_checkout_welcome'], true)
                    && getSetting('order_confirmation_whatsapp', '') !== '') {
                    continue;
                }
                // Filter 1 channel (Kirim Ulang manual: admin pilih WhatsApp / Email).
                if ($onlyChannel !== '' && $template['channel'] !== $onlyChannel) continue;

                // Recipient per channel. Telegram = channel INTERNAL admin: tujuannya
                // chat_id dari setting (bukan per-customer), digerbangi telegram_enabled
                // agar aman saat bot belum dikonfigurasi. Email → email, sisanya → phone.
                if ($template['channel'] === 'email') {
                    $recipient = $recipientEmail;
                } elseif ($template['channel'] === 'telegram') {
                    if (getSetting('telegram_enabled', '0') !== '1') continue;
                    $recipient = getSetting('telegram_chat_id', '');
                } else {
                    $recipient = $recipientPhone;
                }
                if (empty($recipient)) continue;

                $providerName = $template['provider'] ?? '';

                // WhatsApp: hormati provider pilihan template SELAMA provider itu aktif.
                // Bila provider template dinonaktifkan admin, fallback otomatis ke
                // provider WA lain yang sedang AKTIF — supaya notifikasi tetap terkirim
                // mengikuti provider yang diaktifkan admin. Dengan begini admin bebas
                // mengganti provider WA di Pengaturan tanpa harus mengedit tiap template.
                // (Email/mailketing tidak punya toggle _enabled, jadi dilewati cek ini.)
                if ($template['channel'] === 'whatsapp') {
                    // effectiveWaProvider(): provider template menang bila menyala,
                    // selain itu fallback. Sama persis dgn perilaku sebelumnya, tapi
                    // kini SATU fungsi yang juga dibaca halaman Integrasi.
                    $activeProvider = self::effectiveWaProvider($providerName);
                    if ($activeProvider === '') {
                        // Jalur belum dikonfigurasi. Tidak ada pengiriman untuk dicoba ulang.
                        continue;
                    }
                    if ($activeProvider !== $providerName) {
                        error_log("NotificationDispatcher: provider template '{$providerName}' nonaktif, fallback ke '{$activeProvider}' untuk event {$eventKey}.");
                        $providerName = $activeProvider;
                    }
                }

                // ── Idempotensi anti-notif-dobel ────────────────────────────────
                // Satu order hanya boleh menerima tiap notif transaksional SEKALI per
                // channel+penerima. Race webhook (2 callback gateway nyaris bersamaan
                // lolos guard status), callback ganda berbeda-key, atau submit ganda
                // bisa memicu event yg sama 2-4x → buyer dapat WA/email dobel. Guard di
                // sini = SATU chokepoint (semua jalur bayar lewat dispatcher).
                // Dedup key = order_number. Hanya berlaku utk event transaksional; event
                // marketing/berulang (broadcast/sequence/reminder) TIDAK didedup.
                // Hanya blokir bila sudah pernah 'sent' → retry setelah GAGAL tetap boleh.
                // Fail-open: bila kolom dedup_key belum ada (pra-migrasi) → kirim normal.
                $dedupKey = trim((string) ($data['order_number'] ?? ''));
                if (!$force && $dedupKey !== '' && in_array($eventKey, self::DEDUP_EVENTS, true)) {
                    try {
                        $dchk = $pdo->prepare(
                            "SELECT 1 FROM notification_logs
                             WHERE event_key = ? AND channel = ? AND recipient = ?
                               AND dedup_key = ? AND status = 'sent' LIMIT 1"
                        );
                        $dchk->execute([$eventKey, $template['channel'], $recipient, $dedupKey]);
                        if ($dchk->fetchColumn()) {
                            error_log("NotificationDispatcher: skip notif DOBEL {$eventKey}/{$template['channel']} → {$recipient} (order {$dedupKey})");
                            continue;
                        }
                    } catch (Throwable $dupEx) {
                        // kolom dedup_key belum ada → lewati dedup, kirim normal
                    }
                }

                $subject = self::renderPlaceholders($template['subject'] ?? '', $data, $template['channel']);
                $body    = self::renderPlaceholders($template['body']    ?? '', $data, $template['channel']);

                // Bungkus email dengan layout premium HANYA bila body berupa fragment.
                // Template yang sudah menyertakan dokumen HTML lengkap (<!doctype>/<html>)
                // dikirim apa adanya agar tidak ter-wrap ganda (HTML bersarang → email rusak).
                // WhatsApp tetap teks polos.
                if ($template['channel'] === 'email' && function_exists('emailLayout')
                    && !self::isFullHtmlDocument($body)) {
                    $variant = match ($eventKey) {
                        'register'                          => 'welcome',
                        'order_created'                     => 'info',
                        'payment_success', 'access_activated' => 'success',
                        default                             => 'success',
                    };
                    $body = emailLayout($subject, $body, $variant);
                }

                $adapter = self::getAdapter($providerName);
                if (!$adapter) continue;

                $result = $adapter->send($recipient, $subject, $body);

                $ok   = !empty($result['success']);
                $resp = is_array($result['raw_response'])
                    ? json_encode($result['raw_response'])
                    : ($result['raw_response'] ?? $result['message']);

                // Payload (subject+body) disimpan HANYA saat gagal — dipakai tombol
                // "Kirim Ulang" di /admin/system-health, lalu dikosongkan setelah sukses.
                try {
                    $pdo->prepare(
                        "INSERT INTO notification_logs
                         (event_key, channel, provider, recipient, dedup_key, status, response, subject, body, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                    )->execute([
                        $eventKey,
                        $template['channel'],
                        $providerName, // provider yang BENAR-BENAR dipakai (termasuk hasil fallback)
                        $recipient,
                        ($dedupKey !== '' && in_array($eventKey, self::DEDUP_EVENTS, true)) ? $dedupKey : null,
                        $ok ? 'sent' : 'failed',
                        $resp,
                        $ok ? null : mb_substr($subject, 0, 255),
                        $ok ? null : $body,
                    ]);
                } catch (Exception $logEx) {
                    // Kolom subject/body belum ada (pra-migrasi 1.1.50) — log format lama.
                    $pdo->prepare(
                        "INSERT INTO notification_logs
                         (event_key, channel, provider, recipient, status, response, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, NOW())"
                    )->execute([
                        $eventKey, $template['channel'], $providerName, $recipient,
                        $ok ? 'sent' : 'failed', $resp,
                    ]);
                }
            }
        } catch (Exception $e) {
            error_log('NotificationDispatcher error: ' . $e->getMessage());
        }
    }

    public static function getAdapterPublic(string $provider): ?NotificationAdapter {
        return self::getAdapter($provider);
    }

    /**
     * Urutan prioritas provider WhatsApp saat FALLBACK (provider template mati).
     * SATU sumber kebenaran — halaman Integrasi ikut membacanya lewat
     * effectiveWaProvider() supaya layar & pengirim tak pernah beda pendapat.
     * Catatan: 'averionrelay' paling buntut, jadi ia hanya menang bila tak ada
     * provider lain yang menyala.
     */
    public const WA_PROVIDER_PRIORITY = ['fonte', 'onesender', 'starsender', 'dripsender', 'kirimdev', 'averionrelay'];

    /**
     * Provider WhatsApp pertama yang sedang diaktifkan admin (toggle *_enabled = '1').
     * Mengembalikan '' bila tidak ada satupun yang aktif.
     */
    public static function resolveActiveWaProvider(): string {
        foreach (self::WA_PROVIDER_PRIORITY as $wp) {
            if (getSetting($wp . '_enabled', '0') === '1') {
                return $wp;
            }
        }
        return '';
    }

    /**
     * Provider yang BENAR-BENAR akan mengirim untuk sebuah template WhatsApp.
     * Aturannya: provider pilihan template menang SELAMA ia menyala; kalau mati,
     * jatuh ke provider aktif pertama (fallback). '' = tak ada provider aktif.
     *
     * Dipakai bersama oleh trigger() DAN halaman Admin > Integrasi. Ini penting:
     * menyalakan toggle sebuah provider TIDAK otomatis membuatnya dipakai — kalau
     * template masih menunjuk provider lain yang juga menyala, yang lama tetap
     * menang. Tanpa satu fungsi bersama, indikator di layar gampang berbohong.
     */
    public static function effectiveWaProvider(string $templateProvider): string {
        if ($templateProvider !== '' && getSetting($templateProvider . '_enabled', '0') === '1') {
            return $templateProvider;
        }
        return self::resolveActiveWaProvider();
    }

    private static function getAdapter(string $provider): ?NotificationAdapter {
        return match ($provider) {
            'mailketing' => new MailketingAdapter(),
            'fonte'      => new FonteAdapter(),
            'onesender'  => new OneSenderAdapter(),
            'starsender' => new StarSenderAdapter(),
            'dripsender' => new DripSenderAdapter(),
            'kirimdev'   => new KirimdevAdapter(),
            'averionrelay' => new AverionRelayAdapter(),
            'telegram'   => new TelegramAdapter(),
            default      => null,
        };
    }

    /**
     * Deteksi apakah body sudah berupa dokumen HTML lengkap (punya <!doctype> atau
     * tag <html>). Bila ya, jangan dibungkus emailLayout() lagi.
     */
    private static function isFullHtmlDocument(string $body): bool {
        $head = ltrim($body);
        return stripos($head, '<!doctype') === 0 || stripos($head, '<html') === 0;
    }

    private static function renderPlaceholders(string $template, array $data, string $channel = 'email'): string {
        // WhatsApp = teks polos, JANGAN di-htmlspecialchars (dulu semua channel
        // di-escape lewat e() — merusak karakter seperti &, ', " di nama/catatan
        // buyer pada pesan WA karena tak pernah di-decode balik oleh adapter).
        foreach ($data as $key => $value) {
            $value    = (string) $value;
            $template = str_replace('{{' . $key . '}}', $channel === 'email' ? e($value) : $value, $template);
        }
        // Placeholder yang tak ter-passing (typo nama key / lupa di-isi) jangan
        // sampai tampil mentah "{{xxx}}" ke buyer — buang sisa yang tak ter-replace.
        return preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $template);
    }
}

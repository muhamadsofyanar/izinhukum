<?php
// ─── Order Core (jantung: order lifecycle + akses) ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/**
 * Nomor invoice: INV<tanggal><8 karakter acak>.
 *
 * Dulu pakai `uniqid()` = turunan microtime, bukan acak → bisa ditebak/diurutkan
 * (nomor order tampil di URL invoice) dan dua order pada mikrodetik yang sama
 * menghasilkan nomor kembar. Sekarang random_bytes (CSPRNG).
 *
 * Kenapa 8 karakter, bukan 6: prefiks tanggal me-reset ruang nomor tiap hari, jadi
 * yang berlaku adalah batas ulang tahun terhadap order PER HARI. 36^6 ≈ 2,2 miliar
 * → pada 10.000 order/hari peluang kembar ~2% PER HARI, dan karena `uk_order_number`
 * itu UNIQUE, kembar = INSERT gagal = checkout pembeli error. 36^8 ≈ 2,8 triliun
 * menurunkannya ke ~0,002% pada volume yang sama. Kolom varchar(50) — muat.
 */
function generateOrderNumber(): string
{
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $suffix   = '';
    // random_int (bukan ord(random_bytes) % 36) — modulo 256 ke 36 tidak habis
    // dibagi → sebagian karakter sedikit lebih sering muncul (bias modulo).
    for ($i = 0; $i < 8; $i++) {
        $suffix .= $alphabet[random_int(0, 35)];
    }
    return 'INV' . date('Ymd') . $suffix;
}

function activateAccess(int $userId, int $productId, int $orderId, ?string $startAt = null): bool
{
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT access_duration_days FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            error_log("activateAccess: produk ID $productId tidak ditemukan.");
            return false;
        }

        // Produk berjangka? (0/kosong disimpan NULL oleh save-product = sekali bayar)
        $days = $product['access_duration_days'] !== null ? (int) $product['access_duration_days'] : null;

        // Basis waktu mulai akses: NOW() secara default (perilaku lama, dipakai SEMUA
        // caller — checkout/webhook/bundle/dst). $startAt HANYA dipakai admin (Input
        // Transaksi Manual) utk migrasi langganan yg sudah berjalan sebelumnya (mis. dari
        // Sejoli) — started_at & expires_at dihitung dari tanggal riil itu, bukan hari ini,
        // supaya drip content & reminder perpanjangan (processRenewalReminders) akurat.
        $startTs = $startAt !== null && $startAt !== '' ? strtotime($startAt) : false;
        $basis   = ($startTs !== false && $startTs > 0) ? date('Y-m-d H:i:s', $startTs) : date('Y-m-d H:i:s');

        $expiresAt = $days !== null ? date('Y-m-d H:i:s', strtotime("+{$days} days", strtotime($basis))) : null;

        // PERPANJANGAN (baris sudah ada) ditangani di SQL, bukan PHP:
        //
        // 1. expires_at MENUMPUK dari tanggal habis (GREATEST(expires_at, NOW())),
        //    bukan dihitung ulang dari NOW(). Dulu `VALUES(expires_at)` menimpa
        //    dengan NOW()+durasi → pembeli yang menurut pada reminder H-3 lalu
        //    perpanjang JUSTRU kehilangan sisa hari yang sudah dia bayar. Rumus ini
        //    disamakan dengan tombol Perpanjang admin (manage-subscription.php).
        // 2. started_at DIPERTAHANKAN selama akses lama masih hidup — drip
        //    (unlock_after_days) menghitung dari started_at, jadi me-resetnya bikin
        //    materi yang sudah terbuka TERKUNCI LAGI tiap kali member perpanjang.
        //    Kalau akses sudah telanjur mati/dicabut, mulai siklus baru dari NOW().
        //
        // URUTAN PENTING: di ON DUPLICATE KEY UPDATE, assignment dievaluasi kiri→kanan
        // dan referensi kolom setelahnya membaca nilai BARU. Jadi started_at (yang
        // membaca status & expires_at LAMA) wajib diassign SEBELUM keduanya ditimpa.
        $stmt = $pdo->prepare('
            INSERT INTO member_access (user_id, product_id, order_id, status, started_at, expires_at)
            VALUES (?, ?, ?, \'active\', ?, ?)
            ON DUPLICATE KEY UPDATE
                started_at = CASE
                    WHEN status = \'active\' AND (expires_at IS NULL OR expires_at >= NOW())
                        THEN started_at
                    ELSE NOW()
                END,
                expires_at = CASE
                    WHEN ? IS NULL THEN NULL
                    ELSE DATE_ADD(GREATEST(COALESCE(expires_at, NOW()), NOW()), INTERVAL ? DAY)
                END,
                status               = \'active\',
                renewal_remind_stage = 0
        ');
        $stmt->execute([$userId, $productId, $orderId, $basis, $expiresAt, $days, $days]);

        // Waitlist: tandai pendaftar yang akhirnya membeli sebagai 'converted'
        // (metrik konversi waitlist per launch). Best effort.
        markWaitlistConverted($userId, $productId);

        return true;
    } catch (Exception $e) {
        error_log('activateAccess error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Waitlist: tandai entri milik user ini (match user_id / email / WA) sebagai
 * converted saat akses produk aktif. Best effort — aman bila tabel belum ada.
 */
function markWaitlistConverted(int $userId, int $productId): void
{
    try {
        $pdo = getDB();
        $u = $pdo->prepare('SELECT email, phone FROM users WHERE id = ? LIMIT 1');
        $u->execute([$userId]);
        $usr = $u->fetch();
        if (!$usr) return;

        $params = [$productId, $userId];
        $cond   = 'user_id = ?';
        $email  = trim((string) ($usr['email'] ?? ''));
        if ($email !== '') { $cond .= ' OR email = ?'; $params[] = $email; }
        $wa = function_exists('lead_normalize_wa') ? lead_normalize_wa((string) ($usr['phone'] ?? '')) : '';
        if ($wa !== '') { $cond .= ' OR whatsapp = ?'; $params[] = $wa; }

        $pdo->prepare("UPDATE product_waitlist SET status = 'converted'
                       WHERE product_id = ? AND ($cond) AND status <> 'converted'")
            ->execute($params);
    } catch (Throwable $e) {
        // Tabel belum ada (pra-migrasi) / error lain — jangan ganggu aktivasi akses.
    }
}

/**
 * ─── Jalur PAID terpusat ─────────────────────────────────────────────────
 *
 * Satu-satunya tempat logika "order berubah jadi paid": aktivasi akses
 * (main + bundle + bump, dengan guard Booking Fee), enrol email sequence,
 * terbit lisensi, approve komisi afiliasi, dan update membership level.
 * Dipakai oleh 3 webhook gateway (Xendit/Duitku/Moota), update-transaction,
 * dan confirm-payment — fitur pembayaran baru cukup diubah di sini.
 *
 * Panggil DI DALAM database transaction milik caller, SETELAH caller
 * meng-update orders.status='paid' + payments. Commit/rollback tetap
 * tanggung jawab caller. $order wajib hasil SELECT * (butuh order_stage,
 * referral_code, total, order_number, user_id).
 *
 * $opts:
 *   - commission_fallback (bool, default false): bila order ber-referral tapi
 *     belum punya baris komisi sama sekali, buat baris 'approved' baru.
 *     Dipakai jalur webhook; jalur admin hanya approve yang 'pending'.
 *
 * Return: context untuk onOrderPaidPostCommit() — ['user','items','main_product_id','is_booking'].
 */
function onOrderPaid(PDO $pdo, array $order, array $opts = []): array
{
    $orderId   = (int) $order['id'];
    $userId    = (int) $order['user_id'];
    // Booking Fee (DP): order stage='booking' TIDAK memberi akses — akses baru
    // terbuka saat order pelunasan (settlement) paid. Order pelunasan dibuat
    // otomatis oleh processBookingOrders() (cron/Auto-Cron).
    $isBooking = (($order['order_stage'] ?? 'full') === 'booking');

    $userStmt = $pdo->prepare('SELECT name, email, phone FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch() ?: null;

    $itemStmt = $pdo->prepare(
        'SELECT product_id, product_name, is_bump, COALESCE(is_bundle, 0) AS is_bundle, bundle_id
         FROM order_items WHERE order_id = ? ORDER BY id'
    );
    $itemStmt->execute([$orderId]);
    $items = $itemStmt->fetchAll();

    $mainProductId = 0;
    foreach ($items as $it) {
        if ((int) $it['is_bump'] === 0 && (int) $it['is_bundle'] === 0) {
            $mainProductId = (int) $it['product_id'];
            break;
        }
    }
    // Main product ASLI (tanpa fallback) — dipakai counter dime agar tak salah hitung
    // untuk order bundle-only/bump-only.
    $dimeMainId = $mainProductId;
    if ($mainProductId === 0 && $items) {
        $mainProductId = (int) $items[0]['product_id'];
    }

    $instant = false; // akses instan saat DP (di-set di cabang booking bila produk mengizinkan)
    if (!$isBooking) {
        if ((float) ($order['total'] ?? 0) > 0) {
            stifinQueuePaidVouchers($pdo, $orderId, $userId);
        }
        // Dime: naikkan counter penjualan main product SEBELUM activateAccess (cek renewal).
        if ($dimeMainId) bumpDimeCounter($pdo, $userId, $dimeMainId);
        foreach ($items as $it) {
            $pid = (int) $it['product_id'];
            activateAccess($userId, $pid, $orderId);
            issueLicense($orderId, $pid, $userId); // no-op utk produk non-lisensi
            // Produk berlisensi VENDOR: antrekan pemesanan lisensi ke server vendor.
            // Diantre DI DALAM transaksi ini (bukan dipanggil langsung) → order lunas
            // & antreannya tercatat bersama; vendor down tak menggagalkan checkout.
            // no-op bila integrasi mati / produk tak dipetakan.
            vfEnqueue($pdo, $order, $pid, $userId);
            // Paket Agency SEO (vendor-only): catat kuota 50 slot. Idempoten + fail-soft;
            // no-op bila bukan produk paket agency / bukan instalasi vendor.
            agencySeoProvisionOnPaid($pdo, $order, $pid, $userId);
            enrollUserToSequences('order_paid', $userId, $pid, (string) ($order['order_number'] ?? ''));
        }
    } elseif ($mainProductId) {
        // Booking DP. Opsi akses instan (opt-in per produk): buka area member meski
        // baru DP. Lisensi/vendor SENGAJA tak diterbitkan di sini — hanya saat lunas
        // (agar kunci tak diserahkan atas DP). Akses ini PROVISIONAL: dicabut bila
        // booking hangus (processBookingOrders()).
        try {
            $iaStmt = $pdo->prepare("SELECT booking_instant_access FROM products WHERE id = ? LIMIT 1");
            $iaStmt->execute([$mainProductId]);
            $instant = ((int) $iaStmt->fetchColumn() === 1);
        } catch (Throwable $e) { $instant = false; }
        if ($instant) {
            activateAccess($userId, $mainProductId, $orderId);
            enrollUserToSequences('order_paid', $userId, $mainProductId, (string) ($order['order_number'] ?? ''));
        }
        // DP dibayar → follow-up menuju pelunasan.
        enrollUserToSequences('booking_paid', $userId, $mainProductId, (string) ($order['order_number'] ?? ''));
    }

    // Komisi afiliasi: approve baris 'pending' (dibuat saat checkout) + tambah
    // total_earned. Booking tetap berkomisi (DP berkomisi di checkout, sisanya
    // via processBookingOrders) — sengaja TANPA guard stage.
    // $affiliateNotify: diisi saat penjualan via link afiliasi benar-benar
    // menghasilkan komisi (baru diapprove / baru dibuat). Dikirim SETELAH commit
    // oleh onOrderPaidPostCommit sebagai notif 'affiliate_new_order' ke afiliasi.
    $affiliateNotify = null;
    if (!empty($order['referral_code'])) {
        $affStmt = $pdo->prepare('SELECT id, user_id FROM affiliates WHERE code = ? LIMIT 1');
        $affStmt->execute([$order['referral_code']]);
        $affiliate = $affStmt->fetch();

        if ($affiliate) {
            $pendStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE order_id = ? AND status = 'pending'");
            $pendStmt->execute([$orderId]);
            $pendingTotal = (float) $pendStmt->fetchColumn();

            if ($pendingTotal > 0) {
                $pdo->prepare("UPDATE commissions SET status = 'approved', approved_at = NOW() WHERE order_id = ? AND status = 'pending'")
                    ->execute([$orderId]);
                $pdo->prepare('UPDATE affiliates SET total_earned = total_earned + ? WHERE id = ?')
                    ->execute([$pendingTotal, $affiliate['id']]);
                $affiliateNotify = ['user_id' => (int) $affiliate['user_id'], 'amount' => $pendingTotal];
            } elseif (!empty($opts['commission_fallback'])) {
                $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM commissions WHERE order_id = ?');
                $cntStmt->execute([$orderId]);
                if ((int) $cntStmt->fetchColumn() === 0 && $mainProductId
                    && affiliateEligibleUser((int) $affiliate['user_id'])) {
                    $rate = function_exists('resolveCommissionRate')
                        ? resolveCommissionRate((int) $affiliate['user_id'], $mainProductId) : 10;
                    $commissionAmount = round(((float) $order['total'] * $rate) / 100, 2);
                    if ($commissionAmount > 0) {
                        $pdo->prepare("INSERT INTO commissions (affiliate_id, order_id, amount, rate, status, approved_at) VALUES (?, ?, ?, ?, 'approved', NOW())")
                            ->execute([$affiliate['id'], $orderId, $commissionAmount, $rate]);
                        $pdo->prepare('UPDATE affiliates SET total_earned = total_earned + ? WHERE id = ?')
                            ->execute([$commissionAmount, $affiliate['id']]);
                        $affiliateNotify = ['user_id' => (int) $affiliate['user_id'], 'amount' => $commissionAmount];
                    }
                }
            }
        }
    }

    // Konversi referral: tandai relasi referral pembeli ini jadi 'purchased' saat order
    // lunas (relasi dibuat saat pendaftaran via link afiliasi, status 'registered').
    // Idempoten (hanya naik dari 'registered' → tak menimpa yang sudah purchased), fail-soft.
    // Inilah yang mengisi metrik Konversi di Leaderboard admin & label "Bertransaksi" di
    // dashboard afiliat — sebelumnya tak pernah di-set sehingga konversi selalu 0.
    if ($userId > 0) {
        try {
            $pdo->prepare("UPDATE referrals SET status = 'purchased', order_id = ? WHERE referred_user_id = ? AND status = 'registered'")
                ->execute([$orderId, $userId]);
        } catch (Throwable $e) {
            error_log('onOrderPaid referral convert: ' . $e->getMessage());
        }
    }

    // JV Partner: catat jatah bagi hasil (setelah blok komisi agar SUM komisi final).
    // Sengaja TANPA guard booking — bagi hasil atas tiap uang masuk (DP + pelunasan).
    if ($dimeMainId) jvRecordEarning($pdo, $order, $dimeMainId);

    if (function_exists('updateUserMembershipLevel')) {
        updateUserMembershipLevel($userId);
    }

    // Poin Reward: hadiah poin atas order LUNAS. Basis = nominal yang benar-benar
    // dibayar (orders.total), jadi order yang sudah dipotong poin tidak menghasilkan
    // poin penuh lagi. Idempoten (UNIQUE order+earn) → callback gateway berulang
    // tak bisa memberi poin dobel. Fail-soft: poin tak boleh menggagalkan order.
    if (function_exists('pointsAwardForOrder')) {
        try { pointsAwardForOrder($pdo, $order); }
        catch (Throwable $e) { error_log('onOrderPaid points: ' . $e->getMessage()); }
    }

    return [
        'user'            => $user,
        'items'           => $items,
        'main_product_id' => $mainProductId,
        'is_booking'      => $isBooking,
        'instant_access'  => $instant, // booking DP dgn akses instan → kirim notif access_activated
        'affiliate'       => $affiliateNotify, // ['user_id','amount'] bila komisi via link afiliasi
    ];
}

/**
 * Aksi post-commit jalur paid: CAPI tracking (opsional), notifikasi
 * payment_success, dan access_activated (opsional).
 * Panggil SETELAH $pdo->commit(), dengan context hasil onOrderPaid().
 * Kegagalan di sini tidak boleh (dan tidak bisa) membatalkan order paid.
 *
 * $opts:
 *   - capi (bool, default false): kirim Purchase server-side (Meta CAPI) — jalur webhook.
 *   - notify_access (bool, default false): kirim notif access_activated. Dipakai
 *     SEMUA jalur yang benar-benar membuka akses: admin (confirm/update/create) &
 *     webhook gateway. Dulu webhook tak mengirimnya → pembeli yang bayar via
 *     Duitku/Xendit/Moota tak pernah dapat notif "Akses Sudah Aktif", padahal
 *     pembeli produk gratis & yang dikonfirmasi admin dapat (templatenya pun
 *     aktif sejak seed) — asimetris, bukan disengaja.
 *     Otomatis di-skip untuk order booking (belum ada akses; booking_confirmed
 *     dikirim processBookingOrders bersama link pelunasan).
 */
function onOrderPaidPostCommit(array $order, array $ctx, array $opts = []): void
{
    if (!stifinVoucherTransferEnabled() && stifinVoucherProductId() > 0 && empty($ctx['is_booking'])) {
        try {
            stifinDistributeVouchers(getDB());
        } catch (Throwable $error) {
            error_log('[stifin-voucher] Distribusi tertunda. Jalankan ulang dari panel admin.');
        }
    }

    $orderNumber = (string) ($order['order_number'] ?? '');
    $user        = $ctx['user'] ?? null;

    if (!empty($opts['capi'])) {
        try {
            require_once dirname(__DIR__) . '/tracking/PixelHelper.php';
            PixelHelper::trackPurchaseServerSide([
                'email'        => $user['email'] ?? '',
                'total'        => $order['total'],
                'order_number' => $orderNumber,
            ]);
        } catch (Exception $e) {
            error_log('onOrderPaidPostCommit CAPI error: ' . $e->getMessage());
        }
    }

    // Mailketing: tambah pembeli ke list "Complete Order" (SEMUA jalur paid —
    // webhook gateway & konfirmasi admin). Fail-soft, di-antre → auto-cron.
    try {
        mailketing_enqueue_list('paid', (string) ($user['email'] ?? ''), (string) ($user['name'] ?? ''), (string) ($user['phone'] ?? ''));
    } catch (Throwable $e) {
        error_log('onOrderPaidPostCommit mailketing error: ' . $e->getMessage());
    }

    // Integrasi Webhook Keluar: antre event order.paid ke aplikasi eksternal
    // (grant akses per produk yg punya external_program_code). Fail-soft → auto-cron.
    try {
        webhookEnqueueOrderPaid(getDB(), $order, $ctx);
    } catch (Throwable $e) {
        error_log('onOrderPaidPostCommit webhook error: ' . $e->getMessage());
    }

    if ($user && file_exists(dirname(__DIR__) . '/notifications/NotificationDispatcher.php')) {
        try {
            require_once dirname(__DIR__) . '/notifications/NotificationDispatcher.php';
            $invoiceUrl = baseUrl('transactions/' . $orderNumber);
            $memberUrl  = baseUrl('member');

            // Nama produk utama (non-bump, non-bundle) — dipakai access_activated
            // & payment_admin_notify. Sengaja dihitung di luar blok notify_access
            // agar tak terduplikasi.
            $productName = '';
            foreach (($ctx['items'] ?? []) as $it) {
                if ((int) $it['is_bump'] === 0 && (int) $it['is_bundle'] === 0) {
                    $productName = (string) ($it['product_name'] ?? '');
                    break;
                }
            }
            // Order bundle: SEMUA order_items is_bundle=1 (tak ada produk utama
            // non-bundle) → pakai NAMA BUNDLE, bukan produk pertama dalam bundle.
            // Konsisten dengan notif order_created (process-bundle.php) yang memakai
            // "Bundle {nama}". Tanpa ini, {{product_name}} di access_activated (pembeli)
            // & payment_admin_notify (admin) keliru menyebut produk pertama saja.
            if ($productName === '') {
                $bundleId = 0;
                foreach (($ctx['items'] ?? []) as $it) {
                    if ((int) ($it['is_bundle'] ?? 0) === 1 && (int) ($it['bundle_id'] ?? 0) > 0) {
                        $bundleId = (int) $it['bundle_id'];
                        break;
                    }
                }
                if ($bundleId > 0) {
                    try {
                        $bStmt = getDB()->prepare('SELECT name FROM product_bundles WHERE id = ? LIMIT 1');
                        $bStmt->execute([$bundleId]);
                        $bName = (string) ($bStmt->fetchColumn() ?: '');
                        if ($bName !== '') $productName = 'Bundle ' . $bName;
                    } catch (Throwable $e) {
                        error_log('onOrderPaidPostCommit bundle name error: ' . $e->getMessage());
                    }
                }
            }
            if ($productName === '' && !empty($ctx['items'])) {
                $productName = (string) ($ctx['items'][0]['product_name'] ?? '');
            }

            NotificationDispatcher::trigger('payment_success', [
                'name'         => $user['name'] ?? '',
                'email'        => $user['email'] ?? '',
                'order_number' => $orderNumber,
                'total'        => function_exists('formatRupiah') ? formatRupiah((float) $order['total']) : $order['total'],
                'paid_at'      => date('d M Y, H:i'),
                'invoice_url'  => $invoiceUrl,
                'member_url'   => $memberUrl,
            ], $user['email'] ?? '', $user['phone'] ?? '');

            if (!empty($opts['notify_access']) && (empty($ctx['is_booking']) || !empty($ctx['instant_access']))) {
                NotificationDispatcher::trigger('access_activated', [
                    'name'         => $user['name'] ?? '',
                    'email'        => $user['email'] ?? '',
                    'product_name' => $productName,
                    'order_number' => $orderNumber,
                    'member_url'   => $memberUrl,
                    'invoice_url'  => $invoiceUrl,
                ], $user['email'] ?? '', $user['phone'] ?? '');

                // Magic-link akses instan (Segmen B flow checkout): pembeli berakun-lama
                // dapat link sekali-pakai ke email+WA → login otomatis ke area member
                // tanpa harus ingat password. Fail-soft, hanya role 'user'. Titik ini
                // mencakup SEMUA jalur paid async (webhook gateway) & konfirmasi admin.
                if (function_exists('dispatchAccessLink')) {
                    dispatchAccessLink((int) ($order['user_id'] ?? 0), (int) ($order['id'] ?? 0), (int) ($ctx['main_product_id'] ?? 0), $productName);
                }
            }

            $adminPhone = resolveAdminNotifyPhone();
            if ($adminPhone === '') {
                // Nomor WA admin kosong → channel WA payment_admin_notify dilewati
                // (recipient kosong). Telegram TIDAK terpengaruh: tujuannya chat_id
                // dari setting (di-resolve di dispatcher). Log agar tak "hilang tanpa jejak".
                error_log(
                    'payment_admin_notify: nomor WA admin kosong (isi lead_admin_wa di '
                    . '/admin/notifications atau nomor WA profil admin) — channel WA dilewati; '
                    . 'Telegram tetap terkirim bila aktif. Order: ' . $orderNumber
                );
            }
            // SELALU dipanggil: dispatcher meloop SEMUA template aktif event ini →
            // template WA terkirim bila $adminPhone terisi, template Telegram terkirim
            // bila telegram_enabled (chat_id dari setting). Jadi admin bisa memakai
            // Telegram sebagai kanal notif order tanpa harus punya nomor WA admin.
            // {{whatsapp}} & {{product_name}}: admin tahu SIAPA yang bayar & APA yang laku.
            NotificationDispatcher::trigger('payment_admin_notify', [
                'name'         => $user['name'] ?? '',
                'email'        => $user['email'] ?? '',
                'whatsapp'     => $user['phone'] ?? '',
                'product_name' => $productName,
                'order_number' => $orderNumber,
                'total'        => function_exists('formatRupiah') ? formatRupiah((float) $order['total']) : $order['total'],
                'paid_at'      => date('d M Y, H:i'),
                'invoice_url'  => $invoiceUrl,
            ], '', $adminPhone);

            // Notif ke AFFILIATE saat penjualan via link mereka menghasilkan komisi.
            // ctx['affiliate'] hanya terisi bila onOrderPaid benar-benar mengapprove/
            // membuat komisi (order Rp0 & non-afiliasi → null → dilewati).
            if (!empty($ctx['affiliate']['user_id'])) {
                $aStmt = getDB()->prepare('SELECT name, email, phone FROM users WHERE id = ? LIMIT 1');
                $aStmt->execute([(int) $ctx['affiliate']['user_id']]);
                $aff = $aStmt->fetch();
                if ($aff) {
                    $commAmt = (float) ($ctx['affiliate']['amount'] ?? 0);
                    NotificationDispatcher::trigger('affiliate_new_order', [
                        'name'          => $aff['name'] ?? '',
                        'amount'        => function_exists('formatRupiah') ? formatRupiah($commAmt) : $commAmt,
                        'order_number'  => $orderNumber,
                        'product_name'  => $productName,
                        'total'         => function_exists('formatRupiah') ? formatRupiah((float) $order['total']) : $order['total'],
                        'buyer_name'    => $user['name'] ?? '',
                        'affiliate_url' => baseUrl('affiliate'),
                    ], $aff['email'] ?? '', $aff['phone'] ?? '');
                }
            }
        } catch (Exception $e) {
            error_log('onOrderPaidPostCommit notif error: ' . $e->getMessage());
        }
    }
}

/**
 * Ubah status sebuah order dgn PIPELINE LENGKAP (dipakai bersama oleh
 * update-transaction.php [tunggal] & bulk-transaction.php [massal]) agar tak ada
 * drift logika di jalur uang. Mencakup: lock FOR UPDATE, update orders+payments,
 * jalur paid (onOrderPaid + notifikasi), jalur cancelled/failed (revoke akses +
 * reverse komisi belum-dibayar + notif order_cancelled utk 'cancelled'),
 * update membership level. Tiap pemanggilan = 1 transaksi DB sendiri.
 *
 * $order minimal berisi: id, user_id, status, order_number, total, notes.
 * Return: ['ok'=>bool, 'changed'=>bool, 'status'=>string, 'error'=>?string].
 */
function changeOrderStatus(PDO $pdo, array $order, string $newStatus, string $notes = ''): array
{
    $allowed = ['paid', 'pending', 'failed', 'cancelled'];
    if (!in_array($newStatus, $allowed, true)) {
        return ['ok' => false, 'changed' => false, 'status' => (string) ($order['status'] ?? ''), 'error' => 'status_invalid'];
    }
    if (($order['status'] ?? '') === $newStatus) {
        return ['ok' => true, 'changed' => false, 'status' => $newStatus, 'error' => null];
    }

    $paidCtx = null;
    try {
        $pdo->beginTransaction();

        // Re-baca status dgn FOR UPDATE — cegah double-process (race).
        $lock = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
        $lock->execute([(int) $order['id']]);
        $lockedStatus = $lock->fetchColumn();
        if ($lockedStatus === false) { $pdo->rollBack(); return ['ok'=>false,'changed'=>false,'status'=>'','error'=>'not_found']; }
        if ($lockedStatus === $newStatus) { $pdo->rollBack(); return ['ok'=>true,'changed'=>false,'status'=>$newStatus,'error'=>null]; }

        $paidAt      = ($newStatus === 'paid') ? date('Y-m-d H:i:s') : null;
        $notesUpdate = $notes !== '' ? $notes : ($order['notes'] ?? null);

        $pdo->prepare('UPDATE orders SET status = ?, paid_at = ?, notes = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$newStatus, $paidAt, $notesUpdate, (int) $order['id']]);

        if ($newStatus === 'paid') { $payStatus = 'success'; $payPaidAt = date('Y-m-d H:i:s'); }
        elseif (in_array($newStatus, ['failed', 'cancelled'], true)) { $payStatus = 'failed'; $payPaidAt = null; }
        else { $payStatus = 'pending'; $payPaidAt = null; }
        $pdo->prepare('UPDATE payments SET status = ?, paid_at = ?, updated_at = NOW() WHERE order_id = ?')
            ->execute([$payStatus, $payPaidAt, (int) $order['id']]);

        // Jalur paid terpusat (akses+lisensi+komisi+membership) — guard booking di dalam.
        if ($newStatus === 'paid') {
            $paidCtx = onOrderPaid($pdo, $order);
        }

        // Jalur cancelled/failed: revoke akses + reverse komisi belum-dibayar.
        if (in_array($newStatus, ['cancelled', 'failed'], true)) {
            $pdo->prepare("UPDATE member_access SET status = 'revoked' WHERE order_id = ?")->execute([(int) $order['id']]);
            $appr = $pdo->prepare("SELECT affiliate_id, SUM(amount) AS amt FROM commissions WHERE order_id = ? AND status = 'approved' GROUP BY affiliate_id");
            $appr->execute([(int) $order['id']]);
            foreach ($appr->fetchAll() as $rev) {
                $pdo->prepare("UPDATE affiliates SET total_earned = GREATEST(0, total_earned - ?) WHERE id = ?")
                    ->execute([(float) $rev['amt'], (int) $rev['affiliate_id']]);
            }
            $pdo->prepare("UPDATE commissions SET status = 'rejected' WHERE order_id = ? AND status IN ('pending','approved')")
                ->execute([(int) $order['id']]);
            // JV Partner: batalkan jatah bagi hasil yang BELUM dibayar (paid tak diutak-atik
            // — sudah ditransfer manual ke partner). Konsisten dgn reverse komisi affiliate.
            $pdo->prepare("UPDATE jv_earnings SET status = 'cancelled' WHERE order_id = ? AND status = 'unpaid'")
                ->execute([(int) $order['id']]);

            // Poin Reward: tarik kembali poin hadiah & KEMBALIKAN poin yang dipakai
            // sebagai potongan (member tak boleh kehilangan poin atas order batal).
            if (function_exists('pointsReverseForOrder')) {
                pointsReverseForOrder($pdo, $order);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('changeOrderStatus error (' . ($order['order_number'] ?? '?') . '): ' . $e->getMessage());
        return ['ok' => false, 'changed' => false, 'status' => (string) ($order['status'] ?? ''), 'error' => 'exception'];
    }

    // ── Post-commit (tak boleh menggagalkan perubahan yang sudah tersimpan) ──
    if (in_array($newStatus, ['cancelled', 'failed'], true)) {
        if (function_exists('updateUserMembershipLevel')) updateUserMembershipLevel((int) $order['user_id']);
    }
    if ($newStatus === 'paid' && $paidCtx !== null) {
        onOrderPaidPostCommit($order, $paidCtx, ['notify_access' => true]);
    }
    if ($newStatus === 'cancelled') {
        notifyOrderCancelled($pdo, $order);
    }

    // Alert INTERNAL admin (Telegram) saat order gagal/batal — Telegram Alerts Fase 1.
    // Fail-soft; digerbangi telegram_enabled + telegram_alert_order_failed (default ON).
    // Mencakup pembatalan manual admin & auto-expiry yang lewat jalur ini.
    if (in_array($newStatus, ['cancelled', 'failed'], true)
        && function_exists('notifyAdminTelegram')
        && getSetting('telegram_enabled', '0') === '1'
        && getSetting('telegram_alert_order_failed', '1') === '1') {
        try {
            $custName = ''; $prodName = '';
            try {
                $uq = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
                $uq->execute([(int) ($order['user_id'] ?? 0)]);
                $custName = (string) ($uq->fetchColumn() ?: '');
                $pq = $pdo->prepare('SELECT product_name FROM order_items WHERE order_id = ? ORDER BY id ASC LIMIT 1');
                $pq->execute([(int) $order['id']]);
                $prodName = (string) ($pq->fetchColumn() ?: '');
            } catch (Throwable $e) {}
            $totalFmt = function_exists('formatRupiah') ? formatRupiah((float) ($order['total'] ?? 0)) : (string) ($order['total'] ?? '');
            $reason   = $newStatus === 'failed' ? 'Pembayaran gagal' : 'Dibatalkan / kedaluwarsa';
            $msg = "\xE2\x9D\x8C ORDER GAGAL / BATAL\n\n"
                 . "No. Pesanan: " . ($order['order_number'] ?? '-') . "\n"
                 . "Customer: "    . ($custName !== '' ? $custName : '-') . "\n"
                 . "Produk: "      . ($prodName !== '' ? $prodName : '-') . "\n"
                 . "Total: "       . $totalFmt . "\n"
                 . "Status: "      . $reason . "\n"
                 . "Waktu: "       . date('d M Y, H:i') . " WIB";
            notifyAdminTelegram('order_failed_admin', $msg);
        } catch (Throwable $e) {
            error_log('changeOrderStatus telegram alert error: ' . $e->getMessage());
        }
    }

    return ['ok' => true, 'changed' => true, 'status' => $newStatus, 'error' => null];
}

/**
 * Notifikasi ke PEMBELI saat transaksinya DIBATALKAN (event order_cancelled).
 * Dipanggil SETELAH commit dari jalur pembatalan (admin update-transaction & auto-expiry).
 * Channel bergantung template aktif — secara default hanya WhatsApp yang di-seed.
 * Fail-soft: kegagalan notif tak boleh mengganggu proses pembatalan.
 *
 * Nama produk: item utama non-bump; untuk order bundle dipakai "Bundle {nama}"
 * (konsisten dgn notif lain — jalur bundle jangan tertinggal).
 */
/**
 * Kirim ULANG notifikasi transaksional ke pembeli untuk satu order, lewat 1
 * channel pilihan admin (whatsapp | email). Dipakai tombol "Kirim Ulang
 * Notifikasi" di detail transaksi admin.
 *
 * Scope aman: hanya order berstatus 'paid' → event payment_success (konfirmasi
 * bayar + akses). Pakai NotificationDispatcher::trigger dgn $onlyChannel + $force
 * (lewati dedup; ini kirim ulang manual yang disengaja). Return status terkirim
 * dibaca dari notification_logs baris terbaru hasil trigger.
 *
 * @return array{ok:bool,message:string,recipient?:string}
 */
function resendOrderNotification(string $orderNumber, string $channel): array
{
    $channel = strtolower(trim($channel));
    if (!in_array($channel, ['whatsapp', 'email'], true)) {
        return ['ok' => false, 'message' => 'Channel tidak valid.'];
    }

    $pdo = getDB();
    $st  = $pdo->prepare(
        'SELECT o.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone
         FROM orders o LEFT JOIN users u ON u.id = o.user_id
         WHERE o.order_number = ? LIMIT 1'
    );
    $st->execute([$orderNumber]);
    $order = $st->fetch();
    if (!$order) {
        return ['ok' => false, 'message' => 'Order tidak ditemukan.'];
    }
    // Event mengikuti status order: Lunas → payment_success (konfirmasi bayar +
    // akses); Menunggu Pembayaran → order_created (kirim ulang instruksi /
    // pengingat pembayaran ke pembeli).
    $status = (string) ($order['status'] ?? '');
    if ($status === 'paid') {
        $event = 'payment_success';
    } elseif ($status === 'pending') {
        $event = 'order_created';
    } else {
        return ['ok' => false, 'message' => 'Kirim ulang notifikasi hanya untuk order berstatus Lunas atau Menunggu Pembayaran.'];
    }

    $email     = trim((string) ($order['user_email'] ?? ''));
    $phone     = trim((string) ($order['user_phone'] ?? ''));
    $recipient = $channel === 'email' ? $email : $phone;
    if ($recipient === '') {
        return ['ok' => false, 'message' => $channel === 'email'
            ? 'Email pembeli kosong — tidak bisa kirim ulang via Email.'
            : 'Nomor WhatsApp pembeli kosong — tidak bisa kirim ulang via WhatsApp.'];
    }

    // Template channel+event ini harus aktif, kalau tidak beri pesan jelas (bukan diam).
    $tc = $pdo->prepare('SELECT COUNT(*) FROM notification_templates WHERE event_key = ? AND channel = ? AND is_active = 1');
    $tc->execute([$event, $channel]);
    if ((int) $tc->fetchColumn() === 0) {
        return ['ok' => false, 'message' => 'Template ' . ($channel === 'email' ? 'Email' : 'WhatsApp')
            . ' untuk notifikasi ini tidak aktif.'];
    }

    require_once dirname(__DIR__) . '/notifications/NotificationDispatcher.php';
    $invoiceUrl = baseUrl('transactions/' . $orderNumber);

    if ($event === 'payment_success') {
        $data = [
            'name'         => (string) ($order['user_name'] ?? ''),
            'email'        => $email,
            'order_number' => $orderNumber,
            'total'        => function_exists('formatRupiah') ? formatRupiah((float) $order['total']) : $order['total'],
            'paid_at'      => !empty($order['paid_at']) ? date('d M Y, H:i', strtotime((string) $order['paid_at'])) : date('d M Y, H:i'),
            'invoice_url'  => $invoiceUrl,
            'member_url'   => baseUrl('member'),
        ];
    } else {
        // order_created (pending): placeholder = name, product_name, total,
        // payment_method, invoice_url. Nama produk utama (non-bump/non-bundle).
        $pn = '';
        try {
            $pst = $pdo->prepare("SELECT product_name FROM order_items WHERE order_id = ? AND is_bump = 0 AND is_bundle = 0 ORDER BY id ASC LIMIT 1");
            $pst->execute([(int) $order['id']]);
            $pn = (string) ($pst->fetchColumn() ?: '');
            if ($pn === '') {
                $pst2 = $pdo->prepare("SELECT product_name FROM order_items WHERE order_id = ? ORDER BY id ASC LIMIT 1");
                $pst2->execute([(int) $order['id']]);
                $pn = (string) ($pst2->fetchColumn() ?: '');
            }
        } catch (Throwable $e) { /* biarkan kosong */ }

        // Label metode bayar — sama dengan mapping di checkout (process.php).
        $gateway  = strtolower((string) ($order['payment_gateway'] ?? ''));
        $payLabel = match ($gateway) {
            'moota'                                  => 'Transfer Bank (Otomatis)',
            'xendit', 'duitku', 'midtrans', 'tripay' => 'Pembayaran Online',
            'paypal'                                 => 'PayPal',
            default                                  => 'Transfer Manual',
        };

        $data = [
            'name'           => (string) ($order['user_name'] ?? ''),
            'email'          => $email,
            'order_number'   => $orderNumber,
            'product_name'   => $pn,
            'total'          => function_exists('formatRupiah') ? formatRupiah((float) $order['total']) : $order['total'],
            'payment_method' => $payLabel,
            'invoice_url'    => $invoiceUrl,
        ];
    }

    $beforeId = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM notification_logs')->fetchColumn();
    NotificationDispatcher::trigger($event, $data, $email, $phone, $channel, true);

    // Baca hasil dari log terbaru (dispatcher mencatat sent/failed per kirim).
    $lg = $pdo->prepare(
        'SELECT status, response FROM notification_logs
         WHERE id > ? AND event_key = ? AND channel = ? AND recipient = ?
         ORDER BY id DESC LIMIT 1'
    );
    $lg->execute([$beforeId, $event, $channel, $recipient]);
    $log = $lg->fetch();

    if (!$log) {
        return ['ok' => false, 'message' => 'Tidak ada notifikasi yang terkirim (periksa konfigurasi provider/template).'];
    }
    $jenis = $event === 'payment_success' ? 'pembayaran berhasil' : 'instruksi pembayaran';
    if (($log['status'] ?? '') === 'sent') {
        return ['ok' => true, 'message' => 'Notifikasi ' . ($channel === 'email' ? 'Email' : 'WhatsApp')
            . ' (' . $jenis . ') berhasil dikirim ulang ke ' . $recipient . '.', 'recipient' => $recipient];
    }
    return ['ok' => false, 'message' => 'Gagal mengirim: ' . mb_substr((string) ($log['response'] ?? 'tidak diketahui'), 0, 200)];
}

function notifyOrderCancelled(PDO $pdo, array $order): void
{
    if (!file_exists(dirname(__DIR__) . '/notifications/NotificationDispatcher.php')) return;
    try {
        require_once dirname(__DIR__) . '/notifications/NotificationDispatcher.php';

        // Auto-seed template order_cancelled jika belum ada di DB
        try {
            $chk = $pdo->query("SELECT id FROM notification_templates WHERE event_key = 'order_cancelled' LIMIT 1");
            if (!$chk || !$chk->fetch()) {
                $defaultBody = "❌ *Transaksi Dibatalkan*\n\nHalo {{name}}, transaksi kamu berikut telah *dibatalkan*:\n\n📋 No. Pesanan: *{{order_number}}*\n📦 Produk: {{product_name}}\n💰 Total: {{total}}\n\nJika ini bukan permintaanmu atau kamu butuh bantuan, silakan balas pesan ini.\n\nTim {{app_name}}";
                $pdo->prepare(
                    "INSERT INTO notification_templates (event_key, channel, provider, subject, body, is_active)
                     VALUES ('order_cancelled', 'whatsapp', 'starsender', NULL, ?, 1)"
                )->execute([$defaultBody]);
            }
        } catch (Throwable $e) {}

        $recipientName  = trim((string) ($order['customer_name'] ?? ''));
        $recipientEmail = trim((string) ($order['customer_email'] ?? ''));
        $recipientPhone = trim((string) ($order['customer_phone'] ?? ''));

        $uid = (int) ($order['user_id'] ?? 0);
        if ($uid > 0) {
            $uStmt = $pdo->prepare('SELECT name, email, phone FROM users WHERE id = ? LIMIT 1');
            $uStmt->execute([$uid]);
            if ($user = $uStmt->fetch()) {
                if (!empty($user['name']))  $recipientName  = (string) $user['name'];
                if (!empty($user['email'])) $recipientEmail = (string) $user['email'];
                if (!empty($user['phone'])) $recipientPhone = (string) $user['phone'];
            }
        }

        if (empty($recipientPhone) && empty($recipientEmail)) return;

        // Nama produk utama (non-bump); bila order bundle → "Bundle {nama}".
        $productName = '';
        $iStmt = $pdo->prepare(
            "SELECT product_name, COALESCE(is_bundle,0) AS is_bundle, bundle_id
             FROM order_items WHERE order_id = ? AND is_bump = 0 ORDER BY id LIMIT 1"
        );
        $iStmt->execute([(int) $order['id']]);
        if ($it = $iStmt->fetch()) {
            if ((int) $it['is_bundle'] === 1 && (int) ($it['bundle_id'] ?? 0) > 0) {
                $bStmt = $pdo->prepare('SELECT name FROM product_bundles WHERE id = ? LIMIT 1');
                $bStmt->execute([(int) $it['bundle_id']]);
                $bn = (string) ($bStmt->fetchColumn() ?: '');
                $productName = $bn !== '' ? 'Bundle ' . $bn : (string) $it['product_name'];
            } else {
                $productName = (string) $it['product_name'];
            }
        }

        if ($productName === '') {
            $fbStmt = $pdo->prepare("SELECT product_name FROM order_items WHERE order_id = ? ORDER BY id LIMIT 1");
            $fbStmt->execute([(int) $order['id']]);
            $productName = (string) ($fbStmt->fetchColumn() ?: ($order['product_name'] ?? 'Produk'));
        }

        NotificationDispatcher::trigger('order_cancelled', [
            'name'         => $recipientName !== '' ? $recipientName : 'Pembeli',
            'order_number' => (string) ($order['order_number'] ?? ''),
            'product_name' => $productName,
            'total'        => function_exists('formatRupiah') ? formatRupiah((float) ($order['total'] ?? 0)) : ($order['total'] ?? 0),
        ], $recipientEmail, $recipientPhone);
    } catch (Throwable $e) {
        error_log('notifyOrderCancelled: ' . $e->getMessage());
    }
}

/**
 * Nomor WA tujuan notifikasi admin (dipakai lead_admin_notify & payment_admin_notify).
 * Setting lead_admin_wa, fallback ke nomor akun admin pertama.
 */
function resolveAdminNotifyPhone(): string
{
    $phone = trim((string) getSetting('lead_admin_wa', ''));
    if ($phone !== '') {
        return $phone;
    }
    try {
        return (string) (getDB()->query(
            "SELECT phone FROM users WHERE role='admin' AND phone IS NOT NULL AND phone <> '' ORDER BY id ASC LIMIT 1"
        )->fetchColumn() ?: '');
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Alert INTERNAL admin via Telegram (Telegram Alerts Fase 1 — "Path B": pesan
 * diformat DI KODE, bukan template editable). Untuk event non-customer seperti
 * order gagal/batal & login admin IP baru. Fail-soft: tak pernah throw, tak
 * memblokir caller. Dicatat ke notification_logs (channel 'telegram') untuk audit.
 *
 * Digerbangi telegram_enabled + telegram_chat_id terisi. Caller sebaiknya juga
 * mengecek toggle per-jenis (mis. telegram_alert_order_failed) sebelum memanggil.
 *
 * @param  string $eventKey label event untuk log (mis. 'order_failed_admin').
 * @param  string $message  isi pesan (teks polos + emoji + baris baru).
 * @return bool             true bila terkirim/simulasi sukses; false bila nonaktif/gagal.
 */
function notifyAdminTelegram(string $eventKey, string $message): bool
{
    if (getSetting('telegram_enabled', '0') !== '1') return false;
    $chatId = trim((string) getSetting('telegram_chat_id', ''));
    if ($chatId === '') return false;
    if (!file_exists(dirname(__DIR__) . '/notifications/adapters/TelegramAdapter.php')) return false;

    try {
        require_once dirname(__DIR__) . '/notifications/adapters/TelegramAdapter.php';
        $adapter = new TelegramAdapter(); // baca telegram_bot_token dari setting
        $res     = $adapter->send($chatId, '', $message);
        $ok      = !empty($res['success']);
        try {
            getDB()->prepare(
                "INSERT INTO notification_logs (event_key, channel, provider, recipient, status, response, created_at)
                 VALUES (?, 'telegram', 'telegram', ?, ?, ?, NOW())"
            )->execute([$eventKey, $chatId, $ok ? 'sent' : 'failed', (string) ($res['message'] ?? '')]);
        } catch (Throwable $e) {
            error_log('notifyAdminTelegram log error: ' . $e->getMessage());
        }
        return $ok;
    } catch (Throwable $e) {
        error_log('notifyAdminTelegram error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Waitlist / Coming Soon: (1) auto-launch produk terjadwal yang launch_at-nya
 * sudah lewat, (2) kirim notifikasi launch ke pendaftar waitlist produk yang
 * sudah aktif — batch bertahap & idempoten (klaim notified_at sebelum kirim,
 * aman dijalankan bersamaan oleh Auto-Cron / cron asli / tombol Launch).
 */
function processWaitlistLaunches(int $limit = 30): array
{
    $result = ['launched' => 0, 'sent' => 0];
    try {
        $pdo = getDB();

        // 1) Auto-launch produk coming_soon yang jadwalnya sudah tiba.
        $up = $pdo->prepare(
            "UPDATE products SET status = 'active'
             WHERE status = 'coming_soon' AND launch_at IS NOT NULL AND launch_at <= NOW()"
        );
        $up->execute();
        $result['launched'] = (int) $up->rowCount();

        // 2) Blast notif ke pendaftar produk yang sudah aktif (belum dinotifikasi).
        $stmt = $pdo->prepare(
            "SELECT pw.id, pw.name, pw.whatsapp, pw.email,
                    p.name AS product_name, p.slug AS product_slug
             FROM product_waitlist pw
             JOIN products p ON p.id = pw.product_id
             WHERE p.status = 'active' AND pw.status = 'waiting' AND pw.notified_at IS NULL
             ORDER BY pw.id ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (!$rows) return $result;

        require_once dirname(__DIR__) . '/notifications/NotificationDispatcher.php';
        $claim = $pdo->prepare(
            "UPDATE product_waitlist SET notified_at = NOW(), status = 'notified'
             WHERE id = ? AND notified_at IS NULL"
        );
        foreach ($rows as $r) {
            // Klaim dulu (at-most-once): proses paralel tak akan dobel kirim.
            $claim->execute([(int) $r['id']]);
            if ($claim->rowCount() < 1) continue;

            NotificationDispatcher::trigger('waitlist_launch', [
                'name'         => (string) $r['name'],
                'product_name' => (string) $r['product_name'],
                'product_url'  => baseUrl() . '/products/' . $r['product_slug'],
            ], (string) ($r['email'] ?? ''), (string) $r['whatsapp']);
            $result['sent']++;
        }
    } catch (Throwable $e) {
        // Tabel belum ada (pra-migrasi) / error lain — jangan ganggu cron lain.
        error_log('processWaitlistLaunches error: ' . $e->getMessage());
    }
    return $result;
}

/**
 * Booking Fee (DP): (1) order booking yang sudah PAID tapi belum punya order
 * pelunasan → buat order pelunasan (sisa = subtotal + setup_fee - DP, deadline
 * per produk) + notif booking_confirmed berisi link bayar. Race-safe via UNIQUE
 * KEY uk_orders_parent. (2) Order pelunasan pending yang lewat due_at →
 * dibatalkan (booking hangus) + notif booking_expired.
 */
function processBookingOrders(int $limit = 20): array
{
    $result = ['settlements_created' => 0, 'expired' => 0];
    try {
        $pdo = getDB();

        // ── 1) Buat order pelunasan untuk booking yang sudah paid ──
        $stmt = $pdo->prepare(
            "SELECT o.id, o.order_number, o.user_id, o.subtotal, o.setup_fee, o.booking_fee,
                    o.payment_gateway, o.referral_code,
                    u.name AS user_name, u.email AS user_email, u.phone AS user_phone,
                    oi.product_id
             FROM orders o
             JOIN users u ON u.id = o.user_id
             JOIN order_items oi ON oi.order_id = o.id AND oi.is_bump = 0
             WHERE o.order_stage = 'booking' AND o.status = 'paid'
               AND NOT EXISTS (SELECT 1 FROM orders c WHERE c.parent_order_id = o.id)
             GROUP BY o.id
             ORDER BY o.paid_at ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $bookings = $stmt->fetchAll();

        if ($bookings) {
            require_once dirname(__DIR__) . '/notifications/NotificationDispatcher.php';
        }

        foreach ($bookings as $b) {
            try {
                $pStmt = $pdo->prepare('SELECT id, name, slug, booking_deadline_days FROM products WHERE id = ? LIMIT 1');
                $pStmt->execute([(int) $b['product_id']]);
                $product = $pStmt->fetch();
                if (!$product) continue;

                // Sisa pelunasan dari nilai TERKUNCI di order booking.
                $settleTotal = round((float) $b['subtotal'] + (float) $b['setup_fee'] - (float) $b['booking_fee'], 2);
                if ($settleTotal <= 0) continue; // konfigurasi aneh — jangan buat order 0

                $days  = max(1, (int) ($product['booking_deadline_days'] ?? 7));
                $dueAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));

                do {
                    $settleNumber = generateOrderNumber();
                    $dup = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE order_number = ?');
                    $dup->execute([$settleNumber]);
                } while ((int) $dup->fetchColumn() > 0);

                // referral_code ikut disalin: komisi afiliasi atas SISA dicatat di
                // order pelunasan (DP sudah berkomisi sendiri → total = rate x harga penuh).
                $ins = $pdo->prepare(
                    "INSERT INTO orders (order_number, user_id, subtotal, discount, setup_fee, total,
                                         status, notes, referral_code, order_stage, parent_order_id, due_at, payment_gateway)
                     VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, 'settlement', ?, ?, ?)"
                );
                $ins->execute([
                    $settleNumber, (int) $b['user_id'],
                    (float) $b['subtotal'],            // harga penuh terkunci
                    (float) $b['booking_fee'],         // "diskon" = DP yang sudah dibayar
                    (float) $b['setup_fee'],
                    $settleTotal,
                    'Pelunasan booking ' . $b['order_number'],
                    $b['referral_code'] ?: null,
                    (int) $b['id'], $dueAt,
                    ($b['payment_gateway'] === 'moota') ? null : $b['payment_gateway'], // moota diset ulang di bawah (kode unik)
                ]);
                $settleId = (int) $pdo->lastInsertId();

                $pdo->prepare(
                    'INSERT INTO order_items (order_id, product_id, product_name, price, quantity)
                     VALUES (?, ?, ?, ?, 1)'
                )->execute([$settleId, (int) $product['id'], $product['name'] . ' — Pelunasan', $settleTotal]);

                // Moota: pelunasan butuh kode unik agar webhook mutasi bisa auto-match.
                $finalSettle = $settleTotal;
                if ($b['payment_gateway'] === 'moota') {
                    require_once dirname(__DIR__) . '/payment_gateways/MootaGateway.php';
                    $u = (new MootaGateway())->generateUniqueCode($finalSettle);
                    $finalSettle = $u['total_amount'];
                    $pdo->prepare("UPDATE orders SET total = ?, payment_gateway = 'moota' WHERE id = ?")
                        ->execute([$finalSettle, $settleId]);
                }
                // Pelunasan via Pembayaran Manual (non-Moota): kode unik nominal.
                elseif ($finalSettle > 0) {
                    $withCode = applyManualUniqueCode($pdo, (float) $finalSettle);
                    if ($withCode != $finalSettle) {
                        $finalSettle = $withCode;
                        $pdo->prepare("UPDATE orders SET total = ? WHERE id = ?")->execute([$finalSettle, $settleId]);
                    }
                }
                $pdo->prepare('INSERT INTO payments (order_id, method, amount, status) VALUES (?, ?, ?, "pending")')
                    ->execute([$settleId, ($b['payment_gateway'] === 'moota') ? 'moota' : 'manual', $finalSettle]);

                // Komisi afiliasi atas sisa (mirror checkout; pending sampai paid).
                if (!empty($b['referral_code'])) {
                    $aff = $pdo->prepare("SELECT id, user_id FROM affiliates WHERE code = ? AND status = 'active' LIMIT 1");
                    $aff->execute([$b['referral_code']]);
                    $affRow = $aff->fetch();
                    if ($affRow && (int) $affRow['user_id'] !== (int) $b['user_id']
                        && affiliateEligibleUser((int) $affRow['user_id'])) {
                        $rate = resolveCommissionRate((int) $affRow['user_id'], (int) $product['id']);
                        $amt  = round($finalSettle * $rate / 100, 2);
                        if ($amt > 0) {
                            $pdo->prepare("INSERT INTO commissions (affiliate_id, order_id, amount, rate, status) VALUES (?, ?, ?, ?, 'pending')")
                                ->execute([(int) $affRow['id'], $settleId, $amt, $rate]);
                        }
                    }
                }

                NotificationDispatcher::trigger('booking_confirmed', [
                    'name'             => (string) $b['user_name'],
                    'product_name'     => (string) $product['name'],
                    'booking_amount'   => formatRupiah((float) $b['booking_fee']),
                    'settlement_total' => formatRupiah($finalSettle),
                    'due_date'         => date('d M Y H:i', strtotime($dueAt)),
                    'settlement_url'   => baseUrl() . '/transactions/' . $settleNumber,
                ], (string) ($b['user_email'] ?? ''), (string) ($b['user_phone'] ?? ''));

                $result['settlements_created']++;
            } catch (Throwable $e) {
                // Duplikat parent (race dgn proses lain) / error lain — lanjut booking berikutnya.
                error_log('processBookingOrders create settlement (booking #' . $b['id'] . '): ' . $e->getMessage());
            }
        }

        // ── 2) Pelunasan lewat deadline → batalkan (booking hangus) + notif ──
        $ex = $pdo->prepare(
            "SELECT o.id, o.order_number, o.user_id, o.due_at, o.parent_order_id,
                    u.name AS user_name, u.email AS user_email, u.phone AS user_phone,
                    oi.product_id
             FROM orders o
             JOIN users u ON u.id = o.user_id
             JOIN order_items oi ON oi.order_id = o.id AND oi.is_bump = 0
             WHERE o.order_stage = 'settlement' AND o.status = 'pending'
               AND o.due_at IS NOT NULL AND o.due_at <= NOW()
             GROUP BY o.id
             LIMIT ?"
        );
        $ex->bindValue(1, $limit, PDO::PARAM_INT);
        $ex->execute();
        $lapsed = $ex->fetchAll();

        if ($lapsed) {
            require_once dirname(__DIR__) . '/notifications/NotificationDispatcher.php';
        }

        foreach ($lapsed as $l) {
            // Klaim (rowCount) → aman diproses paralel, notif tak dobel.
            $cl = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status = 'pending'");
            $cl->execute([(int) $l['id']]);
            if ($cl->rowCount() < 1) continue;
            $pdo->prepare("UPDATE payments SET status = 'failed' WHERE order_id = ? AND status = 'pending'")
                ->execute([(int) $l['id']]);

            // Booking hangus: cabut akses instan yang diberikan atas DP (bila ada).
            // Akses booking-instan bersifat PROVISIONAL. No-op bila tak ada akses.
            if (!empty($l['parent_order_id'])) {
                $pdo->prepare("UPDATE member_access SET status = 'revoked' WHERE order_id = ? AND status = 'active'")
                    ->execute([(int) $l['parent_order_id']]);
            }

            $pStmt = $pdo->prepare('SELECT name, slug FROM products WHERE id = ? LIMIT 1');
            $pStmt->execute([(int) $l['product_id']]);
            $prod = $pStmt->fetch() ?: ['name' => 'produk', 'slug' => ''];

            NotificationDispatcher::trigger('booking_expired', [
                'name'         => (string) $l['user_name'],
                'product_name' => (string) $prod['name'],
                'due_date'     => date('d M Y H:i', strtotime((string) $l['due_at'])),
                'product_url'  => baseUrl() . '/products/' . $prod['slug'],
            ], (string) ($l['user_email'] ?? ''), (string) ($l['user_phone'] ?? ''));

            $result['expired']++;
        }
    } catch (Throwable $e) {
        // Kolom/tabel belum ada (pra-migrasi) — jangan ganggu cron lain.
        error_log('processBookingOrders error: ' . $e->getMessage());
    }
    return $result;
}

function hasAccess(int $userId, int $productId): bool
{
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare('
            SELECT expires_at
            FROM member_access
            WHERE user_id = ? AND product_id = ? AND status = \'active\'
            LIMIT 1
        ');
        $stmt->execute([$userId, $productId]);
        $access = $stmt->fetch();

        if (!$access) {
            return false;
        }

        // Cek expired
        if ($access['expires_at'] !== null && strtotime($access['expires_at']) < time()) {
            return false;
        }

        return true;
    } catch (Exception $e) {
        error_log('hasAccess error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Drip Content: hitung status kunci tiap modul untuk user & produk.
 * Modul dengan unlock_after_days > 0 baru terbuka N hari sejak akses produk
 * aktif (member_access.started_at). unlock_after_days 0 = langsung terbuka.
 *
 * $classes = baris classes yang minimal memuat id + unlock_after_days.
 * Return map: class_id => ['locked'=>bool, 'unlock_at'=>?'Y-m-d H:i:s', 'days_left'=>int]
 *
 * Catatan perilaku:
 * - Pembelian ulang/perpanjangan me-reset started_at → timer drip ikut mulai ulang.
 * - Lead magnet tidak punya member_access → panggil dengan productId 0, semua terbuka.
 * - Tanpa baris member_access (mis. preview admin) → fail-open, semua terbuka
 *   (halaman member sudah digerbangi hasAccess sebelum sampai sini).
 */
function dripLockStates(array $classes, int $userId, int $productId): array
{
    $needsDrip = false;
    foreach ($classes as $cls) {
        if ((int) ($cls['unlock_after_days'] ?? 0) > 0) { $needsDrip = true; break; }
    }

    $startedTs = null;
    if ($needsDrip && $userId > 0 && $productId > 0) {
        try {
            $stmt = getDB()->prepare(
                "SELECT started_at FROM member_access
                 WHERE user_id = ? AND product_id = ? AND status = 'active' LIMIT 1"
            );
            $stmt->execute([$userId, $productId]);
            $startedAt = $stmt->fetchColumn();
            if ($startedAt) $startedTs = strtotime((string) $startedAt);
        } catch (Exception $e) {
            error_log('dripLockStates error: ' . $e->getMessage());
        }
    }

    $states = [];
    foreach ($classes as $cls) {
        $cid  = (int) ($cls['id'] ?? 0);
        $days = (int) ($cls['unlock_after_days'] ?? 0);
        if ($days <= 0 || $startedTs === null || $startedTs === false) {
            $states[$cid] = ['locked' => false, 'unlock_at' => null, 'days_left' => 0];
            continue;
        }
        $unlockTs = $startedTs + $days * 86400;
        $locked   = time() < $unlockTs;
        $states[$cid] = [
            'locked'    => $locked,
            'unlock_at' => date('Y-m-d H:i:s', $unlockTs),
            'days_left' => $locked ? max(1, (int) ceil(($unlockTs - time()) / 86400)) : 0,
        ];
    }
    return $states;
}

/**
 * Apakah user sudah PERNAH membeli produk ini (ada order berstatus 'paid')?
 * Dipakai untuk menentukan pembelian pertama vs perpanjangan.
 * Fail-safe: bila query error, kembalikan true (anggap sudah beli) agar biaya awal
 * TIDAK keliru dibebankan ulang saat gangguan sesaat.
 */
function userHasPurchasedProduct(int $userId, int $productId): bool
{
    if ($userId <= 0 || $productId <= 0) return false;
    try {
        $stmt = getDB()->prepare(
            "SELECT 1 FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE o.user_id = ? AND oi.product_id = ? AND o.status = 'paid'
             LIMIT 1"
        );
        $stmt->execute([$userId, $productId]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('userHasPurchasedProduct error: ' . $e->getMessage());
        return true;
    }
}

/**
 * Batalkan otomatis order 'pending' yang melewati batas kedaluwarsa (setting
 * `order_expiry_hours`). Dipakai bersama oleh cron manual (cron/process.php) DAN
 * auto-cron. Idempoten & aman diulang: hanya menyentuh order yang masih 'pending'.
 *
 * @return int Jumlah order yang dibatalkan.
 */
function processExpiredOrders(): int
{
    try {
        $hrs = (int) getSetting('order_expiry_hours', '0');
        if ($hrs <= 0) return 0;

        $pdo  = getDB();
        // Order pelunasan (settlement) dikecualikan — deadline-nya per-order (due_at,
        // ditangani processBookingOrders), bukan expiry global.
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE status='pending' AND order_stage <> 'settlement' AND created_at <= NOW() - INTERVAL ? HOUR");
        $stmt->execute([$hrs]);
        $expiredOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$expiredOrders) return 0;

        $oids = array_map('intval', array_column($expiredOrders, 'id'));
        $ph   = implode(',', array_fill(0, count($oids), '?'));

        $pdo->prepare("UPDATE orders SET status='cancelled', updated_at=NOW() WHERE id IN ($ph) AND status='pending'")->execute($oids);
        $pdo->prepare("UPDATE payments SET status='failed', updated_at=NOW() WHERE order_id IN ($ph) AND status='pending'")->execute($oids);

        foreach ($expiredOrders as $expOrd) {
            $expOrd['status'] = 'cancelled';
            notifyOrderCancelled($pdo, $expOrd);
        }

        logActivity('admin', 'order.batch_cancel_expired', 'order', null, 'Auto-cancel ' . count($oids) . " order expired (>{$hrs} jam)");
        return count($oids);
    } catch (Throwable $e) {
        error_log('processExpiredOrders error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Kirim reminder RENEWAL ke member yang aksesnya (berjangka) mendekati/melewati
 * kedaluwarsa. Model manual-renewal (adopsi Sejoli): 4 lapis via
 * member_access.renewal_remind_stage (0=belum, 1=H-7, 2=H-3, 3=H-1, 4=H+3);
 * direset ke 0 saat perpanjang di activateAccess(). Fail-safe, tak pernah lempar.
 *
 *   H-7  — hanya untuk langganan > 14 hari. Untuk produk mingguan, H-7 jatuh di
 *          awal siklus (baru saja bayar) → mengganggu, jadi dilewati.
 *   H-3, H-1 — pengingat mendekati habis (event `renewal_reminder`).
 *   H+3  — WIN-BACK setelah akses berakhir (event `renewal_expired`, kalimatnya
 *          beda: sudah berakhir, bukan akan berakhir). Di model manual-renewal
 *          banyak orang baru bayar setelah telat; dulu app diam total begitu lewat.
 *
 * PENTING — jendela win-back sempit (telat 3–5 hari): tanpa batas atas, sekali
 * fitur ini menyala SEMUA langganan yang mati berbulan-bulan lalu akan diberondong
 * pesan sekaligus (stage lama mereka < 4). Baris kedaluwarsa tetap status='active'
 * (tak ada cron penanda; hasAccess cek expires_at saat baca) — karena itu filter
 * bertumpu pada expires_at, bukan status.
 *
 * @return array ['processed'=>int, 'sent'=>int]
 */
function processRenewalReminders(int $limit = 100): array
{
    $result = ['processed' => 0, 'sent' => 0];
    if ((string) getSetting('renewal_reminder_enabled', '1') !== '1') return $result;

    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            "SELECT ma.id, ma.product_id, ma.expires_at, ma.renewal_remind_stage,
                    DATEDIFF(ma.expires_at, NOW()) AS days_left,
                    u.name AS user_name, u.email AS user_email, u.phone AS user_phone,
                    p.name AS product_name
             FROM member_access ma
             JOIN users u    ON u.id = ma.user_id
             JOIN products p ON p.id = ma.product_id
             WHERE ma.status = 'active'
               AND ma.expires_at IS NOT NULL
               AND (
                     (
                       ma.expires_at >= NOW()
                       AND (
                             (DATEDIFF(ma.expires_at, NOW()) <= 1 AND ma.renewal_remind_stage < 3)
                          OR (DATEDIFF(ma.expires_at, NOW()) <= 3 AND ma.renewal_remind_stage < 2)
                          OR (DATEDIFF(ma.expires_at, NOW()) <= 7 AND ma.renewal_remind_stage < 1
                              AND p.access_duration_days > 14)
                          )
                     )
                  OR (
                       ma.expires_at < NOW()
                       AND DATEDIFF(NOW(), ma.expires_at) BETWEEN 3 AND 5
                       AND ma.renewal_remind_stage < 4
                     )
                   )
             LIMIT " . (int) $limit
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (!$rows) return $result;

        if (file_exists(dirname(__DIR__) . '/notifications/NotificationDispatcher.php')) {
            require_once dirname(__DIR__) . '/notifications/NotificationDispatcher.php';
        }

        foreach ($rows as $r) {
            $result['processed']++;
            $daysDiff = (int) $r['days_left'];   // negatif = sudah lewat tanggal
            $expired  = $daysDiff < 0;
            // `renew=1` bikin checkout sadar ini perpanjangan (judul + tanggal
            // "berlaku sampai X → jadi Y"), bukan pembelian baru.
            $renewUrl = baseUrl() . '/checkout?product_id=' . (int) $r['product_id'] . '&renew=1';
            $expiryDt = date('d M Y', strtotime((string) $r['expires_at']));

            if ($expired)              { $newStage = 4; }   // H+3 win-back
            elseif ($daysDiff <= 1)    { $newStage = 3; }   // H-1
            elseif ($daysDiff <= 3)    { $newStage = 2; }   // H-3
            else                       { $newStage = 1; }   // H-7

            try {
                if (class_exists('NotificationDispatcher')) {
                    if ($expired) {
                        NotificationDispatcher::trigger('renewal_expired', [
                            'name'         => $r['user_name']    ?? '',
                            'product_name' => $r['product_name'] ?? '',
                            'days_ago'     => abs($daysDiff),
                            'expiry_date'  => $expiryDt,
                            'renew_url'    => $renewUrl,
                            'member_url'   => baseUrl('member'),
                        ], $r['user_email'] ?? '', $r['user_phone'] ?? '');
                    } else {
                        NotificationDispatcher::trigger('renewal_reminder', [
                            'name'         => $r['user_name']    ?? '',
                            'product_name' => $r['product_name'] ?? '',
                            'days_left'    => max(0, $daysDiff),
                            'expiry_date'  => $expiryDt,
                            'renew_url'    => $renewUrl,
                            'member_url'   => baseUrl('member'),
                        ], $r['user_email'] ?? '', $r['user_phone'] ?? '');
                    }
                }
                $pdo->prepare("UPDATE member_access SET renewal_remind_stage = ? WHERE id = ?")
                    ->execute([$newStage, (int) $r['id']]);
                $result['sent']++;
            } catch (Throwable $e) {
                error_log('processRenewalReminders item error: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('processRenewalReminders error: ' . $e->getMessage());
    }
    return $result;
}

/**
 * Reminder Pelunasan Booking — H-2 & H-1 sebelum due_at order settlement.
 * Pola staged seperti processRenewalReminders (orders.settlement_remind_stage:
 * 0 belum, 1 = H-2 terkirim, 2 = H-1 terkirim), tapi klaim-dulu (rowCount)
 * agar aman dijalankan paralel oleh Auto-Cron / cron asli.
 * Order pelunasan yang lewat deadline dihanguskan processBookingOrders().
 */
function processSettlementReminders(int $limit = 100): array
{
    $result = ['processed' => 0, 'sent' => 0];
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            "SELECT o.id, o.order_number, o.total, o.due_at, o.settlement_remind_stage,
                    TIMESTAMPDIFF(HOUR, NOW(), o.due_at) AS hours_left,
                    u.name AS user_name, u.email AS user_email, u.phone AS user_phone,
                    p.name AS product_name
             FROM orders o
             JOIN users u ON u.id = o.user_id
             JOIN order_items oi ON oi.order_id = o.id AND oi.is_bump = 0
             JOIN products p ON p.id = oi.product_id
             WHERE o.order_stage = 'settlement' AND o.status = 'pending'
               AND o.due_at IS NOT NULL AND o.due_at > NOW()
               AND (
                     (o.due_at <= NOW() + INTERVAL 24 HOUR AND o.settlement_remind_stage < 2)
                  OR (o.due_at <= NOW() + INTERVAL 48 HOUR AND o.settlement_remind_stage < 1)
                   )
             GROUP BY o.id
             LIMIT " . (int) $limit
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (!$rows) return $result;

        require_once dirname(__DIR__) . '/notifications/NotificationDispatcher.php';
        $claim = $pdo->prepare(
            "UPDATE orders SET settlement_remind_stage = ? WHERE id = ? AND settlement_remind_stage < ?"
        );

        foreach ($rows as $r) {
            $result['processed']++;
            $hoursLeft = max(0, (int) $r['hours_left']);
            $newStage  = ($hoursLeft <= 24) ? 2 : 1; // H-1 → tahap 2, H-2 → tahap 1

            // Klaim dulu (at-most-once per tahap): proses paralel tak dobel kirim.
            $claim->execute([$newStage, (int) $r['id'], $newStage]);
            if ($claim->rowCount() < 1) continue;

            try {
                NotificationDispatcher::trigger('settlement_reminder', [
                    'name'             => (string) ($r['user_name'] ?? ''),
                    'product_name'     => (string) ($r['product_name'] ?? ''),
                    'days_left'        => max(1, (int) ceil($hoursLeft / 24)),
                    'settlement_total' => formatRupiah((float) $r['total']),
                    'due_date'         => date('d M Y H:i', strtotime((string) $r['due_at'])),
                    'settlement_url'   => baseUrl() . '/transactions/' . $r['order_number'],
                ], (string) ($r['user_email'] ?? ''), (string) ($r['user_phone'] ?? ''));
                $result['sent']++;
            } catch (Throwable $e) {
                error_log('processSettlementReminders item error: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        // Kolom belum ada (pra-migrasi) — jangan ganggu cron lain.
        error_log('processSettlementReminders error: ' . $e->getMessage());
    }
    return $result;
}

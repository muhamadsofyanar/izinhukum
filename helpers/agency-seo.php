<?php
/**
 * Portal Agency SEO Engine — averion.id menjual paket agency (mis. Rp497.000) yang
 * memberi pembeli KUOTA 50 lisensi SEO Engine lifetime, untuk dibagikan ke kliennya.
 *
 * FITUR VENDOR-ONLY (digerbangi AVERION_VENDOR_INSTALL, sama seperti vendor-fulfillment).
 * Semua fungsi no-op / kosong di instalasi pembeli biasa.
 *
 * KEPUTUSAN ARSITEKTUR INTI: lisensi diterbitkan SAAT DIBAGIKAN, bukan 50 sekaligus.
 * Alasan teknis: di server lisensi, dompet kredit AI dikunci ke EMAIL
 * (ls_ai_wallets.email UNIQUE). Menerbitkan 50 lisensi dengan email pembeli agency =
 * 50 klien berbagi satu dompet. Karena itu tiap slot diterbitkan dengan EMAIL KLIEN,
 * dan tiap slot memakai order_ref unik (induk + '-SEO-NN') supaya:
 *   1. Dompet AI tiap klien terpisah & benar sejak awal.
 *   2. UNIQUE(external_order_ref, product_id) di vendor bikin retry AMAN — order_ref
 *      yang sama tak pernah menerbitkan dua lisensi (respons "reused":true = SUKSES).
 *
 * Penerbitan lewat POST /api/fulfill (dipakai ulang lewat vfCall di vendor-fulfillment.php,
 * HMAC + rahasia bersama yang sudah live). TIDAK menulis langsung ke DB vendor.
 */

/** Fitur boleh tampil? (hanya server vendor — sama gerbang dengan vendor-fulfillment) */
function agencySeoAvailable(): bool
{
    return function_exists('vfAvailable') && vfAvailable();
}

/** Produk averion.id yang dianggap "paket Agency SEO" (0 = belum diatur admin). */
function agencySeoProductId(): int
{
    if (!agencySeoAvailable()) return 0;
    return (int) getSetting('agency_seo_product_id', '0');
}

/** Jumlah slot default per pembelian (fallback bila produk tak punya nilai sendiri). */
function agencySeoSlotTotal(): int
{
    $n = (int) getSetting('agency_seo_slot_total', '50');
    if ($n < 1)    $n = 1;
    if ($n > 1000) $n = 1000;
    return $n;
}

/**
 * Peta produk averion.id yang menjadi "paket Agency SEO" → jumlah slot lisensi SEO
 * lifetime per pembelian. Disimpan JSON {"<product_id>": <slot_total>}. Mendukung
 * BANYAK produk (mis. tier/whitelabel berbeda: SEO Agency 50, SEO Agency 100, dst).
 * Backward-compat: bila peta kosong tapi setting lama agency_seo_product_id>0 ada,
 * dipakai sebagai satu entri. @return array<int,int> productId => slotTotal (1..1000).
 */
function agencySeoProductMap(): array
{
    if (!agencySeoAvailable()) return [];
    $out = [];
    $raw = json_decode((string) getSetting('agency_seo_products', ''), true);
    if (is_array($raw)) {
        foreach ($raw as $pid => $slot) {
            $pid = (int) $pid; $slot = (int) $slot;
            if ($pid > 0 && $slot > 0) $out[$pid] = min(1000, max(1, $slot));
        }
    }
    if (empty($out)) {
        $legacyPid = (int) getSetting('agency_seo_product_id', '0');
        if ($legacyPid > 0) $out[$legacyPid] = agencySeoSlotTotal();
    }
    return $out;
}

/** Slot untuk sebuah produk (0 = produk ini BUKAN paket agency). */
function agencySeoSlotTotalForProduct(int $productId): int
{
    return agencySeoProductMap()[$productId] ?? 0;
}

/** Tautan top-up kredit AI klien (dibuka klien sendiri; portal hanya menampilkan). */
function agencySeoTopupUrl(int $pack): string
{
    $key = $pack === 30 ? 'agency_seo_topup30_url' : 'agency_seo_topup10_url';
    $def = $pack === 30
        ? 'https://vendor.averion.id/storefront?product=seo-topup-30'
        : 'https://vendor.averion.id/storefront?product=seo-topup-10';
    $url = trim((string) getSetting($key, $def));
    return $url !== '' ? $url : $def;
}

/**
 * Siap menerbitkan lisensi? Butuh gerbang vendor + endpoint fulfillment terisi
 * (vfEnabled: URL + rahasia + saklar on). Provisioning kuota tak butuh ini; penerbitan
 * per-slot butuh, karena memanggil vendor.
 */
function agencySeoCanIssue(): bool
{
    return agencySeoAvailable() && function_exists('vfEnabled') && vfEnabled();
}

/**
 * Catat kuota 50 slot saat order paket agency LUNAS. Dipanggil DI DALAM transaksi
 * onOrderPaid (per produk), setelah vfEnqueue. Idempoten: UNIQUE(order_id) → webhook
 * ganda / retry tak menggandakan kuota. Fail-soft: tak pernah menggagalkan order.
 */
function agencySeoProvisionOnPaid(PDO $pdo, array $order, int $productId, int $userId): void
{
    try {
        if (!agencySeoAvailable()) return;
        // Produk ini salah satu paket agency? (mendukung banyak produk whitelabel)
        $slotTotal = agencySeoSlotTotalForProduct($productId);
        if ($slotTotal <= 0) return;

        $orderId     = (int) ($order['id'] ?? 0);
        $orderNumber = trim((string) ($order['order_number'] ?? ''));
        if ($orderId <= 0 || $orderNumber === '') return;

        // Tiap ORDER = satu kuota baru (UNIQUE order_id). Pembeli yang order lagi
        // otomatis dapat kuota tambahan (portal menampilkan "Paket #N" terpisah).
        $pdo->prepare(
            "INSERT IGNORE INTO agency_seo_quotas
                (user_id, order_id, order_ref, slot_total, slot_used)
             VALUES (?, ?, ?, ?, 0)"
        )->execute([$userId, $orderId, $orderNumber, $slotTotal]);
    } catch (Throwable $e) {
        // Tabel belum ada (pra-migrasi) / error lain — order yang sudah dibayar TAK
        // boleh gagal hanya karena pencatatan kuota bermasalah.
        error_log('agencySeoProvisionOnPaid error: ' . $e->getMessage());
    }
}

/** Semua kuota paket agency milik seorang user (bisa >1 bila beli beberapa paket). */
function agencySeoQuotasForUser(int $userId): array
{
    if (!agencySeoAvailable()) return [];
    try {
        $st = getDB()->prepare(
            "SELECT * FROM agency_seo_quotas WHERE user_id = ? ORDER BY id ASC"
        );
        $st->execute([$userId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Satu kuota milik user (verifikasi kepemilikan). null bila bukan miliknya. */
function agencySeoQuotaForUser(int $quotaId, int $userId): ?array
{
    if (!agencySeoAvailable()) return null;
    try {
        $st = getDB()->prepare(
            "SELECT * FROM agency_seo_quotas WHERE id = ? AND user_id = ? LIMIT 1"
        );
        $st->execute([$quotaId, $userId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Daftar slot (lisensi terbit / pending) untuk sebuah kuota, urut nomor slot. */
function agencySeoSlots(int $quotaId): array
{
    try {
        $st = getDB()->prepare(
            "SELECT * FROM agency_seo_licenses WHERE quota_id = ? ORDER BY slot_number ASC"
        );
        $st->execute([$quotaId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Pengirim default ke vendor: POST /api/fulfill lewat vfCall (HMAC bersama). */
function agencySeoDefaultCaller(): callable
{
    return static fn(array $payload): array => vfCall('new', $payload);
}

/**
 * Panggil vendor untuk satu baris lisensi (pending) lalu tandai hasilnya. Dipakai
 * bersama oleh terbit-baru (agencySeoIssue) & coba-lagi (agencySeoRetry).
 *
 * $caller(array $payload): array — dapat diinjeksi untuk uji tanpa memanggil vendor.
 * Sukses: license_key disimpan, status='issued', slot_used kuota dihitung ulang.
 * Gagal: last_error dicatat, status TETAP 'pending' (slot_used TIDAK bertambah) →
 * bisa dicoba lagi dengan order_ref yang SAMA (idempoten di vendor).
 */
function agencySeoFulfillRow(PDO $pdo, array $row, ?callable $caller = null): array
{
    $caller = $caller ?? agencySeoDefaultCaller();
    $id      = (int) $row['id'];
    $quotaId = (int) $row['quota_id'];

    // Sudah terbit sebelumnya (mis. klik ganda) → sukses idempoten, jangan panggil lagi.
    if (($row['status'] ?? '') === 'issued' && trim((string) ($row['license_key'] ?? '')) !== '') {
        return ['ok' => true, 'license_key' => (string) $row['license_key'], 'reused' => true,
                'message' => 'Lisensi sudah diterbitkan.'];
    }

    $payload = [
        'order_ref'      => (string) $row['order_ref'],
        'plan'           => 'lifetime',
        'product_code'   => 'seo',
        'customer_name'  => (string) $row['client_name'],
        'customer_email' => (string) $row['client_email'],
        'customer_phone' => (string) ($row['client_phone'] ?? ''),
    ];

    try {
        $res = $caller($payload);
    } catch (Throwable $e) {
        $res = ['ok' => false, 'error' => 'Gagal memanggil server lisensi: ' . $e->getMessage(), 'data' => []];
    }

    $ok  = !empty($res['ok']);
    $key = trim((string) ($res['data']['license_key'] ?? ''));

    if ($ok && $key !== '') {
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE agency_seo_licenses
                    SET license_key = ?, status = 'issued', issued_at = NOW(), last_error = NULL
                  WHERE id = ? AND status <> 'issued'"
            )->execute([$key, $id]);
            // slot_used = jumlah slot yang BENAR-BENAR terbit (hanya sukses).
            $pdo->prepare(
                "UPDATE agency_seo_quotas
                    SET slot_used = (SELECT COUNT(*) FROM agency_seo_licenses
                                      WHERE quota_id = ? AND status = 'issued')
                  WHERE id = ?"
            )->execute([$quotaId, $quotaId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('agencySeoFulfillRow simpan sukses error: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Lisensi terbit di server namun gagal disimpan lokal. Coba muat ulang halaman.'];
        }
        return ['ok' => true, 'license_key' => $key, 'reused' => !empty($res['data']['reused']),
                'message' => 'Lisensi berhasil diterbitkan.'];
    }

    // Gagal → catat error, slot tetap pending (bisa dicoba lagi, order_ref sama).
    $err = (string) ($res['error'] ?? 'Gagal menerbitkan lisensi.');
    try {
        $pdo->prepare("UPDATE agency_seo_licenses SET last_error = ? WHERE id = ?")
            ->execute([mb_substr($err, 0, 500), $id]);
    } catch (Throwable $e) {
        error_log('agencySeoFulfillRow catat error gagal: ' . $e->getMessage());
    }
    return ['ok' => false, 'message' => $err];
}

/**
 * Terbitkan lisensi ke slot BARU untuk seorang klien. Aman dari klik ganda:
 * kuota dikunci FOR UPDATE saat mengalokasikan nomor slot, lalu transaksi ditutup
 * SEBELUM memanggil vendor (HTTP tak menahan lock).
 */
function agencySeoIssue(PDO $pdo, int $quotaId, int $userId, array $client, ?callable $caller = null): array
{
    if (!agencySeoCanIssue()) {
        return ['ok' => false, 'message' => 'Penerbitan lisensi belum aktif (endpoint vendor belum dikonfigurasi).'];
    }

    $name  = trim((string) ($client['name'] ?? ''));
    $email = trim((string) ($client['email'] ?? ''));
    $phone = trim((string) ($client['phone'] ?? ''));

    if ($name === '' || mb_strlen($name) > 150) {
        return ['ok' => false, 'message' => 'Nama klien wajib diisi (maks 150 karakter).'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
        return ['ok' => false, 'message' => 'Email klien tidak valid.'];
    }
    if ($phone !== '') {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        if (mb_strlen($phone) > 40) $phone = mb_substr($phone, 0, 40);
    }

    // ── Alokasi slot (transaksi pendek, terkunci) ──
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare("SELECT * FROM agency_seo_quotas WHERE id = ? AND user_id = ? FOR UPDATE");
        $q->execute([$quotaId, $userId]);
        $quota = $q->fetch();
        if (!$quota) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Kuota tidak ditemukan.'];
        }
        $slotTotal = (int) $quota['slot_total'];

        $issued = (int) $pdo->query(
            "SELECT COUNT(*) FROM agency_seo_licenses WHERE quota_id = " . (int) $quotaId . " AND status = 'issued'"
        )->fetchColumn();
        if ($issued >= $slotTotal) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Semua ' . $slotTotal . ' lisensi sudah diterbitkan.'];
        }

        $nextSlot = 1 + (int) $pdo->query(
            "SELECT COALESCE(MAX(slot_number),0) FROM agency_seo_licenses WHERE quota_id = " . (int) $quotaId
        )->fetchColumn();
        if ($nextSlot > $slotTotal) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Semua slot sedang terpakai. Selesaikan slot yang masih tertunda dulu (tombol Coba Lagi).'];
        }

        $orderRef = (string) $quota['order_ref'] . sprintf('-SEO-%02d', $nextSlot);

        $pdo->prepare(
            "INSERT INTO agency_seo_licenses
                (quota_id, slot_number, order_ref, client_name, client_email, client_phone, status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')"
        )->execute([$quotaId, $nextSlot, $orderRef, $name, $email, ($phone !== '' ? $phone : null)]);
        $licenseId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('agencySeoIssue alokasi error: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Gagal menyiapkan slot. Coba lagi.'];
    }

    // ── Panggil vendor DI LUAR lock ──
    $row = [
        'id' => $licenseId, 'quota_id' => $quotaId, 'order_ref' => $orderRef,
        'client_name' => $name, 'client_email' => $email, 'client_phone' => $phone,
        'status' => 'pending', 'license_key' => null,
    ];
    $r = agencySeoFulfillRow($pdo, $row, $caller);
    $r['slot'] = $nextSlot;
    return $r;
}

/**
 * Coba lagi menerbitkan lisensi untuk slot yang masih PENDING (order_ref & klien
 * tetap sama → idempoten di vendor). Verifikasi kepemilikan lewat kuota.
 */
function agencySeoRetry(PDO $pdo, int $licenseId, int $userId, ?callable $caller = null): array
{
    if (!agencySeoCanIssue()) {
        return ['ok' => false, 'message' => 'Penerbitan lisensi belum aktif (endpoint vendor belum dikonfigurasi).'];
    }
    try {
        $st = $pdo->prepare(
            "SELECT l.* FROM agency_seo_licenses l
               JOIN agency_seo_quotas q ON q.id = l.quota_id
              WHERE l.id = ? AND q.user_id = ? LIMIT 1"
        );
        $st->execute([$licenseId, $userId]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        error_log('agencySeoRetry load error: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Gagal memuat data slot.'];
    }
    if (!$row) {
        return ['ok' => false, 'message' => 'Slot tidak ditemukan.'];
    }
    return agencySeoFulfillRow($pdo, $row, $caller);
}

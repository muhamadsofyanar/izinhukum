<?php
/**
 * order-service.php — logika pembuatan order yang DIPAKAI BERSAMA oleh jalur
 * checkout login (actions/checkout/process.php) dan guest
 * (actions/public/submit-public-checkout.php).
 *
 * Tujuan: satu sumber kebenaran untuk logika order yang sebelumnya diduplikasi di
 * dua file → menutup kelas bug "jalur admin benar, jalur pembeli salah" (lihat
 * CLAUDE.md §8 & §19). Diisi bertahap & behavior-preserving; tiap ekstraksi wajib
 * lolos test karakterisasi kedua jalur sebelum dianggap selesai.
 *
 * Fungsi di sini TIDAK mengelola transaksi sendiri — pemanggil sudah berada di
 * dalam $pdo->beginTransaction() (pola existing kedua jalur dipertahankan).
 */

if (!function_exists('orderProcessFlashBonuses')) {
    /**
     * Proses pilihan "Bonus Kilat" (produk premium GRATIS berbatas waktu) saat checkout.
     * Menyisipkan tiap bonus valid ke order_items sebagai is_bump harga 0 (ter-grant
     * otomatis oleh onOrderPaid untuk order berbayar, atau oleh pemanggil untuk order
     * gratis via id yang dikembalikan).
     *
     * Validasi server = sumber kebenaran: timer (cookie fb_start_{productId} + durasi
     * setting), enabled, max_select, offer aktif, produk aktif, bukan produk utama,
     * tak bentrok bump, & belum dimiliki user. Best-effort: cookie hilang dianggap
     * BELUM kedaluwarsa (menolak bonus gratis karena cookie mati = UX buruk, risiko
     * rendah karena gratis). Perilaku identik dengan blok inline lama di kedua jalur.
     *
     * @param PDO   $pdo               Koneksi (pemanggil sudah di dalam transaksi).
     * @param int   $orderId           Order tujuan penyisipan item.
     * @param int   $productId         Produk utama yang di-checkout.
     * @param int   $userId            Pembeli (sudah teresolusi, termasuk guest baru).
     * @param array $rawFlashInput     Mentah dari $_POST['flash_bonus_ids'] (array id).
     * @param array $excludeProductIds Product id yang sudah jadi bump berbayar (di-skip).
     * @param bool  $skip              true bila konteks tak mengizinkan bonus (booking/flexible).
     * @return int[] Product id bonus yang berhasil disisipkan (untuk aktivasi order gratis).
     */
    function orderProcessFlashBonuses(
        PDO $pdo,
        int $orderId,
        int $productId,
        int $userId,
        array $rawFlashInput,
        array $excludeProductIds = [],
        bool $skip = false
    ): array {
        $validIds = [];

        $rawIds = $skip ? [] : array_values(array_unique(array_filter(
            array_map('intval', $rawFlashInput),
            fn($v) => $v > 0
        )));
        if (empty($rawIds)) {
            return $validIds;
        }

        // Setting produk (durasi + max). Belum ada baris → default aktif 60m/1.
        $fsStmt = $pdo->prepare(
            "SELECT enabled, duration_minutes, max_select FROM flash_bonus_settings WHERE main_product_id = ? LIMIT 1"
        );
        $fsStmt->execute([$productId]);
        $fbSet      = $fsStmt->fetch();
        $fbEnabled  = $fbSet ? ((int) $fbSet['enabled'] === 1) : true;
        $fbDuration = $fbSet ? max(1, (int) $fbSet['duration_minutes']) : 60;
        $fbMaxSel   = $fbSet ? max(1, (int) $fbSet['max_select']) : 1;

        // Timer server-anchored: cookie hilang → anggap belum kedaluwarsa (best-effort).
        $ckName    = 'fb_start_' . $productId;
        $startTs   = isset($_COOKIE[$ckName]) ? (int) $_COOKIE[$ckName] : 0;
        $fbExpired = ($startTs > 0) && ((time() - $startTs) >= ($fbDuration * 60));
        if (!$fbEnabled || $fbExpired) {
            return $validIds;
        }

        $fbRule = $pdo->prepare(
            "SELECT p.id AS bonus_product_id, p.name AS bonus_name
             FROM flash_bonus_offers fb
             JOIN products p ON fb.bonus_product_id = p.id
             WHERE fb.main_product_id = ? AND fb.bonus_product_id = ?
               AND fb.status = 'active' AND p.status = 'active'
             LIMIT 1"
        );
        $ins = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, product_name, price, quantity, is_bump)
             VALUES (?, ?, ?, 0, 1, 1)'
        );

        foreach ($rawIds as $bonusPid) {
            if (count($validIds) >= $fbMaxSel) break;          // hormati batas
            if ($bonusPid === $productId) continue;             // tak boleh produk utama
            if (in_array($bonusPid, $excludeProductIds, true)) continue; // sudah jadi bump
            if (hasAccess($userId, $bonusPid)) continue;        // sudah punya

            $fbRule->execute([$productId, $bonusPid]);
            $rule = $fbRule->fetch();
            if (!$rule) continue;

            $ins->execute([$orderId, $bonusPid, $rule['bonus_name'] . ' (Bonus Gratis)']);
            $validIds[] = $bonusPid;
        }

        return $validIds;
    }
}

if (!function_exists('orderProcessBumps')) {
    /**
     * Proses "Order Bump" (penawaran tambahan BERBAYAR) saat checkout. Menyisipkan tiap
     * bump valid ke order_items sebagai is_bump dengan harga dari DB (anti-manipulasi
     * form), lalu menambah subtotal+total order sebesar total bump. Akses bump diberikan
     * saat pembayaran terkonfirmasi (onOrderPaid untuk berbayar; oleh pemanggil untuk
     * order gratis via id yang dikembalikan).
     *
     * Validasi server = sumber kebenaran: aturan bump aktif (`product_bumps`), produk
     * aktif, & belum dimiliki user. Perilaku identik dengan blok inline lama di kedua
     * jalur (login & guest). TIDAK mengelola transaksi (pemanggil sudah di dalamnya).
     *
     * Bump SENGAJA di-skip untuk order gratis/booking/flexible (lihat $skip) — mencegah
     * bump berbayar lolos "lunas" tanpa bayar pada produk utama gratis.
     *
     * @param PDO   $pdo          Koneksi (pemanggil sudah di dalam transaksi).
     * @param int   $orderId      Order tujuan penyisipan item.
     * @param int   $productId    Produk utama yang di-checkout.
     * @param int   $userId       Pembeli (sudah teresolusi).
     * @param array $rawBumpInput Mentah dari $_POST['bump_ids'] (array id).
     * @param bool  $skip         true bila konteks tak mengizinkan bump (gratis/booking/flexible).
     * @return array{ids:int[],total:float} id bump yang disisipkan + total harga bump.
     */
    function orderProcessBumps(
        PDO $pdo,
        int $orderId,
        int $productId,
        int $userId,
        array $rawBumpInput,
        bool $skip = false
    ): array {
        $result = ['ids' => [], 'total' => 0.0];

        $rawIds = $skip ? [] : array_values(array_filter(
            array_map('intval', $rawBumpInput),
            fn($v) => $v > 0
        ));
        if (empty($rawIds)) {
            return $result;
        }

        $bStmt = $pdo->prepare(
            "SELECT pb.bump_price, p.id AS bump_product_id, p.name AS bump_name, p.price AS bump_normal_price
             FROM product_bumps pb
             JOIN products p ON pb.bump_product_id = p.id
             WHERE pb.main_product_id = ? AND pb.bump_product_id = ?
               AND pb.status = 'active' AND p.status = 'active'
             LIMIT 1"
        );
        $ins = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, product_name, price, quantity, is_bump)
             VALUES (?, ?, ?, ?, 1, 1)'
        );

        $bumpTotal = 0.0;
        foreach ($rawIds as $bumpPid) {
            $bStmt->execute([$productId, $bumpPid]);
            $rule = $bStmt->fetch();
            if (!$rule) continue;
            if (hasAccess($userId, $bumpPid)) continue; // sudah punya → lewati

            $price = ($rule['bump_price'] !== null)
                ? (float) $rule['bump_price']
                : (float) $rule['bump_normal_price'];

            $ins->execute([$orderId, $bumpPid, $rule['bump_name'], $price]);
            $bumpTotal += $price;
            $result['ids'][] = (int) $bumpPid;
        }

        if ($bumpTotal > 0) {
            $pdo->prepare('UPDATE orders SET subtotal = subtotal + ?, total = total + ? WHERE id = ?')
                ->execute([$bumpTotal, $bumpTotal, $orderId]);
        }

        $result['total'] = $bumpTotal;
        return $result;
    }
}

if (!function_exists('orderProductNotSellableFree')) {
    /**
     * GUARD KEAMANAN — produk TIDAK boleh di-checkout sebagai gratis?
     * Dua e-book gratis yang dipublikasikan dapat diklaim dengan harga tetap Rp0.
     *
     * Mencegah akses GRATIS ke produk berbayar akibat harga 0 yang tak disengaja —
     * mis. placeholder migrasi Sejoli (slug 'transaksi-migrasi-sejoli', price 0) yang
     * keliru di-aktifkan, atau produk migrasi yang field price-nya kosong. Order gratis
     * yang SAH tetap lolos: (a) produk berharga >0 lalu jadi 0 via kupon 100%, (b) produk
     * flexible (nominal ditentukan pembeli, min > 0). Dipakai IDENTIK di kedua jalur
     * checkout (login process.php + guest submit-public-checkout.php) & halaman checkout,
     * supaya tak terulang pola "jalur admin benar, jalur pembeli salah".
     */
    function orderProductNotSellableFree(array $product): bool
    {
        $flexible = ($product['pricing_type'] ?? 'fixed') === 'flexible';
        $freeEbook = ($product['type'] ?? '') === 'ebook'
            && ($product['status'] ?? '') === 'active'
            && ($product['pricing_type'] ?? 'fixed') === 'fixed'
            && is_numeric($product['price'] ?? null)
            && (float) $product['price'] === 0.0
            && in_array($product['slug'] ?? '', [
                'satu-pesan-beda-cara',
                'ebook-coba-peran-promotor-stifin',
            ], true);
        return !$freeEbook && !$flexible && (float) ($product['price'] ?? 0) <= 0;
    }
}

if (!function_exists('orderCouponFieldsValid')) {
    /**
     * Validasi field kupon (server-side) — aturan yang IDENTIK di jalur login & guest.
     * TIDAK termasuk cek pemakaian per-user (berbeda antar jalur) & TIDAK termasuk
     * anti-race (lihat orderCouponAntiRaceValid). Kupon di-load pemanggil (login by id,
     * guest by code) lalu diperiksa di sini.
     *
     * @param array $coupon    Baris tabel coupons (harus status='active' saat di-load).
     * @param float $subtotal  Subtotal produk (dasar min_purchase & perhitungan).
     * @param int   $productId Produk yang di-checkout (untuk applicable_product_id).
     * @return bool true bila lolos SEMUA aturan field (belum termasuk usage per-user).
     */
    function orderCouponFieldsValid(array $coupon, float $subtotal, int $productId): bool
    {
        $now = date('Y-m-d H:i:s');
        if ($coupon['starts_at'] && $now < $coupon['starts_at'])   return false;
        if ($coupon['expires_at'] && $now > $coupon['expires_at']) return false;
        if ($coupon['usage_limit'] !== null && $coupon['used_count'] >= $coupon['usage_limit']) return false;
        if ($coupon['applicable_product_id'] !== null && (int) $coupon['applicable_product_id'] !== $productId) return false;
        if ($subtotal < (float) $coupon['min_purchase']) return false;
        return true;
    }
}

if (!function_exists('orderCouponDiscount')) {
    /**
     * Hitung nominal diskon kupon — IDENTIK di kedua jalur. percentage (dgn cap
     * max_discount opsional) atau fixed, lalu di-cap tak melebihi subtotal.
     *
     * @return float Nominal diskon (>= 0, tak melebihi subtotal).
     */
    function orderCouponDiscount(array $coupon, float $subtotal): float
    {
        if ($coupon['type'] === 'percentage') {
            $d = $subtotal * (float) $coupon['value'] / 100;
            if ($coupon['max_discount'] !== null) {
                $d = min($d, (float) $coupon['max_discount']);
            }
        } else {
            $d = (float) $coupon['value'];
        }
        return min($d, $subtotal);
    }
}

if (!function_exists('orderCouponAntiRaceValid')) {
    /**
     * Re-validasi kupon DI DALAM lock baris (FOR UPDATE) — mencegah usage_limit
     * terlampaui & pemakaian ganda oleh user yang sama via request paralel. Logika
     * IDENTIK di kedua jalur; penanganan kegagalan (rollback + redirect) tetap di
     * pemanggil karena tujuan redirect berbeda (checkout login vs form publik).
     *
     * WAJIB dipanggil setelah $pdo->beginTransaction().
     *
     * @return bool true bila kupon masih valid (boleh lanjut); false → pemanggil
     *              harus rollBack() + redirect dgn pesan yang sesuai jalurnya.
     */
    function orderCouponAntiRaceValid(PDO $pdo, int $couponId, int $userId): bool
    {
        $lockStmt = $pdo->prepare("SELECT used_count, usage_limit FROM coupons WHERE id = ? FOR UPDATE");
        $lockStmt->execute([$couponId]);
        $cLock = $lockStmt->fetch();

        $limitReached = $cLock && $cLock['usage_limit'] !== null
            && (int) $cLock['used_count'] >= (int) $cLock['usage_limit'];

        $dupStmt = $pdo->prepare("SELECT 1 FROM coupon_usages WHERE coupon_id = ? AND user_id = ? LIMIT 1");
        $dupStmt->execute([$couponId, $userId]);
        $alreadyUsed = (bool) $dupStmt->fetchColumn();

        return !(!$cLock || $limitReached || $alreadyUsed);
    }
}

if (!function_exists('orderRecordCommission')) {
    /**
     * Catat komisi afiliasi untuk sebuah order (rate dinamis via resolveCommissionRate).
     * Logika IDENTIK di jalur login & guest. No-op bila tak ada afiliat atau nominal 0.
     * WAJIB dipanggil di dalam transaksi order (atomik).
     *
     * Untuk order GRATIS (langsung paid): total_earned afiliat di-update sekarang &
     * komisi 'approved'. Untuk order berbayar: 'pending' (total_earned di-update admin
     * saat konfirmasi via update-transaction.php).
     *
     * @param float $finalTotal Nominal order final (dasar hitung komisi).
     */
    function orderRecordCommission(
        PDO $pdo,
        ?int $affiliateId,
        int $orderId,
        int $productId,
        float $finalTotal,
        bool $isFree
    ): void {
        if ($affiliateId === null || $finalTotal <= 0) {
            return;
        }

        $affUserStmt = $pdo->prepare("SELECT user_id FROM affiliates WHERE id = ? LIMIT 1");
        $affUserStmt->execute([$affiliateId]);
        $affUserRow = $affUserStmt->fetch();
        if (!$affUserRow || !affiliateEligibleUser((int) $affUserRow['user_id'])) {
            return;
        }

        $resolvedRate = resolveCommissionRate((int) $affUserRow['user_id'], $productId);
        $commAmount   = round($finalTotal * $resolvedRate / 100, 2);
        if ($commAmount <= 0) {
            return;
        }

        $commStatus = $isFree ? 'approved' : 'pending';
        $pdo->prepare(
            'INSERT INTO commissions (affiliate_id, order_id, amount, rate, status)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$affiliateId, $orderId, $commAmount, $resolvedRate, $commStatus]);

        if ($isFree) {
            $pdo->prepare('UPDATE affiliates SET total_earned = total_earned + ? WHERE id = ?')
                ->execute([$commAmount, $affiliateId]);
        }
    }
}

if (!function_exists('orderRecordCouponUsage')) {
    /**
     * Catat pemakaian kupon (coupon_usages + increment coupons.used_count). Logika
     * IDENTIK di kedua jalur. No-op bila tak ada kupon atau diskon 0. WAJIB dipanggil
     * di dalam transaksi order (atomik dengan pembuatan order).
     */
    function orderRecordCouponUsage(
        PDO $pdo,
        ?int $couponId,
        int $userId,
        int $orderId,
        float $couponDiscount
    ): void {
        if ($couponId === null || $couponDiscount <= 0) {
            return;
        }
        $pdo->prepare(
            'INSERT INTO coupon_usages (coupon_id, user_id, order_id, discount_amount) VALUES (?, ?, ?, ?)'
        )->execute([$couponId, $userId, $orderId, $couponDiscount]);
        $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?')
            ->execute([$couponId]);
    }
}

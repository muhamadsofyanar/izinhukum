<?php
// ─── Harga Dinamis (dime/unique-code/upsell/setup-fee) ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/**
 * Kode unik nominal untuk PEMBAYARAN MANUAL (transfer bank / manual / QR).
 * Menambahkan angka 1–999 ke nominal dasar agar tiap transfer punya total unik →
 * memudahkan penjual mencocokkan mutasi bank ke order tertentu. Nominalnya dibakar
 * langsung ke orders.total (pola sama dgn Moota) sehingga otomatis muncul di semua
 * tampilan pembeli/admin/notifikasi tanpa kolom tambahan.
 *
 * Digerbang setting `manual_unique_code_enabled` (default '1' = AKTIF). Mengembalikan
 * nominal DASAR apa adanya bila setting mati, nominal ≤ 0, atau gagal menemukan kode
 * bebas-bentrok. Collision dicek terhadap SEMUA order pending (bukan cuma manual) agar
 * nominal benar-benar unik antar order yang sedang menunggu bayar — termasuk hindari
 * bentrok dengan order Moota.
 *
 * @return float Nominal akhir (dasar + kode unik), atau dasar bila tak diterapkan.
 */
function applyManualUniqueCode(PDO $pdo, float $baseAmount): float
{
    if ($baseAmount <= 0) return $baseAmount;
    if ((string) getSetting('manual_unique_code_enabled', '1') !== '1') return $baseAmount;

    try {
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE total = ? AND status = 'pending' LIMIT 1");
        for ($i = 0; $i < 25; $i++) {
            $amount = $baseAmount + random_int(1, 999);
            $stmt->execute([$amount]);
            if (!$stmt->fetch()) {
                return (float) $amount;
            }
        }
    } catch (Throwable $e) {
        error_log('applyManualUniqueCode: ' . $e->getMessage());
    }
    return $baseAmount; // fallback (sangat jarang: 25x bentrok / DB error)
}

// ─── Dime Sale (harga naik bertahap seiring penjualan) ────────
/**
 * Harga efektif dime: harga_dasar + floor(terjual / step) × increment, dibatasi max.
 * Bila dime tak aktif → harga dasar apa adanya. Dipakai di SEMUA tampilan + server checkout.
 */
function dimeEffectivePrice(array $product): float
{
    $base = (float) ($product['price'] ?? 0);
    if (empty($product['dime_enabled'])) return $base;
    $step  = max(1, (int) ($product['dime_step_qty'] ?? 1));
    $inc   = max(0.0, (float) ($product['dime_increment'] ?? 0));
    $sold  = max(0, (int) ($product['dime_sold_count'] ?? 0));
    $price = $base + intdiv($sold, $step) * $inc;
    $cap   = $product['dime_max_price'] ?? null;
    if ($cap !== null && (float) $cap > 0) $price = min($price, (float) $cap);
    return $price;
}

/**
 * Info urgensi dime untuk UI. null bila dime tak aktif.
 * ['price','remaining' (sisa penjualan ke kenaikan berikut),'increment','at_cap','step']
 */
function dimeInfo(array $product): ?array
{
    if (empty($product['dime_enabled'])) return null;
    $step  = max(1, (int) ($product['dime_step_qty'] ?? 1));
    $inc   = max(0.0, (float) ($product['dime_increment'] ?? 0));
    $sold  = max(0, (int) ($product['dime_sold_count'] ?? 0));
    $price = dimeEffectivePrice($product);
    $cap   = $product['dime_max_price'] ?? null;
    $atCap = ($cap !== null && (float) $cap > 0 && $price >= (float) $cap);
    return [
        'price'     => $price,
        'remaining' => $step - ($sold % $step),
        'increment' => $inc,
        'at_cap'    => $atCap,
        'step'      => $step,
    ];
}

/**
 * Kunci harga dime di sesi saat halaman checkout dibuka (default 30 menit), agar pembeli
 * membayar persis harga yang dilihat meski counter naik saat dia mengisi form.
 */
function dimeLockPrice(int $productId, float $price): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $_SESSION['dime_lock'][$productId] = ['price' => $price, 'exp' => time() + 1800];
}

/** Ambil harga dime terkunci dari sesi bila masih berlaku; null bila tak ada/kedaluwarsa. */
function dimeLockedPrice(int $productId): ?float
{
    $l = $_SESSION['dime_lock'][$productId] ?? null;
    if (!is_array($l) || (int) ($l['exp'] ?? 0) < time()) return null;
    return (float) $l['price'];
}

/**
 * Naikkan counter dime produk TEPAT 1x untuk penjualan LUNAS BARU (bukan renewal).
 * WAJIB dipanggil SEBELUM activateAccess() produk itu (dipakai keberadaan baris
 * member_access sebagai pembeda sale-baru vs beli-ulang/renewal). WHERE dime_enabled=1
 * membuatnya no-op untuk produk non-dime. Aman idempoten mengikuti kanal pemanggil.
 */
function bumpDimeCounter(PDO $pdo, int $userId, int $productId): void
{
    if ($productId <= 0) return;
    try {
        $chk = $pdo->prepare('SELECT 1 FROM member_access WHERE user_id = ? AND product_id = ? LIMIT 1');
        $chk->execute([$userId, $productId]);
        if ($chk->fetchColumn()) return; // sudah punya akses → bukan penjualan baru
        $pdo->prepare('UPDATE products SET dime_sold_count = dime_sold_count + 1 WHERE id = ? AND dime_enabled = 1')
            ->execute([$productId]);
    } catch (Throwable $e) {
        error_log('bumpDimeCounter: ' . $e->getMessage());
    }
}

/**
 * Penawaran upsell untuk sebuah order yang sudah LUNAS.
 * Dicari dari produk utama order (item non-bump pertama). Return null bila:
 * tidak ada penawaran aktif, buyer sudah punya akses produk yang ditawarkan,
 * atau tidak ada gateway hosted (Duitku/Xendit) aktif untuk membayarnya.
 *
 * @return array{id:int, upsell_product_id:int, upsell_price:float, headline:string,
 *               description:string, product_name:string, normal_price:float,
 *               thumbnail:?string}|null
 */
function getUpsellOffer(int $orderId, int $userId): ?array
{
    try {
        $pdo = getDB();

        // Produk pemicu = item utama order.
        $st = $pdo->prepare(
            "SELECT product_id FROM order_items
             WHERE order_id = ? AND is_bump = 0 ORDER BY id ASC LIMIT 1"
        );
        $st->execute([$orderId]);
        $mainProductId = (int) $st->fetchColumn();
        if ($mainProductId <= 0) return null;

        $st = $pdo->prepare(
            "SELECT pu.id, pu.upsell_product_id, pu.upsell_price, pu.headline, pu.description,
                    p.name AS product_name, p.price AS normal_price, p.thumbnail
             FROM product_upsells pu
             JOIN products p ON p.id = pu.upsell_product_id AND p.status = 'active'
             WHERE pu.main_product_id = ? AND pu.status = 'active'
             LIMIT 1"
        );
        $st->execute([$mainProductId]);
        $offer = $st->fetch();
        if (!$offer) return null;

        // Buyer sudah punya akses aktif ke produk yang ditawarkan → jangan tawarkan.
        $st = $pdo->prepare(
            "SELECT 1 FROM member_access
             WHERE user_id = ? AND product_id = ? AND status = 'active'
               AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1"
        );
        $st->execute([$userId, (int) $offer['upsell_product_id']]);
        if ($st->fetchColumn()) return null;

        // Metode bayar TIDAK menjadi syarat: order upsell mengikuti metode order
        // sumber dan selalu bisa mundur ke instruksi manual (accept-upsell.php) —
        // dulu penawaran disembunyikan bila tak ada gateway hosted aktif, padahal
        // mayoritas instalasi memakai transfer manual/Moota → fitur tak pernah tampil.
        return [
            'id'                => (int) $offer['id'],
            'upsell_product_id' => (int) $offer['upsell_product_id'],
            'upsell_price'      => (float) $offer['upsell_price'],
            'headline'          => (string) ($offer['headline'] ?? ''),
            'description'       => (string) ($offer['description'] ?? ''),
            'product_name'      => (string) $offer['product_name'],
            'normal_price'      => (float) $offer['normal_price'],
            'thumbnail'         => $offer['thumbnail'] ?: null,
        ];
    } catch (Throwable $e) {
        // Tabel belum ada (install lama belum migrasi) / error lain → tanpa penawaran.
        return null;
    }
}

/** Tampilkan penawaran upsell di dashboard/sidebar member? (default MATI, opt-in admin). */
function memberOffersEnabled(): bool
{
    return getSetting('member_offers_enabled', '0') === '1';
}

/** Tampilkan kartu penawaran MELAYANG (floating) di dashboard? (default MATI, opsional). */
function memberOffersFloatingEnabled(): bool
{
    return memberOffersEnabled() && getSetting('member_offers_floating', '0') === '1';
}

/** Judul section penawaran (editable admin). */
function memberOffersTitle(): string
{
    $t = trim((string) getSetting('member_offers_title', ''));
    return $t !== '' ? $t : 'Penawaran Spesial Untukmu';
}

/**
 * Kumpulan penawaran upsell untuk SEORANG MEMBER (konteks dashboard, bukan 1 order).
 * Memakai ULANG tabel `product_upsells` yang sama dengan upsell invoice/thank-you —
 * jadi admin cukup mengaturnya di /admin/upsells. Logika:
 *   - Ambil produk yang dimiliki member = produk pemicu (main) dari order LUNAS yang
 *     item utamanya (non-bump pertama) produk itu — sumbernya dipakai sebagai
 *     `source_order` utk accept-upsell (warisi referral & metode bayar, harga spesial).
 *   - Buang penawaran yang produk tawarannya SUDAH dimiliki member (anti jual ulang).
 *   - Dedup per produk tawaran (ambil order sumber terbaru). Batasi $limit (0 = semua).
 * Read-only; tidak mengubah apa pun. Aman bila tabel belum ada (install lama).
 *
 * @return list<array{upsell_id:int,main_product_id:int,upsell_product_id:int,
 *   upsell_price:float,normal_price:float,headline:string,description:string,
 *   product_name:string,thumbnail:?string,slug:string,source_order:string,
 *   save_amount:float,save_percent:?int}>
 */
function getMemberUpsellOffers(int $userId, int $limit = 0): array
{
    if ($userId <= 0) return [];
    try {
        $pdo = getDB();
        $sql =
            "SELECT pu.id AS upsell_id, pu.main_product_id, pu.upsell_product_id,
                    pu.upsell_price, pu.headline, pu.description,
                    up.name AS product_name, up.price AS normal_price, up.thumbnail, up.slug,
                    src.order_number AS source_order, src.oid AS source_oid
             FROM product_upsells pu
             JOIN products up ON up.id = pu.upsell_product_id AND up.status = 'active'
             JOIN (
                 SELECT o.id AS oid, o.order_number,
                        (SELECT oi.product_id FROM order_items oi
                          WHERE oi.order_id = o.id AND oi.is_bump = 0
                          ORDER BY oi.id ASC LIMIT 1) AS main_pid
                 FROM orders o
                 WHERE o.user_id = ? AND o.status = 'paid'
             ) src ON src.main_pid = pu.main_product_id
             WHERE pu.status = 'active'
               AND NOT EXISTS (
                   SELECT 1 FROM member_access ma
                   WHERE ma.user_id = ? AND ma.product_id = pu.upsell_product_id
                     AND ma.status = 'active' AND (ma.expires_at IS NULL OR ma.expires_at > NOW())
               )
             ORDER BY src.oid DESC";
        $st = $pdo->prepare($sql);
        $st->execute([$userId, $userId]);

        $offers = [];
        $seen   = [];
        foreach ($st->fetchAll() as $r) {
            $upid = (int) $r['upsell_product_id'];
            if (isset($seen[$upid])) continue; // dedup: ambil order sumber terbaru saja
            $seen[$upid] = true;

            $normal = (float) $r['normal_price'];
            $price  = (float) $r['upsell_price'];
            $save   = max(0.0, $normal - $price);
            $offers[] = [
                'upsell_id'         => (int) $r['upsell_id'],
                'main_product_id'   => (int) $r['main_product_id'],
                'upsell_product_id' => $upid,
                'upsell_price'      => $price,
                'normal_price'      => $normal,
                'headline'          => (string) ($r['headline'] ?? ''),
                'description'       => (string) ($r['description'] ?? ''),
                'product_name'      => (string) $r['product_name'],
                'thumbnail'         => $r['thumbnail'] ?: null,
                'slug'              => (string) ($r['slug'] ?? ''),
                'source_order'      => (string) $r['source_order'],
                'save_amount'       => $save,
                'save_percent'      => ($normal > 0 && $save > 0) ? (int) round($save / $normal * 100) : null,
            ];
            if ($limit > 0 && count($offers) >= $limit) break;
        }
        return $offers;
    } catch (Throwable $e) {
        return []; // tabel belum ada / error → tanpa penawaran (fail-soft)
    }
}

/** Jumlah penawaran untuk badge sidebar (ringan). 0 bila fitur mati / tak ada. */
function memberOffersCount(int $userId): int
{
    if (!memberOffersEnabled() || $userId <= 0) return 0;
    try {
        $pdo = getDB();
        $st = $pdo->prepare(
            "SELECT COUNT(DISTINCT pu.upsell_product_id)
             FROM product_upsells pu
             JOIN products up ON up.id = pu.upsell_product_id AND up.status = 'active'
             JOIN (
                 SELECT (SELECT oi.product_id FROM order_items oi
                          WHERE oi.order_id = o.id AND oi.is_bump = 0
                          ORDER BY oi.id ASC LIMIT 1) AS main_pid
                 FROM orders o WHERE o.user_id = ? AND o.status = 'paid'
             ) src ON src.main_pid = pu.main_product_id
             WHERE pu.status = 'active'
               AND NOT EXISTS (
                   SELECT 1 FROM member_access ma
                   WHERE ma.user_id = ? AND ma.product_id = pu.upsell_product_id
                     AND ma.status = 'active' AND (ma.expires_at IS NULL OR ma.expires_at > NOW())
               )"
        );
        $st->execute([$userId, $userId]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Biaya awal (setup fee) yang BERLAKU untuk pembelian produk oleh user tertentu.
 * Hanya dibebankan pada PEMBELIAN PERTAMA — bila user sudah pernah beli produk ini
 * (mis. perpanjangan setelah akses habis), biaya awal = 0.
 *
 * @param array $product Baris produk (butuh 'id' & 'setup_fee').
 * @param int   $userId  0 = tamu belum teridentifikasi → dianggap pembelian pertama.
 */
function productSetupFee(array $product, int $userId): float
{
    $fee = (float) ($product['setup_fee'] ?? 0);
    if ($fee <= 0) return 0.0;
    if ($userId <= 0) return $fee; // tamu baru → pembelian pertama
    return userHasPurchasedProduct($userId, (int) $product['id']) ? 0.0 : $fee;
}

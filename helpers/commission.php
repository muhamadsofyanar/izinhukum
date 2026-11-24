<?php
// ─── Komisi & Membership Level ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

// ─── JV Partner (bagi hasil penjualan) ────────────────────────
/**
 * Catat jatah partner JV utk sebuah order LUNAS. Basis = total order − komisi
 * affiliate order tsb (keputusan terkunci). Idempoten via UNIQUE order_id (INSERT
 * IGNORE — webhook dobel tak dobel catat). No-op bila produk tak ber-partner /
 * persen 0 / jatah <= 0. Dipanggil utk SEMUA order paid produk JV termasuk
 * booking DP & settlement (bagi hasil atas tiap uang masuk; totalnya = harga penuh).
 * Pembayaran ke partner MANUAL oleh admin — ini murni pembukuan.
 */
function jvRecordEarning(PDO $pdo, array $order, int $mainProductId): void
{
    if ($mainProductId <= 0) return;
    try {
        $p = $pdo->prepare('SELECT jv_partner_user_id, jv_partner_percent FROM products WHERE id = ? LIMIT 1');
        $p->execute([$mainProductId]);
        $prod = $p->fetch(PDO::FETCH_ASSOC);
        if (!$prod || empty($prod['jv_partner_user_id'])) return;
        $percent = (float) $prod['jv_partner_percent'];
        if ($percent <= 0) return;
        $partnerId = (int) $prod['jv_partner_user_id'];
        if ($partnerId === (int) $order['user_id']) return; // partner beli produknya sendiri → tanpa jatah

        $c = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE order_id = ? AND status <> 'rejected'");
        $c->execute([(int) $order['id']]);
        $affCommission = (float) $c->fetchColumn();

        $total  = (float) ($order['total'] ?? 0);
        $amount = round(max(0, $total - $affCommission) * $percent / 100, 2);
        if ($amount <= 0) return;

        $pdo->prepare(
            'INSERT IGNORE INTO jv_earnings
             (order_id, product_id, partner_user_id, order_total, affiliate_commission, percent, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([(int) $order['id'], $mainProductId, $partnerId, $total, $affCommission, $percent, $amount]);
    } catch (Throwable $e) {
        error_log('jvRecordEarning: ' . $e->getMessage());
    }
}

function getMembershipLevel(int $userId): ?array
{
    try {
        $stmt = getDB()->prepare(
            'SELECT ml.* FROM membership_levels ml
             JOIN users u ON u.membership_level_id = ml.id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    } catch (Exception $e) {
        error_log('getMembershipLevel error: ' . $e->getMessage());
        return null;
    }
}

function updateUserMembershipLevel(int $userId): bool
{
    try {
        $pdo = getDB();

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total),0) FROM orders WHERE user_id = ? AND status = 'paid'"
        );
        $stmt->execute([$userId]);
        $totalSpend = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT id FROM membership_levels WHERE min_spend <= ? ORDER BY min_spend DESC LIMIT 1'
        );
        $stmt->execute([$totalSpend]);
        $levelId = $stmt->fetchColumn();

        if ($levelId) {
            $pdo->prepare('UPDATE users SET membership_level_id = ? WHERE id = ?')
                ->execute([$levelId, $userId]);
        }
        return true;
    } catch (Exception $e) {
        error_log('updateUserMembershipLevel error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Rekalkulasi & sinkronisasi level membership seluruh pengguna berdasarkan total belanja (min_spend).
 * Dipanggil otomatis di admin/users & admin/membership-levels untuk self-healing data masal.
 */
function recalculateAllUserMembershipLevels(?PDO $pdo = null, bool $force = false): int
{
    static $done = false;
    if ($done && !$force) return 0;
    $done = true;
    if (!$pdo) $pdo = getDB();
    try {
        // THROTTLE: recalc MASAL mahal (UPDATE ribuan user, subquery SUM per baris).
        // Dulu jalan TIAP buka /admin/users → berat tiap page-view. Tidak perlu:
        // level yang DITAMPILKAN sudah dihitung live via subquery di halaman, dan
        // updateUserMembershipLevel() jalan tiap order lunas (real-time per user).
        // Recalc massal cukup sesekali (maks 1x / 30 menit) untuk menangkap import
        // massal / perubahan ambang. $force=true (mis. saat ambang membership diubah)
        // melewati throttle agar sinkron seketika.
        if (!$force) {
            $last = (int) getSetting('membership_recalc_at', '0');
            if (time() - $last < 1800) return 0;
        }

        $sql = "UPDATE users u
                SET u.membership_level_id = (
                    SELECT ml.id FROM membership_levels ml
                    WHERE ml.min_spend <= COALESCE((SELECT SUM(o.total) FROM orders o WHERE o.user_id = u.id AND o.status = 'paid'), 0)
                    ORDER BY ml.min_spend DESC
                    LIMIT 1
                )";
        $affected = (int) $pdo->exec($sql);
        setSetting('membership_recalc_at', (string) time(), 'system');
        return $affected;
    } catch (Exception $e) {
        error_log('recalculateAllUserMembershipLevels error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Berikan membership level bonus (mis. dari Lead Magnet) ke user TANPA men-downgrade.
 * Tingkat ditentukan oleh min_spend (selaras dgn updateUserMembershipLevel).
 * Hanya menaikkan bila level bonus lebih tinggi dari level user saat ini.
 *
 * @return string 'granted' (dinaikkan), 'kept_higher' (sudah ≥ bonus, dibiarkan), 'noop' (gagal/level tak ada)
 *
 * Catatan: level ini bisa di-recalc oleh updateUserMembershipLevel() saat ada
 * pembelian (berbasis total belanja). Bonus tidak "sticky" terhadap recalc itu.
 */
function lead_grant_membership_level(int $userId, int $bonusLevelId): string
{
    if ($userId <= 0 || $bonusLevelId <= 0) return 'noop';
    try {
        $pdo = getDB();

        $st = $pdo->prepare('SELECT min_spend FROM membership_levels WHERE id = ? LIMIT 1');
        $st->execute([$bonusLevelId]);
        $bonusSpend = $st->fetchColumn();
        if ($bonusSpend === false) return 'noop'; // level bonus tidak ada
        $bonusSpend = (float) $bonusSpend;

        $st = $pdo->prepare(
            'SELECT u.membership_level_id AS cur_id, ml.min_spend AS cur_spend
             FROM users u
             LEFT JOIN membership_levels ml ON ml.id = u.membership_level_id
             WHERE u.id = ? LIMIT 1'
        );
        $st->execute([$userId]);
        $row = $st->fetch();
        if (!$row) return 'noop';

        // Tanpa level → anggap paling rendah (-1) agar bonus apa pun = kenaikan.
        $curSpend = ($row['cur_id'] !== null) ? (float) $row['cur_spend'] : -1.0;

        if ($bonusSpend > $curSpend) {
            $pdo->prepare('UPDATE users SET membership_level_id = ? WHERE id = ?')
                ->execute([$bonusLevelId, $userId]);
            return 'granted';
        }
        return 'kept_higher'; // sudah punya level ≥ bonus → JANGAN downgrade
    } catch (Exception $e) {
        error_log('lead_grant_membership_level error: ' . $e->getMessage());
        return 'noop';
    }
}

/**
 * Rate komisi DEFAULT global yang LIVE (setting "Komisi Default" di /admin/settings).
 * Sumber kebenaran tunggal untuk fallback default — hormati nilai 0 eksplisit
 * (hanya setting yang benar-benar absen → default 10). JANGAN pakai pola
 * `getSetting(...) ?: 10` karena itu mengubah 0 yang disengaja menjadi 10.
 */
function affiliateDefaultCommissionRate(): float {
    return (float) getSetting('affiliate_commission_rate', '10');
}

/**
 * True bila member DITARGETKAN secara spesifik oleh sebuah Aturan Komisi aktif,
 * yaitu rule yang menaikkan/menetapkan rate hanya untuk sebagian member:
 *   - rule per LEVEL membership (membership_level_id = level member ini), ATAU
 *   - rule RESELL bersyarat kepemilikan (required_product_id yang DIMILIKI member,
 *     via member_access aktif & belum kedaluwarsa) — mis. pembeli lisensi resell.
 *
 * Rule yang mengunci produk saja atau global (product_id/level/required semua NULL
 * atau hanya product_id) SENGAJA tidak dihitung: itu berlaku untuk SEMUA member dan
 * tidak membedakan siapa pun (default global pun demikian). Dipakai bersama
 * affiliateHasActivity() untuk memutuskan tampil/tidaknya menu Affiliate: member
 * yang berhak atas rate khusus adalah affiliate yang dimaksud, jadi menunya tampil
 * walau belum ada referral/komisi. Logika pencocokan meniru resolveCommissionRate().
 * Fail-OPEN (true) saat error DB agar menu yang semestinya ada tak hilang diam-diam.
 */
function affiliateHasCommissionEntitlement(int $userId): bool
{
    if ($userId <= 0) return false;
    try {
        $pdo = getDB();

        $stmt = $pdo->prepare("SELECT membership_level_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $levelRaw = $stmt->fetchColumn();
        $levelId  = ($levelRaw !== false && $levelRaw !== null) ? (int) $levelRaw : null;

        $stmt = $pdo->prepare(
            "SELECT 1 FROM commission_rules cr
             WHERE cr.status = 'active'
               AND (
                     (cr.membership_level_id IS NOT NULL AND cr.membership_level_id = :lvl)
                  OR (cr.required_product_id IS NOT NULL AND EXISTS (
                        SELECT 1 FROM member_access ma
                        WHERE ma.user_id = :uid
                          AND ma.product_id = cr.required_product_id
                          AND ma.status = 'active'
                          AND (ma.expires_at IS NULL OR ma.expires_at > NOW())))
                   )
             LIMIT 1"
        );
        $stmt->execute([':lvl' => $levelId, ':uid' => $userId]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('affiliateHasCommissionEntitlement: ' . $e->getMessage());
        return true;
    }
}

function resolveCommissionRate(int $affiliateUserId, int $productId): float {
    try {
        $pdo = getDB();

        // Ambil membership level affiliate
        $stmt = $pdo->prepare("SELECT membership_level_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$affiliateUserId]);
        $levelIdRaw = $stmt->fetchColumn();
        $levelId    = ($levelIdRaw !== false && $levelIdRaw !== null) ? (int) $levelIdRaw : null;

        // ─── Prioritas rate (keputusan terkunci): rule yang MENGUNCI produk ini atau
        // bersyarat kepemilikan (resell) > angka EKSPLISIT di produk > rule UMUM
        // (level/global) > default affiliate > setting global. Nilai komisi yang diisi
        // eksplisit di produk (products.affiliate_commission) mengalahkan rule UMUM level
        // (mis. Gold), TAPI rule yang khusus menunjuk produk / resell tetap menang.

        // ─── Tingkat 1: rule yang MENGUNCI ke produk ini (product_id = produk) atau
        // bersyarat kepemilikan (required_product_id, v1.2.28 — model resell: pembeli
        // lisensi resell produk A dapat rate khusus mis. 100 saat menjual produk A
        // personal; akses resell yang expired otomatis kembali ke rate normal). Override
        // SENGAJA per-produk → menang atas angka etalase products.affiliate_commission.
        // Spesifisitas: syarat-produk > produk; priority hanya pemutus seri.
        $stmt = $pdo->prepare(
            "SELECT commission_rate FROM commission_rules
             WHERE status = 'active'
               AND (product_id = :pid OR product_id IS NULL)
               AND (membership_level_id = :lvl OR membership_level_id IS NULL)
               AND (product_id = :pid2 OR required_product_id IS NOT NULL)
               AND (required_product_id IS NULL OR EXISTS (
                     SELECT 1 FROM member_access ma
                     WHERE ma.user_id = :uid AND ma.product_id = required_product_id
                       AND ma.status = 'active'
                       AND (ma.expires_at IS NULL OR ma.expires_at > NOW())))
             ORDER BY
               (required_product_id IS NOT NULL) DESC,
               (product_id IS NOT NULL) DESC,
               (membership_level_id IS NOT NULL) DESC,
               priority DESC
             LIMIT 1"
        );
        $stmt->execute([':pid' => $productId, ':lvl' => $levelId, ':pid2' => $productId, ':uid' => $affiliateUserId]);
        $rate = $stmt->fetchColumn();
        if ($rate !== false) {
            return (float) $rate;
        }

        // ─── Tingkat 2: angka EKSPLISIT di produk (products.affiliate_commission, v1.2.28).
        // Nilai ini DITAMPILKAN di halaman produk publik sebagai janji rate. Kini dibaca
        // SEBELUM rule umum (keputusan terkunci): yang dijanjikan produk = yang dibayar,
        // tak bisa ditimpa rule level umum. 0 = tidak diisi → lanjut ke fallback berikutnya.
        if ($productId > 0) {
            $stmt = $pdo->prepare('SELECT affiliate_commission FROM products WHERE id = ? LIMIT 1');
            $stmt->execute([$productId]);
            $productRate = $stmt->fetchColumn();
            if ($productRate !== false && $productRate !== null && (float) $productRate > 0) {
                return (float) $productRate;
            }
        }

        // ─── Opsi "produk tanpa komisi di-set = TANPA komisi afiliasi" (saklar pemilik,
        // setting affiliate_zero_no_commission, default MATI). Saat NYALA: produk yang
        // tak punya rule khusus/resell DAN kolom komisinya 0 = BUKAN produk afiliasi →
        // komisi 0, abaikan rule UMUM & default global. Default MATI → perilaku lama
        // (fallback global/level) dipertahankan agar instalasi existing tak berubah.
        // Hanya berlaku bila konteks produk jelas ($productId > 0).
        if ($productId > 0 && getSetting('affiliate_zero_no_commission', '0') === '1') {
            return 0.0;
        }

        // ─── Tingkat 3: rule UMUM (tidak mengunci produk & tanpa syarat kepemilikan),
        // mis. rate per level membership atau rate global semua produk. Dicek SETELAH
        // angka eksplisit produk. Spesifisitas: level > global; priority pemutus seri.
        $stmt = $pdo->prepare(
            "SELECT commission_rate FROM commission_rules
             WHERE status = 'active'
               AND product_id IS NULL
               AND required_product_id IS NULL
               AND (membership_level_id = :lvl OR membership_level_id IS NULL)
             ORDER BY (membership_level_id IS NOT NULL) DESC, priority DESC
             LIMIT 1"
        );
        $stmt->execute([':lvl' => $levelId]);
        $rate = $stmt->fetchColumn();
        if ($rate !== false) {
            return (float) $rate;
        }

        // ─── Tingkat 4 (default): "Komisi Default" global LIVE = sumber kebenaran.
        // Kolom affiliates.commission_rate SENGAJA tidak dipakai lagi: itu hanya
        // snapshot basi default global saat baris affiliate dibuat (tak ada UI override
        // per-affiliate), yang dulu menaungi perubahan setting → set 0 tetap bayar 35.
        // Override NYATA tetap lewat Aturan Komisi (Tingkat 1/3) & angka komisi per
        // produk (Tingkat 2); bila tak ada, default global yang berlaku (termasuk 0).
        return affiliateDefaultCommissionRate();

    } catch (Exception $e) {
        error_log('resolveCommissionRate error: ' . $e->getMessage());
        return affiliateDefaultCommissionRate();
    }
}

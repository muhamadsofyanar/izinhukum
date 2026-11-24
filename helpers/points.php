<?php
// ─── Poin Reward (loyalitas member) ────────────────────────────────────
// Member dapat poin tiap order LUNAS; poin ditukar jadi potongan di checkout login.
//
// PRINSIP (poin = uang, jadi disiplinnya sama dengan komisi):
//  1. SUMBER KEBENARAN = tabel point_transactions (append-only, bisa diaudit).
//     users.points_balance hanya CACHE agar pembacaan cepat.
//  2. Semua mutasi saldo WAJIB lewat pointsRecord() → ledger & cache selalu sejalan.
//  3. Anti dobel: UNIQUE(order_id, type) di ledger. onOrderPaid yang terpanggil dua
//     kali TIDAK bisa memberi poin dua kali (INSERT ditolak diam-diam).
//  4. Anti balapan: pointsRedeem() mengunci baris user (FOR UPDATE) di dalam
//     transaksi order → 2 tab checkout tak bisa memakai poin yang sama.
//  5. Potongan DIBATASI persen dari subtotal → total tak pernah jatuh ke Rp 0
//     (mencegah terbukanya kembali jalur "order gratis" — lihat v1.2.140).

/** Fitur poin aktif? */
function pointsEnabled(): bool
{
    return getSetting('points_enabled', '0') === '1';
}

/** Label satuan poin (bisa di-rebrand admin: Poin / Koin / Reward / Cashback). */
function pointsLabel(): string
{
    $l = trim((string) getSetting('points_label', 'Poin'));
    return $l !== '' ? $l : 'Poin';
}

/** Label menu member (default "Reward Saya"). */
function pointsMenuLabel(): string
{
    $l = trim((string) getSetting('points_menu_label', 'Reward Saya'));
    return $l !== '' ? $l : 'Reward Saya';
}

/** Rupiah yang dibutuhkan untuk mendapat 1 poin (default 1000). */
function pointsEarnPerAmount(): float
{
    $v = (float) getSetting('points_earn_per_amount', '1000');
    return $v > 0 ? $v : 1000.0;
}

/** Nilai tukar 1 poin dalam rupiah (default 50). */
function pointsValue(): float
{
    $v = (float) getSetting('points_value', '50');
    return $v > 0 ? $v : 0.0;
}

/** Minimal poin agar bisa ditukar (default 100). */
function pointsMinRedeem(): int
{
    return max(0, (int) getSetting('points_min_redeem', '100'));
}

/** Batas potongan poin sebagai persen dari subtotal (default 50, dikunci 1..100). */
function pointsMaxDiscountPercent(): float
{
    $p = (float) getSetting('points_max_discount_percent', '50');
    if ($p <= 0)   return 0.0;
    if ($p > 100)  return 100.0;
    return $p;
}

/**
 * Poin yang diperoleh dari sebuah nominal yang BENAR-BENAR dibayar.
 * Sengaja memakai nominal dibayar (bukan subtotal) supaya bayar-pakai-poin tidak
 * menghasilkan poin penuh lagi (mencegah putaran poin tak terbatas).
 */
function pointsEarnFor(float $paidAmount): int
{
    if (!pointsEnabled() || $paidAmount <= 0) return 0;
    return (int) floor($paidAmount / pointsEarnPerAmount());
}

/** Nilai rupiah dari sejumlah poin. */
function pointsToRupiah(int $points): float
{
    if ($points <= 0) return 0.0;
    return round($points * pointsValue(), 2);
}

/** Saldo poin user (dari cache; direkonsiliasi oleh pointsRecord). */
function pointsBalance(int $userId): int
{
    if ($userId <= 0) return 0;
    try {
        $st = getDB()->prepare('SELECT points_balance FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $v = $st->fetchColumn();
        return $v === false ? 0 : (int) $v;
    } catch (Throwable $e) {
        error_log('pointsBalance: ' . $e->getMessage());
        return 0; // kolom belum ada (pra-migrasi) → anggap 0, fitur diam
    }
}

/**
 * Berapa poin maksimal yang MASUK AKAL dipakai untuk sebuah order, beserta nilai
 * rupiahnya. Dipakai bersama oleh checkout (hitung nyata) & UI (pratinjau) supaya
 * angka di layar tak pernah beda dengan yang dipotong server.
 *
 * Urutan pagar: saldo → batas persen subtotal → sisa tagihan setelah kupon.
 *
 * @return array{points:int, discount:float}
 */
function pointsPlanRedeem(int $balance, float $subtotal, float $couponDiscount = 0.0): array
{
    $none = ['points' => 0, 'discount' => 0.0];
    if (!pointsEnabled() || $balance <= 0 || $subtotal <= 0) return $none;
    if ($balance < pointsMinRedeem()) return $none;

    $unit = pointsValue();
    if ($unit <= 0) return $none;

    // Batas 1: persen dari subtotal (pagar anti total Rp 0).
    $capByPercent = $subtotal * (pointsMaxDiscountPercent() / 100);
    // Batas 2: tak boleh melebihi sisa tagihan setelah kupon.
    $capByRemain  = max(0.0, $subtotal - $couponDiscount);
    $capRupiah    = min($capByPercent, $capByRemain);
    if ($capRupiah <= 0) return $none;

    // Bulatkan KE BAWAH ke kelipatan poin utuh — user tak pernah "kehilangan" poin
    // untuk potongan pecahan yang tak terpakai.
    $usablePoints = (int) floor($capRupiah / $unit);
    $usablePoints = min($usablePoints, $balance);
    if ($usablePoints <= 0) return $none;

    return ['points' => $usablePoints, 'discount' => round($usablePoints * $unit, 2)];
}

/**
 * Catat mutasi poin ke ledger + sinkronkan cache saldo. SATU-SATUNYA pintu menulis.
 * Idempoten untuk mutasi bertaut order: UNIQUE(order_id,type) → percobaan kedua
 * mengembalikan false tanpa mengubah saldo.
 *
 * WAJIB dipanggil di dalam transaksi pemanggil bila harus atomik dengan order.
 *
 * @param int $delta  poin (+ menambah, - mengurangi)
 * @return bool true bila benar-benar tercatat
 */
function pointsRecord(PDO $pdo, int $userId, ?int $orderId, string $type, int $delta, string $note = ''): bool
{
    if ($userId <= 0 || $delta === 0) return false;
    if (!in_array($type, ['earn', 'redeem', 'reverse_earn', 'refund_redeem', 'adjust'], true)) return false;

    try {
        // Saldo terkunci → hitung balance_after yang konsisten walau ada request paralel.
        $lock = $pdo->prepare('SELECT points_balance FROM users WHERE id = ? FOR UPDATE');
        $lock->execute([$userId]);
        $cur = $lock->fetchColumn();
        if ($cur === false) return false;
        $after = max(0, (int) $cur + $delta);

        $ins = $pdo->prepare(
            'INSERT INTO point_transactions (user_id, order_id, type, points, balance_after, note)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$userId, $orderId, $type, $delta, $after, ($note !== '' ? mb_substr($note, 0, 191) : null)]);

        $pdo->prepare('UPDATE users SET points_balance = ? WHERE id = ?')->execute([$after, $userId]);
        return true;
    } catch (Throwable $e) {
        // Duplikat (order+type sudah pernah dicatat) = perilaku yang DIINGINKAN: no-op.
        if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
            error_log('pointsRecord: mutasi dobel diabaikan (order ' . (string) $orderId . '/' . $type . ')');
            return false;
        }
        error_log('pointsRecord: ' . $e->getMessage());
        return false;
    }
}

/**
 * Pakai poin untuk sebuah order. Mengunci saldo lalu memverifikasi ULANG di dalam
 * lock (saldo bisa berubah sejak layar checkout dirender / tab lain memakainya).
 * Mengembalikan jumlah poin & rupiah yang BENAR-BENAR dipakai (0 bila tak memenuhi).
 *
 * @return array{points:int, discount:float}
 */
function pointsRedeemForOrder(PDO $pdo, int $userId, int $orderId, int $requestedPoints, float $subtotal, float $couponDiscount = 0.0): array
{
    $none = ['points' => 0, 'discount' => 0.0];
    if (!pointsEnabled() || $userId <= 0 || $requestedPoints <= 0) return $none;

    try {
        $lock = $pdo->prepare('SELECT points_balance FROM users WHERE id = ? FOR UPDATE');
        $lock->execute([$userId]);
        $balance = $lock->fetchColumn();
        if ($balance === false) return $none;

        // Hitung ulang dari saldo TERKUNCI — layar tak dipercaya.
        $plan = pointsPlanRedeem((int) $balance, $subtotal, $couponDiscount);
        $use  = min($requestedPoints, $plan['points']);
        if ($use <= 0 || $use < pointsMinRedeem()) return $none;

        $discount = round($use * pointsValue(), 2);
        if ($discount <= 0) return $none;

        if (!pointsRecord($pdo, $userId, $orderId, 'redeem', -$use, 'Potongan order')) return $none;
        return ['points' => $use, 'discount' => $discount];
    } catch (Throwable $e) {
        error_log('pointsRedeemForOrder: ' . $e->getMessage());
        return $none;
    }
}

/**
 * Beri poin atas order LUNAS. Basis = nominal yang benar-benar dibayar (orders.total).
 * Aman dipanggil berulang (UNIQUE order+earn). No-op bila fitur mati / poin 0.
 */
function pointsAwardForOrder(PDO $pdo, array $order): int
{
    if (!pointsEnabled()) return 0;
    $userId = (int) ($order['user_id'] ?? 0);
    $total  = (float) ($order['total'] ?? 0);
    if ($userId <= 0 || $total <= 0) return 0;

    $earn = pointsEarnFor($total);
    if ($earn <= 0) return 0;

    $ok = pointsRecord(
        $pdo, $userId, (int) $order['id'], 'earn', $earn,
        'Pembelian ' . (string) ($order['order_number'] ?? '')
    );
    return $ok ? $earn : 0;
}

/**
 * Batalkan efek poin sebuah order yang dibatalkan/gagal:
 *  - poin hadiah ditarik kembali (reverse_earn)
 *  - poin yang dipakai dikembalikan ke member (refund_redeem)
 * Keduanya idempoten (UNIQUE order+type). Dipanggil dari jalur pembatalan order.
 */
function pointsReverseForOrder(PDO $pdo, array $order): void
{
    $userId  = (int) ($order['user_id'] ?? 0);
    $orderId = (int) ($order['id'] ?? 0);
    if ($userId <= 0 || $orderId <= 0) return;

    try {
        $st = $pdo->prepare("SELECT type, points FROM point_transactions WHERE order_id = ? AND type IN ('earn','redeem')");
        $st->execute([$orderId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pts = (int) $row['points'];
            if ($row['type'] === 'earn' && $pts > 0) {
                // tarik kembali hadiah (saldo tak boleh minus — dijaga di pointsRecord)
                pointsRecord($pdo, $userId, $orderId, 'reverse_earn', -$pts, 'Order dibatalkan');
            } elseif ($row['type'] === 'redeem' && $pts < 0) {
                // kembalikan poin yang sudah dipotong
                pointsRecord($pdo, $userId, $orderId, 'refund_redeem', -$pts, 'Order dibatalkan');
            }
        }
    } catch (Throwable $e) {
        error_log('pointsReverseForOrder: ' . $e->getMessage());
    }
}

<?php
// ─── Reseller Salespage ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/** Teks WA default tombol Order Personal Use (placeholder {produk}). */
function defaultSalespageWaTemplate(): string
{
    return 'Halo, saya mau order *{produk}* untuk personal use.';
}

/**
 * Apakah $userId berhak mengelola salespage produk PERSONAL $personalProductId?
 * Model resell: produk Personal (P) salespage-enabled + ditautkan ke produk Resell (R)
 * via products.resell_product_id; reseller harus MEMILIKI akses aktif ke R (beli lisensi
 * resell → boleh jual P). Dipakai guard save action reseller + eligibility.
 */
function resellerCanManageSalespage(int $userId, int $personalProductId): bool
{
    if ($userId <= 0 || $personalProductId <= 0) return false;
    try {
        $st = getDB()->prepare("SELECT resell_product_id FROM products WHERE id = ? AND salespage_enabled = 1 AND status = 'active' LIMIT 1");
        $st->execute([$personalProductId]);
        $rid = (int) ($st->fetchColumn() ?: 0);
        return $rid > 0 && hasAccess($userId, $rid);
    } catch (Exception $e) {
        error_log('resellerCanManageSalespage: ' . $e->getMessage());
        return false;
    }
}

/**
 * Setting salespage efektif untuk (reseller, produk): baris reseller_salespage bila
 * ada, jika tidak → default sistem (teks WA standar + tombol resell ON).
 * @return array{wa_template:string, resell_enabled:bool}
 */
function resellerSalespageSettings(?int $userId, int $productId): array
{
    $out = ['wa_template' => defaultSalespageWaTemplate(), 'resell_enabled' => true];
    if (!$userId || $userId <= 0 || $productId <= 0) return $out;
    try {
        $pdo = getDB();
        ensureResellerSalespageTable($pdo);
        $st = $pdo->prepare("SELECT wa_template, resell_enabled FROM reseller_salespage WHERE user_id = ? AND product_id = ? LIMIT 1");
        $st->execute([$userId, $productId]);
        $row = $st->fetch();
        if ($row) {
            $tpl = trim((string) ($row['wa_template'] ?? ''));
            if ($tpl !== '') $out['wa_template'] = $tpl;
            $out['resell_enabled'] = (int) $row['resell_enabled'] === 1;
        }
    } catch (Exception $e) {
        error_log('resellerSalespageSettings: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Bentuk link WhatsApp (wa.me) dari nomor + pesan opsional. Normalisasi nomor
 * Indonesia: buang non-digit, '0' di depan → '62', '8...' tanpa kode → '62...'.
 * Pesan di-rawurlencode. Kembalikan '' bila nomor kosong/tak valid (caller bisa
 * menyembunyikan tombol). Sentralisasi pola wa.me yang tersebar di banyak halaman.
 */
function waLink(?string $phone, string $text = ''): string
{
    $digits = preg_replace('/[^0-9]/', '', (string) $phone);
    if ($digits === '') return '';
    if (str_starts_with($digits, '0')) {
        $digits = '62' . substr($digits, 1);
    } elseif (str_starts_with($digits, '8')) {
        $digits = '62' . $digits;
    }
    // Nomor terlalu pendek → anggap tak valid.
    if (strlen($digits) < 9) return '';
    $url = 'https://wa.me/' . $digits;
    if ($text !== '') {
        $url .= '?text=' . rawurlencode($text);
    }
    return $url;
}

/**
 * Apakah user ini punya minimal SATU "Link Salespage Siap Pakai"?
 * Yaitu produk Personal (salespage aktif, status active, tertaut produk Resell)
 * di mana user MEMILIKI akses aktif ke produk Resell-nya (beli hak resell).
 * Kriteria IDENTIK dengan daftar di pages/user/reseller.php (EXISTS, ringan).
 * Dipakai sidebar-user.php untuk menampilkan menu Reseller hanya bila tersedia.
 * Fail-closed (return false saat error) → tidak menampilkan menu yang kosong.
 */
function userHasResellerSalespages(int $userId): bool
{
    if ($userId <= 0) return false;
    try {
        $st = getDB()->prepare(
            "SELECT 1 FROM products p
              WHERE p.salespage_enabled = 1 AND p.status = 'active' AND p.resell_product_id IS NOT NULL
                AND EXISTS (
                  SELECT 1 FROM member_access ma
                   WHERE ma.user_id = ? AND ma.product_id = p.resell_product_id
                     AND ma.status = 'active' AND (ma.expires_at IS NULL OR ma.expires_at > NOW())
                )
              LIMIT 1"
        );
        $st->execute([$userId]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        error_log('userHasResellerSalespages: ' . $e->getMessage());
        return false;
    }
}

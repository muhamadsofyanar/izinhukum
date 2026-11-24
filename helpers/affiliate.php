<?php
// ─── Affiliate (kode & atribusi cookie) ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/**
 * Pagar runtime program afiliasi. Saat setting 'affiliate_enabled' mati (nilai != '1'),
 * program dianggap NONAKTIF: tak boleh ada sarana promosi, atribusi referral, atau komisi
 * BARU. Data & saldo/pencairan/riwayat lama TETAP dipertahankan (hanya aliran baru yang
 * dihentikan). Default '1' (aktif) agar instalasi lama tidak berubah perilaku.
 */
function affiliateProgramEnabled(): bool
{
    return (string) getSetting('affiliate_enabled', '1') === '1';
}

/**
 * Apakah atribusi AMBIENT (cookie/sesi dari klik link afiliasi) sedang aktif.
 * TRUE hanya bila program afiliasi hidup DAN General Cookie nyala. Dipakai oleh
 * tampilan (badge referral & prefill hidden field di halaman checkout) agar
 * KONSISTEN dengan resolveAffiliateForCheckout: saat mati, jangan menampilkan
 * atau menjanjikan atribusi yang tidak akan dicatat. Tidak memengaruhi Lock
 * Customer (binding persisten) yang punya pagar sendiri di resolver.
 */
function affiliateAmbientEnabled(): bool
{
    return affiliateProgramEnabled() && getSetting('affiliate_general_cookie', '1') === '1';
}

function generateAffiliateCode(): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $len   = strlen($chars);

    do {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, $len - 1)];
        }
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE affiliate_code = ?');
            $stmt->execute([$code]);
            $exists = (int) $stmt->fetchColumn() > 0;

            // Cek juga di tabel affiliates
            if (!$exists) {
                $stmt2 = $pdo->prepare('SELECT COUNT(*) FROM affiliates WHERE code = ?');
                $stmt2->execute([$code]);
                $exists = (int) $stmt2->fetchColumn() > 0;
            }
        } catch (Exception $e) {
            error_log('generateAffiliateCode error: ' . $e->getMessage());
            $exists = false;
        }
    } while ($exists);

    return $code;
}

/**
 * Simpan cookie referral afiliasi ('averion_ref') sesuai pengaturan `affiliate_cookie_days`.
 * Jika affiliate_cookie_days == 0 → masa aktif 10 tahun (selamanya / lifetime).
 * Jika affiliate_cookie_days > 0 → masa aktif $days * 86400 detik.
 */
function setAffiliateCookie(string $code): void
{
    // Pagar: program afiliasi mati → jangan atribusikan referral baru.
    if (!affiliateProgramEnabled()) return;

    $code = strtoupper(trim($code));
    if ($code === '') return;

    $_SESSION['ref_code'] = $code;

    $days = (int) getSetting('affiliate_cookie_days', '30');
    if ($days === 0) {
        $expire = time() + (10 * 365 * 86400); // 10 tahun (selamanya / lifetime)
    } else {
        $expire = time() + ($days * 86400);
    }

    if (!headers_sent()) {
        @setcookie('averion_ref', $code, $expire, '/', '', false, true);
        @setcookie('aff_code', $code, $expire, '/', '', false, true);
    }
}

/**
 * Resolusi Afiliat untuk Checkout berdasarkan Hirarki 3 Tingkat:
 * 1. Customer Binding (Kaitkan Afiliasi): Jika `affiliate_lock_customer` == 1 dan `users.referred_by_affiliate_id` terisi, PAKAI AFILIAT TERKUNCI TERSEBUT.
 * 2. Referral Ambient: Jika belum terkunci (atau mode lock nonaktif), gunakan `refCode` ambient (dari form prefilled/session/cookie).
 * 3. General Cookie: Jika `affiliate_general_cookie` == 0, SELURUH atribusi ambient (kode yang dioper caller + session + cookie) diabaikan; hanya Lock Customer (Tingkat 1) yang berlaku.
 *
 * Mengembalikan array: ['affiliate_id' => ?int, 'affiliate_code' => ?string, 'is_locked' => bool]
 */
function resolveAffiliateForCheckout(PDO $pdo, int $userId, string $refCode = '', ?int $productId = null, ?int $bundleId = null): array
{
    // Pagar: program afiliasi mati → tak ada afiliat terpilih (tak ada komisi baru),
    // meski ada cookie/binding lama. Data & saldo lama tidak disentuh.
    if (!affiliateProgramEnabled()) {
        return ['affiliate_id' => null, 'affiliate_code' => null, 'is_locked' => false];
    }

    ensureUserReferredByColumn($pdo);

    $lockEnabled    = getSetting('affiliate_lock_customer', '0') === '1';
    $generalEnabled = getSetting('affiliate_general_cookie', '1') === '1';

    // ─── TINGKAT 1: Customer Binding (Kaitkan Afiliasi) ───────
    if ($lockEnabled && $userId > 0) {
        $uStmt = $pdo->prepare("SELECT referred_by_affiliate_id FROM users WHERE id = ? LIMIT 1");
        $uStmt->execute([$userId]);
        $boundAffId = (int) ($uStmt->fetchColumn() ?: 0);

        if ($boundAffId > 0) {
            $affStmt = $pdo->prepare("SELECT id, code, user_id FROM affiliates WHERE id = ? AND status = 'active' LIMIT 1");
            $affStmt->execute([$boundAffId]);
            if ($aff = $affStmt->fetch()) {
                if ((int) $aff['user_id'] !== $userId) {
                    return [
                        'affiliate_id'   => (int) $aff['id'],
                        'affiliate_code' => (string) $aff['code'],
                        'is_locked'      => true,
                    ];
                }
            }
        }
    }

    // ─── TINGKAT 2: Referral AMBIENT (input form prefilled / session / cookie) ───
    // Semua sumber referral di checkout bersifat AMBIENT: di-set saat pengunjung
    // mengklik link afiliasi (setAffiliateCookie → cookie averion_ref/aff_code +
    // $_SESSION['ref_code']), lalu di-prefill ke hidden field form. Tak ada field
    // kode yang diketik manual oleh pembeli. Karena itu "General Cookie" mengatur
    // SELURUH atribusi ambient: saat MATI (affiliate_general_cookie=0), kode yang
    // dioper caller (berasal dari cookie/sesi) MAUPUN cookie/sesi yang dibaca di
    // sini SEMUA diabaikan — hanya binding Lock Customer (Tingkat 1) yang berlaku.
    // Sebelumnya gate hanya membatasi pembacaan cookie di fungsi ini, sehingga
    // jalur yang mengoper nilai cookie sebagai "kode eksplisit" (checkout tamu) &
    // pembacaan sesi lolos dari gate — asimetris (jebakan "jalur pembeli beda").
    if (!$generalEnabled) {
        $refCode = '';
    }
    if ($refCode === '' && $generalEnabled) {
        $refCode = trim((string) ($_SESSION['ref_code'] ?? ''));
    }
    if ($refCode === '' && $generalEnabled && isset($_COOKIE['averion_ref'])) {
        $refCode = trim((string) $_COOKIE['averion_ref']);
    }
    if ($refCode === '' && $generalEnabled && isset($_COOKIE['aff_code'])) {
        $refCode = trim((string) $_COOKIE['aff_code']);
    }

    $refCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $refCode));

    if ($refCode !== '') {
        $affStmt = $pdo->prepare("SELECT id, code, user_id FROM affiliates WHERE code = ? AND status = 'active' LIMIT 1");
        $affStmt->execute([$refCode]);
        if ($aff = $affStmt->fetch()) {
            if ((int) $aff['user_id'] !== $userId) {
                return [
                    'affiliate_id'   => (int) $aff['id'],
                    'affiliate_code' => (string) $aff['code'],
                    'is_locked'      => false,
                ];
            }
        }
    }

    return [
        'affiliate_id'   => null,
        'affiliate_code' => null,
        'is_locked'      => false,
    ];
}

/**
 * Simpan keterikatan afiliasi pembeli (Customer Binding / Kaitkan Afiliasi) jika belum ada.
 */
function bindCustomerToAffiliate(PDO $pdo, int $userId, int $affiliateId): void
{
    if ($userId <= 0 || $affiliateId <= 0) return;
    ensureUserReferredByColumn($pdo);

    try {
        $stmt = $pdo->prepare("UPDATE users SET referred_by_affiliate_id = ? WHERE id = ? AND referred_by_affiliate_id IS NULL");
        $stmt->execute([$affiliateId, $userId]);
    } catch (Throwable $e) {
        error_log('bindCustomerToAffiliate error: ' . $e->getMessage());
    }
}

/**
 * Pastikan user punya baris `affiliates` (self-heal) & kembalikan barisnya.
 * Akun admin dari installer (step3-admin.php) hanya dibuat dengan users.affiliate_code
 * TANPA baris affiliates; sebagian user lama juga bisa kehilangan barisnya → halaman
 * /affiliate tampil tanpa link (kosong). Fungsi ini membuat barisnya saat dibutuhkan,
 * memakai users.affiliate_code yang sudah ada (atau generate bila kosong), dengan rate
 * default global. Idempoten (uk_user_id UNIQUE → INSERT IGNORE) & fail-soft.
 *
 * Catatan: ini hanya memastikan SARANA afiliasi ada; tidak memberi komisi apa pun
 * (komisi tetap lewat jalur checkout biasa). Mengembalikan baris affiliates atau null.
 */
function ensureAffiliateRow(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) return null;
    try {
        $sel = $pdo->prepare('SELECT * FROM affiliates WHERE user_id = ? LIMIT 1');
        $sel->execute([$userId]);
        if ($row = $sel->fetch()) return $row;

        // Pakai kode yang sudah ada di users; generate unik bila kosong.
        $uStmt = $pdo->prepare('SELECT affiliate_code FROM users WHERE id = ? LIMIT 1');
        $uStmt->execute([$userId]);
        $code = strtoupper(trim((string) ($uStmt->fetchColumn() ?: '')));
        if ($code === '') {
            $code = generateAffiliateCode();
            $pdo->prepare('UPDATE users SET affiliate_code = ? WHERE id = ?')->execute([$code, $userId]);
        }

        $rate = (float) (getSetting('affiliate_commission_rate', '10') ?: 10);
        $pdo->prepare(
            "INSERT IGNORE INTO affiliates (user_id, code, commission_rate, status)
             VALUES (?, ?, ?, 'active')"
        )->execute([$userId, $code, $rate]);

        $sel->execute([$userId]);
        return $sel->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('ensureAffiliateRow: ' . $e->getMessage());
        return null;
    }
}

/**
 * True bila member sudah punya AKTIVITAS afiliat nyata: minimal satu baris referral
 * (klik/daftar/beli lewat linknya) ATAU satu komisi. Dipakai untuk menyembunyikan
 * menu "Affiliate" di sidebar member yang program afiliatnya aktif tapi belum pernah
 * dipakai — agar menu hanya muncul bagi yang benar-benar menjalankan afiliasi.
 *
 * Catatan: ini HANYA menata permukaan menu. Halaman /affiliate tetap bisa dibuka
 * via URL (gerbang mode 'buyers'/privileged tak berubah), sehingga member masih bisa
 * mengambil link pertamanya; begitu ada aktivitas pertama, menu muncul otomatis.
 * Fail-OPEN (true) saat error DB agar menu yang semestinya ada tak hilang diam-diam.
 */
function affiliateHasActivity(int $userId): bool
{
    if ($userId <= 0) return false;
    try {
        $st = getDB()->prepare(
            "SELECT 1 FROM affiliates a
             WHERE a.user_id = ?
               AND (EXISTS (SELECT 1 FROM referrals r   WHERE r.affiliate_id = a.id)
                 OR EXISTS (SELECT 1 FROM commissions c WHERE c.affiliate_id = a.id))
             LIMIT 1"
        );
        $st->execute([$userId]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        error_log('affiliateHasActivity: ' . $e->getMessage());
        return true;
    }
}

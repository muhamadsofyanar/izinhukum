<?php
/**
 * Funnel tracking helper: checkout_click
 *
 * Dipanggil dari checkout.php saat halaman dimuat (require_once).
 * Menangkap event untuk KEDUA sumber — LP internal maupun LP eksternal —
 * karena /checkout selalu dilewati oleh keduanya.
 *
 * Dedup: 1x per session + product + hari kalender.
 * Fail-soft: error tidak boleh menggagalkan halaman checkout.
 *
 * @param int $productId
 * @return void
 */
function trackCheckoutClick(int $productId): void
{
    if ($productId <= 0) {
        return;
    }
    // Admin/staff & bot di-exclude dari funnel — kunjungan sendiri/non-manusia bukan visitor.
    if (isAdminOrStaff()) {
        return;
    }
    if (isBotUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        return;
    }
    try {
        $pdo = getDB();
        $sid = session_id();
        $ua  = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $chk = $pdo->prepare(
            "SELECT 1 FROM funnel_events
             WHERE product_id = ? AND event_type = 'checkout_click' AND session_id = ?
             AND DATE(created_at) = CURDATE() LIMIT 1"
        );
        $chk->execute([$productId, $sid]);
        if (!$chk->fetch()) {
            $pdo->prepare(
                "INSERT INTO funnel_events (product_id, event_type, session_id, user_agent) VALUES (?, 'checkout_click', ?, ?)"
            )->execute([$productId, $sid, $ua]);
        }
    } catch (Exception $ignored) {}
}

/**
 * Funnel tracking helper: checkout_click untuk Canvas Rotator / Split Test.
 *
 * Saat pengunjung dilayani sebuah varian rotator, renderRotator() menyetel cookie
 * sticky `av_rot_<rotatorId> = <variantId>`. Di halaman /checkout, fungsi ini
 * membaca cookie tsb dan mencatat checkout_click PER VARIAN (proksi konversi MVP).
 *
 * Dedup: 1x per session + varian + hari. Skip admin/staff. Fail-soft.
 *
 * @return void
 */
function trackRotatorCheckoutClick(): void
{
    if (isAdminOrStaff()) {
        return;
    }
    if (isBotUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        return;
    }
    // Kumpulkan pasangan (rotatorId, variantId) dari semua cookie av_rot_*.
    $pairs = [];
    foreach ($_COOKIE as $name => $val) {
        if (strncmp((string) $name, 'av_rot_', 7) !== 0) continue;
        $rotatorId = (int) substr((string) $name, 7);
        $variantId = (int) $val;
        if ($rotatorId > 0 && $variantId > 0) $pairs[] = [$rotatorId, $variantId];
    }
    if (!$pairs) {
        return;
    }
    try {
        $pdo = getDB();
        $sid = session_id() ?: '';
        if ($sid === '') return;
        $chk = $pdo->prepare(
            "SELECT 1 FROM canvas_rotator_events
             WHERE variant_id = ? AND event_type = 'checkout_click' AND session_id = ?
               AND DATE(created_at) = CURDATE() LIMIT 1"
        );
        $ins = $pdo->prepare(
            "INSERT INTO canvas_rotator_events (rotator_id, variant_id, event_type, session_id)
             VALUES (?, ?, 'checkout_click', ?)"
        );
        foreach ($pairs as [$rotatorId, $variantId]) {
            // Pastikan varian benar-benar milik rotator itu (cookie bisa basi/palsu).
            $own = $pdo->prepare(
                "SELECT 1 FROM canvas_rotator_variants WHERE id = ? AND rotator_id = ? LIMIT 1"
            );
            $own->execute([$variantId, $rotatorId]);
            if (!$own->fetch()) continue;
            $chk->execute([$variantId, $sid]);
            if (!$chk->fetch()) $ins->execute([$rotatorId, $variantId, $sid]);
        }
    } catch (Exception $ignored) {}
}

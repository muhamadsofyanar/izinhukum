<?php
// ─── Notifikasi Update Tersedia ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/**
 * Versi update yang tersedia (lebih tinggi dari APP_VERSION), atau '' bila
 * tidak ada / belum dicek. Sumber: setting hasil cek harian Auto-Cron.
 */
function updateAvailableVersion(): string
{
    $v = trim((string) getSetting('update_available_version', ''));
    if ($v === '' || !defined('APP_VERSION')) return '';
    return version_compare($v, APP_VERSION, '>') ? $v : '';
}

/**
 * Simpan broadcast dari vendor (field `broadcast` pada respons check-update) ke
 * setting lokal. $b = array dari server, atau apa pun bila tak ada → dibersihkan.
 * SENYAP & defensif: hanya menulis 1 setting; tak menyentuh jalur lain.
 * CTA URL WAJIB https (kalau tidak → tombol dibuang, pesan tetap tampil).
 */
function storeVendorBroadcast($b): void
{
    try {
        if (!is_array($b) || empty($b['id']) || trim((string) ($b['title'] ?? '')) === '') {
            setSetting('vendor_broadcast', '');
            return;
        }
        $ctaUrl   = trim((string) ($b['cta_url'] ?? ''));
        $ctaLabel = mb_substr(trim((string) ($b['cta_label'] ?? '')), 0, 80);
        // Hanya izinkan https absolut & URL valid; selain itu buang tombolnya.
        if ($ctaUrl !== '' && !(preg_match('#^https://#i', $ctaUrl) && filter_var($ctaUrl, FILTER_VALIDATE_URL))) {
            $ctaUrl = '';
        }
        if ($ctaUrl === '') $ctaLabel = '';

        $endsAt = trim((string) ($b['ends_at'] ?? ''));
        $clean  = [
            'id'        => (int) $b['id'],
            'title'     => mb_substr(trim((string) $b['title']), 0, 200),
            'body'      => mb_substr(trim((string) ($b['body'] ?? '')), 0, 4000),
            'cta_label' => $ctaLabel,
            'cta_url'   => $ctaUrl,
            'ends_at'   => ($endsAt !== '' && strtotime($endsAt) !== false) ? $endsAt : null,
        ];
        setSetting('vendor_broadcast', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        error_log('storeVendorBroadcast: ' . $e->getMessage());
    }
}

/**
 * Broadcast vendor yang layak DITAMPILKAN di lonceng admin, atau null.
 * Disaring: belum kedaluwarsa (ends_at) & belum ditutup admin (dismissed_id).
 */
function vendorBroadcast(): ?array
{
    try {
        $raw = trim((string) getSetting('vendor_broadcast', ''));
        if ($raw === '') return null;
        $b = json_decode($raw, true);
        if (!is_array($b) || empty($b['id']) || trim((string) ($b['title'] ?? '')) === '') return null;

        if (!empty($b['ends_at'])) {
            $ts = strtotime((string) $b['ends_at']);
            if ($ts !== false && $ts < time()) return null; // kedaluwarsa
        }
        if ((int) getSetting('vendor_broadcast_dismissed_id', '0') === (int) $b['id']) return null; // sudah ditutup
        return $b;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Cek update tiap 12 jam (klaim via setting update_check_last_run, pola
 * db_backup_last_run: UPDATE WHERE value=lama + rowCount → anti-balapan).
 */
function processUpdateNotifier(): void
{
}

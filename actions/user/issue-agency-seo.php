<?php
// ─── Terbitkan / coba-lagi satu lisensi Agency SEO (VENDOR-ONLY) ──────────
// Memesan lisensi SEO lifetime ke server vendor lewat helpers/agency-seo.php.
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

// Menyembunyikan UI tak cukup — endpoint tetap bisa di-POST langsung.
if (!agencySeoAvailable()) {
    redirect('/member');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/agency-seo');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/agency-seo');
}

$userId = (int) $_SESSION['user_id'];
$do     = (string) ($_POST['do'] ?? 'issue');
$pdo    = getDB();

try {
    if ($do === 'retry') {
        $licenseId = (int) ($_POST['license_id'] ?? 0);
        $res = agencySeoRetry($pdo, $licenseId, $userId);
    } else {
        $quotaId = (int) ($_POST['quota_id'] ?? 0);
        $res = agencySeoIssue($pdo, $quotaId, $userId, [
            'name'  => (string) ($_POST['client_name'] ?? ''),
            'email' => (string) ($_POST['client_email'] ?? ''),
            'phone' => (string) ($_POST['client_phone'] ?? ''),
        ]);
    }
} catch (Throwable $e) {
    error_log('issue-agency-seo error: ' . $e->getMessage());
    $res = ['ok' => false, 'message' => 'Terjadi kesalahan sistem. Coba lagi.'];
}

if (!empty($res['ok'])) {
    $msg = !empty($res['reused'])
        ? 'Lisensi sudah ada dan ditampilkan (aman, tidak menerbitkan dobel).'
        : 'Lisensi berhasil diterbitkan.';
    flash('success', $msg);
} else {
    flash('error', (string) ($res['message'] ?? 'Gagal menerbitkan lisensi.'), 'error');
}
redirect('/agency-seo');

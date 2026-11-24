<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

// Mode AJAX (auto-selesai video dari player): balas JSON, jangan redirect.
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$ajaxOut = function (bool $ok, string $msg = '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    if ($isAjax) $ajaxOut(false, 'Permintaan tidak valid.');
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/member');
}

$materialId = (int) ($_POST['material_id'] ?? 0);
// Hanya percayai referer internal (cegah open-redirect).
$back = $_SERVER['HTTP_REFERER'] ?? '';
if ($back === '' || !str_starts_with($back, APP_URL)) $back = null;

$pdo = getDB();
// Ambil owner materi (produk ATAU lead magnet — materi bisa milik salah satunya).
$stmt = $pdo->prepare(
    "SELECT m.id, m.content_type, c.id AS class_id, c.unlock_after_days, c.product_id, c.lead_magnet_id
     FROM materials m JOIN classes c ON c.id = m.class_id WHERE m.id = ? LIMIT 1"
);
$stmt->execute([$materialId]);
$material = $stmt->fetch();

if (!$material) {
    if ($isAjax) $ajaxOut(false, 'Materi tidak ditemukan.');
    flash('error', 'Materi tidak ditemukan.', 'error');
    $back ? header('Location: ' . $back) : redirect('/member');
    exit;
}

$userId  = (int) $_SESSION['user_id'];
$classId = (int) ($material['class_id'] ?? 0);

// Owner PEMILIK modul (default). Untuk modul BERBAGI (class_products), materi
// diakses lewat produk LAIN → konteks owner dari URL (dikirim form) dipakai bila
// (a) user memang punya akses produk itu, DAN (b) modul memang terjangkau dari
// produk itu (dimiliki / dibagikan). Ini menyamai logika akses material-view.
$ownerProductId = (int) ($material['product_id'] ?? 0);
$ownerMagnetId  = (int) ($material['lead_magnet_id'] ?? 0);
$ctxProductId   = (int) ($_POST['product_id'] ?? 0);
$ctxMagnetId    = (int) ($_POST['lead_magnet_id'] ?? 0);

$leadMagnetId = 0;
$productId    = 0;
if ($ctxMagnetId > 0 && $ctxMagnetId === $ownerMagnetId) {
    // Konteks lead magnet valid (lead magnet tidak punya modul berbagi).
    $leadMagnetId = $ctxMagnetId;
} elseif ($ctxProductId > 0 && $classReachable = (function (PDO $pdo, int $classId, int $productId): bool {
        $st = $pdo->prepare(
            "SELECT 1 FROM classes c
             WHERE c.id = ? AND (c.product_id = ?
                   OR c.id IN (SELECT class_id FROM class_products WHERE product_id = ?))
             LIMIT 1"
        );
        $st->execute([$classId, $productId, $productId]);
        return (bool) $st->fetchColumn();
    })($pdo, $classId, $ctxProductId)) {
    // Konteks produk (bisa produk berbagi) → pakai produk konteks utk cek akses & drip.
    $productId = $ctxProductId;
} else {
    // Tanpa konteks valid → fallback ke owner pemilik modul (perilaku lama).
    $productId    = $ownerProductId;
    $leadMagnetId = $ownerMagnetId;
}

// Cek akses sesuai owner efektif: produk (hasAccess) atau lead magnet.
$canAccess = $leadMagnetId > 0
    ? hasLeadMagnetAccess($userId, $leadMagnetId)
    : hasAccess($userId, $productId);
if (!$canAccess) {
    if ($isAjax) $ajaxOut(false, 'Kamu tidak punya akses.');
    flash('error', 'Kamu tidak punya akses.', 'error');
    redirect('/member');
}

// Drip Content: materi di modul terkunci tidak boleh ditandai selesai
// (juga mencegah sertifikat terbit dini via checkAndAutoIssueCertificate).
// Timer drip dihitung dari akses produk KONTEKS (member_access produk itu).
if ($leadMagnetId === 0) {
    $dripSt = dripLockStates(
        [['id' => $classId, 'unlock_after_days' => (int) $material['unlock_after_days']]],
        $userId, $productId
    );
    if (!empty($dripSt[$classId]['locked'])) {
        if ($isAjax) $ajaxOut(false, 'Materi ini masih terkunci.');
        flash('error', 'Materi ini masih terkunci.', 'error');
        redirect('/member/' . $productId);
    }
}

if ($material['content_type'] !== 'link') {
    $pdo->prepare("INSERT IGNORE INTO material_completions (user_id, material_id, completed_at) VALUES (?, ?, NOW())")
        ->execute([$userId, $materialId]);
    // Sertifikat khusus produk; lead magnet tidak menerbitkan sertifikat.
    if ($leadMagnetId === 0) {
        checkAndAutoIssueCertificate($userId, $materialId);
    }
    // Auto-selesai dari player: cukup konfirmasi JSON, member tetap di halaman
    // (tanpa flash agar tak muncul ganda saat berpindah materi nanti).
    if ($isAjax) $ajaxOut(true, 'Materi ditandai selesai.');
    flash('success', 'Materi ditandai selesai!');
}

if ($isAjax) $ajaxOut(true);

// Auto-lanjut ke materi berikutnya (urutan sama seperti viewer), atau kembali ke daftar.
$ownerCol = $leadMagnetId > 0 ? 'lead_magnet_id' : 'product_id';
$ownerId  = $leadMagnetId > 0 ? $leadMagnetId : $productId;
$base     = $leadMagnetId > 0 ? '/member/lm/' . $ownerId : '/member/' . $ownerId;

// Modul berbagi (produk): sertakan juga modul yang DIBAGIKAN ke produk konteks
// agar urutan prev/next persis sama dengan viewer (material-view.php).
$shared = ($ownerCol === 'product_id')
    ? ' OR cc.id IN (SELECT class_id FROM class_products WHERE product_id = ?)'
    : '';

$nextId = 0;
try {
    $q = $pdo->prepare(
        "SELECT mm.id, cc.id AS class_id, cc.unlock_after_days
         FROM materials mm JOIN classes cc ON cc.id = mm.class_id
         WHERE (cc.{$ownerCol} = ?{$shared}) AND cc.status = 'active'
         ORDER BY cc.sort_order ASC, cc.id ASC, mm.sort_order ASC, mm.id ASC"
    );
    $q->execute($shared !== '' ? [$ownerId, $ownerId] : [$ownerId]);
    $rows = $q->fetchAll();

    // Drip: materi di modul terkunci dilewati dari urutan "lanjut ke berikutnya".
    $classRows = [];
    foreach ($rows as $r) $classRows[(int) $r['class_id']] = ['id' => (int) $r['class_id'], 'unlock_after_days' => (int) $r['unlock_after_days']];
    $nextDrip = ($leadMagnetId === 0) ? dripLockStates(array_values($classRows), $userId, $productId) : [];

    $flat = [];
    foreach ($rows as $r) {
        if (!empty($nextDrip[(int) $r['class_id']]['locked'])) continue;
        $flat[] = (int) $r['id'];
    }
    $pos = array_search($materialId, $flat, true);
    if ($pos !== false && $pos < count($flat) - 1) $nextId = (int) $flat[$pos + 1];
} catch (Exception $e) { error_log('complete-material next lookup: ' . $e->getMessage()); }

redirect($nextId > 0 ? $base . '/material/' . $nextId : $base);

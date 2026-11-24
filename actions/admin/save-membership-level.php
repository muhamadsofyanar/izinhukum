<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/membership-levels');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/membership-levels');
}

$levelId   = (int) ($_POST['level_id'] ?? 0);
$name      = trim($_POST['name'] ?? '');
$slug      = strtolower(preg_replace('/[^a-z0-9-]/', '', trim($_POST['slug'] ?? '')));
$minSpend  = max(0, (float) ($_POST['min_spend'] ?? 0));
$color     = trim($_POST['color'] ?? '#94a3b8');
$icon      = trim($_POST['icon'] ?? 'medal');
$sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));

// ─── Validasi ─────────────────────────────────────────────────
if (strlen($name) < 2 || strlen($name) > 50) {
    flash('error', 'Nama level harus 2–50 karakter.', 'error');
    redirect('/admin/membership-levels');
}

if ($slug === '') {
    $slug = strtolower(preg_replace('/[^a-z0-9]/', '-', $name));
}

if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
    flash('error', 'Format warna tidak valid (gunakan hex #RRGGBB).', 'error');
    redirect('/admin/membership-levels');
}

if (!in_array($icon, ['medal', 'star', 'crown', 'trophy'], true)) {
    $icon = 'medal';
}

try {
    $pdo = getDB();

    // Cek duplikat nama (kecuali milik sendiri saat edit)
    $dup = $pdo->prepare('SELECT id FROM membership_levels WHERE name = ? AND id != ?');
    $dup->execute([$name, $levelId]);
    if ($dup->fetch()) {
        flash('error', 'Nama level "' . $name . '" sudah digunakan.', 'error');
        redirect('/admin/membership-levels');
    }

    if ($levelId > 0) {
        $pdo->prepare(
            'UPDATE membership_levels SET name=?, slug=?, min_spend=?, color=?, icon=?, sort_order=? WHERE id=?'
        )->execute([$name, $slug, $minSpend, $color, $icon, $sortOrder, $levelId]);
        flash('success', 'Level "' . $name . '" berhasil diperbarui.');
    } else {
        $pdo->prepare(
            'INSERT INTO membership_levels (name, slug, min_spend, color, icon, sort_order) VALUES (?,?,?,?,?,?)'
        )->execute([$name, $slug, $minSpend, $color, $icon, $sortOrder]);
        flash('success', 'Level "' . $name . '" berhasil ditambahkan.');
    }

    // Sinkronisasi otomatis level seluruh user sesuai aturan min_spend terbaru.
    // force=true → lewati throttle (ambang BERUBAH harus langsung sinkron). Berlaku
    // untuk TAMBAH maupun EDIT: dulu recalc hanya di jalur INSERT, sehingga mengedit
    // ambang level existing (mis. Silver 500rb→300rb) tak men-sinkron level user
    // sampai order lunas berikutnya / throttle 30 menit → level tampil basi.
    recalculateAllUserMembershipLevels($pdo, true);

} catch (Exception $e) {
    error_log('Save membership level error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menyimpan level.', 'error');
}

redirect('/admin/membership-levels');

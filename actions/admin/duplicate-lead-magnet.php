<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/lead-magnets');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/lead-magnets');
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'ID lead magnet tidak valid.', 'error');
    redirect('/admin/lead-magnets');
}

$pdo = getDB();

try {
    $src = $pdo->prepare('SELECT * FROM lead_magnets WHERE id = ? LIMIT 1');
    $src->execute([$id]);
    $m = $src->fetch();
    if (!$m) {
        flash('error', 'Lead magnet tidak ditemukan.', 'error');
        redirect('/admin/lead-magnets');
    }

    // Slug unik: base-copy, base-copy-2, base-copy-3, ...
    $base = preg_replace('/-copy(-\d+)?$/', '', (string) $m['slug']);
    $candidate = $base . '-copy';
    $i = 1;
    $chk = $pdo->prepare('SELECT id FROM lead_magnets WHERE slug = ? LIMIT 1');
    while (true) {
        $chk->execute([$candidate]);
        if (!$chk->fetch()) break;
        $i++;
        $candidate = $base . '-copy-' . $i;
    }
    $newSlug  = $candidate;
    $newTitle = (string) $m['title'] . ' (Salinan)';

    // Salin file thumbnail agar tidak berbagi berkas dengan magnet asal
    // (hapus salah satu tidak boleh menghapus gambar yang lain).
    $newThumb = $m['thumbnail'] ?: null;
    if (!empty($m['thumbnail'])) {
        $root  = realpath(__DIR__ . '/../../');
        $lmDir = realpath(rtrim(UPLOAD_PATH, '/\\') . '/lead-magnets');
        $absSrc = $root ? realpath($root . '/' . ltrim($m['thumbnail'], '/')) : false;
        if ($absSrc && $lmDir && str_starts_with($absSrc, $lmDir) && is_file($absSrc)) {
            $ext   = pathinfo($absSrc, PATHINFO_EXTENSION) ?: 'jpg';
            $fname = 'lm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
            if (@copy($absSrc, $lmDir . '/' . $fname)) {
                $newThumb = 'assets/uploads/lead-magnets/' . $fname;
            }
        }
    }

    // Validasi bonus level masih ada
    $bonusLevel = $m['bonus_membership_level_id'] ?: null;
    if ($bonusLevel !== null) {
        $lc = $pdo->prepare('SELECT id FROM membership_levels WHERE id = ? LIMIT 1');
        $lc->execute([$bonusLevel]);
        if (!$lc->fetch()) $bonusLevel = null;
    }

    // Salinan selalu mulai sebagai draft.
    $stmt = $pdo->prepare(
        'INSERT INTO lead_magnets
           (title, slug, headline, subheadline, thumbnail, badge_text, cta_text,
            bonus_membership_level_id, fb_pixel_ids, thankyou_message, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $newTitle, $newSlug, $m['headline'], $m['subheadline'], $newThumb,
        $m['badge_text'], $m['cta_text'], $bonusLevel,
        $m['fb_pixel_ids'], $m['thankyou_message'], 'draft',
    ]);
    $newId = (int) $pdo->lastInsertId();

    if (function_exists('logActivity')) {
        logActivity('admin', 'lead_magnet.duplicated', 'lead_magnets', $newId, 'Lead magnet "' . $m['title'] . '" diduplikat menjadi "' . $newTitle . '".');
    }
    flash('success', 'Lead magnet diduplikat sebagai draft. URL publik: /lp/' . $newSlug);
} catch (Exception $e) {
    error_log('Duplicate lead magnet error: ' . $e->getMessage());
    flash('error', 'Gagal menduplikat lead magnet.', 'error');
}

redirect('/admin/lead-magnets');

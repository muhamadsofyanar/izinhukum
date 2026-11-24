<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('content')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/content');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/admin/content');
}

$action       = trim($_POST['action'] ?? '');
$productId    = (int) ($_POST['product_id'] ?? 0);
$leadMagnetId = (int) ($_POST['lead_magnet_id'] ?? 0);
$classId      = (int) ($_POST['class_id']   ?? 0);

// Owner = produk ATAU lead magnet (eksklusif; produk diprioritaskan).
$ownerType = $productId > 0 ? 'product' : ($leadMagnetId > 0 ? 'lead_magnet' : '');
$ownerQs   = $productId > 0 ? 'product_id=' . $productId
           : ($leadMagnetId > 0 ? 'lead_magnet_id=' . $leadMagnetId : '');

// Base redirect URL dengan context owner yang sama
$baseRedirect  = '/admin/content' . ($ownerQs ? '?' . $ownerQs : '');
$classRedirect = $baseRedirect . ($classId > 0 ? (str_contains($baseRedirect, '?') ? '&' : '?') . 'class_id=' . $classId : '');

try {
    $pdo = getDB();
    ensureClassLeadMagnetOwner($pdo);

    switch ($action) {

        // ── Save Class (create / update) ─────────────────────
        case 'save_class':
            $title       = trim($_POST['title']      ?? '');
            $description = trim($_POST['description'] ?? '');
            $status      = $_POST['status'] ?? 'active';
            $sortOrder   = max(0, (int) ($_POST['sort_order'] ?? 0));
            $unlockDays  = min(3650, max(0, (int) ($_POST['unlock_after_days'] ?? 0)));
            $existingId  = (int) ($_POST['class_id']    ?? 0);
            $rawCatId    = (int) ($_POST['category_id'] ?? 0);
            $categoryId  = $rawCatId > 0 ? $rawCatId : null;

            if (strlen($title) < 2) {
                flash('error', 'Judul modul minimal 2 karakter.', 'error');
                redirect($baseRedirect);
            }
            if ($ownerType === '') {
                flash('error', 'Owner (produk / lead magnet) tidak valid.', 'error');
                redirect('/admin/content');
            }
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }

            // Validate category if provided
            if ($categoryId !== null) {
                $catCheck = $pdo->prepare('SELECT id FROM class_categories WHERE id = ? LIMIT 1');
                $catCheck->execute([$categoryId]);
                if (!$catCheck->fetch()) $categoryId = null;
            }

            $ownerProductId    = $ownerType === 'product'     ? $productId    : null;
            $ownerLeadMagnetId = $ownerType === 'lead_magnet' ? $leadMagnetId : null;

            if ($existingId > 0) {
                // Pastikan modul milik owner yang sama (cegah edit lintas-owner).
                $stmt = $pdo->prepare(
                    'UPDATE classes SET category_id = ?, title = ?, description = ?, status = ?, sort_order = ?, unlock_after_days = ?
                     WHERE id = ? AND COALESCE(product_id,0) = ? AND COALESCE(lead_magnet_id,0) = ?'
                );
                $stmt->execute([$categoryId, $title, $description ?: null, $status, $sortOrder, $unlockDays, $existingId, $productId, $leadMagnetId]);
                $savedClassId = $existingId;
                flash('success', 'Modul berhasil diperbarui.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO classes (product_id, lead_magnet_id, category_id, title, description, status, sort_order, unlock_after_days)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$ownerProductId, $ownerLeadMagnetId, $categoryId, $title, $description ?: null, $status, $sortOrder, $unlockDays]);
                $savedClassId = (int) $pdo->lastInsertId();
                flash('success', 'Modul berhasil ditambahkan.');
            }

            // Sinkron modul berbagi (shared): hanya untuk owner produk, dan hanya
            // jika modul benar-benar milik produk ini (cegah tulisan lintas-owner).
            if ($ownerType === 'product' && $savedClassId > 0) {
                $ownCheck = $pdo->prepare('SELECT id FROM classes WHERE id = ? AND product_id = ? LIMIT 1');
                $ownCheck->execute([$savedClassId, $productId]);
                if ($ownCheck->fetch()) {
                    $rawShared = $_POST['shared_product_ids'] ?? [];
                    if (!is_array($rawShared)) $rawShared = [];
                    $sharedIds = [];
                    foreach ($rawShared as $sid) {
                        $sid = (int) $sid;
                        if ($sid > 0 && $sid !== $productId) $sharedIds[$sid] = $sid;
                    }
                    // Validasi: hanya produk yang benar-benar ada.
                    $validShared = [];
                    if ($sharedIds) {
                        $ph = implode(',', array_fill(0, count($sharedIds), '?'));
                        $vStmt = $pdo->prepare("SELECT id FROM products WHERE id IN ($ph)");
                        $vStmt->execute(array_values($sharedIds));
                        $validShared = array_map('intval', array_column($vStmt->fetchAll(), 'id'));
                    }
                    // Reset lalu tulis ulang link untuk modul ini.
                    $del = $pdo->prepare('DELETE FROM class_products WHERE class_id = ?');
                    $del->execute([$savedClassId]);
                    if ($validShared) {
                        $ins = $pdo->prepare('INSERT INTO class_products (class_id, product_id) VALUES (?, ?)');
                        foreach ($validShared as $vpid) {
                            $ins->execute([$savedClassId, $vpid]);
                        }
                    }
                }
            }
            redirect($baseRedirect);

        // ── Delete Class ─────────────────────────────────────
        case 'delete_class':
            $delClassId = (int) ($_POST['class_id'] ?? 0);
            if ($delClassId <= 0) {
                flash('error', 'Modul tidak valid.', 'error');
                redirect($baseRedirect);
            }
            // Verify ownership (produk / lead magnet)
            $check = $pdo->prepare('SELECT id FROM classes WHERE id = ? AND COALESCE(product_id,0) = ? AND COALESCE(lead_magnet_id,0) = ? LIMIT 1');
            $check->execute([$delClassId, $productId, $leadMagnetId]);
            if (!$check->fetch()) {
                flash('error', 'Modul tidak ditemukan.', 'error');
                redirect($baseRedirect);
            }
            // Cascade delete materials via FK ON DELETE CASCADE
            $pdo->prepare('DELETE FROM classes WHERE id = ?')->execute([$delClassId]);
            flash('success', 'Modul dan semua materinya berhasil dihapus.');
            redirect($baseRedirect);

        // ── Save Material (create / update) ──────────────────
        case 'save_material':
            $title       = trim($_POST['title']        ?? '');
            $contentType = trim($_POST['content_type'] ?? 'text');
            $contentUrl  = trim($_POST['content_url']  ?? '');
            $content     = trim($_POST['content']      ?? '');
            $description = trim($_POST['description']   ?? '');   // teks/link kaya di bawah konten (opsional)
            $sortOrder   = max(0, (int) ($_POST['sort_order'] ?? 0));
            $existingId  = (int) ($_POST['material_id'] ?? 0);

            if (strlen($title) < 2) {
                flash('error', 'Judul materi minimal 2 karakter.', 'error');
                redirect($classRedirect);
            }
            if ($classId <= 0) {
                flash('error', 'Modul tidak valid.', 'error');
                redirect($baseRedirect);
            }
            if (!in_array($contentType, ['text', 'video', 'file', 'link', 'embed', 'html'], true)) {
                $contentType = 'text';
            }
            ensureMaterialHtmlContentType($pdo);
            ensureMaterialDescriptionColumn($pdo);

            // Tipe yang menyimpan teks/HTML di kolom 'content' (bukan URL): text, embed, html.
            // 'embed' = kode iframe (Canva, dll); 'html' = kode HTML kustom — keduanya disimpan apa adanya.
            $usesContent = in_array($contentType, ['text', 'embed', 'html'], true);

            // Validasi URL hanya untuk tipe berbasis URL (video/file/link).
            if (!$usesContent && $contentUrl !== '') {
                // Normalisasi: user sering menempel link TANPA skema (mis. "youtu.be/x",
                // "wa.me/62..."). Tambahkan https:// otomatis supaya tidak ditolak &
                // materi tetap tersimpan. mailto:/tel: dibiarkan apa adanya.
                if (!preg_match('~^[a-z][a-z0-9+.\-]*:~i', $contentUrl)) {
                    $contentUrl = 'https://' . ltrim($contentUrl, '/');
                }
                if (!filter_var($contentUrl, FILTER_VALIDATE_URL)) {
                    flash('error', 'URL konten tidak valid.', 'error');
                    redirect($classRedirect);
                }
            }

            // Tipe file bisa menaut ke Download Manager (pengganti URL manual).
            // Hanya untuk owner produk; file wajib milik produk yang sama.
            $downloadId = (int) ($_POST['download_id'] ?? 0);
            if ($contentType === 'file' && $downloadId > 0 && $productId > 0) {
                $dChk = $pdo->prepare('SELECT id FROM product_downloads WHERE id = ? AND product_id = ? LIMIT 1');
                $dChk->execute([$downloadId, $productId]);
                if (!$dChk->fetch()) {
                    flash('error', 'File Download Manager tidak valid untuk produk ini.', 'error');
                    redirect($classRedirect);
                }
            } else {
                $downloadId = 0;
            }

            $finalContent     = $usesContent ? ($content ?: null) : null;
            $finalContentUrl  = !$usesContent ? ($contentUrl ?: null) : null;
            $finalDownloadId  = $downloadId > 0 ? $downloadId : null;
            $finalDescription = $description !== '' ? $description : null;   // HTML kaya, admin terpercaya

            if ($existingId > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE materials
                     SET title = ?, content_type = ?, content_url = ?, download_id = ?, content = ?, description = ?, sort_order = ?
                     WHERE id = ? AND class_id = ?'
                );
                $stmt->execute([
                    $title, $contentType, $finalContentUrl, $finalDownloadId, $finalContent, $finalDescription,
                    $sortOrder, $existingId, $classId,
                ]);
                $savedMaterialId = $existingId;
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO materials (class_id, title, content_type, content_url, download_id, content, description, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $classId, $title, $contentType, $finalContentUrl, $finalDownloadId, $finalContent, $finalDescription, $sortOrder,
                ]);
                $savedMaterialId = (int) $pdo->lastInsertId();
            }

            // Lampiran (material_files): berlaku semua tipe konten, hanya owner
            // produk. Set penuh ditulis ulang; id divalidasi milik produk ini.
            if ($savedMaterialId > 0 && $productId > 0) {
                $rawAttach = $_POST['attach_download_ids'] ?? [];
                if (!is_array($rawAttach)) $rawAttach = [];
                $attachIds = [];
                foreach ($rawAttach as $aid) {
                    $aid = (int) $aid;
                    if ($aid > 0) $attachIds[$aid] = $aid;
                }
                $validAttach = [];
                if ($attachIds) {
                    $ph = implode(',', array_fill(0, count($attachIds), '?'));
                    $vStmt = $pdo->prepare("SELECT id FROM product_downloads WHERE id IN ($ph) AND product_id = ?");
                    $vStmt->execute([...array_values($attachIds), $productId]);
                    $validAttach = array_map('intval', array_column($vStmt->fetchAll(), 'id'));
                }
                $pdo->prepare('DELETE FROM material_files WHERE material_id = ?')->execute([$savedMaterialId]);
                if ($validAttach) {
                    $ins = $pdo->prepare('INSERT INTO material_files (material_id, download_id, sort_order) VALUES (?, ?, ?)');
                    foreach (array_values($validAttach) as $i => $vid) {
                        $ins->execute([$savedMaterialId, $vid, $i]);
                    }
                }
            }
            flash('success', 'Materi berhasil disimpan.');
            redirect($classRedirect);

        // ── Reorder (drag & drop) ────────────────────────────
        // Dipanggil via fetch; balasan JSON. ids = urutan baru (indeks = sort_order).
        case 'reorder_classes':
        case 'reorder_materials':
            header('Content-Type: application/json; charset=utf-8');
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids) || !$ids) { echo json_encode(['success' => false, 'message' => 'Urutan kosong.']); exit; }
            $ids = array_values(array_unique(array_map('intval', $ids)));

            if ($action === 'reorder_classes') {
                if ($ownerType === '') { echo json_encode(['success' => false, 'message' => 'Owner tidak valid.']); exit; }
                // Hanya modul MILIK owner ini yang boleh digeser (modul berbagi
                // diurut dari produk pemiliknya).
                $upd = $pdo->prepare(
                    'UPDATE classes SET sort_order = ? WHERE id = ? AND COALESCE(product_id,0) = ? AND COALESCE(lead_magnet_id,0) = ?'
                );
                foreach ($ids as $i => $id) {
                    $upd->execute([$i, $id, $productId, $leadMagnetId]);
                }
            } else {
                if ($classId <= 0) { echo json_encode(['success' => false, 'message' => 'Modul tidak valid.']); exit; }
                $upd = $pdo->prepare('UPDATE materials SET sort_order = ? WHERE id = ? AND class_id = ?');
                foreach ($ids as $i => $id) {
                    $upd->execute([$i, $id, $classId]);
                }
            }
            echo json_encode(['success' => true]);
            exit;

        // ── Delete Material ───────────────────────────────────
        case 'delete_material':
            $matId = (int) ($_POST['material_id'] ?? 0);
            if ($matId <= 0) {
                flash('error', 'Materi tidak valid.', 'error');
                redirect($classRedirect);
            }
            $check = $pdo->prepare('SELECT id FROM materials WHERE id = ? AND class_id = ? LIMIT 1');
            $check->execute([$matId, $classId]);
            if (!$check->fetch()) {
                flash('error', 'Materi tidak ditemukan.', 'error');
                redirect($classRedirect);
            }
            $pdo->prepare('DELETE FROM materials WHERE id = ?')->execute([$matId]);
            flash('success', 'Materi berhasil dihapus.');
            redirect($classRedirect);

        default:
            flash('error', 'Aksi tidak dikenali.', 'error');
            redirect('/admin/content');
    }

} catch (Exception $e) {
    error_log('Save content error [' . $action . ']: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan. Silakan coba lagi.', 'error');
    redirect($classRedirect ?: $baseRedirect);
}

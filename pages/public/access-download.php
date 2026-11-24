<?php
// ════════════════════════════════════════════════════════════════
// Unduh file dari Halaman Akses Produk berpassword — /akses-file/{materialId}
// TANPA login. Otorisasi = session $_SESSION['product_access'][productId] yang
// di-set setelah password benar di /akses/{slug}. Meniru streaming
// serve-download.php, tapi gerbang otorisasinya password-halaman (bukan user).
// ════════════════════════════════════════════════════════════════

$materialId = (int) ($_GET['id'] ?? 0);
if ($materialId <= 0) { http_response_code(400); echo 'Materi tidak valid.'; return; }

try {
    $pdo = getDB();

    // Materi + modul induk + download_id.
    $mStmt = $pdo->prepare(
        "SELECT m.id, m.class_id, m.content_type, m.content_url, m.download_id, c.product_id
           FROM materials m JOIN classes c ON c.id = m.class_id
          WHERE m.id = ? LIMIT 1"
    );
    $mStmt->execute([$materialId]);
    $mat = $mStmt->fetch();
    if (!$mat) { http_response_code(404); echo 'File tidak ditemukan.'; return; }

    // Produk pemilik materi: owner langsung + modul yang dibagikan via class_products.
    $prodIds = [];
    if (!empty($mat['product_id'])) $prodIds[] = (int) $mat['product_id'];
    $cp = $pdo->prepare("SELECT product_id FROM class_products WHERE class_id = ?");
    $cp->execute([(int) $mat['class_id']]);
    foreach ($cp->fetchAll(PDO::FETCH_COLUMN) as $pid) $prodIds[] = (int) $pid;

    // Otorisasi: session akses ke salah satu produk pemilik materi + produk itu
    // memang mengaktifkan halaman akses (cegah reuse session produk lain).
    $granted = false;
    foreach (array_unique($prodIds) as $pid) {
        if (!empty($_SESSION['product_access'][$pid])) {
            $ok = $pdo->prepare("SELECT 1 FROM products WHERE id = ? AND access_page_enabled = 1 LIMIT 1");
            $ok->execute([$pid]);
            if ($ok->fetchColumn()) { $granted = true; break; }
        }
    }
    if (!$granted) { http_response_code(403); echo 'Akses ditolak. Buka halaman akses produk dan masukkan password.'; return; }

    // ─── File via Download Manager (product_downloads) → stream dari storage ──
    if (!empty($mat['download_id'])) {
        $dStmt = $pdo->prepare(
            "SELECT filename_stor, filename_orig, mime_type, file_size
               FROM product_downloads WHERE id = ? AND status = 'active' LIMIT 1"
        );
        $dStmt->execute([(int) $mat['download_id']]);
        $dl = $dStmt->fetch();
        if (!$dl) { http_response_code(404); echo 'File tidak tersedia.'; return; }

        $filePath = DOWNLOAD_STORAGE_PATH . $dl['filename_stor'];
        if (!is_file($filePath) || !is_readable($filePath)) {
            error_log('access-download: file missing ' . $filePath);
            http_response_code(404); echo 'File tidak ditemukan. Hubungi penjual.'; return;
        }

        $pdo->prepare("UPDATE product_downloads SET download_count = download_count + 1 WHERE id = ?")
            ->execute([(int) $mat['download_id']]);

        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: ' . ($dl['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . addslashes($dl['filename_orig']) . '"');
        if ((int) $dl['file_size'] > 0) header('Content-Length: ' . (int) $dl['file_size']);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        $h = fopen($filePath, 'rb');
        if ($h) { while (!feof($h)) { echo fread($h, 8192); flush(); } fclose($h); }
        exit;
    }

    // ─── Fallback lama: content_url langsung (file di assets/uploads) ─────────
    if (trim((string) $mat['content_url']) !== '') {
        redirect((string) $mat['content_url']);
    }

    http_response_code(404);
    echo 'File tidak tersedia.';
} catch (Exception $e) {
    error_log('access-download error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Terjadi kesalahan saat mengunduh file.';
}

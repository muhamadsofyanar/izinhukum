<?php
// ─── Download Manager & Sertifikat ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

function generateDownloadToken(int $downloadId, int $userId): string
{
    $rawToken      = bin2hex(random_bytes(32));
    $expiryMinutes = (int) getSetting('download_token_expiry_minutes', '60');
    if ($expiryMinutes <= 0) $expiryMinutes = 60;
    $expiresAt = date('Y-m-d H:i:s', time() + $expiryMinutes * 60);

    getDB()->prepare(
        "INSERT INTO download_tokens (token, download_id, user_id, expires_at, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    )->execute([$rawToken, $downloadId, $userId, $expiresAt, $_SERVER['REMOTE_ADDR'] ?? '']);

    return $rawToken;
}

/**
 * Cek hak unduh file Download Manager untuk seorang user.
 * Jalur 1: akses langsung ke produk pemilik file (perilaku lama halaman /downloads).
 * Jalur 2: file ditautkan ke materi (materials.download_id) yang bisa diakses user —
 * modul bisa DIBAGIKAN lintas produk (class_products) atau milik lead magnet, jadi
 * member sah bisa berhak atas file tanpa punya akses produk pemilik file itu.
 * Fail-closed: error DB (termasuk kolom download_id belum ada di instalasi yang
 * belum migrasi) = jalur 2 dianggap tidak lolos, jalur 1 tetap berlaku.
 */
function canAccessDownload(int $userId, int $downloadId): bool
{
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT product_id FROM product_downloads WHERE id = ? LIMIT 1');
        $stmt->execute([$downloadId]);
        $ownerProductId = $stmt->fetchColumn();
        if ($ownerProductId === false) return false;
        if (hasAccess($userId, (int) $ownerProductId)) return true;
    } catch (Exception $e) {
        error_log('canAccessDownload error: ' . $e->getMessage());
        return false;
    }

    try {
        // Tautan bisa lewat konten utama (materials.download_id) ATAU lampiran
        // (material_files) — keduanya memberi hak unduh yang sama.
        $stmt = $pdo->prepare(
            'SELECT c.id AS class_id, c.product_id, c.lead_magnet_id
             FROM materials m
             JOIN classes c ON c.id = m.class_id
             WHERE m.download_id = ?
                OR m.id IN (SELECT material_id FROM material_files WHERE download_id = ?)'
        );
        $stmt->execute([$downloadId, $downloadId]);
        foreach ($stmt->fetchAll() as $row) {
            if (!empty($row['product_id']) && hasAccess($userId, (int) $row['product_id'])) {
                return true;
            }
            if (!empty($row['lead_magnet_id']) && hasLeadMagnetAccess($userId, (int) $row['lead_magnet_id'])) {
                return true;
            }
            $sp = $pdo->prepare('SELECT product_id FROM class_products WHERE class_id = ?');
            $sp->execute([(int) $row['class_id']]);
            foreach ($sp->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                if (hasAccess($userId, (int) $pid)) return true;
            }
        }
    } catch (Exception $e) {
        error_log('canAccessDownload (jalur materi) error: ' . $e->getMessage());
    }
    return false;
}

/**
 * Mode program affiliate (setting `affiliate_mode`):
 *   'all'    = semua member otomatis bisa pakai link affiliate (default).
 *   'buyers' = hanya member yang sudah pernah punya order paid.
 * Dipakai sebagai gerbang di halaman /affiliate DAN semua titik pencatatan
 * komisi (link milik member yang belum memenuhi syarat tidak menghasilkan
 * komisi). Fail-open saat error DB agar mode default tak pernah terganggu.
 */
function affiliateEligibleUser(int $userId): bool
{
    if (getSetting('affiliate_mode', 'all') !== 'buyers') return true;
    try {
        $st = getDB()->prepare("SELECT 1 FROM orders WHERE user_id = ? AND status = 'paid' LIMIT 1");
        $st->execute([$userId]);
        return (bool) $st->fetchColumn();
    } catch (Exception $e) {
        error_log('affiliateEligibleUser: ' . $e->getMessage());
        return true;
    }
}

/**
 * Batas efektif upload PHP dalam byte: min(post_max_size, upload_max_filesize).
 * Parse notasi shorthand ini (2M, 512K, 1G). 0 = tanpa batas (jarang).
 */
function phpUploadLimitBytes(): int
{
    $parse = function (string $v): int {
        $v = trim($v);
        if ($v === '' || $v === '0' || $v === '-1') return 0;
        $n = (float) $v;
        return (int) round(match (strtolower(substr($v, -1))) {
            'g'     => $n * 1073741824,
            'm'     => $n * 1048576,
            'k'     => $n * 1024,
            default => $n,
        });
    };
    $limits = array_filter([
        $parse((string) ini_get('post_max_size')),
        $parse((string) ini_get('upload_max_filesize')),
    ], fn ($b) => $b > 0);
    return $limits ? min($limits) : 0;
}

/**
 * True bila body POST request ini MELEBIHI post_max_size — PHP mengosongkan
 * $_POST & $_FILES total (termasuk csrf_token), sehingga tanpa deteksi ini
 * guard CSRF menjawab "Permintaan tidak valid" yang menyesatkan. Panggil di
 * action ber-upload SEBELUM validateCSRF.
 */
function postBodyTooLarge(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && empty($_POST) && empty($_FILES)
        && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

function generateCertificateNumber(): string
{
    try {
        $stmt = getDB()->query(
            "SELECT COUNT(*) + 1 FROM certificates WHERE YEAR(issued_at) = YEAR(NOW()) AND MONTH(issued_at) = MONTH(NOW())"
        );
        $count = (int) $stmt->fetchColumn();
        return 'CERT-' . date('Ym') . '-' . str_pad((string) $count, 5, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        return 'CERT-' . time();
    }
}

function generateCertificateUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function issueCertificate(int $userId, int $productId, ?int $issuedBy): ?int
{
    try {
        $pdo = getDB();

        // Duplikat cek
        $stmt = $pdo->prepare("SELECT id FROM certificates WHERE user_id = ? AND product_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$userId, $productId]);
        $existing = $stmt->fetchColumn();
        if ($existing) return (int) $existing;

        $u = $pdo->prepare("SELECT id, name FROM users WHERE id = ?"); $u->execute([$userId]); $user = $u->fetch();
        $p = $pdo->prepare("SELECT id, name FROM products WHERE id = ?"); $p->execute([$productId]); $product = $p->fetch();
        if (!$user || !$product) return null;

        $uuid   = generateCertificateUuid();
        $certNo = generateCertificateNumber();

        $pdo->prepare(
            "INSERT INTO certificates (uuid, user_id, product_id, issued_by, certificate_no, recipient_name, product_name, issued_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'active')"
        )->execute([$uuid, $userId, $productId, $issuedBy, $certNo, $user['name'], $product['name']]);

        $certId = (int) $pdo->lastInsertId();

        // Thumbnail (graceful jika GD tidak ada)
        try {
            require_once dirname(__DIR__) . '/certgen/CertificateRenderer.php';
            $cert = $pdo->query("SELECT * FROM certificates WHERE id = " . $certId)->fetch();
            $thumb = CertificateRenderer::generateThumbnail($cert);
            if ($thumb) {
                $pdo->prepare("UPDATE certificates SET thumbnail_path = ? WHERE id = ?")->execute([$thumb, $certId]);
            }
        } catch (Throwable $e) {
            error_log('cert thumbnail error: ' . $e->getMessage());
        }

        return $certId;
    } catch (Exception $e) {
        error_log('issueCertificate error: ' . $e->getMessage());
        return null;
    }
}

function checkAndAutoIssueCertificate(int $userId, int $materialId): void
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT c.product_id, p.certificate_enabled, p.certificate_auto_issue
             FROM materials m JOIN classes c ON c.id = m.class_id JOIN products p ON p.id = c.product_id
             WHERE m.id = ? LIMIT 1"
        );
        $stmt->execute([$materialId]);
        $info = $stmt->fetch();
        if (!$info || !$info['certificate_enabled'] || !$info['certificate_auto_issue']) return;

        $productId = (int) $info['product_id'];

        $chk = $pdo->prepare("SELECT id FROM certificates WHERE user_id = ? AND product_id = ? AND status = 'active' LIMIT 1");
        $chk->execute([$userId, $productId]);
        if ($chk->fetchColumn()) return;

        $totalStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM materials m JOIN classes c ON c.id = m.class_id
             WHERE c.product_id = ? AND c.status = 'active' AND m.content_type != 'link'"
        );
        $totalStmt->execute([$productId]);
        $total = (int) $totalStmt->fetchColumn();

        $doneStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM material_completions mc
             JOIN materials m ON m.id = mc.material_id JOIN classes c ON c.id = m.class_id
             WHERE mc.user_id = ? AND c.product_id = ? AND m.content_type != 'link' AND c.status = 'active'"
        );
        $doneStmt->execute([$userId, $productId]);
        $done = (int) $doneStmt->fetchColumn();

        if ($total > 0 && $done >= $total) {
            issueCertificate($userId, $productId, null);
        }
    } catch (Exception $e) {
        error_log('checkAndAutoIssueCertificate error: ' . $e->getMessage());
    }
}

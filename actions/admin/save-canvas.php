<?php
// ════════════════════════════════════════════════════════════════
// Averion — Action: Save Canvas
// Handle: publish | update | set_homepage | unset_homepage |
//         toggle_active | delete
// Semua POST. CSRF + requireAdmin wajib.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/canvas');
}

// Upload ZIP melebihi post_max_size hosting → PHP mengosongkan $_POST TOTAL
// (termasuk csrf_token) sebelum skrip ini jalan sama sekali. Tanpa deteksi ini,
// admin melihat "Token keamanan tidak valid" yang menyesatkan padahal akar
// masalahnya batas ukuran upload di hosting (umum di shared hosting cPanel).
// Deteksi SEBELUM cek CSRF supaya pesannya jujur. Pola sama dgn save-download.php.
if (postBodyTooLarge()) {
    $limit = phpUploadLimitBytes();
    flash('error', 'File terlalu besar — melebihi batas upload server'
        . ($limit > 0 ? ' (maks ' . formatFileSize($limit) . ')' : '')
        . '. Perkecil file, atau naikkan post_max_size & upload_max_filesize di pengaturan PHP hosting.', 'error');
    redirect('/admin/canvas');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/admin/canvas');
}

$action      = $_POST['action'] ?? '';
$canvasBase  = rtrim(APP_ROOT, '/\\') . '/assets/uploads/canvas/';

// Slug yang dilarang (bentrok dengan core route Averion).
const CANVAS_RESERVED_SLUGS = [
    'admin', 'dashboard', 'login', 'register', 'logout', 'checkout',
    'products', 'member', 'affiliate', 'api', 'assets', 'actions',
    'pages', 'uploads', 'webhook', 'cron', 'canvas', 'go',
];

// ─── Helper: hapus folder rekursif (AMAN — wajib di dalam canvasBase) ─
$rrmdir = function (string $dir) use ($canvasBase): bool {
    $realBase = realpath($canvasBase);
    $realDir  = realpath($dir);
    if (!$realBase || !$realDir || strpos($realDir, $realBase) !== 0) {
        error_log('Canvas rrmdir: menolak hapus di luar base canvas: ' . $dir);
        return false;
    }
    if (!is_dir($realDir)) return false;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    return @rmdir($realDir);
};

// ─── Helper: proses upload (ZIP atau HTML tunggal) → folder tmp ────
// Mengekstrak/menyimpan ke folder tmp di dalam canvasBase lalu mengembalikan
// path absolut folder tmp tsb (sudah berisi index.html di root). null = gagal.
$processUpload = function (array $file, string $slug, ?string &$error) use ($canvasBase): ?string {
    $allowedExt = [
        'html', 'htm', 'css', 'js', 'json', 'xml', 'txt', 'map',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'avif',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp4', 'webm', 'ogg', 'ogv', 'mov', 'm4v',   // video
        'mp3', 'wav', 'm4a', 'aac',                   // audio
        'pdf',
    ];
    $blockedExt = ['php', 'phtml', 'phar', 'env', 'htaccess', 'web.config'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Gagal mengunggah file (kode: ' . ($file['error'] ?? '?') . ').';
        return null;
    }

    $origName = (string) ($file['name'] ?? '');
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $tmpDir   = rtrim($canvasBase, '/\\') . '/_tmp_' . $slug . '_' . bin2hex(random_bytes(4));

    if (!is_dir($canvasBase) && !mkdir($canvasBase, 0755, true) && !is_dir($canvasBase)) {
        $error = 'Gagal menyiapkan folder upload Canvas.';
        return null;
    }

    // .htaccess folder canvas: IZINKAN serve statis (HTML, gambar, media, font,
    // aset) TAPI tetap BLOKIR skrip server-side. Override assets/uploads/.htaccess
    // yang hanya mengizinkan gambar/css/js — tanpa ini, video/HTML/audio bundle
    // canvas kena 403. Ditulis idempotent (sekali). Terverifikasi override Apache.
    // Versi PORTABEL: hanya blokir eksekusi skrip; sisanya (HTML/media) disajikan
    // normal. TANPA "Options" & TANPA deny-default agar tak 500 di hosting yang
    // membatasi direktif .htaccess (Apache 2.2/LiteSpeed/AllowOverride Options).
    $canvasHt      = rtrim($canvasBase, '/\\') . '/.htaccess';
    $canvasHtBody  = <<<'HT'
# Hardening folder Canvas — PORTABEL. Hanya blokir eksekusi skrip server-side;
# HTML/CSS/JS/media disajikan normal. Tanpa "Options" & deny-default (hindari 500).
<FilesMatch "(?i)\.(php|phtml|phar|php[0-9]|pht|pl|py|cgi|sh|asp|aspx|jsp|shtml|env)$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order Allow,Deny
    Deny from all
  </IfModule>
</FilesMatch>
<FilesMatch "(?i)^(\.ht|web\.config$)">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order Allow,Deny
    Deny from all
  </IfModule>
</FilesMatch>
HT;
    // Tulis bila belum ada ATAU masih versi lama tak-portabel (self-heal instalasi lama).
    $canvasHtOld = file_exists($canvasHt) ? (string) @file_get_contents($canvasHt) : '';
    if ($canvasHtOld === '' || strpos($canvasHtOld, 'Options -Indexes') !== false) {
        @file_put_contents($canvasHt, $canvasHtBody);
    }

    // ── HTML tunggal ──────────────────────────────────────────────
    if (in_array($ext, ['html', 'htm'], true)) {
        if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
            $error = 'File HTML maksimal 2MB.';
            return null;
        }
        if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            $error = 'Gagal membuat folder sementara.';
            return null;
        }
        if (!move_uploaded_file($file['tmp_name'], $tmpDir . '/index.html')) {
            @rmdir($tmpDir);
            $error = 'Gagal menyimpan file HTML.';
            return null;
        }
        return $tmpDir;
    }

    // ── ZIP ───────────────────────────────────────────────────────
    if ($ext !== 'zip') {
        $error = 'Format tidak didukung. Unggah .zip, .html, atau .htm.';
        return null;
    }
    if (($file['size'] ?? 0) > 50 * 1024 * 1024) {
        $error = 'File ZIP maksimal 50MB. Untuk video besar, embed dari YouTube/CDN, jangan bundel di ZIP.';
        return null;
    }
    if (!class_exists('ZipArchive')) {
        $error = 'Ekstensi ZipArchive PHP tidak tersedia di server.';
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) {
        $error = 'File ZIP tidak bisa dibuka.';
        return null;
    }
    if ($zip->numFiles > 2000) {
        $zip->close();
        $error = 'ZIP berisi terlalu banyak file (maks 2000).';
        return null;
    }

    // Validasi + kategorisasi tiap entry SEBELUM ekstrak:
    //   junk (dotfile/dotfolder/.htaccess/__MACOSX/web.config) → DILEWATI (skip, tak menolak ZIP)
    //   skrip server-side → TOLAK (alarm keamanan)
    //   ekstensi tak dikenal → TOLAK (informatif)
    //   diizinkan → masuk daftar ekstrak (ekstraksi selektif)
    $extractList = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = $stat['name'] ?? '';
        if ($name === '') continue;

        $norm = str_replace('\\', '/', $name);
        // Path traversal / absolut.
        if (str_contains($norm, '../') || str_starts_with($norm, '/') || preg_match('#^[A-Za-z]:#', $norm)) {
            $zip->close();
            $error = 'ZIP mengandung path tidak aman: ' . $name;
            return null;
        }
        // Folder entry — struktur dibuat otomatis saat ekstrak file.
        if (str_ends_with($norm, '/')) continue;

        // ── LEWATI junk: dotfile/dotfolder (.htaccess, .DS_Store, .git/, .claude/,
        //    .env, dll) + __MACOSX/ + web.config. Umum di ZIP hasil export; aman
        //    diabaikan (folder canvas di-serve statis, tak butuh config/junk user).
        //    Tidak menolak ZIP — cukup tidak diekstrak. ──
        $skip = str_starts_with($norm, '__MACOSX/') || strtolower(basename($norm)) === 'web.config';
        if (!$skip) {
            foreach (explode('/', $norm) as $seg) {
                if ($seg !== '' && $seg[0] === '.') { $skip = true; break; }
            }
        }
        if ($skip) continue;

        $eext = strtolower(pathinfo($norm, PATHINFO_EXTENSION));
        // Skrip server-side → TOLAK (alarm keamanan).
        if (in_array($eext, $blockedExt, true)) {
            $zip->close();
            $error = 'ZIP mengandung file skrip yang tidak diizinkan: ' . $name;
            return null;
        }
        // Ekstensi tak dikenal → TOLAK (informatif).
        if ($eext === '' || !in_array($eext, $allowedExt, true)) {
            $zip->close();
            $error = 'Ekstensi tak didukung di ZIP: ' . $name . '. Didukung: HTML, CSS, JS, gambar, font, video, audio, PDF.';
            return null;
        }
        $extractList[] = $name;
    }

    if (empty($extractList)) {
        $zip->close();
        $error = 'ZIP tidak berisi file yang bisa ditampilkan (wajib ada index.html).';
        return null;
    }

    if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
        $zip->close();
        $error = 'Gagal membuat folder sementara.';
        return null;
    }
    // Ekstrak HANYA file yang lolos (junk/.htaccess/dotfile tak ikut).
    if (!$zip->extractTo($tmpDir, $extractList)) {
        $zip->close();
        $error = 'Gagal mengekstrak ZIP.';
        return null;
    }
    $zip->close();

    // Cari index.html di root tmp, atau di dalam satu subdirektori tunggal.
    if (!file_exists($tmpDir . '/index.html') && !file_exists($tmpDir . '/index.htm')) {
        $entries = array_values(array_diff(scandir($tmpDir) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir($tmpDir . '/' . $entries[0])) {
            $sub = $tmpDir . '/' . $entries[0];
            if (file_exists($sub . '/index.html') || file_exists($sub . '/index.htm')) {
                return $sub; // index ada di subfolder pembungkus
            }
        }
        $error = 'ZIP wajib memuat index.html di root.';
        return null;
    }

    return $tmpDir;
};

// ════════════════════════════════════════════════════════════════
//  ROUTING ACTION
// ════════════════════════════════════════════════════════════════
try {
    $pdo = getDB();
    if (function_exists('ensureCanvasRedirectColumns')) ensureCanvasRedirectColumns($pdo);

    switch ($action) {

        // ─────────────────────────────────────────────────────────
        case 'publish':
        case 'update':
            $isEdit = ($action === 'update');
            $id     = (int) ($_POST['id'] ?? 0);

            $title           = trim(strip_tags($_POST['title'] ?? ''));
            $slug            = strtolower(trim($_POST['slug'] ?? ''));
            $pageType        = $_POST['page_type'] ?? 'standalone';

            // Multi-produk: Canvas landing bisa menaut >1 produk (junction
            // canvas_page_products). Kolom linked_product_id tetap diisi produk
            // PERTAMA sebagai "utama" (backward-compat + FK lama).
            $linkedProductIds = [];
            foreach ((array) ($_POST['linked_product_ids'] ?? []) as $pidRaw) {
                $pid = (int) $pidRaw;
                if ($pid > 0) $linkedProductIds[$pid] = $pid;   // dedup lewat key
            }
            if (!$linkedProductIds) {                            // kompat form lama (single)
                $single = (int) ($_POST['linked_product_id'] ?? 0);
                if ($single > 0) $linkedProductIds[$single] = $single;
            }
            $linkedProductIds = array_values($linkedProductIds);
            if ($pageType !== 'product_landing') $linkedProductIds = [];
            // Validasi id benar-benar ada (FK-safe) — buang id palsu/terhapus.
            if ($linkedProductIds) {
                $ph  = implode(',', array_fill(0, count($linkedProductIds), '?'));
                $vst = $pdo->prepare("SELECT id FROM products WHERE id IN ($ph)");
                $vst->execute($linkedProductIds);
                $valid = array_map('intval', array_column($vst->fetchAll(), 'id'));
                $linkedProductIds = array_values(array_intersect($linkedProductIds, $valid));
            }
            $linkedProductId = $linkedProductIds[0] ?? null;   // produk utama (kolom lama)
            // Lead magnet tertaut (single) — hanya untuk page_type lead_magnet_landing.
            // Divalidasi FK-safe (id benar-benar ada) supaya tak menyimpan id palsu.
            $linkedLeadMagnetId = null;
            if ($pageType === 'lead_magnet_landing') {
                $lmId = (int) ($_POST['linked_lead_magnet_id'] ?? 0);
                if ($lmId > 0) {
                    $lchk = $pdo->prepare("SELECT id FROM lead_magnets WHERE id = ? LIMIT 1");
                    $lchk->execute([$lmId]);
                    if ($lchk->fetchColumn()) $linkedLeadMagnetId = $lmId;
                }
            }
            $metaDesc        = trim(strip_tags($_POST['meta_desc'] ?? ''));
            $modeRaw         = $_POST['mode'] ?? 'upload';
            $mode            = in_array($modeRaw, ['embed', 'builder'], true) ? $modeRaw : 'upload';
            $isActive        = isset($_POST['is_active']) ? 1 : 0;
            $aiChatEnabled   = isset($_POST['ai_chat_enabled']) ? 1 : 0;
            $metaPixelId     = trim(strip_tags($_POST['meta_pixel_id'] ?? ''));
            $tiktokPixelId   = trim(strip_tags($_POST['tiktok_pixel_id'] ?? ''));
            $createdBy       = $_SESSION['user_id'] ?? null;
            // Redirect (short-link / cloaking affiliate).
            $redirectUrl     = trim((string) ($_POST['redirect_url'] ?? ''));
            $redirectKeepQ   = (int) ($_POST['redirect_keep_query'] ?? 0) === 1 ? 1 : 0;
            if ($pageType !== 'redirect') { $redirectUrl = ''; $redirectKeepQ = 1; }

            $errors = [];

            if ($title === '') {
                $errors[] = 'Nama Canvas wajib diisi.';
            } elseif (mb_strlen($title) > 255) {
                $errors[] = 'Nama Canvas maksimal 255 karakter.';
            }

            // Untuk publish: validasi slug. Untuk update: slug immutable, ambil dari DB.
            $existing = null;
            if ($isEdit) {
                $eStmt = $pdo->prepare("SELECT * FROM canvas_pages WHERE id = ? LIMIT 1");
                $eStmt->execute([$id]);
                $existing = $eStmt->fetch();
                if (!$existing) {
                    flash('error', 'Canvas tidak ditemukan.', 'error');
                    redirect('/admin/canvas');
                }
                $slug = (string) $existing['slug']; // immutable
            } else {
                if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,79}$/', $slug)) {
                    $errors[] = 'Slug tidak valid. Gunakan a-z, 0-9, dash, underscore (2–80 karakter).';
                } elseif (in_array($slug, CANVAS_RESERVED_SLUGS, true)) {
                    $errors[] = 'Slug "' . $slug . '" sudah dipakai sistem. Pilih slug lain.';
                }
            }

            if (!in_array($pageType, ['standalone', 'homepage', 'product_landing', 'rotator', 'lead_magnet_landing', 'redirect'], true)) {
                $errors[] = 'Tipe halaman tidak valid.';
            }

            // Redirect wajib punya URL tujuan http(s) valid.
            if ($pageType === 'redirect') {
                if ($redirectUrl === '') {
                    $errors[] = 'URL Tujuan wajib diisi untuk tipe Redirect.';
                } elseif (!preg_match('#^https?://#i', $redirectUrl) || filter_var($redirectUrl, FILTER_VALIDATE_URL) === false) {
                    $errors[] = 'URL Tujuan harus URL http(s) yang valid.';
                } elseif (mb_strlen($redirectUrl) > 2048) {
                    $errors[] = 'URL Tujuan terlalu panjang (maksimal 2048 karakter).';
                }
            }

            // ── Rotator / Split Test: parse & validasi varian ────
            // rotator_variants_json: [{canvasId,label,weight,active}]. Tiap varian
            // merujuk canvas lain (BUKAN rotator, BUKAN dirinya sendiri).
            $rotatorVariants = [];
            if ($pageType === 'rotator') {
                $rawRV = $_POST['rotator_variants_json'] ?? '';
                $decRV = $rawRV !== '' ? json_decode($rawRV, true) : null;
                $seen  = [];
                if (is_array($decRV)) {
                    foreach ($decRV as $rv) {
                        if (!is_array($rv)) continue;
                        $cvId = (int) ($rv['canvasId'] ?? 0);
                        if ($cvId <= 0 || isset($seen[$cvId])) continue;      // buang kosong/duplikat
                        if ($isEdit && $cvId === (int) $id) continue;         // tak boleh dirinya sendiri
                        $seen[$cvId] = true;
                        $rotatorVariants[] = [
                            'canvasId' => $cvId,
                            'label'    => substr(trim((string) ($rv['label'] ?? '')), 0, 100),
                            'weight'   => max(1, min(1000, (int) ($rv['weight'] ?? 1))),
                            'active'   => !empty($rv['active']) ? 1 : 0,
                        ];
                    }
                }
                // Validasi canvas benar-benar ada & bukan rotator.
                if ($rotatorVariants) {
                    $ids = array_column($rotatorVariants, 'canvasId');
                    $ph  = implode(',', array_fill(0, count($ids), '?'));
                    $vst = $pdo->prepare("SELECT id FROM canvas_pages WHERE id IN ($ph) AND page_type <> 'rotator'");
                    $vst->execute($ids);
                    $validCv = array_map('intval', array_column($vst->fetchAll(), 'id'));
                    $rotatorVariants = array_values(array_filter($rotatorVariants, fn($v) => in_array($v['canvasId'], $validCv, true)));
                }
                if (empty($rotatorVariants)) {
                    $errors[] = 'Rotator wajib punya minimal 1 varian canvas yang valid (bukan sesama rotator).';
                }
            }

            // Multi-pixel: beberapa ID dipisah koma. Validasi tiap ID, dedup, normalisasi.
            $cleanPixels = function (string $raw, string $label) use (&$errors): string {
                if (trim($raw) === '') return '';
                $ok = [];
                foreach (array_map('trim', explode(',', $raw)) as $x) {
                    if ($x === '') continue;
                    if (!preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $x)) {
                        $errors[] = $label . ' tidak valid: "' . htmlspecialchars($x, ENT_QUOTES) . '". Pisahkan beberapa ID dengan koma.';
                        return '';
                    }
                    $ok[$x] = $x;                    // dedup lewat key
                }
                $joined = implode(',', array_values($ok));
                if (strlen($joined) > 255) {
                    $errors[] = $label . ': terlalu banyak ID (maksimal 255 karakter gabungan).';
                    return '';
                }
                return $joined;
            };
            $metaPixelId   = $cleanPixels($metaPixelId, 'Meta Pixel ID');
            $tiktokPixelId = $cleanPixels($tiktokPixelId, 'TikTok Pixel ID');

            // product_landing wajib punya produk valid.
            if ($pageType === 'product_landing') {
                if (!$linkedProductId) {
                    $errors[] = 'Pilih produk yang ditautkan untuk Landing Page Produk.';
                } else {
                    $pChk = $pdo->prepare("SELECT id FROM products WHERE id = ? LIMIT 1");
                    $pChk->execute([$linkedProductId]);
                    if (!$pChk->fetch()) {
                        $errors[] = 'Produk yang ditautkan tidak ditemukan.';
                    }
                }
            } else {
                $linkedProductId = null;
            }

            // Cek slug unik (hanya saat publish).
            if (!$isEdit && empty($errors)) {
                $uStmt = $pdo->prepare("SELECT COUNT(*) FROM canvas_pages WHERE slug = ?");
                $uStmt->execute([$slug]);
                if ((int) $uStmt->fetchColumn() > 0) {
                    $errors[] = 'Slug sudah dipakai Canvas lain.';
                }
            }

            if (!empty($errors)) {
                flash('error', implode(' ', $errors), 'error');
                redirect('/admin/canvas');
            }

            // ── Resolusi konten (embed vs upload) ────────────────
            $content    = $isEdit ? ($existing['content'] ?? null) : null;
            $path       = $isEdit ? ($existing['path'] ?? null)    : null;
            $hasNewFile = isset($_FILES['html_zip']) && ($_FILES['html_zip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

            // Rotator & Redirect TIDAK punya konten sendiri. Lewati syarat upload/embed.
            if ($pageType === 'rotator' || $pageType === 'redirect') {
                $content = null;
                $path    = null;
            } elseif ($mode === 'builder') {
                // Landing Builder: envelope JSON {layout,theme,blocks} → disanitasi
                // (allow-list registry, strip tag) lalu simpan di kolom content.
                $rawEnv = (string) ($_POST['builder_json'] ?? '');
                if (($_POST['builder_json_encoding'] ?? '') === 'base64') {
                    $decEnv = base64_decode($rawEnv, true);
                    if ($decEnv !== false) $rawEnv = $decEnv;
                }
                if (!function_exists('avLbSanitizeEnvelope')) {
                    flash('error', 'Modul Landing Builder tidak tersedia.', 'error');
                    redirect('/admin/canvas');
                }
                $content = avLbSanitizeEnvelope($rawEnv);
                $dec     = json_decode($content, true);
                if (!is_array($dec) || empty($dec['blocks'])) {
                    flash('error', 'Landing Builder: tambahkan minimal satu blok sebelum menyimpan.', 'error');
                    redirect('/admin/canvas');
                }
                $path = null;
            } elseif ($mode === 'embed') {
                $html = (string) ($_POST['html_embed'] ?? '');
                // Anti-WAF: browser mengirim HTML dalam base64 (flag html_embed_encoding=base64)
                // supaya body POST tak mengandung token <script>/<!DOCTYPE> yang memicu 403
                // mod_security/LiteSpeed di hosting pembeli. Decode di sini; fallback ke
                // plaintext bila flag tak ada (JS mati / kompatibilitas mundur).
                if (($_POST['html_embed_encoding'] ?? '') === 'base64') {
                    $decoded = base64_decode($html, true);
                    if ($decoded !== false) {
                        $html = $decoded;
                    }
                }
                if (trim($html) === '') {
                    flash('error', 'Konten embed HTML tidak boleh kosong.', 'error');
                    redirect('/admin/canvas');
                }
                $content = $html;
                $path    = null;
            } else {
                // mode upload
                $destDir = rtrim($canvasBase, '/\\') . '/' . $slug;

                if (!$isEdit || $hasNewFile) {
                    if (!$hasNewFile) {
                        flash('error', 'Mode upload memerlukan file ZIP/HTML.', 'error');
                        redirect('/admin/canvas');
                    }
                    $upErr = null;
                    $tmpDir = $processUpload($_FILES['html_zip'], $slug, $upErr);
                    if (!$tmpDir) {
                        flash('error', $upErr ?: 'Gagal memproses file upload.', 'error');
                        redirect('/admin/canvas');
                    }

                    // Backup folder lama (jika ada) → atomic swap.
                    $bakDir = null;
                    if (is_dir($destDir)) {
                        $bakDir = rtrim($canvasBase, '/\\') . '/_bak_' . $slug . '_' . bin2hex(random_bytes(4));
                        if (!@rename($destDir, $bakDir)) {
                            $rrmdir($tmpDir);
                            flash('error', 'Gagal memperbarui file Canvas (backup gagal).', 'error');
                            redirect('/admin/canvas');
                        }
                    }

                    if (!@rename($tmpDir, $destDir)) {
                        // Restore backup bila swap gagal.
                        if ($bakDir && is_dir($bakDir)) { @rename($bakDir, $destDir); }
                        $rrmdir($tmpDir);
                        flash('error', 'Gagal menempatkan file Canvas baru.', 'error');
                        redirect('/admin/canvas');
                    }

                    // Sukses — buang backup lama.
                    if ($bakDir && is_dir($bakDir)) { $rrmdir($bakDir); }

                    $path    = 'assets/uploads/canvas/' . $slug;
                    $content = null;
                }
            }

            // ── Homepage exclusivity ─────────────────────────────
            $isHomepage = ($pageType === 'homepage') ? 1 : 0;
            if ($isHomepage) {
                $pdo->exec("UPDATE canvas_pages SET is_homepage = 0 WHERE is_homepage = 1");
            }

            if ($isEdit) {
                $pdo->prepare(
                    "UPDATE canvas_pages SET
                        title = ?, meta_desc = ?, mode = ?, content = ?, path = ?,
                        page_type = ?, linked_product_id = ?, linked_lead_magnet_id = ?, is_homepage = ?, is_active = ?,
                        meta_pixel_id = ?, tiktok_pixel_id = ?, ai_chat_enabled = ?, redirect_url = ?, redirect_keep_query = ?, updated_at = NOW()
                     WHERE id = ?"
                )->execute([
                    $title, $metaDesc ?: null, $mode, $content, $path,
                    $pageType, $linkedProductId, $linkedLeadMagnetId, $isHomepage, $isActive,
                    $metaPixelId ?: null, $tiktokPixelId ?: null, $aiChatEnabled, ($redirectUrl !== '' ? $redirectUrl : null), $redirectKeepQ,
                    $id,
                ]);
                $canvasId = $id;
                logActivity('admin', 'canvas.update', 'canvas', $id, 'Canvas: ' . $title);
                flash('success', 'Canvas berhasil diperbarui.');
            } else {
                $pdo->prepare(
                    "INSERT INTO canvas_pages
                        (slug, title, meta_desc, mode, content, path, page_type,
                         linked_product_id, linked_lead_magnet_id, is_homepage, is_active, meta_pixel_id,
                         tiktok_pixel_id, ai_chat_enabled, redirect_url, redirect_keep_query, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $slug, $title, $metaDesc ?: null, $mode, $content, $path, $pageType,
                    $linkedProductId, $linkedLeadMagnetId, $isHomepage, $isActive, $metaPixelId ?: null,
                    $tiktokPixelId ?: null, $aiChatEnabled, ($redirectUrl !== '' ? $redirectUrl : null), $redirectKeepQ, $createdBy,
                ]);
                $newId = (int) $pdo->lastInsertId();
                $canvasId = $newId;
                logActivity('admin', 'canvas.create', 'canvas', $newId, 'Canvas: ' . $title);
                flash('success', 'Canvas berhasil dipublish.');
            }

            // Sinkronkan produk tertaut (junction) — ganti total: hapus lalu insert.
            try {
                $pdo->prepare("DELETE FROM canvas_page_products WHERE canvas_page_id = ?")->execute([$canvasId]);
                if ($linkedProductIds) {
                    $ins = $pdo->prepare(
                        "INSERT IGNORE INTO canvas_page_products (canvas_page_id, product_id) VALUES (?, ?)"
                    );
                    foreach ($linkedProductIds as $pid) $ins->execute([$canvasId, $pid]);
                }
            } catch (Throwable $e) {
                error_log('canvas junction sync error: ' . $e->getMessage());
            }

            // ── Sinkronkan varian Rotator ────────────────────────
            // PENTING: UPSERT per (rotator_id, variant_canvas_id) — JANGAN
            // DELETE+INSERT. Id baris varian jadi FK logis event impression/
            // checkout_click; kalau id berubah tiap simpan, metrik split-test
            // historis ter-orphan (hilang). Jaga id baris tetap STABIL.
            try {
                if ($pageType !== 'rotator') {
                    // Bukan rotator lagi → bersihkan varian.
                    $pdo->prepare("DELETE FROM canvas_rotator_variants WHERE rotator_id = ?")->execute([$canvasId]);
                } else {
                    $exStmt = $pdo->prepare("SELECT id, variant_canvas_id FROM canvas_rotator_variants WHERE rotator_id = ?");
                    $exStmt->execute([$canvasId]);
                    $existingByCv = [];
                    foreach ($exStmt->fetchAll() as $r) { $existingByCv[(int) $r['variant_canvas_id']] = (int) $r['id']; }

                    $upd = $pdo->prepare(
                        "UPDATE canvas_rotator_variants SET label = ?, weight = ?, is_active = ?, sort_order = ? WHERE id = ?"
                    );
                    $insRV = $pdo->prepare(
                        "INSERT INTO canvas_rotator_variants
                           (rotator_id, variant_canvas_id, label, weight, is_active, sort_order)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $keepCv = [];
                    $so = 0;
                    foreach ($rotatorVariants as $rv) {
                        $cv = (int) $rv['canvasId'];
                        $keepCv[$cv] = true;
                        if (isset($existingByCv[$cv])) {
                            $upd->execute([$rv['label'], $rv['weight'], $rv['active'], $so, $existingByCv[$cv]]);
                        } else {
                            $insRV->execute([$canvasId, $cv, $rv['label'], $rv['weight'], $rv['active'], $so]);
                        }
                        $so++;
                    }
                    // Hapus varian yang dibuang admin (event historisnya ter-orphan — wajar).
                    foreach ($existingByCv as $cv => $rid) {
                        if (!isset($keepCv[$cv])) {
                            $pdo->prepare("DELETE FROM canvas_rotator_variants WHERE id = ?")->execute([$rid]);
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('canvas rotator variants sync error: ' . $e->getMessage());
            }

            redirect('/admin/canvas');
            break;

        // ─────────────────────────────────────────────────────────
        case 'set_homepage':
            $slug = strtolower(preg_replace('/[^a-z0-9_-]/', '', $_POST['slug'] ?? ''));
            if ($slug === '') {
                flash('error', 'Slug tidak valid.', 'error');
                redirect('/admin/canvas');
            }
            $pdo->exec("UPDATE canvas_pages SET is_homepage = 0 WHERE is_homepage = 1");
            $pdo->prepare("UPDATE canvas_pages SET is_homepage = 1, is_active = 1 WHERE slug = ?")
                ->execute([$slug]);
            flash('success', 'Homepage Canvas aktif.');
            redirect('/admin/canvas');
            break;

        // ─────────────────────────────────────────────────────────
        case 'unset_homepage':
            $pdo->exec("UPDATE canvas_pages SET is_homepage = 0 WHERE is_homepage = 1");
            flash('success', 'Homepage Canvas dinonaktifkan. Halaman produk default aktif kembali.');
            redirect('/admin/canvas');
            break;

        // ─────────────────────────────────────────────────────────
        case 'toggle_active':
            $id = (int) ($_POST['id'] ?? 0);
            $cStmt = $pdo->prepare("SELECT is_active, is_homepage FROM canvas_pages WHERE id = ? LIMIT 1");
            $cStmt->execute([$id]);
            $row = $cStmt->fetch();
            if (!$row) {
                flash('error', 'Canvas tidak ditemukan.', 'error');
                redirect('/admin/canvas');
            }
            // Jika dinonaktifkan, sekaligus lepas status homepage.
            if ((int) $row['is_active'] === 1 && (int) $row['is_homepage'] === 1) {
                $pdo->prepare("UPDATE canvas_pages SET is_active = 0, is_homepage = 0 WHERE id = ?")
                    ->execute([$id]);
            } else {
                $pdo->prepare("UPDATE canvas_pages SET is_active = 1 - is_active WHERE id = ?")
                    ->execute([$id]);
            }
            flash('success', 'Status Canvas diperbarui.');
            redirect('/admin/canvas');
            break;

        // ─────────────────────────────────────────────────────────
        case 'clear_cache':
            $id = (int) ($_POST['id'] ?? 0);
            $cStmt = $pdo->prepare("SELECT title, slug, mode FROM canvas_pages WHERE id = ? LIMIT 1");
            $cStmt->execute([$id]);
            $page = $cStmt->fetch();
            if (!$page) {
                flash('error', 'Canvas tidak ditemukan.', 'error');
                redirect('/admin/canvas');
            }

            // Update timestamp updated_at ke NOW() di DB
            $pdo->prepare("UPDATE canvas_pages SET updated_at = NOW() WHERE id = ?")->execute([$id]);

            // Bila mode upload, touch file index.html di folder assets/uploads/canvas/{slug}/
            $slug      = (string) ($page['slug'] ?? '');
            $folder    = rtrim($canvasBase, '/\\') . '/' . $slug;
            $indexFile = $folder . '/index.html';
            if (file_exists($indexFile)) {
                @touch($indexFile);
            }

            logActivity('admin', 'canvas.clear_cache', 'canvas', $id, 'Clear cache canvas: ' . $page['title']);
            flash('success', 'Cache Canvas "' . $page['title'] . '" berhasil dibersihkan.');
            redirect('/admin/canvas');
            break;

        // ─────────────────────────────────────────────────────────
        case 'delete':
            $id = (int) ($_POST['id'] ?? 0);
            $dStmt = $pdo->prepare("SELECT slug FROM canvas_pages WHERE id = ? LIMIT 1");
            $dStmt->execute([$id]);
            $slug = $dStmt->fetchColumn();
            if ($slug === false) {
                flash('error', 'Canvas tidak ditemukan.', 'error');
                redirect('/admin/canvas');
            }

            // Hapus folder filesystem (guard realpath di dalam helper).
            $folder = rtrim($canvasBase, '/\\') . '/' . $slug;
            if (is_dir($folder)) {
                $rrmdir($folder);
            }

            $pdo->prepare("DELETE FROM canvas_pages WHERE id = ?")->execute([$id]);
            logActivity('admin', 'canvas.delete', 'canvas', $id, 'Canvas slug: ' . $slug);
            flash('success', 'Canvas berhasil dihapus.');
            redirect('/admin/canvas');
            break;

        // ─────────────────────────────────────────────────────────
        default:
            flash('error', 'Aksi tidak dikenal.', 'error');
            redirect('/admin/canvas');
    }

} catch (Throwable $e) {
    error_log('Save canvas error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menyimpan Canvas. Silakan coba lagi.', 'error');
    redirect('/admin/canvas');
}

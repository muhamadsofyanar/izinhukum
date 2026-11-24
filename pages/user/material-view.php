<?php
require_once __DIR__ . '/../../bootstrap.php';

// Mode preview: admin/staff melihat materi persis seperti pembeli (read-only).
$previewMode = !empty($_GET['preview']);
if ($previewMode) {
    requireAdminOrStaff();
} else {
    requireLogin();
}

$productId    = (int) ($_GET['product_id']  ?? 0);
$leadMagnetId = (int) ($_GET['lead_magnet_id'] ?? 0);
$materialId   = (int) ($_GET['material_id'] ?? 0);

// Owner = produk ATAU lead magnet (default produk → perilaku lama tak berubah).
$ownerType = $leadMagnetId > 0 ? 'lead_magnet' : 'product';
$ownerId   = $ownerType === 'lead_magnet' ? $leadMagnetId : $productId;
$ownerCol  = $ownerType === 'lead_magnet' ? 'lead_magnet_id' : 'product_id';
// Semua navigasi internal memakai $ownerBase → di preview otomatis tetap di preview.
$baseRoot  = $previewMode ? '/admin/content/preview' : '/member';
$ownerBase = $ownerType === 'lead_magnet' ? $baseRoot . '/lm/' . $ownerId : $baseRoot . '/' . $ownerId;
// URL keluar preview → kembali ke editor konten.
$exitUrl   = '/admin/content?' . ($ownerType === 'lead_magnet' ? 'lead_magnet_id=' : 'product_id=') . $ownerId;

if ($ownerId <= 0 || $materialId <= 0) {
    redirect($previewMode ? '/admin/content' : '/member');
}

$userId = (int) $_SESSION['user_id'];

if (!$previewMode) {
    $canAccess = $ownerType === 'lead_magnet'
        ? hasLeadMagnetAccess($userId, $leadMagnetId)
        : hasAccess($userId, $productId);
    if (!$canAccess) {
        flash('error', 'Kamu tidak memiliki akses ke konten ini.', 'error');
        redirect('/member');
    }
}

$product      = null;
$material     = null;
$classes      = [];
$prevMatId    = null;
$nextMatId    = null;
$currentClass = null;
$dripStates   = [];
$materialAttachments = [];
$isCompleted  = false;

try {
    $pdo = getDB();

    if ($ownerType === 'lead_magnet') {
        $stmt = $pdo->prepare("SELECT id, title AS name, thumbnail FROM lead_magnets WHERE id = ? LIMIT 1");
    } else {
        $stmt = $pdo->prepare("SELECT id, name, thumbnail FROM products WHERE id = ? LIMIT 1");
    }
    $stmt->execute([$ownerId]);
    $product = $stmt->fetch();
    if (!$product) redirect('/member');

    // All active classes + their materials for sidebar (owner: produk / lead magnet).
    // Untuk owner produk, sertakan juga modul yang DIBAGIKAN ke produk ini.
    $sharedClause = ($ownerCol === 'product_id')
        ? ' OR id IN (SELECT class_id FROM class_products WHERE product_id = ?)'
        : '';
    $stmt = $pdo->prepare(
        "SELECT id, title, sort_order, unlock_after_days FROM classes
         WHERE ({$ownerCol} = ?{$sharedClause}) AND status = 'active'
         ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute($sharedClause !== '' ? [$ownerId, $ownerId] : [$ownerId]);
    $rawClasses = $stmt->fetchAll();

    // Drip Content: status kunci per modul — hanya owner produk, bukan preview.
    $dripStates = (!$previewMode && $ownerType === 'product')
        ? dripLockStates($rawClasses, $userId, $productId)
        : [];
    $isClassLocked = fn(int $cid): bool => !empty($dripStates[$cid]['locked']);

    if ($rawClasses) {
        $classIds = array_column($rawClasses, 'id');
        $ph = implode(',', array_fill(0, count($classIds), '?'));
        $mStmt = $pdo->prepare(
            "SELECT id, class_id, title, content_type FROM materials
             WHERE class_id IN ($ph)
             ORDER BY sort_order ASC, id ASC"
        );
        $mStmt->execute($classIds);
        $allMats = $mStmt->fetchAll();

        $matsByClass = [];
        foreach ($allMats as $m) {
            $matsByClass[$m['class_id']][] = $m;
        }
        foreach ($rawClasses as &$cls) {
            $cls['materials'] = $matsByClass[$cls['id']] ?? [];
        }
        unset($cls);
        $classes = $rawClasses;

        // Flat ordered list for prev/next — modul terkunci (drip) dilewati
        $flatMats = [];
        foreach ($classes as $cls) {
            if ($isClassLocked((int) $cls['id'])) continue;
            foreach ($cls['materials'] as $m) {
                $flatMats[] = (int) $m['id'];
            }
        }

        $idx = array_search($materialId, $flatMats);
        if ($idx !== false) {
            $prevMatId = $idx > 0 ? $flatMats[$idx - 1] : null;
            $nextMatId = $idx < count($flatMats) - 1 ? $flatMats[$idx + 1] : null;
        }
    }

    // Load current material (verify it belongs to this product / modul yang dibagikan)
    $matShared = ($ownerCol === 'product_id')
        ? ' OR c.id IN (SELECT class_id FROM class_products WHERE product_id = ?)'
        : '';
    $stmt = $pdo->prepare(
        "SELECT m.* FROM materials m
         JOIN classes c ON c.id = m.class_id
         WHERE m.id = ? AND (c.{$ownerCol} = ?{$matShared}) LIMIT 1"
    );
    $stmt->execute($matShared !== '' ? [$materialId, $ownerId, $ownerId] : [$materialId, $ownerId]);
    $material = $stmt->fetch();

    if (!$material) {
        flash('error', 'Materi tidak ditemukan.', 'error');
        redirect($ownerBase);
    }

    // Drip Content: materi milik modul terkunci tidak boleh diakses langsung via URL.
    $matDrip = $dripStates[(int) $material['class_id']] ?? null;
    if ($matDrip && $matDrip['locked']) {
        flash('error', 'Materi ini masih terkunci — terbuka dalam ' . (int) $matDrip['days_left'] . ' hari lagi.', 'error');
        redirect($ownerBase);
    }

    // Materi tipe file yang menaut Download Manager: muat metadata file-nya
    // (unduhan lewat token sekali-pakai, bukan URL langsung).
    $material['_download'] = null;
    if (($material['content_type'] ?? '') === 'file' && !empty($material['download_id'])) {
        $dStmt = $pdo->prepare(
            "SELECT id, title, filename_orig, filename_stor, mime_type, file_size FROM product_downloads
             WHERE id = ? AND status = 'active' LIMIT 1"
        );
        $dStmt->execute([(int) $material['download_id']]);
        $material['_download'] = $dStmt->fetch() ?: null;
    }

    $download = $material['_download'];
    $isActiveClass = in_array((int) $material['class_id'], array_map('intval', array_column($rawClasses, 'id')), true);
    $isPdf = $isActiveClass && ($download['mime_type'] ?? '') === 'application/pdf';

    // Serve the protected PDF through the same material access and drip checks.
    if (($_GET['view'] ?? '') === 'pdf') {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            http_response_code(405);
            exit;
        }

        $storage = $isPdf ? realpath(DOWNLOAD_STORAGE_PATH) : false;
        $path = $storage !== false
            ? realpath($storage . DIRECTORY_SEPARATOR . $download['filename_stor'])
            : false;
        if ($path === false || dirname($path) !== $storage || !is_file($path)) {
            http_response_code(404);
            exit;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            http_response_code(404);
            exit;
        }

        $stat = fstat($handle);
        session_write_close();
        header('Content-Type: application/pdf');
        header("Content-Disposition: inline; filename=ebook.pdf; filename*=UTF-8''"
            . rawurlencode($download['filename_orig']));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Accept-Ranges: none');
        if ($stat !== false) {
            header('Content-Length: ' . $stat['size']);
        }
        if ($method === 'GET') {
            fpassthru($handle);
        }
        fclose($handle);
        exit;
    }

    if ($isPdf) {
        $material['_pdf_url'] = rtrim(APP_URL, '/') . $ownerBase . '/material/' . $materialId . '?view=pdf';
    }

    // Lampiran materi (material_files → Download Manager) — berlaku semua tipe.
    // Fail-safe bila tabel belum ada (instalasi belum menjalankan migrasi 1.2.26).
    try {
        $aStmt = $pdo->prepare(
            "SELECT pd.id, pd.title, pd.filename_orig, pd.file_size
             FROM material_files mf
             JOIN product_downloads pd ON pd.id = mf.download_id AND pd.status = 'active'
             WHERE mf.material_id = ?
             ORDER BY mf.sort_order ASC, mf.id ASC"
        );
        $aStmt->execute([(int) $material['id']]);
        $materialAttachments = $aStmt->fetchAll();
    } catch (Exception $e) { /* tabel belum ada — lampiran dilewati */ }

    // Status selesai materi ini — dipakai tombol Tandai Selesai DAN auto-selesai
    // video di JS bawah halaman.
    if (!$previewMode) {
        try {
            $cc = $pdo->prepare('SELECT 1 FROM material_completions WHERE user_id = ? AND material_id = ? LIMIT 1');
            $cc->execute([$userId, (int) $material['id']]);
            $isCompleted = (bool) $cc->fetchColumn();
        } catch (Exception $e) {}
    }

    // Lanjutkan Belajar: catat materi terakhir dibuka per konteks belajar
    // (produk / lead magnet). Fail-safe bila tabel belum ada.
    if (!$previewMode) {
        try {
            $pdo->prepare(
                'INSERT INTO material_resume (user_id, product_id, lead_magnet_id, material_id)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE material_id = VALUES(material_id)'
            )->execute([
                $userId,
                $ownerType === 'lead_magnet' ? 0 : $productId,
                $ownerType === 'lead_magnet' ? $leadMagnetId : 0,
                (int) $material['id'],
            ]);
        } catch (Exception $e) { /* tabel belum ada — resume dilewati */ }
    }

    // Find which class the current material belongs to
    foreach ($classes as $cls) {
        foreach ($cls['materials'] as $m) {
            if ((int) $m['id'] === $materialId) {
                $currentClass = $cls;
                break 2;
            }
        }
    }

} catch (Exception $e) {
    error_log('Material view error: ' . $e->getMessage());
    flash('error', 'Gagal memuat materi.', 'error');
    redirect($ownerBase);
}

$pageTitle = $material['title'];

function matTypeIcon(string $type): string
{
    return match ($type) {
        'video' => '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>',
        'file'  => '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        'link'  => '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        'embed' => '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
        'html'  => '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="7 8 3 12 7 16"/><polyline points="17 8 21 12 17 16"/><line x1="14" y1="4" x2="10" y2="20"/></svg>',
        default => '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
    };
}

/**
 * Player YouTube terkunci — dipakai tipe materi "video" (dari URL) maupun "embed"
 * (ID ditarik dari kode iframe admin), supaya keduanya sama-sama tertutup.
 * Antarmuka YouTube dimatikan (controls=0) & seluruh permukaan ditutup perisai;
 * kontrol pengganti dijalankan lewat IFrame API di JS bawah halaman.
 */
function renderLockedYouTube(string $rawId): string
{
    $id = htmlspecialchars($rawId, ENT_QUOTES, 'UTF-8');

    // CATATAN PENTING soal "Error 153" — DUA syarat harus terpenuhi:
    // 1) `origin` (bila diisi) HARUS cocok dengan domain pemuat. origin yang hilang
    //    tidak masalah; origin yang meleset mematikan player. Karena itu origin
    //    disusun di browser dari location.origin (bukan APP_URL yang bisa meleset:
    //    http vs https, www vs non-www, subfolder). JS di bawah halaman yang
    //    memasang src-nya; <noscript> menyediakan pemutar biasa bila JS mati.
    // 2) Browser HARUS mengirim Referer ke youtube.com. Server yang menyetel header
    //    `Referrer-Policy: same-origin` (nginx/CloudPanel di averion.id — .htaccess
    //    kita diabaikan nginx) membuat Referer tidak terkirim lintas origin, dan
    //    player enablejsapi menolak dengan Error 153 walau origin sudah benar.
    //    Atribut referrerpolicy DI IFRAME menimpa kebijakan halaman/server, jadi
    //    wajib dipasang di sini agar jalan di hosting mana pun. Terbukti lewat
    //    percobaan terkendali: kombinasi sama persis, tanpa atribut = onError 153,
    //    dengan atribut = onReady sehat.
    return '<div class="yt-lock" data-yt="' . $id . '">'
         . '<div class="yt-stage">'
         // Domain youtube.com biasa — BUKAN youtube-nocookie.com: banyak pemblokir
         // iklan/pelacak menghadang domain nocookie sehingga player tak muncul.
         . '<iframe class="yt-frame" data-src="https://www.youtube.com/embed/' . $id
         . '?enablejsapi=1&amp;controls=0&amp;rel=0&amp;modestbranding=1&amp;iv_load_policy=3'
         . '&amp;disablekb=1&amp;playsinline=1&amp;fs=0" '
         . 'referrerpolicy="strict-origin-when-cross-origin" '
         . 'allow="autoplay; encrypted-media; picture-in-picture" frameborder="0"></iframe>'
         . '<noscript><iframe class="yt-frame" style="pointer-events:auto" '
         . 'src="https://www.youtube.com/embed/' . $id . '?rel=0" '
         . 'referrerpolicy="strict-origin-when-cross-origin" '
         . 'allowfullscreen frameborder="0"></iframe></noscript>'
         . '<div class="yt-shield" aria-hidden="true"></div>'
         . '<button type="button" class="yt-big" aria-label="Putar video">'
         . '<svg viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg>'
         . '</button>'
         . '</div>'
         . '<div class="yt-bar">'
         . '<button type="button" class="yt-play" aria-label="Putar atau jeda">'
         . '<svg class="yt-ic-play" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg>'
         . '<svg class="yt-ic-pause hidden" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>'
         . '</button>'
         . '<div class="yt-seek" role="slider" tabindex="0" aria-label="Garis waktu video"><div class="yt-seek-fill"></div></div>'
         . '<span class="yt-time">0:00 / 0:00</span>'
         . '<button type="button" class="yt-fs" aria-label="Layar penuh">'
         . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>'
         . '</button>'
         . '</div>'
         . '</div>';
}

function renderContent(array $mat): string
{
    $type = $mat['content_type'];
    $url  = $mat['content_url'] ?? '';
    $text = $mat['content']     ?? '';

    if ($type === 'text') {
        if (empty($text)) {
            return '<p class="text-sm text-gray-400 dark:text-gray-600 italic">Belum ada konten teks.</p>';
        }
        // Konten dari editor teks kaya (toolbar di form admin) tersimpan sebagai
        // HTML — render apa adanya dgn gaya rapi (admin/staff terpercaya, sama
        // seperti tipe 'html'). Konten lama berupa teks polos tetap via nl2br.
        if (preg_match('/<[a-z][^>]*>/i', $text)) {
            return '<div class="material-html max-w-none text-gray-700 dark:text-gray-300 text-sm leading-relaxed '
                 . '[&_img]:max-w-full [&_img]:h-auto [&_img]:rounded-lg [&_a]:text-[var(--brand-color)] [&_a]:underline '
                 . '[&_table]:block [&_table]:overflow-x-auto [&_h1]:font-bold [&_h2]:font-bold [&_h3]:font-semibold '
                 . '[&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-2 [&_h2]:text-lg [&_h2]:mt-4 [&_h2]:mb-2 [&_h3]:mt-3 [&_h3]:mb-1.5 [&_blockquote]:border-l-4 [&_blockquote]:border-gray-200 [&_blockquote]:pl-3 [&_blockquote]:italic">'
                 . $text
                 . '</div>';
        }
        return '<div class="prose prose-sm dark:prose-invert max-w-none text-gray-700 dark:text-gray-300 text-sm leading-relaxed whitespace-pre-wrap">'
             . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'))
             . '</div>';
    }

    if ($type === 'video') {
        if (empty($url)) {
            return '<p class="text-sm text-gray-400 dark:text-gray-600 italic">URL video tidak tersedia.</p>';
        }
        // Link "Buka di ..." — fallback bila embed terblokir (embedding dimatikan
        // pengunggah, CSP host, jaringan) supaya member tetap bisa menonton.
        $watchLink = function (string $href, string $label): string {
            $h = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
            return '<div class="mt-2 text-right"><a href="' . $h . '" target="_blank" rel="noopener" '
                 . 'class="inline-flex items-center gap-1 text-xs text-gray-400 hover:text-[var(--brand-color)] transition-colors">'
                 . $label . ' <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></a></div>';
        };
        // YouTube (watch, youtu.be, embed, shorts, live)
        // ─── Player terkunci (proteksi konten berbayar) ─────────────────────
        // Materi di area member = konten ber-akses, jadi seluruh antarmuka
        // YouTube DIMATIKAN (controls=0) lalu diganti kontrol milik kita:
        //  • tanpa logo YouTube, judul, tombol share/watch-later, dan tanpa
        //    tombol "Tonton di YouTube" yang muncul saat video di-pause,
        //  • perisai transparan menutup SELURUH permukaan player → tak ada
        //    satu pun klik yang sampai ke dalam iframe (perisai parsial versi
        //    sebelumnya tidak cukup: logo & tombol pause berada di luarnya),
        //  • klik-kanan otomatis kehilangan sasaran karena yang tersentuh
        //    kursor adalah perisai kita, bukan dokumen YouTube. (Handler
        //    contextmenu saja TIDAK PERNAH bisa menjangkau isi iframe lintas
        //    domain — itu sebab versi sebelumnya masih memunculkan menu.)
        //  • kontrol pengganti: putar/jeda, garis waktu (bisa digeser), durasi,
        //    dan layar penuh — dijalankan lewat YouTube IFrame API.
        // CATATAN JUJUR: ini menutup jalan lewat ANTARMUKA, bukan proteksi
        // mutlak — ID video tetap terbaca lewat "view source". Proteksi
        // sungguhan butuh penyedia ber-signed-URL + kunci domain
        // (Bunny/Cloudflare Stream).
        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?|shorts|live)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $url, $m)) {
            return renderLockedYouTube($m[1]);
        }
        // Vimeo
        if (preg_match('/vimeo\.com\/(?:video\/)?(\d+)/i', $url, $m)) {
            $id = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            return '<div class="relative w-full rounded-xl overflow-hidden bg-black" style="padding-top:56.25%">'
                 . '<iframe class="absolute inset-0 w-full h-full" src="https://player.vimeo.com/video/' . $id . '" '
                 . 'allowfullscreen frameborder="0"></iframe>'
                 . '</div>'
                 . $watchLink('https://vimeo.com/' . $id, 'Buka di Vimeo');
        }
        // Direct video (mp4/webm dsb). Bila bukan file video, tetap sediakan link buka.
        $su = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        if (preg_match('/\.(mp4|webm|ogg|mov|m4v)(\?|$)/i', $url)) {
            return '<video class="w-full rounded-xl" controls><source src="' . $su . '">'
                 . '<p class="text-sm text-gray-500 p-4">Browser tidak mendukung pemutaran video.</p></video>'
                 . $watchLink($url, 'Buka video');
        }
        // URL video lain (tak dikenali) — tawarkan tombol buka, jangan biarkan kosong.
        return '<div class="rounded-xl border border-dashed border-gray-200 dark:border-gray-700 p-6 text-center">'
             . '<p class="text-sm text-gray-500 dark:text-gray-400 mb-3">Video ini dibuka di tab baru.</p>'
             . '<a href="' . $su . '" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-brand hover:opacity-90 transition">'
             . 'Buka Video <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></a></div>';
    }

    if ($type === 'embed') {
        if (empty($text)) {
            return '<p class="text-sm text-gray-400 dark:text-gray-600 italic">Kode embed belum diisi.</p>';
        }
        // Kode embed (iframe) dibuat oleh admin/staff terpercaya → dirender apa adanya.
        // Iframe polos dibuat responsif penuh-lebar & MEMPERTAHANKAN rasio aslinya (dari
        // atribut width/height): YouTube 16:9, Canva sesuai desainnya. Fallback 16:9 bila
        // iframe tak menyertakan dimensi. Rasio diatur via CSS `.material-embed > iframe`
        // + penormal JS di bawah. Embed ber-wrapper sendiri (Canva versi div > iframe)
        // TIDAK terkena selektor `> iframe`, jadi tetap memakai sizing-nya sendiri.
        //
        // KHUSUS embed YouTube: pakai perlakuan sama dgn tipe "video" —
        //  (a) dibungkus kotak rasio 16:9 TERKUNCI supaya tak gepeng saat kode embed
        //      di-paste tanpa atribut dimensi / dengan wrapper sendiri;
        //  (b) diberi perisai + anti-klik-kanan agar tidak jadi celah kabur ke YouTube
        //      (percuma mengeraskan tipe "video" bila lewat "embed" masih terbuka).
        // Embed YouTube di-ubah jadi player terkunci yang SAMA dgn tipe "video":
        // ID ditarik dari kode embed, sisanya dirender ulang oleh kita. Kode embed
        // asli sengaja DIBUANG untuk tipe ini — kalau iframe milik admin dipakai
        // apa adanya, antarmuka YouTube (logo, judul, "Tonton di YouTube") ikut
        // terbawa dan celah kabur tetap terbuka.
        if (preg_match('#(?:youtube\.com|youtube-nocookie\.com)/embed/([A-Za-z0-9_-]{11})#i', $text, $ym)) {
            return renderLockedYouTube($ym[1]);
        }
        return '<div class="material-embed rounded-xl overflow-hidden">'
             . $text
             . '</div>';
    }

    if ($type === 'html') {
        if (empty($text)) {
            return '<p class="text-sm text-gray-400 dark:text-gray-600 italic">Konten HTML belum diisi.</p>';
        }
        // HTML kustom dari admin/staff terpercaya → dirender apa adanya.
        // Gambar dibuat responsif, tabel bisa scroll horizontal, link memakai brand color.
        return '<div class="material-html max-w-none text-gray-700 dark:text-gray-300 text-sm leading-relaxed '
             . '[&_img]:max-w-full [&_img]:h-auto [&_img]:rounded-lg [&_a]:text-[var(--brand-color)] [&_a]:underline '
             . '[&_table]:block [&_table]:overflow-x-auto [&_h1]:font-bold [&_h2]:font-bold [&_h3]:font-semibold">'
             . $text
             . '</div>';
    }

    if ($type === 'file') {
        // Prioritas: file Download Manager (terproteksi token + cek akses).
        // URL manual tetap jalan sebagai fallback lama.
        $dl = $mat['_download'] ?? null;
        if ($dl) {
            $fname = htmlspecialchars($dl['filename_orig'], ENT_QUOTES, 'UTF-8');
            $fsize = formatFileSize((int) $dl['file_size']);
            $viewer = '';
            if (isset($mat['_pdf_url'])) {
                $pdfUrl = e($mat['_pdf_url']);
                $viewer = '<div class="w-full min-w-0">'
                    . '<div class="material-pdf-reader" data-material-pdf="' . $pdfUrl . '">'
                    . '<div class="material-pdf-frame">'
                    . '<canvas hidden role="img"></canvas>'
                    . '<p data-pdf-status role="status" class="p-2 text-sm text-gray-500 dark:text-gray-400"></p></div>'
                    . '<div data-pdf-controls hidden><div class="material-pdf-toolbar">'
                    . '<button type="button" data-pdf-prev aria-label="Halaman sebelumnya"><span aria-hidden="true">‹</span></button>'
                    . '<form data-pdf-jump class="material-pdf-jump" aria-label="Lompat ke halaman">'
                    . '<input type="number" aria-label="Nomor halaman" min="1" step="1" inputmode="numeric" enterkeyhint="go" required>'
                    . '<span aria-hidden="true">/ <span data-pdf-total></span></span></form>'
                    . '<button type="button" data-pdf-next aria-label="Halaman berikutnya"><span aria-hidden="true">›</span></button>'
                    . '<button type="button" data-pdf-fullscreen hidden aria-label="Layar penuh" title="Layar penuh">'
                    . '<span aria-hidden="true">⛶</span></button>'
                    . '</div></div></div>'
                    . '<dialog class="material-pdf-dialog" data-pdf-dialog aria-label="Penampil PDF layar penuh"></dialog>'
                    . '<div class="material-pdf-extra mt-3"><a class="inline-flex font-semibold underline text-[var(--brand-color)]" href="' . $pdfUrl
                    . '" target="_blank" rel="noopener">Buka PDF di tab baru</a></div></div>';
            }
            return $viewer . '
        <div class="flex items-center justify-center ' . ($viewer === '' ? 'py-16' : 'material-pdf-extra py-4') . '">
          <button type="button" data-material-download="' . (int) $dl['id'] . '"
             class="group flex flex-col items-center gap-5 p-10 rounded-2xl border-2 border-dashed border-gray-200 dark:border-gray-700 hover:border-[var(--brand-color)] transition-colors max-w-sm w-full text-center disabled:opacity-60 disabled:cursor-wait">
            <div class="w-16 h-16 rounded-2xl bg-gray-100 dark:bg-gray-800 group-hover:bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center transition-colors">
              <svg class="w-8 h-8 text-gray-400 group-hover:text-[var(--brand-color)] transition-colors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                <line x1="12" y1="12" x2="12" y2="18"/><polyline points="9 15 12 18 15 15"/>
              </svg>
            </div>
            <div>
              <p class="font-semibold text-gray-900 dark:text-white text-sm mb-1">' . $fname . '</p>
              <p class="text-xs text-gray-500 dark:text-gray-400">' . $fsize . ' &middot; link unduh aman sekali-pakai</p>
            </div>
            <span class="px-5 py-2 rounded-xl text-sm font-semibold text-white bg-brand group-hover:opacity-90 transition">Download File</span>
          </button>
        </div>';
        }
        if (!empty($mat['download_id'])) {
            // Tertaut Download Manager tapi file nonaktif/terhapus.
            return '<p class="text-sm text-gray-400 dark:text-gray-600 italic">File tidak tersedia.</p>';
        }
        if (empty($url)) {
            return '<p class="text-sm text-gray-400 dark:text-gray-600 italic">File tidak tersedia.</p>';
        }
        $su       = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $filename = htmlspecialchars(basename(parse_url($url, PHP_URL_PATH) ?: $url), ENT_QUOTES, 'UTF-8');
        return '
        <div class="flex items-center justify-center py-16">
          <a href="' . $su . '" target="_blank" rel="noopener" download
             class="group flex flex-col items-center gap-5 p-10 rounded-2xl border-2 border-dashed border-gray-200 dark:border-gray-700 hover:border-[var(--brand-color)] transition-colors max-w-sm w-full text-center">
            <div class="w-16 h-16 rounded-2xl bg-gray-100 dark:bg-gray-800 group-hover:bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center transition-colors">
              <svg class="w-8 h-8 text-gray-400 group-hover:text-[var(--brand-color)] transition-colors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                <line x1="12" y1="12" x2="12" y2="18"/><polyline points="9 15 12 18 15 15"/>
              </svg>
            </div>
            <div>
              <p class="font-semibold text-gray-900 dark:text-white text-sm mb-1">' . $filename . '</p>
              <p class="text-xs text-gray-500 dark:text-gray-400">Klik untuk mengunduh file</p>
            </div>
            <span class="px-5 py-2 rounded-xl text-sm font-semibold text-white bg-brand group-hover:opacity-90 transition">Download File</span>
          </a>
        </div>';
    }

    if ($type === 'link') {
        if (empty($url)) {
            return '<p class="text-sm text-gray-400 dark:text-gray-600 italic">Link tidak tersedia.</p>';
        }
        $su = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return '
        <div class="flex items-center justify-center py-16">
          <a href="' . $su . '" target="_blank" rel="noopener"
             class="group flex flex-col items-center gap-5 p-10 rounded-2xl border-2 border-dashed border-gray-200 dark:border-gray-700 hover:border-[var(--brand-color)] transition-colors max-w-sm w-full text-center">
            <div class="w-16 h-16 rounded-2xl bg-gray-100 dark:bg-gray-800 group-hover:bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center transition-colors">
              <svg class="w-8 h-8 text-gray-400 group-hover:text-[var(--brand-color)] transition-colors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
              </svg>
            </div>
            <div>
              <p class="text-xs font-mono text-gray-400 dark:text-gray-500 break-all mb-1">' . $su . '</p>
              <p class="text-xs text-gray-500 dark:text-gray-400">Klik untuk membuka tautan eksternal</p>
            </div>
            <span class="px-5 py-2 rounded-xl text-sm font-semibold text-white bg-brand group-hover:opacity-90 transition">Buka Tautan</span>
          </a>
        </div>';
    }

    return '<p class="text-sm text-gray-400 italic">Tipe konten tidak dikenali.</p>';
}

$totalMaterials = array_sum(array_map(fn($c) => count($c['materials']), $classes));
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<?php if (isset($material['_pdf_url'])): ?>
<script type="module" src="<?= e(rtrim(APP_URL, '/')) ?>/assets/js/material-pdf.mjs?v=<?= e(APP_VERSION) ?>"></script>
<?php endif; ?>
<style>
  .material-pdf-frame {
    display: grid;
    min-width: 0;
    place-items: center;
  }
  .material-pdf-frame canvas {
    max-width: 100%;
    height: auto;
    vertical-align: top;
  }
  .material-pdf-toolbar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding-block: 4px;
  }
  .material-pdf-toolbar button, .material-pdf-jump input {
    border: 1px solid currentColor;
    border-radius: 8px;
  }
  .material-pdf-toolbar button {
    width: 44px;
    min-height: 44px;
    font-size: 24px;
    line-height: 1;
  }
  .material-pdf-toolbar button:disabled { opacity: .35; }
  .material-pdf-jump {
    display: flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
  }
  .material-pdf-jump input {
    width: 52px;
    min-height: 44px;
    padding-inline: 2px;
    color: inherit;
    background: transparent;
    font-size: 16px;
    text-align: center;
    appearance: textfield;
  }
  .material-pdf-jump input::-webkit-inner-spin-button { appearance: none; }
  .material-pdf-dialog[open] {
    box-sizing: border-box;
    position: fixed;
    inset: 0;
    width: 100%;
    height: 100%;
    height: 100dvh;
    max-width: none;
    max-height: none;
    margin: 0;
    padding: max(4px, env(safe-area-inset-top)) max(4px, env(safe-area-inset-right))
             max(4px, env(safe-area-inset-bottom)) max(4px, env(safe-area-inset-left));
    overflow: hidden;
    color: #111827;
    background: #f3f4f6;
  }
  .dark .material-pdf-dialog[open] { color: #f9fafb; background: #111827; }
  .material-pdf-dialog .material-pdf-reader {
    display: grid;
    height: 100%;
    grid-template-rows: minmax(0, 1fr) auto;
  }
  .material-pdf-dialog .material-pdf-frame { min-height: 0; overflow: hidden; }

  @media (max-width: 639px) {
    .material-pdf-card { margin-inline: -8px; }
    .material-pdf-card .material-content { padding: 4px 0 0; }
    .material-pdf-card .material-pdf-extra { padding-inline: 12px; }
  }

  /* Course nav sidebar transition */
  @media (max-width: 1023px) {
    #courseNav { transition: transform 0.3s ease; }
  }

  /* Tipe konten "embed": iframe langsung dibuat responsif penuh-lebar & menjaga
     rasionya. height:auto menimpa atribut height="…" bawaan embed (mis. YouTube
     560x315) agar tidak terkunci. aspect-ratio 16/9 = fallback; rasio asli diset
     via JS penormal dari atribut width/height iframe (Canva bisa non-16:9). */
  .material-embed > iframe {
    display: block;
    width: 100%;
    max-width: 100%;
    height: auto;
    border: 0;
    aspect-ratio: 16 / 9;
  }

  /* ── Player YouTube terkunci ──────────────────────────────────────────────
     Antarmuka YouTube dimatikan (controls=0) dan SELURUH permukaan player
     ditutup .yt-shield, sehingga tak satu pun klik (kiri maupun kanan) sampai
     ke dalam iframe. Kontrol di bawah adalah milik kita sendiri. */
  .yt-lock { width: 100%; border-radius: 12px; overflow: hidden; background: #000; }
  .yt-stage { position: relative; width: 100%; padding-top: 56.25%; background: #000; }
  .yt-stage .yt-frame {
    position: absolute; inset: 0;
    width: 100% !important; height: 100% !important;
    border: 0; pointer-events: none;   /* iframe tak lagi menerima interaksi */
  }
  .yt-shield { position: absolute; inset: 0; z-index: 2; cursor: pointer; background: transparent; }
  .yt-big {
    position: absolute; inset: 0; z-index: 3; margin: auto;
    width: 66px; height: 66px; border: 0; border-radius: 999px;
    background: rgba(0,0,0,.6); color: #fff; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: opacity .2s, transform .2s;
  }
  .yt-big svg { width: 26px; height: 26px; margin-left: 3px; }
  .yt-big:hover { transform: scale(1.06); }
  .yt-lock.is-playing .yt-big { opacity: 0; pointer-events: none; }

  .yt-bar { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #0f0f0f; }
  .yt-bar button {
    flex: none; width: 30px; height: 30px; border: 0; border-radius: 6px;
    background: transparent; color: #fff; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
  }
  .yt-bar button:hover { background: rgba(255,255,255,.12); }
  .yt-bar button svg { width: 16px; height: 16px; }
  .yt-seek { flex: 1; height: 6px; border-radius: 999px; background: rgba(255,255,255,.25); cursor: pointer; position: relative; }
  .yt-seek-fill { height: 100%; width: 0; border-radius: 999px; background: var(--brand-color); }
  .yt-time { flex: none; font-size: 11px; color: #cfcfcf; font-variant-numeric: tabular-nums; }
  /* Layar penuh: panggung mengisi layar, bar kontrol tetap terlihat di bawah. */
  .yt-lock:fullscreen { display: flex; flex-direction: column; justify-content: center; background: #000; }
  .yt-lock:fullscreen .yt-stage { padding-top: 0; flex: 1; }
</style>
<body class="bg-gray-50 dark:bg-gray-950 antialiased overflow-hidden">

<!-- Custom compact topbar -->
<header class="fixed top-0 left-0 right-0 z-30 h-14 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 flex items-center px-4 lg:px-6 gap-3">

  <!-- Back to course -->
  <a href="<?= e(APP_URL) ?><?= $ownerBase ?>"
     class="flex items-center gap-1.5 h-9 px-3 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors shrink-0">
    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <polyline points="15 18 9 12 15 6"/>
    </svg>
    <span class="hidden sm:inline text-xs">Kembali</span>
  </a>

  <div class="w-px h-5 bg-gray-200 dark:bg-gray-700 shrink-0"></div>

  <!-- Product name (truncated) -->
  <div class="flex-1 min-w-0">
    <p class="text-sm font-semibold text-gray-900 dark:text-white truncate"><?= e($product['name']) ?></p>
  </div>

  <!-- Mobile: toggle course nav -->
  <button onclick="toggleCourseNav()" id="courseNavBtn"
          class="lg:hidden flex items-center justify-center w-9 h-9 rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors shrink-0"
          title="Navigasi Kursus">
    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/>
      <line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
    </svg>
  </button>

  <!-- Dark mode toggle -->
  <?php include __DIR__ . '/../../partials/dark-toggle.php'; ?>

  <!-- User dropdown -->
  <?php
  $userName = $_SESSION['name']  ?? 'User';
  $userRole = $_SESSION['role']  ?? 'user';
  ?>
  <div class="relative shrink-0" data-dropdown>
    <button data-dropdown-trigger
            class="flex items-center gap-1.5 h-9 pl-1.5 pr-2.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
      <div class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-bold"
           style="background:var(--brand-color)">
        <?= e(strtoupper(substr($userName, 0, 1))) ?>
      </div>
      <svg class="w-3.5 h-3.5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <polyline points="6 9 12 15 18 9"/>
      </svg>
    </button>
    <div data-dropdown-menu
         class="hidden absolute right-0 top-full mt-1 w-44 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg py-1 z-50">
      <div class="px-3 py-2 border-b border-gray-100 dark:border-gray-700">
        <p class="text-xs font-semibold text-gray-900 dark:text-white truncate"><?= e($userName) ?></p>
      </div>
      <a href="<?= e(APP_URL) ?>/member"
         class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
        <svg class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        Area Member
      </a>
      <a href="<?= e(APP_URL) ?>/profile"
         class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
        <svg class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
        </svg>
        Profil Saya
      </a>
      <?php if ($userRole === 'admin'): ?>
      <a href="<?= e(APP_URL) ?>/admin"
         class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
        <svg class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
          <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
        </svg>
        Admin Panel
      </a>
      <?php endif; ?>
      <div class="border-t border-gray-100 dark:border-gray-700 mt-1 pt-1">
        <button type="button"
                onclick="confirmAndRun({title:'Keluar?',message:'Kamu akan keluar dari sesi ini.',confirmText:'Ya, Keluar',variant:'warning'},()=>{document.getElementById('logout-form').submit()})"
                class="flex items-center gap-2 px-3 py-2 text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors w-full text-left">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
          Keluar
        </button>
      </div>
    </div>
  </div>

</header>

<!-- Main layout (below topbar) -->
<div class="pt-14 flex h-screen">

  <!-- ─── Course Navigation Sidebar (left) ───────────────────── -->
  <aside id="courseNav"
         class="w-72 shrink-0 flex flex-col bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800
                fixed lg:relative inset-y-0 left-0 z-20 pt-14 lg:pt-0 -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out overflow-hidden">

    <!-- Sidebar header -->
    <div class="px-4 py-3.5 border-b border-gray-100 dark:border-gray-800 shrink-0">
      <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Konten Kursus</p>
      <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">
        <?= count($classes) ?> modul &middot; <?= $totalMaterials ?> materi
      </p>
    </div>

    <!-- Module + material tree -->
    <div class="flex-1 overflow-y-auto">
      <?php if (empty($classes)): ?>
      <div class="py-10 text-center">
        <p class="text-xs text-gray-400 dark:text-gray-600">Belum ada konten</p>
      </div>
      <?php else: ?>
      <?php foreach ($classes as $i => $cls): ?>
      <?php
      $clsHasCurrent = false;
      foreach ($cls['materials'] as $m) {
          if ((int) $m['id'] === $materialId) { $clsHasCurrent = true; break; }
      }
      $clsDrip = $dripStates[(int) $cls['id']] ?? null;
      $clsLocked = !empty($clsDrip['locked']);
      ?>
      <div class="border-b border-gray-100 dark:border-gray-800">
        <!-- Module toggle button -->
        <button type="button"
                onclick="toggleNavModule(this)"
                class="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
          <div class="w-6 h-6 rounded-md text-xs font-bold flex items-center justify-center shrink-0
                      <?= $clsHasCurrent
                            ? 'bg-[var(--brand-color)] text-white'
                            : 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400' ?>">
            <?= $i + 1 ?>
          </div>
          <p class="flex-1 text-xs font-medium leading-snug text-left
                     <?= $clsHasCurrent ? 'text-[var(--brand-color)]' : 'text-gray-700 dark:text-gray-300' ?>">
            <?= e($cls['title']) ?>
          </p>
          <svg class="nav-chevron w-3.5 h-3.5 text-gray-400 shrink-0 transition-transform duration-200
                      <?= $clsHasCurrent ? 'rotate-180' : '' ?>"
               viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </button>

        <!-- Materials list (collapsed unless current module) -->
        <div class="nav-mat-list <?= $clsHasCurrent ? '' : 'hidden' ?>">
          <?php if ($clsLocked): ?>
          <p class="flex items-center gap-1.5 px-10 py-2.5 text-xs text-amber-600 dark:text-amber-400">
            <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Terbuka <?= (int) $clsDrip['days_left'] ?> hari lagi
          </p>
          <?php elseif (empty($cls['materials'])): ?>
          <p class="px-10 py-2.5 text-xs text-gray-400 dark:text-gray-600 italic">Belum ada materi</p>
          <?php else: ?>
          <?php foreach ($cls['materials'] as $mat): ?>
          <?php $isCurrent = (int) $mat['id'] === $materialId; ?>
          <a href="<?= e(APP_URL) ?><?= $ownerBase ?>/material/<?= (int) $mat['id'] ?>"
             class="flex items-center gap-2.5 pl-10 pr-4 py-2.5 transition-colors
                    <?= $isCurrent
                          ? 'bg-[color-mix(in_srgb,var(--brand-color)_8%,transparent)] border-r-2 border-[var(--brand-color)]'
                          : 'hover:bg-gray-50 dark:hover:bg-gray-800/30' ?>">
            <span class="shrink-0 <?= $isCurrent ? 'text-[var(--brand-color)]' : 'text-gray-400 dark:text-gray-600' ?>">
              <?= matTypeIcon($mat['content_type']) ?>
            </span>
            <span class="text-xs leading-snug
                         <?= $isCurrent ? 'font-semibold text-[var(--brand-color)]' : 'text-gray-600 dark:text-gray-400' ?>">
              <?= e($mat['title']) ?>
            </span>
          </a>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </aside>

  <!-- Overlay (mobile only) -->
  <div id="courseNavOverlay"
       class="fixed inset-0 z-10 bg-black/50 hidden lg:hidden"
       onclick="toggleCourseNav()"></div>

  <!-- ─── Material Content (right panel) ────────────────────── -->
  <main class="flex-1 overflow-y-auto min-w-0 bg-gray-50 dark:bg-gray-950">
    <div class="max-w-4xl mx-auto px-4 lg:px-8 py-6 pb-12">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <!-- Breadcrumb -->
      <nav class="flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-600 mb-5 flex-wrap">
        <a href="<?= e(APP_URL) ?><?= $previewMode ? e($exitUrl) : '/member' ?>" class="hover:text-[var(--brand-color)] transition"><?= $previewMode ? 'Editor Konten' : 'Area Member' ?></a>
        <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
        <a href="<?= e(APP_URL) ?><?= $ownerBase ?>" class="hover:text-[var(--brand-color)] transition max-w-[100px] truncate">
          <?= e($product['name']) ?>
        </a>
        <?php if ($currentClass): ?>
        <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
        <span class="text-gray-500 dark:text-gray-400 max-w-[100px] truncate"><?= e($currentClass['title']) ?></span>
        <?php endif; ?>
      </nav>

      <!-- Material type badge + title -->
      <div class="mb-6">
        <?php
        $typeBadgeMap = [
            'video' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
            'file'  => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
            'link'  => 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
            'text'  => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
            'embed' => 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400',
            'html'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
        ];
        $badgeCls = $typeBadgeMap[$material['content_type']] ?? $typeBadgeMap['text'];
        ?>
        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium <?= $badgeCls ?> mb-3">
          <?= matTypeIcon($material['content_type']) ?>
          <?= e(materialTypeLabel($material['content_type'])) ?>
        </span>
        <h1 class="text-xl lg:text-2xl font-bold text-gray-900 dark:text-white leading-tight">
          <?= e($material['title']) ?>
        </h1>
      </div>

      <!-- Content card -->
      <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-6<?= isset($material['_pdf_url']) ? ' material-pdf-card' : '' ?>">
        <div class="material-content p-6">
          <?= renderContent($material) ?>
          <?php
          // Deskripsi kaya di bawah konten (teks + link, mis. prompt/tautan tool).
          // Admin/staff terpercaya → HTML dirender apa adanya; link dibuka di tab baru.
          $matDesc = trim((string) ($material['description'] ?? ''));
          if ($matDesc !== ''):
              $matDescOut = preg_replace('/<a (?![^>]*\btarget=)/i', '<a target="_blank" rel="noopener" ', $matDesc);
          ?>
          <div class="material-pdf-extra material-html mt-5 pt-5 border-t border-gray-100 dark:border-gray-800 text-gray-700 dark:text-gray-300 text-sm leading-relaxed [&_img]:max-w-full [&_img]:h-auto [&_img]:rounded-lg [&_a]:text-[var(--brand-color)] [&_a]:underline [&_a]:break-words [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_h2]:text-lg [&_h2]:font-bold [&_h2]:mt-4 [&_h2]:mb-2 [&_h3]:font-semibold [&_h3]:mt-3 [&_h3]:mb-1.5 [&_p]:mb-2 [&_blockquote]:border-l-4 [&_blockquote]:border-gray-200 [&_blockquote]:pl-3 [&_blockquote]:italic">
            <?= $matDescOut ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($materialAttachments)): ?>
      <!-- Lampiran (file Download Manager yang ditautkan ke materi ini) -->
      <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800">
          <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            Lampiran (<?= count($materialAttachments) ?>)
          </p>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($materialAttachments as $att): ?>
          <div class="flex items-center gap-3 px-5 py-3">
            <div class="w-9 h-9 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0 text-gray-500 dark:text-gray-400">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
              </svg>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate"><?= e($att['title']) ?></p>
              <p class="text-xs text-gray-400 dark:text-gray-600 truncate"><?= e($att['filename_orig']) ?> &middot; <?= formatFileSize((int) $att['file_size']) ?></p>
            </div>
            <button type="button" data-material-download="<?= (int) $att['id'] ?>"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-brand hover:opacity-90 transition disabled:opacity-60 disabled:cursor-wait shrink-0">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
              </svg>
              Download
            </button>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Tandai Selesai (disembunyikan di mode preview — tak menulis progres) -->
      <?php if (!$previewMode && ($material['content_type'] ?? '') !== 'link'): ?>
      <div class="mb-6" id="completeBox">
        <?php if ($isCompleted): ?>
        <div class="flex items-center gap-2 text-green-600 dark:text-green-400 text-sm font-medium">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Materi selesai
        </div>
        <?php else: ?>
        <form method="POST" action="<?= e(APP_URL) ?>/actions/user/complete-material">
          <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
          <input type="hidden" name="material_id" value="<?= (int) $material['id'] ?>">
          <!-- Konteks owner dari URL — penting utk modul BERBAGI (class_products):
               produk konteks bisa beda dari produk PEMILIK modul. -->
          <input type="hidden" name="product_id" value="<?= (int) $productId ?>">
          <input type="hidden" name="lead_magnet_id" value="<?= (int) $leadMagnetId ?>">
          <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            <?= $nextMatId ? 'Tandai Selesai &amp; Lanjut' : 'Tandai Selesai' ?>
          </button>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Prev / Next navigation -->
      <div class="flex items-center <?= $prevMatId ? 'justify-between' : 'justify-end' ?> gap-4">
        <?php if ($prevMatId): ?>
        <a href="<?= e(APP_URL) ?><?= $ownerBase ?>/material/<?= $prevMatId ?>"
           class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium
                  bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800
                  text-gray-700 dark:text-gray-300
                  hover:border-[var(--brand-color)] hover:text-[var(--brand-color)] transition-colors shadow-sm">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="15 18 9 12 15 6"/>
          </svg>
          Sebelumnya
        </a>
        <?php endif; ?>

        <?php if ($nextMatId): ?>
        <a href="<?= e(APP_URL) ?><?= $ownerBase ?>/material/<?= $nextMatId ?>"
           class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white hover:opacity-90 transition shadow-sm"
           style="background-color: var(--brand-color);">
          Berikutnya
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </a>
        <?php else: ?>
        <a href="<?= e(APP_URL) ?><?= $ownerBase ?>"
           class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white hover:opacity-90 transition shadow-sm"
           style="background-color: var(--brand-color);">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          Selesai
        </a>
        <?php endif; ?>
      </div>

    </div>
  </main>
</div>

<?php if ($previewMode): ?>
<!-- Toolbar Mode Preview (admin/staff) -->
<div class="fixed left-1/2 -translate-x-1/2 bottom-5 z-[9999] flex items-center gap-3 pl-4 pr-2 py-2 rounded-full bg-gray-900 text-white shadow-2xl border border-white/10">
  <span class="inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap">
    <svg class="w-4 h-4 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
    Mode Preview — tampilan pembeli
  </span>
  <a href="<?= e(APP_URL) ?><?= e($exitUrl) ?>" class="inline-flex items-center gap-1 text-xs font-semibold px-3 py-1.5 rounded-full bg-white/15 hover:bg-white/25 transition whitespace-nowrap">
    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    Keluar
  </a>
</div>
<?php endif; ?>

<script>
function toggleCourseNav() {
  const nav     = document.getElementById('courseNav');
  const overlay = document.getElementById('courseNavOverlay');
  const isOpen  = !nav.classList.contains('-translate-x-full');
  nav.classList.toggle('-translate-x-full', isOpen);
  overlay.classList.toggle('hidden', isOpen);
}

function toggleNavModule(btn) {
  const list    = btn.nextElementSibling;
  const chevron = btn.querySelector('.nav-chevron');
  const hidden  = list.classList.contains('hidden');
  list.classList.toggle('hidden', !hidden);
  chevron.classList.toggle('rotate-180', hidden);
}

// Normalisasi iframe embed: pertahankan rasio ASLI dari atribut width/height
// (YouTube 560x315 -> 16:9, Canva sesuai desainnya). Bila iframe tak menyertakan
// dimensi, biarkan fallback 16:9 dari CSS. Hanya iframe anak langsung .material-embed
// (Canva versi div>iframe nested tak tersentuh, tetap memakai sizing sendiri).
document.querySelectorAll('.material-embed > iframe').forEach(function (f) {
  var w = parseFloat(f.getAttribute('width'));
  var h = parseFloat(f.getAttribute('height'));
  if (w > 0 && h > 0) f.style.aspectRatio = w + ' / ' + h;
  f.style.width  = '100%';
  f.style.height = 'auto';
});

// ── Player YouTube terkunci ────────────────────────────────────────────────
// Antarmuka YouTube dimatikan (controls=0) & iframe tak menerima pointer, jadi
// kontrol di bawah player inilah yang menjalankan video lewat IFrame API.
// Klik-kanan di atas video mengenai perisai kita — menu "Copy video URL" milik
// YouTube tak pernah muncul (handler contextmenu saja tak bisa menembus iframe
// lintas domain, itu sebab cara lama gagal).
// Kendali player memakai postMessage LANGSUNG ke iframe (protokol enablejsapi),
// TANPA memuat script iframe_api dari luar: script itu terbukti bisa gagal termuat
// (pemblokir iklan/pelacak) dan kalau kita menggantungkan diri padanya, member
// tak bisa memutar video sama sekali — lebih buruk daripada masalah awal.
// ── Konteks materi utk auto-selesai & posisi tonton ──────────────────────────
var MATV = {
  id: <?= (int) $material['id'] ?>,
  completed: <?= !empty($isCompleted) ? 'true' : 'false' ?>,
  preview: <?= $previewMode ? 'true' : 'false' ?>,
  productId: <?= (int) ($ownerType === 'lead_magnet' ? 0 : $productId) ?>,
  leadMagnetId: <?= (int) ($ownerType === 'lead_magnet' ? $leadMagnetId : 0) ?>,
  csrf: '<?= e(generateCSRF()) ?>'
};
var autoCompleteBusy = false;
// Auto-selesai: dipanggil player saat video ≥90% ditonton / berakhir.
// Menutup gesekan lama "nonton tapi lupa klik Tandai Selesai → sertifikat
// tak terbit". Gagal kirim = tidak apa-apa, tombol manual tetap ada.
function autoCompleteMaterial() {
  if (autoCompleteBusy || MATV.completed || MATV.preview) return;
  autoCompleteBusy = true;
  var fd = new FormData();
  fd.append('csrf_token', MATV.csrf);
  fd.append('material_id', MATV.id);
  fd.append('product_id', MATV.productId);
  fd.append('lead_magnet_id', MATV.leadMagnetId);
  fetch('<?= e(APP_URL) ?>/actions/user/complete-material', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.success) { autoCompleteBusy = false; return; }
      MATV.completed = true;
      var box = document.getElementById('completeBox');
      if (box) {
        box.innerHTML = '<div class="flex items-center gap-2 text-green-600 dark:text-green-400 text-sm font-medium">'
          + '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
          + 'Materi selesai</div>';
      }
      if (window.showToast) showToast('Materi otomatis ditandai selesai.', 'success');
    })
    .catch(function () { autoCompleteBusy = false; });
}

(function () {
  var locks = document.querySelectorAll('.yt-lock');
  if (!locks.length) return;

  function fmt(t) {
    t = Math.max(0, Math.floor(t || 0));
    var m = Math.floor(t / 60), s = t % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  locks.forEach(function (box) {
    var frame   = box.querySelector('.yt-frame');
    var shield  = box.querySelector('.yt-shield');
    var big     = box.querySelector('.yt-big');
    var playBtn = box.querySelector('.yt-play');
    var icPlay  = box.querySelector('.yt-ic-play');
    var icPause = box.querySelector('.yt-ic-pause');
    var seek    = box.querySelector('.yt-seek');
    var fill    = box.querySelector('.yt-seek-fill');
    var timeEl  = box.querySelector('.yt-time');
    var fsBtn   = box.querySelector('.yt-fs');

    var dur = 0, cur = 0, playing = false, alive = false, unlocked = false;

    // Posisi tonton tersimpan per materi (localStorage — tanpa DB).
    // pendingResume diterapkan saat player MELAPOR playing (bukan saat klik):
    // seekTo yang dikirim bersamaan playVideo diabaikan player yang masih cued.
    var posKey = 'av:vidpos:' + MATV.id;
    var pendingResume = 0, lastSavedPos = -1;
    try {
      var sp = parseFloat(localStorage.getItem(posKey) || '0') || 0;
      if (sp > 20) pendingResume = sp;
    } catch (e) {}

    // Pasang src di sini (bukan dari PHP) supaya `origin` diambil dari
    // location.origin — nilainya dijamin sama persis dengan domain yang sedang
    // memuat halaman. origin yang meleset (http vs https, www vs non-www)
    // membuat YouTube menolak dgn "Error 153", sedangkan origin yang benar aman.
    (function () {
      var base = frame.getAttribute('data-src');
      if (!base) return;
      frame.src = base + '&origin=' + encodeURIComponent(window.location.origin);
    })();

    function send(func, args) {
      if (!frame.contentWindow) return;
      frame.contentWindow.postMessage(
        JSON.stringify({ event: 'command', func: func, args: args || [] }), '*'
      );
    }
    function paint() {
      fill.style.width = dur ? ((cur / dur) * 100) + '%' : '0';
      timeEl.textContent = fmt(cur) + ' / ' + fmt(dur);
      box.classList.toggle('is-playing', playing);
      icPlay.classList.toggle('hidden', playing);
      icPause.classList.toggle('hidden', !playing);
    }
    // Status diputuskan SENDIRI saat tombol ditekan, tidak menunggu balasan player.
    // Kalau digantungkan pada kabar dari player (infoDelivery) dan kabar itu tak
    // pernah sampai — mis. dihadang pemblokir di browser member — status akan
    // selamanya "tidak main", sehingga setiap klik mengirim playVideo lagi:
    // ikon berubah jadi jeda tetapi suara terus berjalan. Kabar dari player tetap
    // dipakai, hanya untuk MENYELARASKAN bila berbeda.
    function toggle() {
      playing = !playing;
      send(playing ? 'playVideo' : 'pauseVideo');
      paint();
    }

    // Fallback keandalan: bila player tak pernah menjawab (postMessage diblokir /
    // iframe gagal), kembalikan kontrol asli YouTube supaya member TETAP bisa
    // menonton. Proteksi berkurang, tetapi materi tidak jadi buntu.
    function unlock() {
      if (unlocked || alive) return;
      unlocked = true;
      send('pauseVideo');            // jangan tinggalkan audio saat iframe diganti
      playing = false;
      // Kembalikan ke bentuk PALING sederhana & paling pasti jalan: kontrol asli
      // YouTube, tanpa enablejsapi maupun origin (dua hal yang bisa memicu
      // "Error 153" bila lingkungan menolak verifikasi).
      var id = (frame.getAttribute('data-src') || '').match(/embed\/([A-Za-z0-9_-]{11})/);
      frame.src = 'https://www.youtube.com/embed/' + (id ? id[1] : '') + '?rel=0';
      frame.style.pointerEvents = 'auto';
      frame.setAttribute('allowfullscreen', 'allowfullscreen');
      shield.style.display = 'none';
      big.style.display = 'none';
      box.querySelector('.yt-bar').style.display = 'none';
    }

    window.addEventListener('message', function (e) {
      if (!e.origin || e.origin.indexOf('youtube.com') === -1) return;
      if (e.source !== frame.contentWindow) return;
      var d;
      try { d = JSON.parse(e.data); } catch (err) { return; }
      if (!d) return;
      alive = true;
      var info = d.info || {};
      if (typeof info.duration === 'number')    dur = info.duration;
      if (typeof info.currentTime === 'number') cur = info.currentTime;
      if (typeof info.playerState === 'number') playing = (info.playerState === 1);
      paint();

      // Auto-selesai: ≥90% ditonton ATAU player lapor video berakhir (state 0).
      if (dur > 0 && (cur / dur >= 0.9 || info.playerState === 0)) autoCompleteMaterial();

      // Lanjutkan dari posisi terakhir — dikirim SETELAH player lapor playing
      // (sekali). Dilewati bila posisi sudah dekat akhir video.
      if (pendingResume > 0 && info.playerState === 1) {
        var target = pendingResume;
        pendingResume = 0;
        if (!dur || target < dur - 30) {
          cur = target;
          send('seekTo', [target, true]);
          paint();
        }
      }

      // Simpan posisi tonton (throttle ~3 dtk); JANGAN menyimpan selama resume
      // masih menunggu (mencegah posisi lama tertimpa detik-detik awal); hapus
      // saat video berakhir agar pemutaran berikutnya mulai dari awal.
      if (info.playerState === 0) {
        try { localStorage.removeItem(posKey); } catch (err2) {}
        lastSavedPos = -1;
      } else if (pendingResume === 0 && playing && dur > 0 && cur > 5 && Math.abs(cur - lastSavedPos) >= 3) {
        lastSavedPos = cur;
        try { localStorage.setItem(posKey, String(Math.floor(cur))); } catch (err2) {}
      }
    });

    // Minta player mulai mengirim kabar (waktu/durasi/status) secara berkala.
    function ping() {
      if (!frame.contentWindow) return;
      frame.contentWindow.postMessage(JSON.stringify({ event: 'listening' }), '*');
    }
    frame.addEventListener('load', function () { ping(); setTimeout(ping, 400); });
    var pings = setInterval(ping, 1000);
    setTimeout(function () { clearInterval(pings); unlock(); }, 5000);

    shield.addEventListener('click', toggle);
    big.addEventListener('click', toggle);
    playBtn.addEventListener('click', toggle);
    seek.addEventListener('click', function (e) {
      if (!dur) return;
      pendingResume = 0;   // seek manual membatalkan lanjut-dari-posisi-terakhir
      var r = seek.getBoundingClientRect();
      var ratio = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width));
      cur = dur * ratio;
      send('seekTo', [cur, true]);
      paint();
    });
    fsBtn.addEventListener('click', function () {
      if (document.fullscreenElement) document.exitFullscreen();
      else if (box.requestFullscreen) box.requestFullscreen();
    });

    // Hentikan video saat member berpindah materi/menutup halaman, dan saat tab
    // disembunyikan — mencegah suara terus terdengar "di belakang layar".
    function stop() { if (playing) { playing = false; send('pauseVideo'); paint(); } }
    window.addEventListener('pagehide', stop);
    window.addEventListener('beforeunload', stop);
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stop();
    });
  });
})();

// ── Download file materi (tautan Download Manager) ───────────────────────────
// Pola sama dgn halaman /downloads: minta token sekali-pakai lalu redirect.
document.querySelectorAll('[data-material-download]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    if (btn.disabled) return;
    btn.disabled = true;
    var fd = new FormData();
    fd.append('csrf_token', '<?= e(generateCSRF()) ?>');
    fd.append('download_id', btn.getAttribute('data-material-download'));
    fetch('<?= e(APP_URL) ?>/actions/user/request-download', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) {
          window.location.href = '<?= e(APP_URL) ?>/download/' + d.token;
        } else if (window.showToast) {
          showToast(d.message || 'Gagal membuat link download', 'error');
        }
        btn.disabled = false;
      })
      .catch(function () {
        btn.disabled = false;
        if (window.showToast) showToast('Gagal terhubung ke server. Coba lagi.', 'error');
      });
  });
});
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

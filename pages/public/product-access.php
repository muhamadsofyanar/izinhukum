<?php
// ════════════════════════════════════════════════════════════════
// Akses Produk Berpassword — /akses/{slug}
// Halaman delivery off-platform: menampilkan konten produk (modul & materi)
// TANPA login, dibuka dengan password milik reseller (reseller_salespage.access_password).
// Reseller membagikan link + password ke pembeli personal-use.
// Gerbang via $_SESSION['product_access'][productId]. File diunduh lewat
// /akses-file/{materialId} yang juga dijaga session ini.
// ════════════════════════════════════════════════════════════════

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['slug'] ?? ''));

$product = null;
if ($slug !== '') {
    try {
        $stmt = getDB()->prepare(
            "SELECT id, name, slug, thumbnail, short_description, access_type, embed_html, embed_url
               FROM products WHERE slug = ? AND status = 'active' AND access_page_enabled = 1 LIMIT 1"
        );
        $stmt->execute([$slug]);
        $product = $stmt->fetch();
    } catch (Exception $e) {
        error_log('product-access load error: ' . $e->getMessage());
    }
}

if (!$product) {
    http_response_code(404);
    $page404 = __DIR__ . '/404.php';
    file_exists($page404) ? require $page404 : print('404 — Halaman tidak ditemukan.');
    return;
}

$productId = (int) $product['id'];
$authed    = !empty($_SESSION['product_access'][$productId]);
$error     = '';

// Mode akses konten produk: 'embed' (satu konten HTML/URL, tanpa kelas) vs 'course'
// (Modul & Materi). Mode embed dirender sebagai iframe sandbox full-height, sama
// seperti halaman akses member (pages/user/embed-access.php).
$isEmbed   = ($product['access_type'] ?? 'course') === 'embed';
$embedHtml = trim((string) ($product['embed_html'] ?? ''));
$embedUrl  = trim((string) ($product['embed_url'] ?? ''));

// ─── Verifikasi password (POST) ────────────────────────────────────
if (!$authed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (function_exists('checkIpRateLimit') && !checkIpRateLimit('access', $ip, 10, 10)) {
        $error = 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.';
    } else {
        $try = (string) ($_POST['access_password'] ?? '');
        // Password dikelola PER-RESELLER. Terima bila cocok dengan password reseller
        // MANA PUN untuk produk ini (tiap reseller punya password sendiri untuk
        // pembelinya). Link akses tetap bersih tanpa ref — pembeli cukup memasukkan
        // password dari penjualnya.
        $granted = false;
        if ($try !== '') {
            try {
                $q = getDB()->prepare("SELECT 1 FROM reseller_salespage WHERE product_id = ? AND access_password = ? LIMIT 1");
                $q->execute([$productId, $try]);
                $granted = (bool) $q->fetchColumn();
            } catch (Exception $e) { /* tabel belum ada → abaikan */ }
        }
        if ($granted) {
            $_SESSION['product_access'][$productId] = time();
            redirect('/akses/' . $slug); // PRG: hindari resubmit
        } else {
            $error = 'Password salah. Periksa kembali password dari penjual.';
        }
    }
}

$appName    = getSetting('app_name', 'STIFIn Mulia');
$brandColor = getSetting('brand_color', '#6366f1');
$appFavicon = trim((string) getSetting('app_favicon', ''));
$faviconSvg = "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%23" . ltrim($brandColor, '#') . "'/><path d='M16 7 L25 25 L7 25 Z' fill='white'/></svg>";

// ─── Muat konten bila sudah terautentikasi ─────────────────────────
$classes = [];
$materialsByClass = [];
if ($authed && !$isEmbed) {
    try {
        $pdo = getDB();
        $cStmt = $pdo->prepare(
            "SELECT c.id, c.title, c.description
               FROM classes c
              WHERE (c.product_id = ? OR c.id IN (SELECT class_id FROM class_products WHERE product_id = ?))
                AND c.status = 'active'
              ORDER BY c.sort_order ASC, c.id ASC"
        );
        $cStmt->execute([$productId, $productId]);
        $classes = $cStmt->fetchAll();

        $classIds = array_map(fn($c) => (int) $c['id'], $classes);
        if ($classIds) {
            $ph = implode(',', array_fill(0, count($classIds), '?'));
            $mStmt = $pdo->prepare("SELECT * FROM materials WHERE class_id IN ($ph) ORDER BY sort_order ASC, id ASC");
            $mStmt->execute($classIds);
            foreach ($mStmt->fetchAll() as $m) {
                $materialsByClass[(int) $m['class_id']][] = $m;
            }
        }
    } catch (Exception $e) {
        error_log('product-access content error: ' . $e->getMessage());
    }
}

/**
 * Render satu materi (versi ringkas untuk halaman akses off-platform — tanpa
 * drip/proteksi member). File diunduh via /akses-file/{id} (dijaga session).
 */
function renderAccessMaterial(array $m): string
{
    $type    = (string) ($m['content_type'] ?? 'text');
    $url     = trim((string) ($m['content_url'] ?? ''));
    $content = (string) ($m['content'] ?? '');
    $title   = e((string) ($m['title'] ?? 'Materi'));

    $head = '<p class="text-sm font-semibold text-gray-900 mb-2">' . $title . '</p>';

    switch ($type) {
        case 'html':
            return $head . '<div class="am-html text-sm text-gray-700">' . $content . '</div>';

        case 'text':
            if (preg_match('/<[a-z][^>]*>/i', $content)) {
                return $head . '<div class="am-html text-sm text-gray-700">' . $content . '</div>';
            }
            return $head . '<div class="text-sm text-gray-700 leading-relaxed whitespace-pre-line">' . nl2br(e($content)) . '</div>';

        case 'video':
            if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $mm)) {
                return $head . '<div class="am-embed"><iframe src="https://www.youtube.com/embed/' . e($mm[1]) . '" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>';
            }
            if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $mm)) {
                return $head . '<div class="am-embed"><iframe src="https://player.vimeo.com/video/' . e($mm[1]) . '" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe></div>';
            }
            if ($url !== '' && preg_match('~\.(mp4|webm|ogg|mov|m4v)(\?|$)~i', $url)) {
                return $head . '<video controls class="w-full rounded-lg"><source src="' . e($url) . '"></video>';
            }
            if ($url !== '') {
                return $head . '<a href="' . e($url) . '" target="_blank" rel="noopener" class="am-link">Buka Video</a>';
            }
            return $head . '<p class="text-sm text-gray-400">Video belum tersedia.</p>';

        case 'embed':
            return $head . '<div class="am-embed am-embed-raw">' . $content . '</div>';

        case 'link':
            if ($url === '') return $head . '<p class="text-sm text-gray-400">Link belum tersedia.</p>';
            return $head . '<a href="' . e($url) . '" target="_blank" rel="noopener" class="am-link">' . e($url) . '</a>';

        case 'file':
            if (!empty($m['download_id'])) {
                return $head . '<a href="' . e(rtrim(APP_URL, '/')) . '/akses-file/' . (int) $m['id'] . '" class="am-file">'
                    . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'
                    . 'Download File</a>';
            }
            if ($url !== '') {
                return $head . '<a href="' . e($url) . '" download class="am-file">'
                    . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'
                    . 'Download File</a>';
            }
            return $head . '<p class="text-sm text-gray-400">File belum tersedia.</p>';
    }
    return $head;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Akses <?= e($product['name']) ?> — <?= e($appName) ?></title>
  <link rel="icon" href="<?= $appFavicon !== '' ? e($appFavicon) : $faviconSvg ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/tailwind.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/tailwind.css') ?: '1' ?>">
  <style>
    body { font-family: Inter, sans-serif; }
    .am-embed { position: relative; width: 100%; padding-top: 56.25%; border-radius: .6rem; overflow: hidden; background:#000; }
    .am-embed iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
    .am-embed-raw { padding-top: 0; height: auto; background: transparent; }
    .am-embed-raw iframe { position: static; width: 100%; min-height: 360px; }
    .am-html img { max-width: 100%; height: auto; border-radius: .5rem; }
    .am-html a { color: <?= e($brandColor) ?>; text-decoration: underline; }
    .am-link { display: inline-block; font-size: .875rem; font-weight: 600; color: <?= e($brandColor) ?>; word-break: break-all; }
    .am-file { display: inline-flex; align-items: center; gap: .5rem; padding: .6rem 1rem; border-radius: .6rem; font-size: .875rem; font-weight: 600; color: #fff; background: <?= e($brandColor) ?>; }
    .am-file svg { width: 1rem; height: 1rem; }
  </style>
<link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/mulia-pages.css?v=1">
</head>
<body class="mulia mulia-access min-h-screen bg-gray-50 text-gray-900">
<?php if (!$authed): ?>
  <!-- Gerbang password -->
  <div class="min-h-screen flex items-center justify-center p-4">
    <div class="max-w-sm w-full">
      <div class="text-center mb-5">
        <div class="w-12 h-12 rounded-2xl ring-1 ring-black/5 flex items-center justify-center mx-auto mb-3" style="background:color-mix(in srgb, <?= e($brandColor) ?> 12%, white)">
          <svg class="w-6 h-6" style="color:<?= e($brandColor) ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
        </div>
        <h1 class="text-lg font-bold text-gray-900">Akses <?= e($product['name']) ?></h1>
        <p class="text-sm text-gray-500 mt-1">Masukkan password akses dari penjual untuk membuka konten produk.</p>
      </div>
      <form method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 space-y-3">
        <?php if ($error !== ''): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-2.5 text-sm text-red-700"><?= e($error) ?></div>
        <?php endif; ?>
        <input type="password" name="access_password" autofocus placeholder="Password akses" required
               class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 bg-gray-50 focus:ring-2 outline-none"
               style="--tw-ring-color: <?= e($brandColor) ?>">
        <button type="submit" class="w-full px-4 py-2.5 rounded-lg text-sm font-semibold text-white transition hover:opacity-90" style="background:<?= e($brandColor) ?>">Buka Akses</button>
      </form>
      <p class="text-center text-xs text-gray-400 mt-4">Dilindungi oleh <?= e($appName) ?></p>
    </div>
  </div>
<?php elseif ($isEmbed): ?>
  <!-- Mode "Embed langsung": iframe sandbox full-height, tanpa daftar kelas.
       Sama seperti halaman akses member (pages/user/embed-access.php). -->
  <div class="h-screen flex flex-col">
    <div class="flex items-center gap-3 px-4 h-12 shrink-0 border-b border-gray-200 bg-white">
      <?php if (!empty($product['thumbnail'])): ?>
      <img src="<?= e($product['thumbnail']) ?>" alt="" class="w-7 h-7 rounded-lg object-cover shrink-0">
      <?php endif; ?>
      <h1 class="text-sm font-semibold text-gray-900 truncate"><?= e($product['name']) ?></h1>
      <span class="ml-auto text-[11px] text-gray-400 shrink-0 hidden sm:inline">Dilindungi <?= e($appName) ?> — jangan bagikan link &amp; password</span>
    </div>
    <?php if ($embedHtml !== ''): ?>
    <!-- HTML dari penjual: sandbox TANPA allow-same-origin → tak bisa menyentuh sesi akses. -->
    <iframe sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation allow-modals allow-downloads"
            srcdoc="<?= e($embedHtml) ?>"
            class="flex-1 w-full block" style="border:0" title="<?= e($product['name']) ?>"></iframe>
    <?php elseif ($embedUrl !== ''): ?>
    <!-- URL tool eksternal: iframe origin situs itu sendiri. -->
    <iframe sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation allow-modals allow-downloads"
            src="<?= e($embedUrl) ?>"
            class="flex-1 w-full block" style="border:0" title="<?= e($product['name']) ?>"
            referrerpolicy="no-referrer"></iframe>
    <?php else: ?>
    <div class="flex-1 flex items-center justify-center p-10">
      <div class="text-center">
        <div class="w-12 h-12 rounded-xl bg-gray-100 flex items-center justify-center mx-auto mb-3">
          <svg class="w-6 h-6 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
        </div>
        <p class="text-sm font-medium text-gray-600">Konten belum tersedia</p>
        <p class="text-xs text-gray-400 mt-1">Penjual belum mengisi konten embed untuk produk ini.</p>
      </div>
    </div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <!-- Konten produk -->
  <div class="max-w-2xl mx-auto px-4 py-8">
    <div class="flex items-center gap-3 mb-6">
      <?php if (!empty($product['thumbnail'])): ?>
      <img src="<?= e($product['thumbnail']) ?>" alt="" class="w-12 h-12 rounded-xl object-cover shrink-0">
      <?php endif; ?>
      <div>
        <h1 class="text-xl font-extrabold leading-tight"><?= e($product['name']) ?></h1>
        <p class="text-xs text-gray-400">Akses konten produk</p>
      </div>
    </div>

    <?php if (empty($classes)): ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 text-center text-sm text-gray-500">
      Konten produk belum tersedia. Silakan hubungi penjual.
    </div>
    <?php else: ?>
    <div class="space-y-4">
      <?php foreach ($classes as $c): $mats = $materialsByClass[(int) $c['id']] ?? []; ?>
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-gray-100">
          <h2 class="text-sm font-bold text-gray-900"><?= e($c['title']) ?></h2>
          <?php if (trim((string) $c['description']) !== ''): ?>
          <p class="text-xs text-gray-400 mt-0.5"><?= e($c['description']) ?></p>
          <?php endif; ?>
        </div>
        <div class="divide-y divide-gray-100">
          <?php if (empty($mats)): ?>
          <p class="px-5 py-4 text-sm text-gray-400">Belum ada materi di modul ini.</p>
          <?php else: foreach ($mats as $m): ?>
          <div class="px-5 py-4"><?= renderAccessMaterial($m) ?></div>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p class="text-center text-xs text-gray-400 mt-8">Dilindungi oleh <?= e($appName) ?> — jangan bagikan link &amp; password ini sembarangan.</p>
  </div>
<?php endif; ?>
</body>
</html>

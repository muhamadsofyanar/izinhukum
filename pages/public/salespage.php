<?php
// ════════════════════════════════════════════════════════════════
// Salespage Siap Pakai — /on/{slug}?ref={kode}
// Landing produk yang dibagikan reseller. Tombol:
//   • Order Personal Use  → WhatsApp reseller (off-platform, reseller untung 100%)
//   • Order Resell Rights → checkout produk dgn ref reseller (komisi via afiliasi)
// Publik, tanpa login. Ref ditangkap otomatis oleh bootstrap.php.
// ════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../helpers/product-presentation.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['slug'] ?? ''));

$product = null;
if ($slug !== '') {
    try {
        $stmt = getDB()->prepare(
            "SELECT * FROM products WHERE slug = ? AND status = 'active' AND salespage_enabled = 1 LIMIT 1"
        );
        $stmt->execute([$slug]);
        $product = $stmt->fetch();
    } catch (Exception $e) {
        error_log('salespage load error: ' . $e->getMessage());
    }
}

if (!$product) {
    http_response_code(404);
    $page404 = __DIR__ . '/404.php';
    file_exists($page404) ? require $page404 : print('404 — Halaman tidak ditemukan.');
    return;
}

// ─── Resolusi reseller dari ref (untuk tombol WA Personal Use) ──────
$refCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($_GET['ref'] ?? ($_SESSION['ref_code'] ?? ''))));
$reseller = null;
if ($refCode !== '') {
    try {
        // Cocokkan kode reseller ke pemiliknya. Prioritas affiliates.code (kanonik,
        // dahulukan yang aktif) LALU fallback users.affiliate_code — sebab dashboard
        // reseller membuat link dari salah satu sumber itu (lihat pages/user/reseller.php).
        // Tombol WA bersifat off-platform: yang dibutuhkan cuma nomor si pemilik kode,
        // bukan status afiliasinya — jadi jangan kunci ke status='active' saja
        // (kalau tidak, reseller tanpa baris affiliates / berstatus non-aktif malah
        // jatuh ke WA admin).
        $rs = getDB()->prepare(
            "SELECT u.id, u.name, u.phone FROM affiliates a JOIN users u ON a.user_id = u.id
             WHERE a.code = ? ORDER BY (a.status = 'active') DESC, a.id ASC LIMIT 1"
        );
        $rs->execute([$refCode]);
        $reseller = $rs->fetch() ?: null;

        if (!$reseller) {
            $ru = getDB()->prepare(
                "SELECT id, name, phone FROM users WHERE affiliate_code = ? LIMIT 1"
            );
            $ru->execute([$refCode]);
            $reseller = $ru->fetch() ?: null;
        }
    } catch (Exception $e) { /* abaikan */ }
}
$resellerPhone = trim((string) ($reseller['phone'] ?? ''));

// Fallback: bila tak ada reseller valid, tombol Personal Use pakai WA admin (preview).
if ($resellerPhone === '') {
    try {
        $resellerPhone = (string) getDB()->query(
            "SELECT phone FROM users WHERE role='admin' AND phone IS NOT NULL AND phone <> '' ORDER BY id ASC LIMIT 1"
        )->fetchColumn();
    } catch (Exception $e) { $resellerPhone = ''; }
}

// ─── Konten & harga ────────────────────────────────────────────────
$classCnt = 0; $matCnt = 0;
try {
    $pdoP = getDB();
    // Modul milik produk: langsung (classes.product_id) + dibagikan (class_products).
    $cc = $pdoP->prepare(
        "SELECT COUNT(*) FROM classes
          WHERE (product_id = ? OR id IN (SELECT class_id FROM class_products WHERE product_id = ?))
            AND status = 'active'"
    );
    $cc->execute([(int) $product['id'], (int) $product['id']]);
    $classCnt = (int) $cc->fetchColumn();
    $mc = $pdoP->prepare(
        "SELECT COUNT(*) FROM materials m JOIN classes c ON c.id = m.class_id
          WHERE (c.product_id = ? OR c.id IN (SELECT class_id FROM class_products WHERE product_id = ?))
            AND c.status = 'active'"
    );
    $mc->execute([(int) $product['id'], (int) $product['id']]);
    $matCnt = (int) $mc->fetchColumn();
} catch (Exception $e) {}
$pres = productPresentation($product, $classCnt, $matCnt);

$appName    = getSetting('app_name', 'STIFIn Mulia');
$brandColor = getSetting('brand_color', '#6366f1');
$appFavicon = trim((string) getSetting('app_favicon', ''));
$faviconSvg = "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%23" . ltrim($brandColor, '#') . "'/><path d='M16 7 L25 25 L7 25 Z' fill='white'/></svg>";

// Harga yang tampil = harga produk Personal (P) ini langsung.
$personalPrice = (float) ($product['price'] ?? 0) > 0 ? (float) $product['price'] : null;

// Setting salespage per-reseller (teks WA + toggle resell) — default sistem bila belum diatur.
$rsSet    = resellerSalespageSettings((int) ($reseller['id'] ?? 0), (int) $product['id']);
$waText   = str_replace('{produk}', $product['name'], $rsSet['wa_template']);
$waButton = waLink($resellerPhone, $waText);

// Tombol Order Resell Rights → checkout produk RESELL (R) yang ditautkan admin.
// Tampil hanya bila: reseller mengaktifkan (toggle) DAN admin sudah menautkan R aktif.
$resellUrl     = '';
$resellEnabled = false;
$resellPid = (int) ($product['resell_product_id'] ?? 0);
if ($rsSet['resell_enabled'] && $resellPid > 0) {
    try {
        $rp = getDB()->prepare("SELECT slug FROM products WHERE id = ? AND status = 'active' LIMIT 1");
        $rp->execute([$resellPid]);
        $rSlug = (string) ($rp->fetchColumn() ?: '');
        if ($rSlug !== '') {
            $resellUrl     = rtrim(APP_URL, '/') . '/order/' . rawurlencode($rSlug) . ($refCode !== '' ? '?ref=' . rawurlencode($refCode) : '');
            $resellEnabled = true;
        }
    } catch (Exception $e) { /* abaikan → tombol resell disembunyikan */ }
}

$shortDesc = trim((string) ($product['short_description'] ?? ''));
$desc      = trim((string) ($product['description'] ?? ''));
$origPrice  = (float) ($product['original_price'] ?? 0);
$hasDisc    = $personalPrice !== null && $origPrice > $personalPrice;
$discPct    = $hasDisc ? (int) round(100 - ($personalPrice / $origPrice * 100)) : 0;
$sellerName = trim((string) ($reseller['name'] ?? ''));
$myrPrice   = ($product['price_myr'] !== null && (float) $product['price_myr'] > 0) ? (float) $product['price_myr'] : null;
$waSvg = '<svg style="width:1.15rem;height:1.15rem" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/></svg>';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($product['name']) ?> — <?= e($appName) ?></title>
  <link rel="icon" href="<?= $appFavicon !== '' ? e($appFavicon) : $faviconSvg ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/tailwind.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/tailwind.css') ?: '1' ?>">
  <style>
    :root{ --brand-color: <?= e($brandColor) ?>; }
    *{box-sizing:border-box;}
    html,body{margin:0;}
    body{font-family:Inter,system-ui,sans-serif;background:#f8fafc;color:#0f172a;-webkit-font-smoothing:antialiased;}
    a{text-decoration:none;}
    .sp-wrap{max-width:64rem;margin:0 auto;padding:1.5rem 1rem 7.5rem;}
    @media(min-width:1024px){ .sp-wrap{padding:3rem 1.5rem 3rem;} }
    .sp-hero{display:grid;gap:1.75rem;align-items:start;}
    @media(min-width:1024px){ .sp-hero{grid-template-columns:1.05fr .95fr;gap:2.5rem;} .sp-info{position:sticky;top:1.75rem;} }
    .sp-media{border-radius:.5rem;overflow:hidden;border:1px solid #e5e7eb;box-shadow:0 18px 40px -20px rgba(15,23,42,.28);background:#eef2f7;}
    .sp-ar{aspect-ratio:16/9;width:100%;}
    .sp-media img{width:100%;height:100%;object-fit:cover;display:block;}
    .sp-card{background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 1px 3px rgba(15,23,42,.06);padding:1.25rem;margin-top:1.5rem;}
    .sp-h2{font-size:.95rem;font-weight:700;margin:0 0 .85rem;}
    .sp-chip{display:inline-flex;align-items:center;gap:.35rem;font-size:.72rem;font-weight:700;padding:.3rem .6rem;border-radius:.375rem;}
    .sp-price{font-size:2.1rem;font-weight:800;color:var(--brand-color);line-height:1;}
    .sp-btn{display:flex;align-items:center;justify-content:center;gap:.6rem;width:100%;border-radius:.5rem;font-weight:700;transition:transform .12s ease,box-shadow .12s ease,opacity .12s ease;cursor:pointer;}
    .sp-btn-primary{color:#fff;background:var(--brand-color);padding:.95rem 1rem;font-size:1rem;box-shadow:0 10px 24px -10px color-mix(in srgb,var(--brand-color) 75%,transparent);}
    .sp-btn-primary:hover{transform:translateY(-1px);box-shadow:0 14px 30px -12px color-mix(in srgb,var(--brand-color) 80%,transparent);}
    .sp-btn-primary:active{transform:translateY(0);}
    .sp-btn-ghost{border:2px solid var(--brand-color);color:var(--brand-color);background:#fff;padding:.7rem 1rem;font-size:.9rem;}
    .sp-btn-ghost:hover{background:#f8fafc;}
    .sp-list{list-style:none;padding:0;margin:0;display:grid;gap:.65rem;}
    .sp-li{display:flex;align-items:flex-start;gap:.6rem;font-size:.9rem;color:#334155;line-height:1.5;}
    .sp-trust{display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem;margin-top:1.5rem;}
    .sp-trust > div{display:flex;flex-direction:column;align-items:center;text-align:center;gap:.4rem;padding:1rem .5rem;background:#fff;border:1px solid #eef0f3;border-radius:.5rem;}
    .sp-trust span{font-size:.76rem;font-weight:600;color:#475569;}
    .sp-trust svg{width:1.35rem;height:1.35rem;color:var(--brand-color);}
    .sp-mobilebar{position:fixed;left:0;right:0;bottom:0;z-index:50;display:flex;align-items:center;gap:.8rem;padding:.7rem .9rem;padding-bottom:calc(.7rem + env(safe-area-inset-bottom,0px));background:rgba(255,255,255,.97);backdrop-filter:blur(8px);border-top:1px solid #e5e7eb;box-shadow:0 -6px 22px -10px rgba(15,23,42,.2);}
    @media(min-width:1024px){ .sp-mobilebar{display:none;} }
  </style>
<link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/mulia-pages.css?v=1">
</head>
<body class="mulia mulia-sales">
  <div class="sp-wrap">

    <!-- HERO -->
    <div class="sp-hero">
      <div>
        <div class="sp-media">
          <div class="sp-ar">
            <?php if (!empty($product['thumbnail'])): ?>
            <img src="<?= e($product['thumbnail']) ?>" alt="<?= e($product['name']) ?>">
            <?php else: ?>
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--brand-color)">
              <svg style="width:3rem;height:3rem;color:rgba(255,255,255,.35)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="20" height="14" rx="2"/></svg>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="sp-info">
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
          <span class="sp-chip" style="background:var(--brand-color);color:#fff"><?= e($pres['type_label']) ?></span>
          <?php if ($hasDisc && $discPct >= 5): ?><span class="sp-chip" style="background:#fee2e2;color:#dc2626">Hemat <?= $discPct ?>%</span><?php endif; ?>
        </div>
        <h1 style="font-size:1.8rem;line-height:1.2;font-weight:800;margin:.8rem 0 0"><?= e($product['name']) ?></h1>
        <?php if ($shortDesc !== ''): ?>
        <p style="color:#64748b;margin:.65rem 0 0;line-height:1.6"><?= e($shortDesc) ?></p>
        <?php endif; ?>

        <?php if ($personalPrice !== null): ?>
        <div style="display:flex;align-items:baseline;gap:.65rem;flex-wrap:wrap;margin:1.25rem 0 0">
          <span class="sp-price"><?= e(formatRupiah($personalPrice)) ?></span>
          <?php if ($hasDisc): ?><span style="color:#94a3b8;text-decoration:line-through;font-size:1.05rem"><?= e(formatRupiah($origPrice)) ?></span><?php endif; ?>
        </div>
        <?php if ($myrPrice !== null): ?><div style="margin-top:.4rem"><?= ringgitBadge($myrPrice) ?></div><?php endif; ?>
        <?php endif; ?>

        <div style="margin-top:1.3rem;display:flex;flex-direction:column;gap:.7rem">
          <?php if ($waButton !== ''): ?>
          <a href="<?= e($waButton) ?>" target="_blank" rel="noopener noreferrer" class="sp-btn sp-btn-primary"><?= $waSvg ?> Order Personal Use</a>
          <?php endif; ?>
          <?php if ($resellEnabled): ?>
          <a href="<?= e($resellUrl) ?>" class="sp-btn sp-btn-ghost">Order Resell Rights
            <svg style="width:1rem;height:1rem" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
          </a>
          <?php endif; ?>
        </div>
        <?php if ($waButton !== ''): ?>
        <p style="text-align:center;color:#94a3b8;font-size:.75rem;margin:.75rem 0 0">Order via WhatsApp — dilayani langsung oleh penjual.</p>
        <?php endif; ?>
        <?php if ($sellerName !== ''): ?>
        <p style="text-align:center;color:#64748b;font-size:.82rem;margin:.45rem 0 0">Dijual oleh <strong style="color:#0f172a"><?= e($sellerName) ?></strong></p>
        <?php endif; ?>
      </div>
    </div>

    <!-- TRUST BAR -->
    <div class="sp-trust">
      <div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/></svg><span>Akses instan</span></div>
      <div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 2l8 3v6c0 5-3.5 8.5-8 11-4.5-2.5-8-6-8-11V5l8-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/></svg><span>Pembayaran aman</span></div>
      <div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span>Dilayani penjual</span></div>
    </div>

    <!-- Yang kamu dapat -->
    <?php if (!empty($pres['highlights'])): ?>
    <div class="sp-card">
      <h2 class="sp-h2">Yang kamu dapat</h2>
      <ul class="sp-list">
        <?php foreach ($pres['highlights'] as $h): ?>
        <li class="sp-li"><svg style="width:1.2rem;height:1.2rem;flex:0 0 auto;margin-top:1px;color:var(--brand-color)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M20 6L9 17l-5-5"/></svg><span><?= e($h) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <!-- Isi Paket -->
    <?php if (!empty($pres['includes'])): ?>
    <div class="sp-card">
      <h2 class="sp-h2">Isi Paket</h2>
      <ul class="sp-list">
        <?php foreach ($pres['includes'] as $inc): ?>
        <li class="sp-li"><span style="width:.4rem;height:.4rem;border-radius:9999px;margin-top:.5rem;flex:0 0 auto;background:var(--brand-color)"></span><span><?= e($inc) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <!-- Deskripsi -->
    <?php if ($desc !== ''): ?>
    <div class="sp-card">
      <h2 class="sp-h2">Deskripsi</h2>
      <div style="font-size:.9rem;color:#334155;line-height:1.7;white-space:pre-line"><?= nl2br(e($desc)) ?></div>
    </div>
    <?php endif; ?>

    <p style="text-align:center;color:#94a3b8;font-size:.75rem;margin-top:2rem">Dipromosikan via <?= e($appName) ?></p>
  </div>

  <!-- STICKY CTA (mobile) -->
  <?php if ($waButton !== ''): ?>
  <div class="sp-mobilebar">
    <?php if ($personalPrice !== null): ?>
    <div style="line-height:1.15;flex:0 0 auto">
      <div style="font-size:1.15rem;font-weight:800;color:var(--brand-color)"><?= e(formatRupiah($personalPrice)) ?></div>
      <?php if ($hasDisc): ?><div style="font-size:.7rem;color:#94a3b8;text-decoration:line-through"><?= e(formatRupiah($origPrice)) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <a href="<?= e($waButton) ?>" target="_blank" rel="noopener noreferrer" class="sp-btn sp-btn-primary" style="flex:1;padding:.85rem 1rem"><?= $waSvg ?> Order</a>
  </div>
  <?php endif; ?>
</body>
</html>
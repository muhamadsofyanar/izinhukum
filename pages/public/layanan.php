<?php
require_once __DIR__ . '/../../bootstrap.php';
$catalog = require __DIR__ . '/../../helpers/izin-catalog.php';
$slug = $_GET['slug'] ?? null;
$item = is_string($slug) ? ($catalog[$slug] ?? null) : null;
if ($slug !== null && !$item) { http_response_code(404); }
$esc = static fn ($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$base = $esc(rtrim(APP_URL, '/'));
$title = $item['name'] ?? ($slug === null ? 'Layanan Izin Hukum' : 'Layanan tidak ditemukan');
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $esc($title) ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f6f7f3;color:#19372f;font:16px/1.6 system-ui,sans-serif}header{background:#143b32;color:white}nav,main{max-width:1050px;margin:auto;padding:20px 5%}nav{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}nav a{color:white}h1{font:normal clamp(35px,5vw,58px)/1.15 Georgia,serif;margin:30px 0 20px}h2{line-height:1.25}section{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,265px),1fr));gap:16px}article{background:white;border:1px solid #d8dfd9;border-radius:12px;padding:20px}article h2,article h3{margin-top:0}a{color:#11634e}ul{padding-left:20px}main{padding-bottom:60px}
</style></head><body><header><nav><strong>STIFIn Cabang</strong><span><a href="<?= $base ?>/layanan">Layanan Izin Hukum</a> · <a href="<?= $base ?>/dashboard">Dashboard cabang</a></span></nav></header><main>
<h1><?= $esc($title) ?></h1>
<?php if ($item): ?>
<p><?= $esc($item['description']) ?></p>
<?php if (!empty($item['requirements'])): ?><h2>Persyaratan</h2><ul><?php foreach ($item['requirements'] as $req): ?><li><?= $esc($req) ?></li><?php endforeach ?></ul><?php endif ?>
<h2>Pilihan paket</h2><section><?php foreach ($item['packages'] as $p): ?><article><h3><?= $esc($p['name']) ?></h3><strong>
<?php if (!$p['price']): ?>Sesuai penawaran<?php else: ?>
<?= ($p['price_suffix'] ?? '') === 'mulai' ? 'Mulai dari ' : (!empty($p['is_estimated']) ? 'Estimasi ' : '') ?>Rp<?= number_format($p['price'], 0, ',', '.') ?><?= $esc(($p['price_suffix'] ?? '') === 'mulai' ? '' : ($p['price_suffix'] ?? '')) ?>
<?php endif ?></strong><?php if (!empty($p['original_price'])): ?> <del>Rp<?= number_format($p['original_price'], 0, ',', '.') ?></del><?php endif ?><p><?= $esc($p['tagline'] ?? '') ?></p><ul><?php foreach ($p['features'] as $feature): ?><li><?= $esc($feature) ?></li><?php endforeach ?></ul></article><?php endforeach ?></section>
<?php elseif ($slug === null): foreach (array_unique(array_column($catalog, 'category')) as $category): ?>
<h2><?= $esc($category) ?></h2><section><?php foreach ($catalog as $service): if ($service['category'] !== $category) continue; ?><article><h3><a href="<?= $base ?>/layanan/<?= $esc($service['slug']) ?>"><?= $esc($service['name']) ?></a></h3><p><?= $esc($service['summary']) ?></p></article><?php endforeach ?></section>
<?php endforeach; else: ?><a href="<?= $base ?>/layanan">Kembali ke katalog</a><?php endif ?>
</main></body></html>

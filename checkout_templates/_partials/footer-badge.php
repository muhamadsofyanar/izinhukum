<?php
// Footer badge "Powered by" opsional (white-label per template checkout).
// Variables dari scope: $tplFooterBadge (bool), $tplFooterBadgeText,
//   $tplFooterBadgeIcon (URL gambar, opsional), $tplFooterBadgeUrl (link opsional).
if (empty($tplFooterBadge)) return;

$fbText = trim((string) ($tplFooterBadgeText ?? ''));
$fbIcon = trim((string) ($tplFooterBadgeIcon ?? ''));
$fbUrl  = trim((string) ($tplFooterBadgeUrl ?? ''));
if ($fbText === '' && $fbIcon === '') return; // tak ada yang ditampilkan

// Ikon = URL opsional. Di produksi https, URL http/lintas-origin DIBLOKIR CSP
// (img-src 'self' data: https:) → ikon patah. Maka: pakai <img> HANYA bila URL aman
// (https / same-origin). Selain itu (kosong atau tak aman) jatuh ke LAMBANG INLINE SVG
// warna brand — identik dgn footer form Funnel Gate, selalu tajam & tak bisa patah.
$iconSafe = false;
if ($fbIcon !== '') {
    $appOrigin = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
    if (stripos($fbIcon, 'https://') === 0)                          $iconSafe = true; // https absolut
    elseif ($appOrigin !== '' && stripos($fbIcon, $appOrigin) === 0) $iconSafe = true; // same-origin absolut
    elseif ($fbIcon[0] === '/')                                      $iconSafe = true; // relatif same-origin
}

// Lambang inline (zap) — sama persis dgn footer Funnel Gate; ikut warna brand.
$fbMark = '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" style="color:var(--brand-color)" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>';
$fbIconHtml = $iconSafe
    ? '<img src="' . e($fbIcon) . '" alt="" class="h-4 w-auto object-contain">'
    : $fbMark; // kosong / URL tak aman → lambang inline brand (anti-patah)

$fbInner = '<span class="text-xs font-semibold text-gray-400 dark:text-gray-500">Powered by</span>'
    . $fbIconHtml
    . ($fbText !== '' ? '<span class="text-sm font-bold text-gray-500 dark:text-gray-400">' . e($fbText) . '</span>' : '');
?>
<div class="mt-6 mb-2 text-center">
  <?php if ($fbUrl !== ''): ?>
  <a href="<?= e($fbUrl) ?>" target="_blank" rel="noopener nofollow" class="inline-flex items-center justify-center gap-1.5 opacity-80 hover:opacity-100 transition"><?= $fbInner ?></a>
  <?php else: ?>
  <div class="inline-flex items-center justify-center gap-1.5 opacity-80"><?= $fbInner ?></div>
  <?php endif; ?>
  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1.5">Copyright &copy; <?= date('Y') ?></p>
</div>

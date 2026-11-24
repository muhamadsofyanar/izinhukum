<?php
// ─── Kompresi Gambar Upload ────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh, tanpa ubah signature/logika.

/**
 * Kompres & resize gambar upload IN-PLACE (menimpa file yang sama, nama/URL
 * tidak berubah). Aman dipanggil untuk file apa pun: non-gambar, PDF, GIF,
 * ICO, SVG dilewati tanpa efek. Format & transparansi dipertahankan.
 *
 * Kenapa: di shared hosting, thumbnail 8 MB dari kamera HP menghabiskan disk
 * (disk penuh = MySQL berhenti = situs mati) dan memberatkan halaman + backup.
 *
 * Return ['compressed'=>bool, 'before'=>int, 'after'=>int] (byte).
 */
function compressUploadedImage(string $path, int $maxDim = 1600, int $quality = 82): array
{
    $out = ['compressed' => false, 'before' => 0, 'after' => 0];
    try {
        if (!is_file($path) || !function_exists('imagecreatetruecolor')) return $out;
        $before = (int) filesize($path);
        $out['before'] = $out['after'] = $before;

        $info = @getimagesize($path);
        if (!$info) return $out;
        [$w, $h] = $info;
        $mime = (string) ($info['mime'] ?? '');
        if ($w < 1 || $h < 1) return $out;
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return $out;
        // Gambar ekstrem (>25 MP) dilewati — decode-nya bisa melebihi memory_limit hosting.
        if ($w * $h > 25000000) return $out;

        // Proses hanya bila dimensi kebesaran ATAU file > 300 KB (re-encode hemat).
        $needResize = ($w > $maxDim || $h > $maxDim);
        if (!$needResize && $before <= 300 * 1024) return $out;

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        };
        if (!$src) return $out;

        $nw = $w; $nh = $h;
        if ($needResize) {
            $scale = min($maxDim / $w, $maxDim / $h);
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
        }

        $dst = imagecreatetruecolor($nw, $nh);
        if ($mime !== 'image/jpeg') {
            // Jaga transparansi PNG/WebP.
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $tmp = $path . '.tmp';
        $ok  = match ($mime) {
            'image/jpeg' => imagejpeg($dst, $tmp, $quality),
            'image/png'  => imagepng($dst, $tmp, 9),
            'image/webp' => function_exists('imagewebp') ? imagewebp($dst, $tmp, $quality) : false,
        };
        imagedestroy($dst);
        if (!$ok || !is_file($tmp)) { @unlink($tmp); return $out; }

        $after = (int) filesize($tmp);
        // Pakai hasil bila lebih kecil, ATAU resize memang terjadi (dimensi turun
        // tetap menang walau byte setara — hemat memori render & bandwidth).
        if ($after < $before || $needResize) {
            if (@rename($tmp, $path)) {
                return ['compressed' => true, 'before' => $before, 'after' => (int) filesize($path)];
            }
        }
        @unlink($tmp);
    } catch (Throwable $e) {
        error_log('compressUploadedImage error: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Kompres gambar IN-PLACE sampai ukuran berkas ≤ $targetBytes (default 400 KB),
 * dengan tetap menjaga FORMAT (jpg/png/webp) & transparansi. Nama/URL tak berubah.
 *
 * Strategi iteratif (ambil hasil TERKECIL yang dicapai):
 *  - JPG/WebP (lossy): turunkan kualitas bertahap; bila masih besar, perkecil dimensi.
 *  - PNG (lossless): perkecil dimensi + kompresi maksimal; sebagai upaya terakhir
 *    palettize 256 warna (grafis/screenshot turun drastis; alpha jadi 1-bit).
 * Dimensi dibatasi $maxDim. GIF/SVG/non-gambar dilewati (return compressed=false).
 * Best-effort: bila target tetap tak tercapai (mis. foto PNG), pakai hasil terkecil.
 *
 * Return ['compressed'=>bool,'before'=>int,'after'=>int,'width'=>?int,'height'=>?int].
 */
function compressImageToTarget(string $path, int $targetBytes = 409600, int $maxDim = 1600): array
{
    $out = ['compressed' => false, 'before' => 0, 'after' => 0, 'width' => null, 'height' => null];
    try {
        if (!is_file($path) || !function_exists('imagecreatetruecolor')) return $out;
        $before = (int) filesize($path);
        $out['before'] = $out['after'] = $before;

        $info = @getimagesize($path);
        if (!$info) return $out;
        [$w, $h] = $info;
        $mime = (string) ($info['mime'] ?? '');
        $out['width'] = $w; $out['height'] = $h;
        if ($w < 1 || $h < 1) return $out;
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return $out; // gif/svg dilewati
        if ($w * $h > 25000000) return $out; // ekstrem → hindari OOM decode di hosting

        $needWork = ($before > $targetBytes) || ($w > $maxDim || $h > $maxDim);
        if (!$needWork) return $out;

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        };
        if (!$src) return $out;

        $lossy   = in_array($mime, ['image/jpeg', 'image/webp'], true);
        $hasAlpha = ($mime !== 'image/jpeg');
        $isPng   = ($mime === 'image/png');

        // Skala awal: hormati batas maxDim.
        $scale = 1.0;
        if ($w > $maxDim || $h > $maxDim) $scale = min($maxDim / $w, $maxDim / $h);
        $minScale = 0.25; // jangan < 25% dimensi asli
        $quality  = 80;

        $bestTmp = null; $bestSize = PHP_INT_MAX; $bestW = $w; $bestH = $h;

        for ($iter = 0; $iter < 14; $iter++) {
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));

            $dst = imagecreatetruecolor($nw, $nh);
            if ($hasAlpha) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

            $tmp = $path . '.c' . $iter;
            if ($isPng) {
                $okEnc = imagepng($dst, $tmp, 9);
                $szEnc = ($okEnc && is_file($tmp)) ? (int) filesize($tmp) : PHP_INT_MAX;
                // Masih di atas target → coba varian palette 256 (lebih kecil utk grafis).
                if ($szEnc > $targetBytes) {
                    $pal = imagecreatetruecolor($nw, $nh);
                    imagealphablending($pal, false);
                    imagesavealpha($pal, true);
                    imagefilledrectangle($pal, 0, 0, $nw, $nh, imagecolorallocatealpha($pal, 0, 0, 0, 127));
                    imagecopyresampled($pal, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    @imagetruecolortopalette($pal, true, 256);
                    $tmp2 = $tmp . 'p';
                    if (imagepng($pal, $tmp2, 9) && is_file($tmp2)) {
                        if ((int) filesize($tmp2) < $szEnc) { @unlink($tmp); @rename($tmp2, $tmp); }
                        else { @unlink($tmp2); }
                    }
                    imagedestroy($pal);
                }
                $ok = is_file($tmp);
            } elseif ($mime === 'image/webp') {
                $ok = function_exists('imagewebp') ? imagewebp($dst, $tmp, $quality) : false;
            } else {
                $ok = imagejpeg($dst, $tmp, $quality);
            }
            imagedestroy($dst);
            if (!$ok || !is_file($tmp)) { @unlink($tmp); break; }

            $sz = (int) filesize($tmp);
            if ($sz < $bestSize) {
                if ($bestTmp) @unlink($bestTmp);
                $bestTmp = $tmp; $bestSize = $sz; $bestW = $nw; $bestH = $nh;
            } else {
                @unlink($tmp);
            }

            if ($sz <= $targetBytes) break; // target tercapai

            // Belum tercapai → kecilkan lever: kualitas dulu (lossy), lalu dimensi.
            if ($lossy && $quality > 45) {
                $quality -= 12;
            } elseif ($scale > $minScale) {
                $scale = max($minScale, $scale * 0.8);
            } else {
                break; // sudah mentok (dimensi & kualitas minimum)
            }
        }
        imagedestroy($src);

        if ($bestTmp && is_file($bestTmp)) {
            // Pakai hasil bila lebih kecil dari asli ATAU dimensi turun (hemat memori/bandwidth).
            if ($bestSize < $before || $bestW < $w || $bestH < $h) {
                if (@rename($bestTmp, $path)) {
                    return ['compressed' => true, 'before' => $before, 'after' => (int) filesize($path), 'width' => $bestW, 'height' => $bestH];
                }
            }
            @unlink($bestTmp);
        }
    } catch (Throwable $e) {
        error_log('compressImageToTarget error: ' . $e->getMessage());
    }
    return $out;
}

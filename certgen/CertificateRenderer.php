<?php
// Generate thumbnail PNG sertifikat via GD. Graceful: return null jika GD tidak tersedia.

class CertificateRenderer
{
    public static function generateThumbnail(array $cert): ?string
    {
        if (!extension_loaded('gd')) return null;

        try {
            $w = 1200; $h = 850;
            $img = imagecreatetruecolor($w, $h);

            $bg     = self::hex2rgb($img, getSetting('certificate_bg_color', '#1e1b4b'));
            $accent = self::hex2rgb($img, getSetting('certificate_accent_color', '#6366f1'));
            $white  = imagecolorallocate($img, 255, 255, 255);
            $grey   = imagecolorallocate($img, 180, 180, 200);
            $gold   = imagecolorallocate($img, 250, 204, 21);

            imagefilledrectangle($img, 0, 0, $w, $h, $bg);
            imagefilledrectangle($img, 0, 0, $w, 8, $accent);             // top bar
            imagerectangle($img, 20, 20, $w - 20, $h - 20, $accent);      // border
            imagerectangle($img, 23, 23, $w - 23, $h - 23, $accent);

            $appName = (string) getSetting('app_name', 'STIFIn Mulia');
            self::centerText($img, 5, $w, 70,  strtoupper($appName), $accent);
            self::centerText($img, 5, $w, 150, 'SERTIFIKAT PENYELESAIAN', $white);
            self::centerText($img, 3, $w, 250, 'Diberikan kepada:', $grey);
            self::centerText($img, 5, $w, 310, $cert['recipient_name'] ?? '', $gold);
            self::centerText($img, 3, $w, 410, 'Telah berhasil menyelesaikan:', $grey);
            self::centerText($img, 5, $w, 460, $cert['product_name'] ?? '', $white);
            self::centerText($img, 2, $w, 560, 'No. Sertifikat: ' . ($cert['certificate_no'] ?? ''), $grey);
            self::centerText($img, 2, $w, 590, 'Tanggal: ' . date('d F Y', strtotime($cert['issued_at'] ?? 'now')), $grey);

            $sig = (string) getSetting('certificate_signatory_name', '');
            if ($sig !== '') {
                self::centerText($img, 4, $w, 700, $sig, $white);
                self::centerText($img, 2, $w, 730, (string) getSetting('certificate_signatory_title', ''), $grey);
            }

            $dir = UPLOAD_PATH . 'certificates/';
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            $rel  = 'certificates/' . ($cert['uuid'] ?? bin2hex(random_bytes(8))) . '.png';
            $path = UPLOAD_PATH . $rel;

            imagepng($img, $path, 6);
            imagedestroy($img);
            return is_file($path) ? $rel : null;
        } catch (Throwable $e) {
            error_log('CertificateRenderer error: ' . $e->getMessage());
            return null;
        }
    }

    private static function hex2rgb($img, string $hex)
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) $hex = '1e1b4b';
        return imagecolorallocate($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    private static function centerText($img, int $font, int $canvasW, int $y, string $text, $color): void
    {
        $text = substr($text, 0, 80);
        $tw   = imagefontwidth($font) * strlen($text);
        $x    = (int) max(40, ($canvasW - $tw) / 2);
        imagestring($img, $font, $x, $y, $text, $color);
    }
}

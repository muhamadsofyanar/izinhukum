<?php

namespace App\Support;

class VideoEmbed
{
    public static function url(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $videoId = null;

        if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
            $videoId = explode('/', $path)[0] ?? null;
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
            if ($path === 'watch') {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $videoId = $query['v'] ?? null;
            } elseif (str_starts_with($path, 'embed/')) {
                $videoId = explode('/', $path)[1] ?? null;
            } elseif (str_starts_with($path, 'shorts/')) {
                $videoId = explode('/', $path)[1] ?? null;
            }
        }

        if (! is_string($videoId) || preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) {
            return null;
        }

        return 'https://www.youtube-nocookie.com/embed/'.$videoId;
    }
}

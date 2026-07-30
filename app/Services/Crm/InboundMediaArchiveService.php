<?php

namespace App\Services\Crm;

use App\Models\CrmDocument;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class InboundMediaArchiveService
{
    public function archive(CrmDocument $document): CrmDocument
    {
        if ($document->archive_status === 'stored' && filled($document->path)) {
            return $document;
        }

        $url = trim((string) $document->source_url);
        $this->assertAllowedUrl($url);

        $maxBytes = max(1024 * 1024, (int) config('starsender.media_archive_max_bytes', 20 * 1024 * 1024));
        $client = new Client([
            'timeout' => 30,
            'connect_timeout' => 8,
            'allow_redirects' => false,
            'verify' => true,
            'headers' => ['User-Agent' => 'IzinHukum-Media-Archiver/12.0'],
        ]);

        $currentUrl = $url;
        $response = null;
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $this->assertAllowedUrl($currentUrl);
            $response = $client->request('GET', $currentUrl, ['stream' => true]);
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 300 && $statusCode < 400) {
                $location = trim($response->getHeaderLine('Location'));
                if ($location === '' || $redirects === 3) {
                    throw new RuntimeException('Redirect media provider tidak valid atau terlalu banyak.');
                }
                $currentUrl = $this->resolveRedirect($currentUrl, $location);
                continue;
            }
            break;
        }
        if (! $response || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('Provider media mengembalikan HTTP '.($response?->getStatusCode() ?? 0).'.');
        }

        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0] ?? ''));
        if (! $this->allowedMime($contentType)) {
            throw new RuntimeException('Tipe media tidak diizinkan: '.($contentType ?: 'tidak diketahui'));
        }

        $declaredLength = (int) $response->getHeaderLine('Content-Length');
        if ($declaredLength > $maxBytes) {
            throw new RuntimeException('Lampiran melebihi batas '.number_format($maxBytes / 1024 / 1024, 0).' MB.');
        }

        $extension = $this->extensionForMime($contentType);
        $directory = 'crm/whatsapp/'.now()->format('Y/m');
        $path = $directory.'/'.Str::uuid().'.'.$extension;
        $stream = $response->getBody();
        $temporary = tmpfile();
        if ($temporary === false) {
            throw new RuntimeException('Tidak dapat membuat berkas sementara.');
        }

        $size = 0;
        $hash = hash_init('sha256');
        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    continue;
                }
                $size += strlen($chunk);
                if ($size > $maxBytes) {
                    throw new RuntimeException('Lampiran melebihi batas '.number_format($maxBytes / 1024 / 1024, 0).' MB.');
                }
                hash_update($hash, $chunk);
                if (fwrite($temporary, $chunk) === false) {
                    throw new RuntimeException('Gagal menulis berkas sementara.');
                }
            }

            rewind($temporary);
            if (! Storage::disk('local')->put($path, $temporary)) {
                throw new RuntimeException('Gagal menyimpan lampiran ke arsip privat.');
            }
        } finally {
            fclose($temporary);
        }

        $document->forceFill([
            'path' => $path,
            'disk' => 'local',
            'mime_type' => $contentType,
            'extension' => $extension,
            'size' => $size,
            'checksum' => hash_final($hash),
            'archive_status' => 'stored',
            'archived_at' => now(),
            'original_name' => $document->original_name ?: basename((string) parse_url($currentUrl, PHP_URL_PATH)),
        ])->save();

        return $document;
    }

    private function resolveRedirect(string $baseUrl, string $location): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $location;
        }

        $base = parse_url($baseUrl);
        $scheme = (string) ($base['scheme'] ?? 'https');
        $host = (string) ($base['host'] ?? '');
        $port = isset($base['port']) ? ':'.(int) $base['port'] : '';
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$host.$port.$location;
        }

        $basePath = (string) ($base['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
        return $scheme.'://'.$host.$port.($directory === '' ? '' : $directory).'/'.$location;
    }

    private function assertAllowedUrl(string $url): void
    {
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('URL media tidak valid.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            throw new RuntimeException('URL media harus menggunakan HTTPS.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('Host media berbentuk alamat IP tidak diizinkan.');
        }

        $allowed = array_values(array_filter(array_map(
            fn (string $value): string => strtolower(trim($value)),
            (array) config('starsender.media_allowed_hosts', ['starsender.online']),
        )));

        $matches = collect($allowed)->contains(fn (string $candidate): bool => $host === $candidate || str_ends_with($host, '.'.$candidate));
        if (! $matches) {
            throw new RuntimeException('Host media tidak ada dalam allowlist.');
        }
    }

    private function allowedMime(string $mime): bool
    {
        return str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'audio/')
            || str_starts_with($mime, 'video/')
            || in_array($mime, [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
            ], true);
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/zip' => 'zip',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'video/mp4' => 'mp4',
            default => str_contains($mime, '/') ? preg_replace('/[^a-z0-9]+/', '', substr($mime, strpos($mime, '/') + 1)) ?: 'bin' : 'bin',
        };
    }
}

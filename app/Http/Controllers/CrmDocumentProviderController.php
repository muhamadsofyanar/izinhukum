<?php

namespace App\Http\Controllers;

use App\Models\CrmDocumentShareLink;
use App\Services\Crm\CrmDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CrmDocumentProviderController extends Controller
{
    public function __invoke(
        Request $request,
        CrmDocumentShareLink $link,
        string $token,
        CrmDocumentService $documents,
    ): BinaryFileResponse {
        $link->loadMissing('document');
        $document = $link->document;

        abort_unless(
            $document
            && $link->usable()
            && hash_equals((string) $link->token_hash, hash('sha256', $token))
            && $documents->pathExists($document),
            404,
        );

        $link->forceFill([
            'access_count' => $link->access_count + 1,
            'last_access_at' => now(),
        ])->save();
        $documents->logAccess($document, 'download_provider', null, $request->ip(), $request->userAgent());

        $downloadName = basename((string) ($document->original_name ?: $document->name ?: 'dokumen'));
        $downloadName = trim((string) preg_replace('/[\x00-\x1F\x7F"\\\\]+/u', '-', Str::ascii($downloadName))) ?: 'dokumen';

        return response()->file(Storage::disk($document->disk ?: 'local')->path($document->path), [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$downloadName.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CommunityPost;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommunityAttachmentController extends Controller
{
    public function __invoke(CommunityPost $post): StreamedResponse
    {
        abort_unless($post->attachment_path, 404);

        $disk = Storage::disk('local')->exists($post->attachment_path)
            ? 'local'
            : 'public';

        abort_unless(Storage::disk($disk)->exists($post->attachment_path), 404);

        return Storage::disk($disk)->download(
            $post->attachment_path,
            basename($post->attachment_path),
            [
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }
}

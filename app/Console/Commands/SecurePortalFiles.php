<?php

namespace App\Console\Commands;

use App\Models\CommunityPost;
use App\Models\Lesson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SecurePortalFiles extends Command
{
    protected $signature = 'portal:secure-files';

    protected $description = 'Memindahkan materi LMS dan lampiran komunitas lama ke penyimpanan privat.';

    public function handle(): int
    {
        $moved = 0;

        $lessonPaths = Lesson::query()
            ->whereNotNull('file_path')
            ->pluck('file_path')
            ->filter(fn (string $path): bool => str_starts_with($path, 'academy/materials/'));

        $communityPaths = CommunityPost::query()
            ->whereNotNull('attachment_path')
            ->pluck('attachment_path')
            ->filter(fn (string $path): bool => str_starts_with($path, 'community/'));

        $lessonPaths->concat($communityPaths)->unique()->each(
            function (string $path) use (&$moved): void {
                if (Storage::disk('public')->exists($path)) {
                    if (! Storage::disk('local')->exists($path)) {
                        Storage::disk('local')->put($path, Storage::disk('public')->get($path));
                    }

                    if (Storage::disk('local')->exists($path)) {
                        Storage::disk('public')->delete($path);
                        $moved++;
                    }
                }
            },
        );

        $this->components->info("File privat portal siap. {$moved} file lama dipindahkan.");

        return self::SUCCESS;
    }
}

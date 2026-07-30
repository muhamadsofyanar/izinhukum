<?php

namespace App\Console\Commands;

use App\Models\CrmDocument;
use App\Services\Crm\InboundMediaArchiveService;
use Illuminate\Console\Command;
use Throwable;

class ArchivePendingWhatsAppMedia extends Command
{
    protected $signature = 'crm:archive-whatsapp-media {--limit=50} {--retry-failed : Coba ulang dokumen berstatus gagal}';

    protected $description = 'Menyalin lampiran WhatsApp yang masih berupa URL provider ke arsip privat.';

    public function handle(InboundMediaArchiveService $archiver): int
    {
        $stored = 0;
        $failed = 0;
        CrmDocument::query()
            ->whereIn('archive_status', $this->option('retry-failed') ? ['pending', 'failed'] : ['pending'])
            ->whereNotNull('source_url')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->each(function (CrmDocument $document) use ($archiver, &$stored, &$failed): void {
                try {
                    $archiver->archive($document);
                    $stored++;
                } catch (Throwable $exception) {
                    $document->forceFill([
                        'archive_status' => 'failed',
                        'notes' => trim(($document->notes ? $document->notes."\n" : '').$exception->getMessage()),
                    ])->save();
                    $failed++;
                }
            });

        $this->info("Stored: {$stored}, failed: {$failed}");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use App\Models\CrmDocument;
use App\Services\Crm\InboundMediaArchiveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ArchiveInboundWhatsAppMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue('whatsapp');
    }

    public function handle(InboundMediaArchiveService $archiver): void
    {
        $document = CrmDocument::query()->find($this->documentId);
        if (! $document || $document->archive_status === 'stored') {
            return;
        }
        $archiver->archive($document);
    }

    public function failed(Throwable $exception): void
    {
        CrmDocument::query()->whereKey($this->documentId)->update([
            'archive_status' => 'failed',
            'notes' => $exception->getMessage(),
            'updated_at' => now(),
        ]);
    }
}

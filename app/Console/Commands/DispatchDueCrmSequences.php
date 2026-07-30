<?php

namespace App\Console\Commands;

use App\Services\Crm\CrmSequenceService;
use Illuminate\Console\Command;

class DispatchDueCrmSequences extends Command
{
    protected $signature = 'crm:dispatch-sequences {--limit=200}';

    protected $description = 'Menjalankan langkah sequence CRM yang sudah jatuh tempo.';

    public function handle(CrmSequenceService $service): int
    {
        $result = $service->dispatchDue(max(1, (int) $this->option('limit')));
        $this->info("Processed: {$result['processed']}, queued: {$result['queued']}, failed: {$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

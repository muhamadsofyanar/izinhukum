<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Console\Command;

class DispatchDueWhatsAppMessages extends Command
{
    protected $signature = 'whatsapp:dispatch-due {--limit=200}';

    protected $description = 'Memasukkan pesan WhatsApp terjadwal yang sudah jatuh tempo ke antrean.';

    public function handle(WhatsAppManager $manager): int
    {
        $count = $manager->dispatchDue(max(1, (int) $this->option('limit')));
        $this->info("{$count} pesan dimasukkan ke antrean.");

        return self::SUCCESS;
    }
}

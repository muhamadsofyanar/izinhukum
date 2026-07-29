<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WhatsAppAutomationService;
use Illuminate\Console\Command;

class RunWhatsAppAutomations extends Command
{
    protected $signature = 'whatsapp:run-automations';

    protected $description = 'Menjalankan pengingat WhatsApp terjadwal yang aktif.';

    public function handle(WhatsAppAutomationService $automations): int
    {
        $result = $automations->dispatchScheduledReminders();
        foreach ($result as $trigger => $count) {
            $this->line($trigger.': '.$count);
        }

        return self::SUCCESS;
    }
}

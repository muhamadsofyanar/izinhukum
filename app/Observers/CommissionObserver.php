<?php

namespace App\Observers;

use App\Models\Commission;
use App\Services\WhatsApp\WhatsAppAutomationService;
use Illuminate\Support\Facades\DB;

class CommissionObserver
{
    public function created(Commission $commission): void
    {
        $this->dispatchForStatus($commission);
    }

    public function updated(Commission $commission): void
    {
        if ($commission->wasChanged('status')) {
            $this->dispatchForStatus($commission);
        }
    }

    private function dispatchForStatus(Commission $commission): void
    {
        $trigger = match ($commission->status) {
            'approved' => 'commission_available',
            'paid' => 'commission_paid',
            default => null,
        };

        if ($trigger) {
            DB::afterCommit(fn (): mixed => app(WhatsAppAutomationService::class)->trigger($trigger, $commission));
        }
    }
}

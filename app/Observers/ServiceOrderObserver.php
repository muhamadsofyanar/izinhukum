<?php

namespace App\Observers;

use App\Models\ServiceOrder;
use App\Services\WhatsApp\WhatsAppAutomationService;
use Illuminate\Support\Facades\DB;

class ServiceOrderObserver
{
    public function created(ServiceOrder $order): void
    {
        DB::afterCommit(fn (): mixed => app(WhatsAppAutomationService::class)->trigger('order_created', $order));
    }

    public function updated(ServiceOrder $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        $trigger = $order->status === 'completed' ? 'order_completed' : 'order_status_changed';
        DB::afterCommit(fn (): mixed => app(WhatsAppAutomationService::class)->trigger($trigger, $order));
    }
}

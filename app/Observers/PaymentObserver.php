<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\WhatsApp\WhatsAppAutomationService;
use Illuminate\Support\Facades\DB;

class PaymentObserver
{
    public function created(Payment $payment): void
    {
        if ($payment->status === 'active') {
            DB::afterCommit(fn (): mixed => app(WhatsAppAutomationService::class)->trigger('payment_received', $payment));
        }
    }

    public function updated(Payment $payment): void
    {
        if ($payment->wasChanged('status') && $payment->status === 'active') {
            DB::afterCommit(fn (): mixed => app(WhatsAppAutomationService::class)->trigger('payment_received', $payment));
        }
    }
}

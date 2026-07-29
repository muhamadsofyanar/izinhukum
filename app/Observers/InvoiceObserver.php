<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\WhatsApp\WhatsAppAutomationService;
use Illuminate\Support\Facades\DB;

class InvoiceObserver
{
    public function updated(Invoice $invoice): void
    {
        if ($invoice->wasChanged('status') && $invoice->status === 'sent') {
            DB::afterCommit(fn (): mixed => app(WhatsAppAutomationService::class)->trigger('invoice_sent', $invoice));
        }
    }
}

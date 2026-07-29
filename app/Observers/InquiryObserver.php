<?php

namespace App\Observers;

use App\Models\Inquiry;
use App\Services\WhatsApp\WhatsAppAutomationService;
use Illuminate\Support\Facades\DB;

class InquiryObserver
{
    public function created(Inquiry $inquiry): void
    {
        DB::afterCommit(fn (): mixed => app(WhatsAppAutomationService::class)->trigger('proposal_received', $inquiry));
    }
}

<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\BrandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Invoice $invoice)
    {
    }

    public function build(): self
    {
        $branding = app(BrandingService::class)->document();

        return $this
            ->subject('Invoice '.$this->invoice->invoice_number.' · '.$branding['name'])
            ->view('emails.invoice')
            ->with(['branding' => $branding]);
    }
}

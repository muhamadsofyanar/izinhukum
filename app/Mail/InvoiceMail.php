<?php

namespace App\Mail;

use App\Models\Invoice;
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
        return $this
            ->subject('Invoice '.$this->invoice->invoice_number.' · IzinHukum')
            ->view('emails.invoice');
    }
}

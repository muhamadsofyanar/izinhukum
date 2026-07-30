<?php

namespace App\Mail;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewInquiryAdminMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Inquiry $inquiry)
    {
    }

    public function build(): self
    {
        $this->inquiry->loadMissing(['package.service', 'serviceOrder']);

        return $this
            ->subject('[Pesanan Baru] '.$this->inquiry->reference.' · '.$this->inquiry->name)
            ->view('emails.admin-new-inquiry');
    }
}

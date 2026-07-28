<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PartnerActivationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public User $partner, public string $activationUrl)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Aktifkan akun Mitra IzinHukum')
            ->view('emails.partner-activation');
    }
}

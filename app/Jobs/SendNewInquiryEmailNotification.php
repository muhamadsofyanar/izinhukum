<?php

namespace App\Jobs;

use App\Mail\NewInquiryAdminMail;
use App\Models\Inquiry;
use App\Services\MailConfigurator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendNewInquiryEmailNotification implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $inquiryId)
    {
    }

    public function uniqueId(): string
    {
        return 'new-inquiry-email:'.$this->inquiryId;
    }

    public function handle(MailConfigurator $mailConfigurator): void
    {
        if (! config('business-notifications.new_order.email.enabled')) {
            return;
        }

        $recipient = trim((string) config('business-notifications.new_order.email.recipient'));
        $inquiry = Inquiry::query()->with(['package.service', 'serviceOrder'])->find($this->inquiryId);
        if ($recipient === '' || ! $inquiry) {
            return;
        }

        $mailConfigurator->apply();
        Mail::to($recipient)->send(new NewInquiryAdminMail($inquiry));
    }
}

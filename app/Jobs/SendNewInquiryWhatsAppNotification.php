<?php

namespace App\Jobs;

use App\Models\Inquiry;
use App\Services\FeatureFlagService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNewInquiryWhatsAppNotification implements ShouldQueue, ShouldBeUnique
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
        return 'new-inquiry-whatsapp:'.$this->inquiryId;
    }

    public function handle(WhatsAppManager $messages, FeatureFlagService $features): void
    {
        if (
            ! config('business-notifications.new_order.whatsapp.enabled')
            || ! $features->enabled('whatsapp_transactional')
        ) {
            return;
        }

        $phone = trim((string) config('business-notifications.new_order.whatsapp.recipient'));
        $inquiry = Inquiry::query()->with(['package.service', 'serviceOrder'])->find($this->inquiryId);
        if ($phone === '' || ! $inquiry) {
            return;
        }

        $service = $inquiry->package?->service?->name
            ?: $inquiry->package?->name
            ?: 'Konsultasi legalitas';
        $orderUrl = $inquiry->serviceOrder
            ? route('admin.orders.show', $inquiry->serviceOrder)
            : route('admin.inquiries.index');

        $body = implode("\n", array_filter([
            '*Pesanan baru IzinHukum*',
            'Referensi: '.$inquiry->reference,
            'Nama: '.$inquiry->name,
            'Layanan: '.$service,
            'WhatsApp klien: '.$inquiry->phone,
            $inquiry->email ? 'Email klien: '.$inquiry->email : null,
            $inquiry->company_name ? 'Perusahaan: '.$inquiry->company_name : null,
            $inquiry->city ? 'Kota: '.$inquiry->city : null,
            $inquiry->coupon_code ? 'Kupon: '.$inquiry->coupon_code : null,
            $inquiry->coupon_discount_amount > 0
                ? 'Potongan: Rp'.number_format($inquiry->coupon_discount_amount, 0, ',', '.')
                : null,
            'Sumber: '.match ($inquiry->source) {
                'name_generator' => 'Generator nama',
                'deed_simulator' => 'Simulasi akta',
                'service_landing' => 'Landing layanan',
                'partner_referral' => 'Referral mitra',
                default => 'Website',
            },
            $inquiry->message ? 'Kebutuhan: '.(string) str($inquiry->message)->squish()->limit(600) : null,
            '',
            'Chat klien: https://wa.me/'.preg_replace('/\D/', '', $inquiry->phone),
            'Buka order: '.$orderUrl,
        ], fn (?string $line): bool => $line !== null));

        $messages->queueRaw([
            'inquiry_id' => $inquiry->id,
            'service_order_id' => $inquiry->serviceOrder?->id,
            'phone' => $phone,
            'recipient_name' => 'Admin IzinHukum',
            'body' => $body,
            'message_type' => 'text',
            'device_alias' => 'transaction',
            'skip_conversation' => true,
            'idempotency_key' => 'admin:new-inquiry:'.$inquiry->id,
            'metadata' => [
                'notification_type' => 'admin_new_inquiry',
                'inquiry_reference' => $inquiry->reference,
            ],
        ]);
    }
}

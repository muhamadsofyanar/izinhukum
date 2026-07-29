<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReferralEvent;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\Schema;

class ReferralEventService
{
    public function recordInquiry(Inquiry $inquiry): void
    {
        if (! $this->available() || ! $inquiry->referred_by_partner_id) {
            return;
        }

        ReferralEvent::query()->updateOrCreate(
            ['source_key' => 'inquiry:'.$inquiry->id],
            [
                'partner_referral_id' => $inquiry->partner_referral_id,
                'partner_id' => $inquiry->referred_by_partner_id,
                'inquiry_id' => $inquiry->id,
                'event_type' => 'inquiry',
                'event_value' => 0,
                'occurred_at' => $inquiry->created_at ?: now(),
                'metadata' => ['reference' => $inquiry->reference],
            ],
        );
    }

    public function recordOrder(ServiceOrder $order): void
    {
        if (! $this->available() || ! $order->referred_by_partner_id) {
            return;
        }

        ReferralEvent::query()->updateOrCreate(
            ['source_key' => 'order:'.$order->id],
            [
                'partner_referral_id' => $order->partner_referral_id,
                'partner_id' => $order->referred_by_partner_id,
                'inquiry_id' => $order->inquiry_id,
                'service_order_id' => $order->id,
                'event_type' => $order->status === 'cancelled' ? 'order_cancelled' : 'order',
                'event_value' => 0,
                'occurred_at' => $order->created_at ?: now(),
                'metadata' => [
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                ],
            ],
        );
    }


    public function recordInvoice(Invoice $invoice): void
    {
        if (! $this->available()) {
            return;
        }

        $invoice->loadMissing('serviceOrder');
        $order = $invoice->serviceOrder;
        $partnerId = $invoice->referred_by_partner_id ?: $order?->referred_by_partner_id;

        if (! $partnerId) {
            ReferralEvent::query()->where('source_key', 'invoice:'.$invoice->id)->delete();
            return;
        }

        ReferralEvent::query()->updateOrCreate(
            ['source_key' => 'invoice:'.$invoice->id],
            [
                'partner_referral_id' => $order?->partner_referral_id,
                'partner_id' => $partnerId,
                'inquiry_id' => $invoice->inquiry_id,
                'service_order_id' => $invoice->service_order_id,
                'invoice_id' => $invoice->id,
                'event_type' => $invoice->status === 'cancelled' ? 'invoice_cancelled' : 'invoice',
                'event_value' => $invoice->status === 'cancelled' ? 0 : (int) $invoice->total,
                'occurred_at' => $invoice->created_at ?: now(),
                'metadata' => [
                    'invoice_number' => $invoice->invoice_number,
                    'status' => $invoice->status,
                ],
            ],
        );
    }

    public function recordPayment(Payment $payment): void
    {
        if (! $this->available()) {
            return;
        }

        $payment->loadMissing('invoice.serviceOrder');
        $invoice = $payment->invoice;
        $order = $invoice?->serviceOrder;
        $partnerId = $invoice?->referred_by_partner_id ?: $order?->referred_by_partner_id;

        if (! $partnerId) {
            ReferralEvent::query()->where('source_key', 'payment:'.$payment->id)->delete();
            return;
        }

        ReferralEvent::query()->updateOrCreate(
            ['source_key' => 'payment:'.$payment->id],
            [
                'partner_referral_id' => $order?->partner_referral_id,
                'partner_id' => $partnerId,
                'inquiry_id' => $invoice?->inquiry_id,
                'service_order_id' => $invoice?->service_order_id,
                'invoice_id' => $invoice?->id,
                'payment_id' => $payment->id,
                'event_type' => $payment->status === 'active' ? 'payment' : 'payment_cancelled',
                'event_value' => $payment->status === 'active' ? (int) $payment->amount : 0,
                'occurred_at' => $payment->payment_date?->startOfDay() ?: now(),
                'metadata' => [
                    'receipt_number' => $payment->receipt_number,
                    'status' => $payment->status,
                ],
            ],
        );
    }

    private function available(): bool
    {
        return Schema::hasTable('referral_events');
    }
}

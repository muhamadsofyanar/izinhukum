<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Payment;

class CommissionService
{
    public function syncForPayment(Payment $payment): ?Commission
    {
        $payment->loadMissing(['invoice.referredByPartner', 'commission']);

        if ($payment->isCancelled()) {
            return $this->cancelForPayment($payment);
        }

        $invoice = $payment->invoice;
        $partner = $invoice?->referredByPartner;
        if (! $invoice || ! $partner || ! $partner->is_active) {
            return null;
        }

        $plan = $partner->partnerPlan();
        $rateBps = max(0, (int) ($plan['commission_bps'] ?? 0));
        $amount = intdiv(((int) $payment->amount * $rateBps) + 5000, 10000);
        if ($amount <= 0) {
            return null;
        }

        $commission = Commission::query()->where('payment_id', $payment->id)->first();
        if (! $commission) {
            return Commission::query()->create([
                'partner_id' => $partner->id,
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'amount' => $amount,
                'rate_bps' => $rateBps,
                'source' => 'referral',
                'status' => 'pending',
                'notes' => 'Komisi otomatis dari '.$payment->receipt_number.'.',
            ]);
        }

        $requiresAdjustment = $commission->status === 'paid'
            && (
                (int) $commission->amount !== $amount
                || (int) $commission->rate_bps !== $rateBps
            );

        $commission->update([
            'partner_id' => $partner->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'rate_bps' => $rateBps,
            'source' => 'referral',
            'status' => $requiresAdjustment ? 'adjustment_required' : $commission->status,
            'notes' => $requiresAdjustment
                ? 'Nominal pembayaran berubah setelah komisi dibayar. Lakukan penyesuaian manual.'
                : $commission->notes,
        ]);

        return $commission->fresh();
    }

    public function cancelForPayment(Payment $payment): ?Commission
    {
        $commission = Commission::query()->where('payment_id', $payment->id)->first();
        if (! $commission) {
            return null;
        }

        $wasPaid = in_array($commission->status, ['paid', 'adjustment_required'], true);
        $commission->update([
            'status' => $wasPaid ? 'adjustment_required' : 'cancelled',
            'notes' => $wasPaid
                ? 'Kwitansi dibatalkan setelah komisi dibayar. Pengembalian atau pemotongan wajib direkonsiliasi.'
                : 'Komisi dibatalkan karena kwitansi tidak lagi aktif.',
        ]);

        return $commission->fresh();
    }
}


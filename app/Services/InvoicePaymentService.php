<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoicePaymentService
{
    public function record(Invoice $invoice, User $creator, array $data): Payment
    {
        return DB::transaction(function () use ($invoice, $creator, $data): Payment {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($lockedInvoice->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'amount' => 'Pembayaran tidak dapat dicatat pada invoice yang dibatalkan.',
                ]);
            }

            $paidAmount = (int) $lockedInvoice->payments()->sum('amount');
            $remainingAmount = max(0, (int) $lockedInvoice->total - $paidAmount);
            $amount = (int) $data['amount'];

            if ($amount <= 0 || $amount > $remainingAmount) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pembayaran harus lebih dari nol dan tidak boleh melebihi sisa tagihan.',
                ]);
            }

            $payment = $lockedInvoice->payments()->create([
                'created_by' => $creator->id,
                'financial_category_id' => $data['financial_category_id'] ?? null,
                'receipt_number' => 'PENDING-'.Str::uuid(),
                'public_token' => Str::random(64),
                'payment_date' => $data['payment_date'],
                'amount' => $amount,
                'payer_name' => $lockedInvoice->recipient_name,
                'description' => 'Pembayaran '.$lockedInvoice->invoice_number,
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $payment->update([
                'receipt_number' => sprintf('KWT/IH/%s/%05d', $payment->payment_date->format('Ym'), $payment->id),
            ]);

            $totalPaid = $paidAmount + $amount;
            $isPaid = $totalPaid >= (int) $lockedInvoice->total;
            $lockedInvoice->update([
                'status' => $isPaid ? 'paid' : 'partial',
                'paid_at' => $isPaid ? now() : null,
            ]);

            return $payment->fresh(['invoice', 'creator', 'category']);
        });
    }
}

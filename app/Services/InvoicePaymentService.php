<?php

namespace App\Services;

use App\Models\AuditLog;
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

            if ($lockedInvoice->status === 'draft') {
                throw ValidationException::withMessages([
                    'amount' => 'Tandai invoice sebagai terkirim sebelum mencatat pembayaran.',
                ]);
            }

            if ($lockedInvoice->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'amount' => 'Pembayaran tidak dapat dicatat pada invoice yang dibatalkan.',
                ]);
            }

            $paidAmount = (int) $lockedInvoice->payments()->active()->sum('amount');
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

    public function update(
        Payment $payment,
        User $editor,
        array $data,
        ?string $ipAddress = null,
    ): Payment {
        return DB::transaction(function () use ($payment, $editor, $data, $ipAddress): Payment {
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->isCancelled()) {
                throw ValidationException::withMessages([
                    'payment' => 'Kwitansi yang sudah dibatalkan tidak dapat dikoreksi.',
                ]);
            }

            $invoice = $lockedPayment->invoice_id
                ? Invoice::query()->lockForUpdate()->findOrFail($lockedPayment->invoice_id)
                : null;
            $amount = (int) $data['amount'];

            if ($invoice) {
                $otherPaid = (int) $invoice->payments()
                    ->active()
                    ->where('id', '!=', $lockedPayment->id)
                    ->sum('amount');

                if ($amount <= 0 || $otherPaid + $amount > (int) $invoice->total) {
                    throw ValidationException::withMessages([
                        'amount' => 'Nominal koreksi membuat pembayaran melebihi total invoice.',
                    ]);
                }
            }

            $before = $lockedPayment->only([
                'payment_date',
                'amount',
                'payer_name',
                'description',
                'payment_method',
                'financial_category_id',
                'reference_number',
                'notes',
            ]);

            $lockedPayment->update([
                'financial_category_id' => $data['financial_category_id'] ?? null,
                'payment_date' => $data['payment_date'],
                'amount' => $amount,
                'payer_name' => $data['payer_name'] ?? $lockedPayment->payer_name,
                'description' => $data['description'] ?? $lockedPayment->description,
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'last_edited_at' => now(),
                'last_edited_by' => $editor->id,
            ]);

            AuditLog::query()->create([
                'user_id' => $editor->id,
                'action' => 'payment.updated',
                'subject_type' => Payment::class,
                'subject_id' => $lockedPayment->id,
                'metadata' => [
                    'reason' => $data['edit_reason'],
                    'before' => $before,
                    'after' => $lockedPayment->fresh()->only(array_keys($before)),
                ],
                'ip_address' => $ipAddress,
            ]);

            if ($invoice) {
                $this->recalculateInvoice($invoice);
            }

            return $lockedPayment->fresh([
                'invoice',
                'creator',
                'category',
                'lastEditedBy',
            ]);
        });
    }

    public function cancel(
        Payment $payment,
        User $editor,
        string $reason,
        ?string $ipAddress = null,
    ): Payment {
        return DB::transaction(function () use ($payment, $editor, $reason, $ipAddress): Payment {
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->isCancelled()) {
                throw ValidationException::withMessages([
                    'payment' => 'Kwitansi ini sudah dibatalkan.',
                ]);
            }

            $invoice = $lockedPayment->invoice_id
                ? Invoice::query()->lockForUpdate()->findOrFail($lockedPayment->invoice_id)
                : null;

            $lockedPayment->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $editor->id,
                'cancellation_reason' => $reason,
            ]);

            AuditLog::query()->create([
                'user_id' => $editor->id,
                'action' => 'payment.cancelled',
                'subject_type' => Payment::class,
                'subject_id' => $lockedPayment->id,
                'metadata' => [
                    'reason' => $reason,
                    'receipt_number' => $lockedPayment->receipt_number,
                    'amount' => $lockedPayment->amount,
                ],
                'ip_address' => $ipAddress,
            ]);

            if ($invoice) {
                $this->recalculateInvoice($invoice);
            }

            return $lockedPayment->fresh([
                'invoice',
                'creator',
                'category',
                'cancelledBy',
            ]);
        });
    }

    public function recalculateInvoice(Invoice $invoice): void
    {
        if ($invoice->status === 'cancelled') {
            return;
        }

        $totalPaid = (int) $invoice->payments()->active()->sum('amount');
        $isPaid = $totalPaid >= (int) $invoice->total;

        $invoice->update([
            'status' => $isPaid
                ? 'paid'
                : ($totalPaid > 0 ? 'partial' : ($invoice->sent_at ? 'sent' : 'draft')),
            'paid_at' => $isPaid ? ($invoice->paid_at ?: now()) : null,
        ]);
    }
}

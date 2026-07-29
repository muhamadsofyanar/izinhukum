<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class LegacyPaidInvoiceReconciler
{
    public function __construct(
        private readonly CommissionService $commissionService,
    ) {
    }

    public function run(): int
    {
        $created = 0;

        Invoice::query()
            ->where('status', 'paid')
            ->where('total', '>', 0)
            ->orderBy('id')
            ->chunkById(100, function ($invoices) use (&$created): void {
                foreach ($invoices as $invoice) {
                    $payment = DB::transaction(function () use ($invoice): ?Payment {
                        $locked = Invoice::query()->lockForUpdate()->find($invoice->id);

                        if (! $locked || $locked->status !== 'paid' || (int) $locked->total <= 0) {
                            return null;
                        }

                        $activePaid = (int) $locked->payments()->active()->sum('amount');
                        $missingAmount = max(0, (int) $locked->total - $activePaid);

                        if ($missingAmount <= 0) {
                            return null;
                        }

                        $paidAt = $locked->paid_at ?: $locked->updated_at;
                        $sourceKey = $activePaid === 0
                            ? 'legacy-paid-invoice:'.$locked->id
                            : 'legacy-paid-invoice-balance:'.$locked->id.':'.$activePaid;

                        if (Payment::query()->where('source_key', $sourceKey)->exists()) {
                            return null;
                        }

                        $paymentSequence = $locked->payments()->count() + 1;
                        $receiptNumber = $activePaid === 0
                            ? sprintf(
                                'KWT-MIG/IH/%s/%05d',
                                $paidAt->format('Ym'),
                                $locked->id,
                            )
                            : sprintf(
                                'KWT-MIG/IH/%s/%05d-%02d',
                                $paidAt->format('Ym'),
                                $locked->id,
                                $paymentSequence,
                            );

                        $payment = Payment::query()->create([
                            'invoice_id' => $locked->id,
                            'created_by' => $locked->created_by,
                            'receipt_number' => $receiptNumber,
                            'public_token' => hash(
                                'sha256',
                                $sourceKey.'|'.$locked->public_token,
                            ),
                            'status' => 'active',
                            'source' => 'legacy_invoice_migration',
                            'source_key' => $sourceKey,
                            'payment_date' => $paidAt->toDateString(),
                            'amount' => $missingAmount,
                            'payer_name' => $locked->recipient_name,
                            'description' => $activePaid === 0
                                ? 'Konversi pembayaran '.$locked->invoice_number
                                : 'Rekonsiliasi sisa pembayaran '.$locked->invoice_number,
                            'payment_method' => 'other',
                            'reference_number' => $activePaid === 0
                                ? 'MIGRASI-INVOICE-'.$locked->id
                                : 'MIGRASI-SALDO-INVOICE-'.$locked->id,
                            'notes' => $activePaid === 0
                                ? 'Dibuat otomatis dari invoice lama berstatus lunas. Periksa metode dan referensi pembayaran bila diperlukan.'
                                : 'Dibuat otomatis untuk melengkapi selisih antara total invoice lunas dan kwitansi aktif. Periksa data pembayaran bila diperlukan.',
                        ]);

                        AuditLog::query()->create([
                            'user_id' => $locked->created_by,
                            'action' => 'payment.legacy_reconciled',
                            'subject_type' => Payment::class,
                            'subject_id' => $payment->id,
                            'metadata' => [
                                'invoice_id' => $locked->id,
                                'invoice_number' => $locked->invoice_number,
                                'payment_date_source' => $locked->paid_at ? 'paid_at' : 'updated_at',
                                'invoice_total' => (int) $locked->total,
                                'active_paid_before' => $activePaid,
                                'amount_created' => $missingAmount,
                            ],
                        ]);

                        $this->commissionService->syncForPayment($payment);

                        return $payment;
                    });

                    if ($payment) {
                        $created++;
                    }
                }
            });

        return $created;
    }
}

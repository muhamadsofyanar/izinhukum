<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class LegacyPaidInvoiceReconciler
{
    public function run(): int
    {
        $created = 0;

        Invoice::query()
            ->where('status', 'paid')
            ->where('total', '>', 0)
            ->whereDoesntHave('payments')
            ->orderBy('id')
            ->chunkById(100, function ($invoices) use (&$created): void {
                foreach ($invoices as $invoice) {
                    $wasCreated = DB::transaction(function () use ($invoice): bool {
                        $locked = Invoice::query()->lockForUpdate()->find($invoice->id);

                        if (! $locked
                            || $locked->status !== 'paid'
                            || (int) $locked->total <= 0
                            || $locked->payments()->exists()) {
                            return false;
                        }

                        $paidAt = $locked->paid_at ?: $locked->updated_at;
                        $sourceKey = 'legacy-paid-invoice:'.$locked->id;
                        if (Payment::query()->where('source_key', $sourceKey)->exists()) {
                            return false;
                        }

                        $payment = Payment::query()->create([
                            'invoice_id' => $locked->id,
                            'created_by' => $locked->created_by,
                            'receipt_number' => sprintf(
                                'KWT-MIG/IH/%s/%05d',
                                $paidAt->format('Ym'),
                                $locked->id,
                            ),
                            'public_token' => hash(
                                'sha256',
                                'legacy-paid-invoice|'.$locked->id.'|'.$locked->public_token,
                            ),
                            'status' => 'active',
                            'source' => 'legacy_invoice_migration',
                            'source_key' => $sourceKey,
                            'payment_date' => $paidAt->toDateString(),
                            'amount' => (int) $locked->total,
                            'payer_name' => $locked->recipient_name,
                            'description' => 'Konversi pembayaran '.$locked->invoice_number,
                            'payment_method' => 'other',
                            'reference_number' => 'MIGRASI-INVOICE-'.$locked->id,
                            'notes' => 'Dibuat otomatis dari invoice lama berstatus lunas. Periksa metode dan referensi pembayaran bila diperlukan.',
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
                                'amount' => (int) $locked->total,
                            ],
                        ]);

                        return true;
                    });

                    if ($wasCreated) {
                        $created++;
                    }
                }
            });

        return $created;
    }
}


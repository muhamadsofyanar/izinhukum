<?php

namespace App\Services;

use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\SalesQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesQuoteService
{
    public function __construct(private readonly ServiceOrderService $orders)
    {
    }

    public function approve(SalesQuote $quote): SalesQuote
    {
        return DB::transaction(function () use ($quote): SalesQuote {
            $locked = SalesQuote::query()
                ->with(['items.package', 'inquiry.crmLead', 'crmLead', 'serviceOrder', 'creator'])
                ->lockForUpdate()
                ->findOrFail($quote->id);

            if ($locked->status === 'approved' && $locked->invoice_id) {
                return $locked;
            }
            if ($locked->status !== 'sent') {
                throw ValidationException::withMessages(['quote' => 'Penawaran ini tidak tersedia untuk disetujui.']);
            }
            if ($locked->isExpired()) {
                throw ValidationException::withMessages(['quote' => 'Masa berlaku penawaran telah berakhir. Hubungi tim IzinHukum untuk pembaruan.']);
            }

            $inquiry = $locked->inquiry;
            $invoice = Invoice::query()->create([
                'invoice_number' => 'PENDING-'.Str::uuid(),
                'public_token' => Str::random(56),
                'created_by' => $locked->created_by,
                'inquiry_id' => $locked->inquiry_id,
                'service_order_id' => $locked->service_order_id,
                'referred_by_partner_id' => $locked->referred_by_partner_id,
                'referral_code' => $locked->referral_code,
                'coupon_id' => $locked->coupon_id,
                'coupon_code' => $locked->coupon_code,
                'coupon_discount_type' => $inquiry?->coupon_discount_type,
                'coupon_discount_value' => $inquiry?->coupon_discount_value ?? 0,
                'recipient_type' => 'end_user',
                'recipient_name' => $locked->recipient_name,
                'recipient_company' => $locked->recipient_company,
                'recipient_email' => $locked->recipient_email,
                'recipient_phone' => $locked->recipient_phone,
                'recipient_address' => $locked->recipient_address,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays($locked->invoice_due_days)->toDateString(),
                'status' => 'sent',
                'subtotal' => $locked->subtotal,
                'discount' => $locked->discount,
                'tax' => 0,
                'total' => $locked->total,
                'notes' => trim('Dibuat otomatis dari '.$locked->quote_number.'. '.($locked->notes ?: '')),
                'sent_at' => now(),
            ]);
            $invoice->update([
                'invoice_number' => sprintf('INV/IH/%s/%05d', now()->format('Ym'), $invoice->id),
            ]);
            $invoice->items()->createMany($locked->items->map(function ($item): array {
                return [
                    'service_package_id' => $item->service_package_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'partner_cost' => $item->package?->partner_price,
                    'minimum_end_user_price' => $item->package?->minimum_end_user_price,
                    'line_total' => $item->line_total,
                ];
            })->all());

            $locked->update([
                'status' => 'approved',
                'approved_at' => now(),
                'invoice_id' => $invoice->id,
            ]);
            $inquiry?->update(['status' => 'proses']);

            if ($locked->serviceOrder) {
                $this->orders->update($locked->serviceOrder, [
                    'status' => 'awaiting_payment',
                    'progress' => $this->orders->progressForStatus('awaiting_payment'),
                ], $locked->creator);
            }

            $lead = $locked->crmLead ?: $inquiry?->crmLead;
            if ($lead) {
                $lead->update([
                    'stage' => 'deal',
                    'probability' => 80,
                    'estimated_value' => $locked->total,
                    'closed_at' => null,
                    'won_at' => $lead->won_at ?: now(),
                    'last_stage_changed_at' => $lead->stage !== 'deal' ? now() : $lead->last_stage_changed_at,
                ]);
                CrmActivity::query()->create([
                    'contact_id' => $lead->contact_id,
                    'lead_id' => $lead->id,
                    'service_order_id' => $locked->service_order_id,
                    'type' => 'quote_approved',
                    'title' => 'Penawaran disetujui',
                    'description' => $locked->quote_number.' · invoice '.$invoice->invoice_number.' dibuat otomatis.',
                    'completed_at' => now(),
                ]);
            }

            return $locked->fresh(['items', 'invoice', 'inquiry']);
        });
    }

    public function reject(SalesQuote $quote, string $reason): SalesQuote
    {
        return DB::transaction(function () use ($quote, $reason): SalesQuote {
            $locked = SalesQuote::query()->with(['inquiry.crmLead', 'crmLead'])->lockForUpdate()->findOrFail($quote->id);
            if ($locked->status !== 'sent' || $locked->isExpired()) {
                throw ValidationException::withMessages(['quote' => 'Penawaran ini tidak dapat ditolak pada status sekarang.']);
            }
            $locked->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
            ]);
            $lead = $locked->crmLead ?: $locked->inquiry?->crmLead;
            if ($lead) {
                $lead->update(['stage' => 'qualified', 'probability' => 35]);
                CrmActivity::query()->create([
                    'contact_id' => $lead->contact_id,
                    'lead_id' => $lead->id,
                    'service_order_id' => $lead->service_order_id,
                    'type' => 'quote_rejected',
                    'title' => 'Penawaran belum disetujui',
                    'description' => $reason,
                    'completed_at' => now(),
                ]);
            }

            return $locked->fresh();
        });
    }
}

<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ServiceOrderService
{
    public function createFromInquiry(Inquiry $inquiry, ?User $actor = null): ServiceOrder
    {
        return DB::transaction(function () use ($inquiry, $actor): ServiceOrder {
            $inquiry->loadMissing(['package.service', 'referredByPartner']);
            $status = $this->statusFromInquiry($inquiry->status);

            $order = ServiceOrder::query()->firstOrCreate(
                ['inquiry_id' => $inquiry->id],
                [
                    'order_number' => $this->nextNumber(),
                    'public_token' => Str::random(64),
                    'service_package_id' => $inquiry->service_package_id,
                    'referred_by_partner_id' => $inquiry->referred_by_partner_id,
                    'partner_referral_id' => $inquiry->partner_referral_id,
                    'created_by' => $actor?->id,
                    'referral_code' => $inquiry->referral_code,
                    'title' => $inquiry->package?->service?->name
                        ?: $inquiry->package?->name
                        ?: 'Konsultasi legalitas',
                    'customer_name' => $inquiry->name,
                    'customer_email' => $inquiry->email,
                    'customer_phone' => $inquiry->phone,
                    'customer_company' => $inquiry->company_name,
                    'customer_city' => $inquiry->city,
                    'status' => $status,
                    'payment_status' => 'unpaid',
                    'priority' => 'normal',
                    'progress' => $this->progressForStatus($status),
                    'started_at' => in_array($status, ['processing', 'waiting_customer', 'completed'], true) ? now() : null,
                    'completed_at' => $status === 'completed' ? now() : null,
                    'cancelled_at' => $status === 'cancelled' ? now() : null,
                    'description' => $inquiry->message,
                    'checklist' => $this->defaultChecklist(),
                ],
            );

            Invoice::query()
                ->where('inquiry_id', $inquiry->id)
                ->whereNull('service_order_id')
                ->update(['service_order_id' => $order->id]);

            if ($order->wasRecentlyCreated) {
                $this->event(
                    $order,
                    'order_created',
                    'Order dibuat dari permintaan '.$inquiry->reference,
                    'Data proposal, referral, dan layanan telah disatukan ke dalam order.',
                    $actor,
                );
            }

            $this->syncPaymentStatus($order);
            app(ReferralEventService::class)->recordInquiry($inquiry);
            app(ReferralEventService::class)->recordOrder($order);

            return $order->fresh();
        });
    }

    public function createManual(array $data, User $actor): ServiceOrder
    {
        return DB::transaction(function () use ($data, $actor): ServiceOrder {
            $status = $data['status'] ?? 'lead';
            $order = ServiceOrder::query()->create([
                ...$data,
                'order_number' => $this->nextNumber(),
                'public_token' => Str::random(64),
                'created_by' => $actor->id,
                'progress' => $data['progress'] ?? $this->progressForStatus($status),
                'payment_status' => 'unpaid',
                'checklist' => $data['checklist'] ?? $this->defaultChecklist(),
                'started_at' => in_array($status, ['processing', 'waiting_customer', 'completed'], true) ? now() : null,
                'completed_at' => $status === 'completed' ? now() : null,
                'cancelled_at' => $status === 'cancelled' ? now() : null,
            ]);

            $this->event($order, 'order_created', 'Order manual dibuat', null, $actor);
            app(ReferralEventService::class)->recordOrder($order);

            return $order;
        });
    }

    public function update(ServiceOrder $order, array $data, User $actor): ServiceOrder
    {
        return DB::transaction(function () use ($order, $data, $actor): ServiceOrder {
            $beforeStatus = $order->status;
            $data['progress'] = isset($data['progress'])
                ? max(0, min(100, (int) $data['progress']))
                : $this->progressForStatus($data['status'] ?? $order->status);

            if (($data['status'] ?? $order->status) === 'completed') {
                $data['completed_at'] = $order->completed_at ?: now();
                $data['progress'] = 100;
            } elseif ($order->status === 'completed') {
                $data['completed_at'] = null;
            }

            if (($data['status'] ?? $order->status) === 'cancelled') {
                $data['cancelled_at'] = $order->cancelled_at ?: now();
            } elseif ($order->status === 'cancelled') {
                $data['cancelled_at'] = null;
            }

            if (in_array(($data['status'] ?? $order->status), ['document_collection', 'processing'], true)) {
                $data['started_at'] = $order->started_at ?: now();
            }

            $order->update($data);

            if ($order->inquiry_id) {
                Inquiry::query()
                    ->whereKey($order->inquiry_id)
                    ->where('status', '!=', $this->inquiryStatusFromOrder($order->status))
                    ->update(['status' => $this->inquiryStatusFromOrder($order->status)]);
            }

            if ($beforeStatus !== $order->status) {
                $this->event(
                    $order,
                    'status_changed',
                    'Status diubah menjadi '.$order->statusLabel(),
                    'Status sebelumnya: '.(ServiceOrder::STATUSES[$beforeStatus] ?? $beforeStatus).'.',
                    $actor,
                    ['from' => $beforeStatus, 'to' => $order->status],
                );
            } else {
                $this->event($order, 'order_updated', 'Data order diperbarui', null, $actor);
            }

            app(ReferralEventService::class)->recordOrder($order);

            return $order->fresh();
        });
    }

    public function syncPaymentStatus(ServiceOrder $order): ServiceOrder
    {
        $order->loadMissing(['invoices.payments']);
        $validInvoices = $order->invoices->where('status', '!=', 'cancelled');
        $total = (int) $validInvoices->sum('total');
        $paid = (int) $validInvoices->sum(
            fn (Invoice $invoice): int => (int) $invoice->payments->where('status', 'active')->sum('amount'),
        );

        $status = $paid <= 0 ? 'unpaid' : ($total > 0 && $paid >= $total ? 'paid' : 'partial');
        if ($order->payment_status !== $status) {
            $order->forceFill(['payment_status' => $status])->saveQuietly();
        }

        return $order;
    }

    public function backfill(bool $dryRun = false): array
    {
        if (! Schema::hasTable('service_orders')) {
            return ['created' => 0, 'linked_invoices' => 0, 'checked' => 0];
        }

        $result = ['created' => 0, 'linked_invoices' => 0, 'checked' => 0];
        $unlinkedBefore = Invoice::query()
            ->whereNull('service_order_id')
            ->whereNotNull('inquiry_id')
            ->count();

        Inquiry::query()->orderBy('id')->chunkById(100, function ($inquiries) use (&$result, $dryRun): void {
            foreach ($inquiries as $inquiry) {
                $result['checked']++;
                if (ServiceOrder::query()->where('inquiry_id', $inquiry->id)->exists()) {
                    continue;
                }
                $result['created']++;
                if (! $dryRun) {
                    $this->createFromInquiry($inquiry);
                }
            }
        });

        $unlinked = Invoice::query()
            ->whereNull('service_order_id')
            ->whereNotNull('inquiry_id')
            ->count();

        if (! $dryRun && $unlinked > 0) {
            Invoice::query()
                ->whereNull('service_order_id')
                ->whereNotNull('inquiry_id')
                ->each(function (Invoice $invoice): void {
                    $orderId = ServiceOrder::query()->where('inquiry_id', $invoice->inquiry_id)->value('id');
                    if ($orderId) {
                        $invoice->update(['service_order_id' => $orderId]);
                    }
                });
        }

        $unlinkedAfter = Invoice::query()
            ->whereNull('service_order_id')
            ->whereNotNull('inquiry_id')
            ->count();
        $result['linked_invoices'] = max(0, $unlinkedBefore - $unlinkedAfter);

        if (! $dryRun) {
            ServiceOrder::query()->with('invoices.payments')->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $this->syncPaymentStatus($order);
                }
            });

            $referralEvents = app(ReferralEventService::class);

            Inquiry::query()
                ->whereNotNull('referred_by_partner_id')
                ->chunkById(100, function ($inquiries) use ($referralEvents): void {
                    foreach ($inquiries as $inquiry) {
                        $referralEvents->recordInquiry($inquiry);
                    }
                });

            ServiceOrder::query()
                ->whereNotNull('referred_by_partner_id')
                ->chunkById(100, function ($orders) use ($referralEvents): void {
                    foreach ($orders as $order) {
                        $referralEvents->recordOrder($order);
                    }
                });

            Invoice::query()
                ->whereNotNull('referred_by_partner_id')
                ->with('serviceOrder')
                ->chunkById(100, function ($invoices) use ($referralEvents): void {
                    foreach ($invoices as $invoice) {
                        $referralEvents->recordInvoice($invoice);
                    }
                });

            Payment::query()
                ->with('invoice.serviceOrder')
                ->chunkById(100, function ($payments) use ($referralEvents): void {
                    foreach ($payments as $payment) {
                        $referralEvents->recordPayment($payment);
                    }
                });
        }

        return $result;
    }

    public function event(
        ServiceOrder $order,
        string $type,
        string $title,
        ?string $description = null,
        ?User $actor = null,
        array $metadata = [],
        string $actorType = 'admin',
    ): void {
        $order->events()->create([
            'actor_id' => $actor?->id,
            'actor_type' => $actor || $actorType !== 'admin' ? $actorType : 'system',
            'event_type' => $type,
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata ?: null,
            'occurred_at' => now(),
        ]);
    }

    public function progressForStatus(string $status): int
    {
        return match ($status) {
            'lead' => 5,
            'waiting_approval' => 10,
            'awaiting_payment' => 20,
            'document_collection' => 35,
            'processing' => 65,
            'waiting_customer' => 75,
            'completed' => 100,
            'cancelled' => 0,
            default => 0,
        };
    }

    private function nextNumber(): string
    {
        do {
            $number = 'ORD-IH-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (ServiceOrder::query()->where('order_number', $number)->exists());

        return $number;
    }

    private function statusFromInquiry(string $status): string
    {
        return match ($status) {
            'proses' => 'processing',
            'selesai' => 'completed',
            'batal' => 'cancelled',
            'dihubungi' => 'waiting_approval',
            default => 'lead',
        };
    }

    private function inquiryStatusFromOrder(string $status): string
    {
        return match ($status) {
            'waiting_approval', 'awaiting_payment' => 'dihubungi',
            'document_collection', 'processing', 'waiting_customer' => 'proses',
            'completed' => 'selesai',
            'cancelled' => 'batal',
            default => 'baru',
        };
    }

    private function defaultChecklist(): array
    {
        return [
            ['label' => 'Data pelanggan diverifikasi', 'done' => false],
            ['label' => 'Ruang lingkup layanan disetujui', 'done' => false],
            ['label' => 'Invoice diterbitkan', 'done' => false],
            ['label' => 'Pembayaran diverifikasi', 'done' => false],
            ['label' => 'Dokumen pelanggan lengkap', 'done' => false],
            ['label' => 'Pekerjaan selesai dan diserahkan', 'done' => false],
        ];
    }
}

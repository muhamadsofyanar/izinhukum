<?php

namespace App\Services\WhatsApp;

use App\Models\Commission;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceOrder;
use App\Models\WhatsAppAutomation;
use App\Services\FeatureFlagService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class WhatsAppAutomationService
{
    public function __construct(
        private readonly WhatsAppManager $messages,
        private readonly FeatureFlagService $features,
    ) {
    }

    public function trigger(string $trigger, Model $subject): void
    {
        if (! $this->features->enabled('whatsapp_transactional') || ! Schema::hasTable('whatsapp_automations')) {
            return;
        }

        $automation = WhatsAppAutomation::query()
            ->with('template')
            ->where('trigger', $trigger)
            ->where('is_enabled', true)
            ->first();

        if (! $automation?->template?->is_enabled) {
            return;
        }

        $resolved = $this->resolve($trigger, $subject);
        if (! $resolved || empty($resolved['phone'])) {
            return;
        }

        $scheduledAt = $automation->delay_minutes > 0
            ? now()->addMinutes($automation->delay_minutes)
            : null;

        try {
            $this->messages->queueTemplate(
                $automation->template->key,
                $resolved['phone'],
                $resolved['name'] ?? null,
                $resolved['variables'] ?? [],
                $resolved['relations'] ?? [],
                $resolved['idempotency_key'] ?? null,
                $scheduledAt,
                $resolved['device_alias'] ?? 'transaction',
            );
        } catch (Throwable $exception) {
            // Notifikasi tidak boleh membatalkan transaksi utama seperti order, invoice,
            // pembayaran, atau komisi. Kegagalan dicatat untuk diagnosis terpisah.
            Log::warning('WhatsApp automation could not queue a message.', [
                'trigger' => $trigger,
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function dispatchScheduledReminders(): array
    {
        $result = ['invoice_due_3_days' => 0, 'invoice_due_tomorrow' => 0, 'invoice_overdue' => 0];

        if (! $this->features->enabled('whatsapp_transactional') || ! Schema::hasTable('whatsapp_automations')) {
            return $result;
        }

        $map = [
            'invoice_due_3_days' => now()->addDays(3)->toDateString(),
            'invoice_due_tomorrow' => now()->addDay()->toDateString(),
        ];

        foreach ($map as $trigger => $date) {
            if (! $this->enabled($trigger)) {
                continue;
            }

            Invoice::query()
                ->whereDate('due_date', $date)
                ->whereNotIn('status', ['paid', 'cancelled', 'draft'])
                ->with(['payments', 'serviceOrder'])
                ->chunkById(100, function ($invoices) use ($trigger, &$result): void {
                    foreach ($invoices as $invoice) {
                        $this->trigger($trigger, $invoice);
                        $result[$trigger]++;
                    }
                });
        }

        if ($this->enabled('invoice_overdue')) {
            Invoice::query()
                ->whereDate('due_date', '<', today())
                ->whereNotIn('status', ['paid', 'cancelled', 'draft'])
                ->with(['payments', 'serviceOrder'])
                ->chunkById(100, function ($invoices) use (&$result): void {
                    foreach ($invoices as $invoice) {
                        $this->trigger('invoice_overdue', $invoice);
                        $result['invoice_overdue']++;
                    }
                });
        }

        WhatsAppAutomation::query()
            ->whereIn('trigger', array_keys($result))
            ->where('is_enabled', true)
            ->update(['last_run_at' => now()]);

        return $result;
    }

    private function enabled(string $trigger): bool
    {
        return WhatsAppAutomation::query()->where('trigger', $trigger)->where('is_enabled', true)->exists();
    }

    private function resolve(string $trigger, Model $subject): ?array
    {
        return match (true) {
            $subject instanceof Inquiry => $this->inquiry($trigger, $subject),
            $subject instanceof ServiceOrder => $this->order($trigger, $subject),
            $subject instanceof Invoice => $this->invoice($trigger, $subject),
            $subject instanceof Payment => $this->payment($trigger, $subject),
            $subject instanceof Commission => $this->commission($trigger, $subject),
            default => null,
        };
    }

    private function inquiry(string $trigger, Inquiry $inquiry): array
    {
        $inquiry->loadMissing('package.service');

        return [
            'phone' => $inquiry->phone,
            'name' => $inquiry->name,
            'variables' => [
                'nama_pelanggan' => $inquiry->name,
                'nomor_proposal' => $inquiry->reference,
                'nama_layanan' => $inquiry->package?->service?->name ?: $inquiry->package?->name ?: 'Konsultasi legalitas',
                'tautan_status' => route('tracking.index'),
            ],
            'relations' => ['inquiry_id' => $inquiry->id],
            'idempotency_key' => $trigger.':inquiry:'.$inquiry->id,
        ];
    }

    private function order(string $trigger, ServiceOrder $order): array
    {
        $order->loadMissing('package.service');
        $version = $trigger === 'order_status_changed' ? ':'.$order->status.':'.$order->updated_at?->timestamp : '';

        return [
            'phone' => $order->customer_phone,
            'name' => $order->customer_name,
            'variables' => [
                'nama_pelanggan' => $order->customer_name,
                'nomor_order' => $order->order_number,
                'nama_layanan' => $order->package?->service?->name ?: $order->title,
                'status_order' => $order->statusLabel(),
                'progres' => $order->progress,
                'tautan_portal' => route('customer.orders.show', $order->public_token),
            ],
            'relations' => [
                'service_order_id' => $order->id,
                'inquiry_id' => $order->inquiry_id,
                'partner_id' => $order->referred_by_partner_id,
            ],
            'idempotency_key' => $trigger.':order:'.$order->id.$version,
        ];
    }

    private function invoice(string $trigger, Invoice $invoice): array
    {
        $invoice->loadMissing(['payments', 'serviceOrder']);
        $remaining = max(0, (int) $invoice->total - (int) $invoice->payments->where('status', 'active')->sum('amount'));
        $dayKey = in_array($trigger, ['invoice_due_3_days', 'invoice_due_tomorrow', 'invoice_overdue'], true)
            ? ':'.today()->toDateString()
            : ':'.$invoice->updated_at?->timestamp;

        return [
            'phone' => $invoice->recipient_phone,
            'name' => $invoice->recipient_name,
            'variables' => [
                'nama_pelanggan' => $invoice->recipient_name,
                'nomor_invoice' => $invoice->invoice_number,
                'nama_layanan' => $invoice->serviceOrder?->title ?: 'Layanan legalitas',
                'total_invoice' => $this->money((int) $invoice->total),
                'sisa_tagihan' => $this->money($remaining),
                'jatuh_tempo' => $invoice->due_date?->format('d/m/Y') ?: '-',
                'tautan_invoice' => route('invoices.public', $invoice->public_token),
            ],
            'relations' => [
                'invoice_id' => $invoice->id,
                'service_order_id' => $invoice->service_order_id,
                'inquiry_id' => $invoice->inquiry_id,
                'partner_id' => $invoice->referred_by_partner_id,
            ],
            'idempotency_key' => $trigger.':invoice:'.$invoice->id.$dayKey,
        ];
    }

    private function payment(string $trigger, Payment $payment): ?array
    {
        $payment->loadMissing('invoice.payments');
        $invoice = $payment->invoice;
        if (! $invoice || $payment->status !== 'active') {
            return null;
        }

        $remaining = max(0, (int) $invoice->total - (int) $invoice->payments->where('status', 'active')->sum('amount'));

        return [
            'phone' => $invoice->recipient_phone,
            'name' => $invoice->recipient_name,
            'variables' => [
                'nama_pelanggan' => $invoice->recipient_name,
                'nomor_invoice' => $invoice->invoice_number,
                'jumlah_pembayaran' => $this->money((int) $payment->amount),
                'sisa_tagihan' => $this->money($remaining),
                'tautan_kwitansi' => route('receipts.public', $payment->public_token),
            ],
            'relations' => [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'service_order_id' => $invoice->service_order_id,
                'inquiry_id' => $invoice->inquiry_id,
                'partner_id' => $invoice->referred_by_partner_id,
            ],
            'idempotency_key' => $trigger.':payment:'.$payment->id,
        ];
    }

    private function commission(string $trigger, Commission $commission): ?array
    {
        $commission->loadMissing(['partner', 'invoice']);
        if (! $commission->partner?->phone) {
            return null;
        }

        return [
            'phone' => $commission->partner->phone,
            'name' => $commission->partner->name,
            'variables' => [
                'nama_mitra' => $commission->partner->name,
                'nilai_komisi' => $this->money((int) $commission->amount),
                'nomor_invoice' => $commission->invoice?->invoice_number ?: '-',
            ],
            'relations' => [
                'partner_id' => $commission->partner_id,
                'invoice_id' => $commission->invoice_id,
                'payment_id' => $commission->payment_id,
            ],
            'idempotency_key' => $trigger.':commission:'.$commission->id.':'.$commission->status,
            'device_alias' => 'partner',
        ];
    }

    private function money(int $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }
}

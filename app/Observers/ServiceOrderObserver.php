<?php

namespace App\Observers;

use App\Models\CrmLead;
use App\Models\ServiceOrder;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmSequenceService;
use App\Services\FeatureFlagService;
use App\Services\WhatsApp\WhatsAppAutomationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ServiceOrderObserver
{
    public function created(ServiceOrder $order): void
    {
        DB::afterCommit(function () use ($order): void {
            app(WhatsAppAutomationService::class)->trigger('order_created', $order);
            $this->syncCrm($order, true);
        });
    }

    public function updated(ServiceOrder $order): void
    {
        if ($order->wasChanged('status')) {
            $trigger = $order->status === 'completed' ? 'order_completed' : 'order_status_changed';
            DB::afterCommit(fn (): mixed => app(WhatsAppAutomationService::class)->trigger($trigger, $order));
        }

        if ($order->wasChanged(['status', 'customer_name', 'customer_phone'])) {
            DB::afterCommit(fn (): mixed => $this->syncCrm($order, false));
        }
    }

    private function syncCrm(ServiceOrder $order, bool $created): void
    {
        $features = app(FeatureFlagService::class);
        if (! $features->enabled('crm_contacts') || ! Schema::hasTable('crm_contacts')) {
            return;
        }

        $order->loadMissing(['package.service', 'inquiry']);
        $serviceName = $order->package?->service?->name ?: $order->package?->name ?: data_get($order, 'service_name');
        $contacts = app(CrmContactService::class);
        $contact = $contacts->upsertFromWhatsApp(
            (string) $order->customer_phone,
            $order->customer_name,
            $order->inquiry_id ? 'website' : 'order',
            ['service_order_id' => $order->id, 'order_number' => $order->order_number],
        );
        if (! $contact) {
            return;
        }

        $contact->forceFill([
            'email' => data_get($order, 'customer_email') ?: $contact->email,
            'service_interest' => $serviceName ?: $contact->service_interest,
            'lifecycle_stage' => 'customer',
        ])->save();
        $contacts->attachLabel($contact, 'Sudah Deal', 'status', '#16a34a');

        if (! $features->enabled('crm_leads') || ! Schema::hasTable('crm_leads')) {
            return;
        }

        $lead = CrmLead::query()
            ->when($order->inquiry_id, fn ($query) => $query->where('inquiry_id', $order->inquiry_id))
            ->where('contact_id', $contact->id)
            ->latest('id')
            ->first();

        $stage = match ($order->status) {
            'lead', 'waiting_approval', 'awaiting_payment' => 'deal',
            'document_collection', 'waiting_customer' => 'waiting_requirements',
            'processing' => 'processing',
            'completed' => 'completed',
            'cancelled' => 'lost',
            default => 'deal',
        };
        if ($lead) {
            $lead->forceFill([
                'service_order_id' => $order->id,
                'stage' => $stage,
                'service_interest' => $serviceName ?: $lead->service_interest,
                'probability' => 100,
                'closed_at' => in_array($stage, ['completed', 'lost'], true) ? now() : null,
            ])->save();
        } else {
            $lead = $contacts->createLead($contact, [
                'inquiry_id' => $order->inquiry_id,
                'service_order_id' => $order->id,
                'title' => 'Order '.$order->order_number.' - '.$order->customer_name,
                'source' => $order->inquiry_id ? 'website' : 'order',
                'stage' => $stage,
                'service_interest' => $serviceName,
                'probability' => 100,
                'metadata' => ['created_from_order' => $created],
            ]);
        }

        if ($features->enabled('crm_sequences') && Schema::hasTable('crm_sequence_enrollments')) {
            app(CrmSequenceService::class)->stopOnDeal($contact);
        }
    }
}

<?php

namespace App\Observers;

use App\Models\CrmLead;
use App\Models\Inquiry;
use App\Services\Crm\CrmContactService;
use App\Services\FeatureFlagService;
use App\Services\WhatsApp\WhatsAppAutomationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InquiryObserver
{
    public function created(Inquiry $inquiry): void
    {
        DB::afterCommit(function () use ($inquiry): void {
            app(WhatsAppAutomationService::class)->trigger('proposal_received', $inquiry);

            $features = app(FeatureFlagService::class);
            if (! $features->enabled('crm_contacts') || ! Schema::hasTable('crm_contacts')) {
                return;
            }

            $inquiry->loadMissing('package.service');
            $serviceName = $inquiry->package?->service?->name ?: $inquiry->package?->name ?: null;
            $contact = app(CrmContactService::class)->upsertFromWhatsApp(
                (string) $inquiry->phone,
                $inquiry->name,
                'website',
                ['inquiry_id' => $inquiry->id, 'reference' => $inquiry->reference],
            );
            if (! $contact) {
                return;
            }

            $contact->forceFill([
                'email' => $inquiry->email ?: $contact->email,
                'service_interest' => $serviceName ?: $contact->service_interest,
                'lifecycle_stage' => 'lead',
            ])->save();
            app(CrmContactService::class)->attachLabel($contact, 'Website', 'source', '#0284c7');

            if (! $features->enabled('crm_leads') || ! Schema::hasTable('crm_leads')) {
                return;
            }

            CrmLead::query()->firstOrCreate(
                ['inquiry_id' => $inquiry->id],
                [
                    'contact_id' => $contact->id,
                    'title' => 'Permintaan '.$serviceName.' - '.$inquiry->name,
                    'source' => 'website',
                    'stage' => 'new',
                    'service_interest' => $serviceName,
                    'probability' => 20,
                    'metadata' => ['inquiry_reference' => $inquiry->reference],
                ],
            );
        });
    }
}

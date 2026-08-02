<?php

namespace App\Services;

use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\Inquiry;
use App\Services\Crm\CrmContactService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesPipelineService
{
    public function __construct(
        private readonly CrmContactService $contacts,
        private readonly LeadScoringService $scoring,
    ) {
    }

    public function syncInquiry(Inquiry $inquiry, ?int $assignedTo = null): ?CrmLead
    {
        if (! Schema::hasTable('crm_contacts') || ! Schema::hasTable('crm_leads')) {
            return null;
        }

        $inquiry->loadMissing(['package.service', 'serviceOrder']);
        $source = $inquiry->referred_by_partner_id
            ? 'referral'
            : ($inquiry->utm_source ?: $inquiry->source ?: 'website');
        $contact = $this->contacts->upsertFromWhatsApp(
            $inquiry->phone,
            $inquiry->name,
            $source === 'referral' ? 'referral' : 'website',
            [
                'inquiry_reference' => $inquiry->reference,
                'utm_source' => $inquiry->utm_source,
                'utm_campaign' => $inquiry->utm_campaign,
            ],
        );

        if (! $contact) {
            return null;
        }

        $contact->forceFill([
            'email' => $inquiry->email ?: $contact->email,
            'company' => $inquiry->company_name ?: $contact->company,
            'service_interest' => $inquiry->package?->name ?: $contact->service_interest,
        ])->save();

        return DB::transaction(function () use ($inquiry, $contact, $source, $assignedTo): CrmLead {
            $lead = CrmLead::query()->firstOrNew(['inquiry_id' => $inquiry->id]);
            if (! $lead->exists) {
                $lead->contact_id = $contact->id;
                $lead->title = ($inquiry->package?->name ?: 'Konsultasi legalitas').' · '.$inquiry->name;
                $lead->source = $source;
                $lead->stage = $this->stageForInquiry($inquiry->status);
                $lead->service_interest = $inquiry->package?->name;
                $lead->estimated_value = max(0, (int) ($inquiry->package?->price ?? 0) - $inquiry->coupon_discount_amount);
                $lead->probability = $this->probabilityForStage($lead->stage);
                $lead->lead_score = $this->scoring->scoreInquiry($inquiry);
                $lead->temperature = $this->scoring->temperature($lead->lead_score);
                $lead->assigned_to = $assignedTo;
                $lead->last_stage_changed_at = now();
                $lead->metadata = [
                    'utm_source' => $inquiry->utm_source,
                    'utm_medium' => $inquiry->utm_medium,
                    'utm_campaign' => $inquiry->utm_campaign,
                    'coupon_code' => $inquiry->coupon_code,
                    'referral_code' => $inquiry->referral_code,
                ];
            }
            $lead->service_order_id = $inquiry->serviceOrder?->id ?: $lead->service_order_id;
            $lead->save();
            $score = $this->scoring->scoreInquiry($inquiry);
            $lead->forceFill([
                'lead_score' => $score,
                'temperature' => $this->scoring->temperature($score),
            ])->save();

            CrmActivity::query()->firstOrCreate(
                [
                    'lead_id' => $lead->id,
                    'type' => 'inquiry_received',
                    'title' => 'Permintaan website diterima',
                ],
                [
                    'contact_id' => $contact->id,
                    'service_order_id' => $inquiry->serviceOrder?->id,
                    'description' => $inquiry->reference.' · '.($inquiry->message ?: 'Tanpa catatan tambahan.'),
                ],
            );

            return $lead->fresh(['contact', 'inquiry', 'serviceOrder']);
        });
    }

    public function backfill(): int
    {
        $count = 0;
        Inquiry::query()
            ->whereDoesntHave('crmLead')
            ->orderBy('id')
            ->chunkById(100, function ($inquiries) use (&$count): void {
                foreach ($inquiries as $inquiry) {
                    if ($this->syncInquiry($inquiry)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function stageForInquiry(string $status): string
    {
        return match ($status) {
            'dihubungi' => 'qualified',
            'proses' => 'processing',
            'selesai' => 'completed',
            'batal' => 'lost',
            default => 'new',
        };
    }

    public function probabilityForStage(string $stage): int
    {
        return match ($stage) {
            'questioning' => 20,
            'qualified' => 35,
            'proposal' => 55,
            'deal', 'waiting_requirements' => 80,
            'processing' => 90,
            'completed' => 100,
            'lost' => 0,
            default => 10,
        };
    }
}

<?php

namespace App\Services\Crm;

use App\Models\CrmContact;
use App\Models\CrmLabel;
use App\Models\CrmLead;
use App\Models\WhatsAppConversation;
use App\Services\WhatsApp\PhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CrmContactService
{
    public function __construct(private readonly PhoneNumberNormalizer $phones)
    {
    }

    public function upsertFromWhatsApp(
        string $phone,
        ?string $name = null,
        string $source = 'whatsapp',
        array $metadata = [],
    ): ?CrmContact {
        if (! Schema::hasTable('crm_contacts')) {
            return null;
        }

        $normalized = $this->phones->normalize($phone);
        if (! $normalized) {
            return null;
        }

        return DB::transaction(function () use ($normalized, $name, $source, $metadata): CrmContact {
            $contact = CrmContact::query()->firstOrNew(['phone' => $normalized]);
            $contact->name = filled($name) ? trim((string) $name) : ($contact->name ?: null);
            $contact->source = $contact->exists ? ($contact->source ?: $source) : $source;
            $contact->status = $contact->status ?: 'active';
            $contact->lifecycle_stage = $contact->lifecycle_stage ?: 'contact';
            $contact->last_contact_at = now();
            $contact->metadata = array_replace((array) $contact->metadata, $metadata);
            $contact->save();

            if (str_starts_with($source, 'whatsapp')) {
                $this->attachLabel($contact, 'WhatsApp', 'source', '#16a34a');
            } elseif ($source === 'website') {
                $this->attachLabel($contact, 'Website', 'source', '#0284c7');
            } elseif ($source === 'referral') {
                $this->attachLabel($contact, 'Referral', 'source', '#0891b2');
            }

            if (! $contact->wasRecentlyCreated) {
                return $contact;
            }

            $this->attachLabel($contact, 'Lead Baru', 'status', '#2563eb');
            return $contact;
        });
    }

    public function attachLabel(
        CrmContact $contact,
        string $name,
        string $category = 'custom',
        string $color = '#0f766e',
        ?int $assignedBy = null,
    ): CrmLabel {
        $label = CrmLabel::query()->firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => trim($name), 'category' => $category, 'color' => $color, 'is_active' => true],
        );

        $contact->labels()->syncWithoutDetaching([
            $label->id => ['assigned_by' => $assignedBy, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return $label;
    }

    public function linkConversation(WhatsAppConversation $conversation, CrmContact $contact): void
    {
        $conversation->forceFill([
            'contact_id' => $contact->id,
            'display_name' => $contact->name ?: $conversation->display_name,
        ])->save();
    }

    public function createLead(
        CrmContact $contact,
        array $data,
        ?int $userId = null,
    ): CrmLead {
        return DB::transaction(function () use ($contact, $data, $userId): CrmLead {
            $lead = CrmLead::query()->create([
                'contact_id' => $contact->id,
                'inquiry_id' => $data['inquiry_id'] ?? null,
                'service_order_id' => $data['service_order_id'] ?? null,
                'title' => trim((string) ($data['title'] ?? ('Lead '.$contact->name))),
                'source' => $data['source'] ?? $contact->source ?? 'manual',
                'stage' => $data['stage'] ?? 'new',
                'service_interest' => $data['service_interest'] ?? $contact->service_interest,
                'estimated_value' => $data['estimated_value'] ?? 0,
                'probability' => $data['probability'] ?? 10,
                'assigned_to' => $data['assigned_to'] ?? $userId,
                'next_follow_up_at' => $data['next_follow_up_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            $contact->forceFill([
                'lifecycle_stage' => $lead->stage === 'deal' ? 'customer' : 'lead',
                'service_interest' => $lead->service_interest ?: $contact->service_interest,
                'assigned_to' => $lead->assigned_to ?: $contact->assigned_to,
                'next_follow_up_at' => $lead->next_follow_up_at,
            ])->save();

            $this->attachLabel($contact, $lead->stage === 'deal' ? 'Sudah Deal' : 'Calon Klien', 'status', $lead->stage === 'deal' ? '#16a34a' : '#0f766e', $userId);

            return $lead;
        });
    }
}

<?php

namespace App\Services\Crm;

use App\Models\CrmContact;
use App\Models\CrmSequence;
use App\Models\CrmDocument;
use App\Models\CrmSequenceDispatch;
use App\Models\CrmSequenceEnrollment;
use App\Models\CrmSequenceStep;
use App\Models\WhatsAppGroup;
use App\Services\FeatureFlagService;
use App\Services\WhatsApp\WhatsAppManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CrmSequenceService
{
    public function __construct(
        private readonly WhatsAppManager $messages,
        private readonly FeatureFlagService $features,
        private readonly CrmDocumentService $documents,
    ) {
    }

    public function enrollContact(CrmSequence $sequence, CrmContact $contact, array $metadata = []): CrmSequenceEnrollment
    {
        $existing = CrmSequenceEnrollment::query()
            ->where('sequence_id', $sequence->id)
            ->where('contact_id', $contact->id)
            ->whereIn('status', ['active', 'paused'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $first = $sequence->steps()->orderBy('position')->first();
        return CrmSequenceEnrollment::query()->create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'status' => $this->features->enabled('crm_sequences') && $sequence->is_active && $first ? 'active' : 'paused',
            'current_step' => 0,
            'next_run_at' => $first ? $this->dueAt(now(), $first) : null,
            'started_at' => now(),
            'paused_at' => $this->features->enabled('crm_sequences') && $sequence->is_active ? null : now(),
            'metadata' => $metadata,
        ]);
    }

    public function enrollGroupPreset(CrmSequence $sequence, int $presetId, array $metadata = []): CrmSequenceEnrollment
    {
        $existing = CrmSequenceEnrollment::query()
            ->where('sequence_id', $sequence->id)
            ->where('group_preset_id', $presetId)
            ->whereIn('status', ['active', 'paused'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $first = $sequence->steps()->orderBy('position')->first();
        return CrmSequenceEnrollment::query()->create([
            'sequence_id' => $sequence->id,
            'group_preset_id' => $presetId,
            'status' => $this->features->enabled('crm_sequences') && $sequence->is_active && $first ? 'active' : 'paused',
            'current_step' => 0,
            'next_run_at' => $first ? $this->dueAt(now(), $first) : null,
            'started_at' => now(),
            'paused_at' => $this->features->enabled('crm_sequences') && $sequence->is_active ? null : now(),
            'metadata' => $metadata,
        ]);
    }

    public function dispatchDue(int $limit = 200): array
    {
        if (! $this->features->enabled('crm_sequences') || ! Schema::hasTable('crm_sequence_enrollments')) {
            return ['processed' => 0, 'queued' => 0, 'failed' => 0];
        }

        $result = ['processed' => 0, 'queued' => 0, 'failed' => 0];
        CrmSequenceEnrollment::query()
            ->due()
            ->with(['sequence.steps.template', 'sequence.steps.document', 'contact', 'groupPreset'])
            ->orderBy('next_run_at')
            ->limit(max(1, $limit))
            ->get()
            ->each(function (CrmSequenceEnrollment $enrollment) use (&$result): void {
                $result['processed']++;
                try {
                    $result['queued'] += $this->dispatchEnrollment($enrollment);
                } catch (Throwable $exception) {
                    $result['failed']++;
                    $enrollment->forceFill([
                        'status' => 'paused',
                        'paused_at' => now(),
                        'stopped_reason' => 'Gagal menjadwalkan langkah: '.$exception->getMessage(),
                    ])->save();
                }
            });

        return $result;
    }

    public function stopOnReply(CrmContact $contact): int
    {
        return CrmSequenceEnrollment::query()
            ->where('contact_id', $contact->id)
            ->where('status', 'active')
            ->whereHas('sequence', fn ($query) => $query->where('stop_on_reply', true))
            ->update([
                'status' => 'stopped',
                'completed_at' => now(),
                'stopped_reason' => 'Dihentikan otomatis karena kontak membalas.',
                'next_run_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function stopOnDeal(CrmContact $contact): int
    {
        return CrmSequenceEnrollment::query()
            ->where('contact_id', $contact->id)
            ->where('status', 'active')
            ->whereHas('sequence', fn ($query) => $query->where('stop_on_deal', true))
            ->update([
                'status' => 'stopped',
                'completed_at' => now(),
                'stopped_reason' => 'Dihentikan otomatis karena lead sudah deal.',
                'next_run_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function dispatchEnrollment(CrmSequenceEnrollment $enrollment): int
    {
        $sequence = $enrollment->sequence;
        if (! $sequence || ! $sequence->is_active) {
            $enrollment->forceFill(['status' => 'paused', 'paused_at' => now(), 'next_run_at' => null])->save();
            return 0;
        }

        $step = $sequence->steps->firstWhere('position', $enrollment->current_step + 1);
        if (! $step) {
            $enrollment->forceFill(['status' => 'completed', 'completed_at' => now(), 'next_run_at' => null])->save();
            return 0;
        }

        return DB::transaction(function () use ($enrollment, $sequence, $step): int {
            $dispatch = CrmSequenceDispatch::query()->firstOrCreate(
                ['enrollment_id' => $enrollment->id, 'step_id' => $step->id],
                ['status' => 'pending', 'scheduled_at' => now()],
            );

            if ($dispatch->whatsapp_message_id) {
                $this->advance($enrollment, $step);
                return 0;
            }

            $queued = $enrollment->contact_id
                ? $this->dispatchContact($enrollment, $sequence, $step)
                : $this->dispatchPreset($enrollment, $sequence, $step);

            $dispatch->forceFill([
                'status' => $queued > 0 ? 'queued' : 'skipped',
                'dispatched_at' => now(),
            ])->save();
            $this->advance($enrollment, $step);

            return $queued;
        });
    }

    private function dispatchContact(CrmSequenceEnrollment $enrollment, CrmSequence $sequence, CrmSequenceStep $step): int
    {
        $contact = $enrollment->contact;
        if (! $contact || $contact->is_opted_out) {
            return 0;
        }

        $step->loadMissing('template');
        $relations = [
            'contact_id' => $contact->id,
            'conversation_id' => $contact->conversations()->latest('last_message_at')->value('id'),
            'created_by' => $sequence->created_by,
            'metadata' => [
                'source' => 'crm_sequence',
                'sequence_id' => $sequence->id,
                'sequence_step_id' => $step->id,
                'enrollment_id' => $enrollment->id,
            ],
        ];
        $mediaUrl = $this->mediaUrlForStep($step, $sequence->created_by);
        $variables = [
            'nama' => $contact->name ?: 'Bapak/Ibu',
            'nama_pelanggan' => $contact->name ?: 'Bapak/Ibu',
            'nomor_whatsapp' => $contact->phone,
            'layanan' => $contact->service_interest ?: 'layanan legalitas',
            'perusahaan' => $contact->company ?: '',
        ];
        $message = $step->template
            ? $this->messages->queueTemplate(
                $step->template->key,
                $contact->phone,
                $contact->name,
                $variables,
                $relations,
                'crm-sequence:'.$enrollment->id.':'.$step->id,
                null,
                $sequence->device_alias,
            )
            : $this->messages->queueRaw([
                ...$relations,
                'phone' => $contact->phone,
                'recipient_name' => $contact->name,
                'message_type' => $step->message_type,
                'body' => $this->render($step->body, $contact),
                'media_url' => $mediaUrl,
                'device_alias' => $sequence->device_alias,
                'idempotency_key' => 'crm-sequence:'.$enrollment->id.':'.$step->id,
            ]);

        if ($message) {
            CrmSequenceDispatch::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('step_id', $step->id)
                ->update(['whatsapp_message_id' => $message->id]);
            return 1;
        }

        return 0;
    }

    private function dispatchPreset(CrmSequenceEnrollment $enrollment, CrmSequence $sequence, CrmSequenceStep $step): int
    {
        $preset = $enrollment->groupPreset;
        if (! $preset) {
            return 0;
        }

        $groups = WhatsAppGroup::query()
            ->where('device_alias', $preset->device_alias)
            ->whereIn('id', array_map('intval', (array) $preset->group_ids))
            ->where('is_active', true)
            ->get();

        $queued = 0;
        $mediaUrl = $this->mediaUrlForStep($step, $sequence->created_by);
        foreach ($groups->values() as $index => $group) {
            $step->loadMissing('template');
            $relations = [
                'metadata' => [
                    'source' => 'crm_sequence_group',
                    'sequence_id' => $sequence->id,
                    'sequence_step_id' => $step->id,
                    'enrollment_id' => $enrollment->id,
                    'group_preset_id' => $preset->id,
                ],
            ];
            $message = $step->template
                ? $this->messages->queueTemplate(
                    $step->template->key,
                    $group->group_jid,
                    $group->name,
                    ['nama_grup' => $group->name],
                    $relations,
                    'crm-sequence:'.$enrollment->id.':'.$step->id.':group:'.$group->id,
                    null,
                    $sequence->device_alias,
                    'group',
                )
                : $this->messages->queueRaw([
                    ...$relations,
                    'phone' => $group->group_jid,
                    'recipient_name' => $group->name,
                    'channel' => 'group',
                    'message_type' => $step->message_type,
                    'body' => $step->body,
                    'media_url' => $mediaUrl,
                    'device_alias' => $sequence->device_alias,
                    'scheduled_at' => now()->addSeconds($index * max(1, (int) $sequence->group_interval_seconds)),
                    'idempotency_key' => 'crm-sequence:'.$enrollment->id.':'.$step->id.':group:'.$group->id,
                ]);
            $queued += $message ? 1 : 0;
        }

        return $queued;
    }

    private function advance(CrmSequenceEnrollment $enrollment, CrmSequenceStep $step): void
    {
        $next = $enrollment->sequence->steps->firstWhere('position', $step->position + 1);
        $enrollment->forceFill([
            'current_step' => $step->position,
            'status' => $next ? 'active' : 'completed',
            'next_run_at' => $next ? $this->dueAt(now(), $next) : null,
            'completed_at' => $next ? null : now(),
        ])->save();
    }

    private function dueAt($base, CrmSequenceStep $step): Carbon
    {
        $date = Carbon::parse($base);
        $value = max(0, (int) $step->delay_value);
        $date = match ($step->delay_unit) {
            'minute' => $date->addMinutes($value),
            'hour' => $date->addHours($value),
            default => $date->addDays($value),
        };

        if (filled($step->send_time)) {
            [$hour, $minute] = array_map('intval', explode(':', substr((string) $step->send_time, 0, 5)));
            $date->setTime($hour, $minute);
            if ($date->isPast()) {
                $date->addDay();
            }
        }

        return $date;
    }

    private function mediaUrlForStep(CrmSequenceStep $step, ?int $createdBy): ?string
    {
        $step->loadMissing('document');
        if ($step->document instanceof CrmDocument && $this->documents->pathExists($step->document)) {
            return $this->documents->issueProviderAccess($step->document, 180, $createdBy);
        }

        return filled($step->media_url) ? $step->media_url : null;
    }

    private function render(?string $body, CrmContact $contact): ?string
    {
        if ($body === null) {
            return null;
        }

        return strtr($body, [
            '{{nama}}' => $contact->name ?: 'Bapak/Ibu',
            '{{nama_pelanggan}}' => $contact->name ?: 'Bapak/Ibu',
            '{{nomor_whatsapp}}' => $contact->phone,
            '{{layanan}}' => $contact->service_interest ?: 'layanan legalitas',
            '{{perusahaan}}' => $contact->company ?: '',
        ]);
    }
}

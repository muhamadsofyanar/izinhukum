<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use App\Services\LeadScoringService;
use App\Services\SalesMessageRenderer;
use App\Services\SalesPipelineService;
use App\Services\ServiceOrderService;
use App\Services\FeatureFlagService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Models\SalesMessageTemplate;

class SalesPipelineController extends Controller
{
    public function index(Request $request, FeatureFlagService $features): View
    {
        $search = trim((string) $request->query('q'));
        $stage = trim((string) $request->query('stage'));
        $temperature = trim((string) $request->query('temperature'));
        $recovery = $request->boolean('recovery');
        $leads = CrmLead::query()
            ->with(['contact', 'inquiry.package.service', 'serviceOrder', 'assignee', 'activities.user'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $builder) use ($search): void {
                    $builder->where('title', 'like', '%'.$search.'%')
                        ->orWhere('service_interest', 'like', '%'.$search.'%')
                        ->orWhereHas('contact', fn (Builder $contact) => $contact
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('phone', 'like', '%'.$search.'%')
                            ->orWhere('company', 'like', '%'.$search.'%'));
                });
            })
            ->when($stage !== '', fn (Builder $query) => $query->where('stage', $stage))
            ->when($features->enabled('lead_prioritization') && $temperature !== '', fn (Builder $query) => $query->where('temperature', $temperature))
            ->when($features->enabled('lead_recovery') && $recovery, fn (Builder $query) => $query
                ->where('stage', 'lost')
                ->whereNotNull('reactivate_at')
                ->where('reactivate_at', '<=', now()))
            ->orderByRaw("CASE stage WHEN 'new' THEN 1 WHEN 'questioning' THEN 2 WHEN 'qualified' THEN 3 WHEN 'proposal' THEN 4 WHEN 'deal' THEN 5 WHEN 'waiting_requirements' THEN 6 WHEN 'processing' THEN 7 WHEN 'completed' THEN 8 ELSE 9 END")
            ->orderByRaw('CASE WHEN next_follow_up_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_follow_up_at')
            ->orderByDesc('lead_score')
            ->paginate(24)
            ->withQueryString();

        return view('admin.pipeline.index', [
            'leads' => $leads,
            'stages' => CrmLead::STAGES,
            'stage' => $stage,
            'search' => $search,
            'temperature' => $temperature,
            'temperatures' => CrmLead::TEMPERATURES,
            'lossReasons' => CrmLead::LOSS_REASONS,
            'recovery' => $recovery,
            'admins' => User::query()->where('role', 'admin')->where('is_active', true)->orderBy('name')->get(),
            'messageTemplates' => $features->enabled('manual_sales_playbooks')
                ? SalesMessageTemplate::query()->where('is_active', true)->orderBy('sort_order')->get()
                : collect(),
            'playbooksEnabled' => $features->enabled('manual_sales_playbooks'),
            'leadPrioritizationEnabled' => $features->enabled('lead_prioritization'),
            'leadRecoveryEnabled' => $features->enabled('lead_recovery'),
            'quotesEnabled' => $features->enabled('digital_quotes'),
            'summary' => CrmLead::query()->selectRaw('stage, COUNT(*) as aggregate')->groupBy('stage')->pluck('aggregate', 'stage'),
            'dueFollowUps' => CrmLead::query()
                ->whereNotIn('stage', ['completed', 'lost'])
                ->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<=', now())
                ->count(),
            'hotLeads' => CrmLead::query()->where('temperature', 'hot')->whereNotIn('stage', ['completed', 'lost'])->count(),
            'recoveryDue' => CrmLead::query()->where('stage', 'lost')->whereNotNull('reactivate_at')->where('reactivate_at', '<=', now())->count(),
        ]);
    }

    public function store(
        Request $request,
        CrmContactService $contacts,
        LeadScoringService $scoring,
    ): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:160'],
            'company' => ['nullable', 'string', 'max:180'],
            'service_interest' => ['nullable', 'string', 'max:160'],
            'source' => ['required', Rule::in(['whatsapp', 'website', 'ads', 'referral', 'manual'])],
            'estimated_value' => ['nullable', 'integer', 'min:0'],
            'next_follow_up_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);
        $actor = $request->attributes->get('currentUser');
        $contact = $contacts->upsertFromWhatsApp($data['phone'], $data['name'], $data['source']);
        if (! $contact) {
            return back()->withErrors(['phone' => 'Nomor telepon tidak dapat dinormalisasi.'])->withInput();
        }
        $contact->update([
            'email' => $data['email'] ?? $contact->email,
            'company' => $data['company'] ?? $contact->company,
            'service_interest' => $data['service_interest'] ?? $contact->service_interest,
        ]);
        $lead = $contacts->createLead($contact, [
            'title' => ($data['service_interest'] ?: 'Konsultasi legalitas').' · '.$data['name'],
            'source' => $data['source'],
            'stage' => 'new',
            'service_interest' => $data['service_interest'] ?? null,
            'estimated_value' => $data['estimated_value'] ?? 0,
            'probability' => 10,
            'assigned_to' => $actor?->id,
            'next_follow_up_at' => $data['next_follow_up_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], $actor?->id);
        $scoring->refresh($lead);
        CrmActivity::query()->create([
            'contact_id' => $contact->id,
            'lead_id' => $lead->id,
            'user_id' => $actor?->id,
            'type' => 'lead_created',
            'title' => 'Lead manual dibuat',
            'description' => $data['notes'] ?? null,
        ]);

        return redirect()->route('admin.pipeline.index')->with('success', 'Lead '.$data['name'].' berhasil dibuat.');
    }

    public function update(
        Request $request,
        CrmLead $lead,
        SalesPipelineService $pipeline,
        ServiceOrderService $orders,
        LeadScoringService $scoring,
    ): RedirectResponse {
        $data = $request->validate([
            'stage' => ['required', Rule::in(array_keys(CrmLead::STAGES))],
            'service_interest' => ['nullable', 'string', 'max:160'],
            'estimated_value' => ['nullable', 'integer', 'min:0'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'next_follow_up_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'lost_reason' => ['nullable', 'string', 'max:2000'],
            'loss_reason_code' => ['nullable', Rule::in(array_keys(CrmLead::LOSS_REASONS))],
            'reactivate_at' => ['nullable', 'date'],
        ]);
        $oldStage = $lead->stage;
        $data['probability'] = $data['probability'] ?? $pipeline->probabilityForStage($data['stage']);
        $data['closed_at'] = in_array($data['stage'], ['completed', 'lost'], true) ? now() : null;
        if ($oldStage !== $data['stage']) {
            $data['last_stage_changed_at'] = now();
        }
        if (in_array($data['stage'], ['deal', 'waiting_requirements', 'processing', 'completed'], true)) {
            $data['won_at'] = $lead->won_at ?: now();
        }
        if ($data['stage'] !== 'lost') {
            $data['lost_reason'] = null;
            $data['loss_reason_code'] = null;
            $data['reactivate_at'] = null;
        } elseif (app(FeatureFlagService::class)->enabled('lead_recovery') && empty($data['loss_reason_code'])) {
            return back()->withErrors(['loss_reason_code' => 'Pilih alasan lead tidak lanjut.'])->withInput();
        }
        $lead->update($data);
        $lead = $scoring->refresh($lead);
        $lead->loadMissing(['contact', 'inquiry', 'serviceOrder']);
        $lead->contact?->update([
            'lifecycle_stage' => in_array($lead->stage, ['deal', 'waiting_requirements', 'processing', 'completed'], true) ? 'customer' : 'lead',
            'service_interest' => $lead->service_interest,
            'assigned_to' => $lead->assigned_to,
            'next_follow_up_at' => $lead->next_follow_up_at,
        ]);

        $inquiryStatus = match ($lead->stage) {
            'new' => 'baru',
            'questioning', 'qualified', 'proposal' => 'dihubungi',
            'deal', 'waiting_requirements', 'processing' => 'proses',
            'completed' => 'selesai',
            'lost' => 'batal',
            default => 'baru',
        };
        $lead->inquiry?->update(['status' => $inquiryStatus]);

        if ($lead->serviceOrder) {
            $orderStatus = match ($lead->stage) {
                'deal' => 'awaiting_payment',
                'waiting_requirements' => 'document_collection',
                'processing' => 'processing',
                'completed' => 'completed',
                'lost' => 'cancelled',
                'proposal', 'qualified', 'questioning' => 'waiting_approval',
                default => 'lead',
            };
            if ($lead->serviceOrder->status !== $orderStatus) {
                $orders->update($lead->serviceOrder, [
                    'status' => $orderStatus,
                    'progress' => $orders->progressForStatus($orderStatus),
                ], $request->attributes->get('currentUser'));
            }
        }

        CrmActivity::query()->create([
            'contact_id' => $lead->contact_id,
            'lead_id' => $lead->id,
            'service_order_id' => $lead->service_order_id,
            'user_id' => $request->attributes->get('currentUser')?->id,
            'type' => 'stage_changed',
            'title' => 'Tahap penjualan diperbarui',
            'description' => ($oldStage !== $lead->stage ? (CrmLead::STAGES[$oldStage] ?? $oldStage).' → ' : '').$lead->stageLabel(),
            'due_at' => $lead->next_follow_up_at,
        ]);

        return back()->with('success', 'Pipeline dan status order berhasil diperbarui.');
    }

    public function addActivity(Request $request, CrmLead $lead): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['note', 'contacted', 'call', 'follow_up', 'meeting'])],
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:10000'],
            'due_at' => ['nullable', 'date'],
        ]);
        $activity = CrmActivity::query()->create([
            ...$data,
            'contact_id' => $lead->contact_id,
            'lead_id' => $lead->id,
            'service_order_id' => $lead->service_order_id,
            'user_id' => $request->attributes->get('currentUser')?->id,
            'completed_at' => $data['type'] === 'follow_up' ? null : now(),
        ]);
        if ($data['type'] === 'follow_up') {
            $lead->update(['next_follow_up_at' => $data['due_at'] ?? null]);
        } else {
            $lead->contact?->update(['last_contact_at' => now()]);
            if (! $lead->first_contacted_at && in_array($data['type'], ['contacted', 'call', 'meeting'], true)) {
                $lead->update([
                    'first_contacted_at' => now(),
                    'response_minutes' => (int) round(max(0, $lead->created_at->diffInMinutes(now()))),
                ]);
            }
        }

        return back()->with('success', 'Aktivitas penjualan berhasil dicatat.');
    }

    public function completeActivity(Request $request, CrmActivity $activity): RedirectResponse
    {
        $activity->update([
            'completed_at' => $activity->completed_at ?: now(),
            'user_id' => $activity->user_id ?: $request->attributes->get('currentUser')?->id,
        ]);
        if ($activity->lead_id && $activity->due_at) {
            CrmLead::query()->whereKey($activity->lead_id)->update(['next_follow_up_at' => null]);
        }

        return back()->with('success', 'Follow-up ditandai selesai.');
    }

    public function sync(SalesPipelineService $pipeline): RedirectResponse
    {
        $created = $pipeline->backfill();

        return back()->with('success', $created.' permintaan lama ditambahkan ke pipeline.');
    }

    public function whatsapp(
        Request $request,
        CrmLead $lead,
        SalesMessageTemplate $template,
        SalesMessageRenderer $renderer,
    ): RedirectResponse {
        abort_unless($template->is_active, 404);
        $lead->loadMissing('contact');
        abort_unless(filled($lead->contact?->phone), 422, 'Lead tidak memiliki nomor WhatsApp.');
        CrmActivity::query()->create([
            'contact_id' => $lead->contact_id,
            'lead_id' => $lead->id,
            'service_order_id' => $lead->service_order_id,
            'user_id' => $request->attributes->get('currentUser')?->id,
            'type' => 'message_prepared',
            'title' => 'Pesan WhatsApp disiapkan',
            'description' => $template->name.' · Pengiriman tetap dilakukan manual oleh admin.',
            'completed_at' => now(),
        ]);

        return redirect()->away($renderer->whatsappUrl(
            $template,
            $lead,
            $request->attributes->get('currentUser')?->name,
        ));
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmActivity;
use App\Models\CrmContact;
use App\Models\CrmLead;
use App\Models\CrmRequirement;
use App\Models\CrmRequirementTemplate;
use App\Models\ServiceOrder;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmSequenceService;
use App\Services\ServiceOrderService;
use App\Services\WhatsApp\WhatsAppAuditService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WhatsAppLeadController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $stage = trim((string) $request->query('stage'));
        $leads = CrmLead::query()
            ->with(['contact.labels', 'assignee', 'serviceOrder', 'requirements'])
            ->withCount(['requirements', 'documents'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $builder) use ($search): void {
                    $builder->where('title', 'like', '%'.$search.'%')
                        ->orWhere('service_interest', 'like', '%'.$search.'%')
                        ->orWhereHas('contact', fn (Builder $contact) => $contact
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('phone', 'like', '%'.$search.'%'));
                });
            })
            ->when($stage !== '', fn (Builder $query) => $query->where('stage', $stage))
            ->orderByRaw("CASE stage WHEN 'new' THEN 1 WHEN 'questioning' THEN 2 WHEN 'qualified' THEN 3 WHEN 'proposal' THEN 4 WHEN 'deal' THEN 5 WHEN 'waiting_requirements' THEN 6 WHEN 'processing' THEN 7 WHEN 'completed' THEN 8 ELSE 9 END")
            ->orderByDesc('updated_at')
            ->paginate(40)
            ->withQueryString();

        return view('admin.whatsapp.leads.index', [
            'leads' => $leads,
            'stages' => CrmLead::STAGES,
            'stage' => $stage,
            'search' => $search,
            'contacts' => CrmContact::query()->orderBy('name')->limit(500)->get(),
            'admins' => User::query()->where('role', 'admin')->where('is_active', true)->orderBy('name')->get(),
            'templates' => CrmRequirementTemplate::query()->where('is_active', true)->with('items')->orderBy('name')->get(),
            'packages' => ServicePackage::query()->with('service')->where('is_active', true)->orderBy('service_id')->orderBy('sort_order')->get(),
            'summary' => CrmLead::query()->selectRaw('stage, COUNT(*) as aggregate')->groupBy('stage')->pluck('aggregate', 'stage'),
        ]);
    }

    public function store(Request $request, CrmContactService $contacts, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'contact_id' => ['required', 'exists:crm_contacts,id'],
            'title' => ['required', 'string', 'max:190'],
            'source' => ['required', 'string', 'max:60'],
            'stage' => ['required', Rule::in(array_keys(CrmLead::STAGES))],
            'service_interest' => ['nullable', 'string', 'max:160'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'next_follow_up_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $contact = CrmContact::query()->findOrFail($data['contact_id']);
        $lead = $contacts->createLead($contact, $data, $request->attributes->get('currentUser')?->id);
        CrmActivity::query()->create([
            'contact_id' => $contact->id,
            'lead_id' => $lead->id,
            'user_id' => $request->attributes->get('currentUser')?->id,
            'type' => 'lead_created',
            'title' => 'Lead dibuat',
            'description' => $lead->title,
        ]);
        $audit->record($request, 'crm.lead_created', $lead);
        return redirect()->route('admin.whatsapp.leads.index', ['stage' => $lead->stage])->with('success', 'Lead berhasil dibuat.');
    }

    public function update(
        Request $request,
        CrmLead $lead,
        CrmContactService $contacts,
        CrmSequenceService $sequences,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'stage' => ['required', Rule::in(array_keys(CrmLead::STAGES))],
            'service_interest' => ['nullable', 'string', 'max:160'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'probability' => ['required', 'integer', 'min:0', 'max:100'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'next_follow_up_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'lost_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $oldStage = $lead->stage;
        $data['closed_at'] = in_array($data['stage'], ['completed', 'lost'], true) ? now() : null;
        $lead->update($data);
        $lead->contact->forceFill([
            'lifecycle_stage' => in_array($lead->stage, ['deal', 'waiting_requirements', 'processing', 'completed'], true) ? 'customer' : 'lead',
            'service_interest' => $lead->service_interest ?: $lead->contact->service_interest,
            'assigned_to' => $lead->assigned_to ?: $lead->contact->assigned_to,
            'next_follow_up_at' => $lead->next_follow_up_at,
        ])->save();

        if ($lead->stage === 'deal' && $oldStage !== 'deal') {
            $contacts->attachLabel($lead->contact, 'Sudah Deal', 'status', '#16a34a', $request->attributes->get('currentUser')?->id);
            $sequences->stopOnDeal($lead->contact);
        }

        CrmActivity::query()->create([
            'contact_id' => $lead->contact_id,
            'lead_id' => $lead->id,
            'user_id' => $request->attributes->get('currentUser')?->id,
            'type' => 'stage_changed',
            'title' => 'Tahap lead diperbarui',
            'description' => ($oldStage !== $lead->stage ? (CrmLead::STAGES[$oldStage] ?? $oldStage).' → '.$lead->stageLabel() : $lead->stageLabel()),
            'due_at' => $lead->next_follow_up_at,
        ]);
        $audit->record($request, 'crm.lead_updated', $lead, ['old_stage' => $oldStage, 'new_stage' => $lead->stage]);
        return back()->with('success', 'Lead diperbarui.');
    }


    public function createOrder(
        Request $request,
        CrmLead $lead,
        ServiceOrderService $orders,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        if ($lead->service_order_id) {
            return redirect()->route('admin.orders.show', $lead->service_order_id)->with('success', 'Lead sudah terhubung dengan order.');
        }

        $data = $request->validate([
            'service_package_id' => ['nullable', 'exists:service_packages,id'],
            'title' => ['required', 'string', 'max:180'],
            'priority' => ['required', Rule::in(array_keys(ServiceOrder::PRIORITIES))],
            'due_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
        ]);
        $actor = $request->attributes->get('currentUser');
        abort_unless($actor, 403);
        $lead->loadMissing('contact');
        $contact = $lead->contact;
        abort_unless($contact, 422, 'Lead belum memiliki kontak.');

        $status = in_array($lead->stage, ['deal', 'waiting_requirements'], true) ? 'document_collection' : 'lead';
        $order = $orders->createManual([
            ...$data,
            'assigned_to' => $lead->assigned_to ?: $contact->assigned_to,
            'customer_name' => $contact->name ?: $contact->phone,
            'customer_email' => $contact->email,
            'customer_phone' => $contact->phone,
            'customer_company' => $contact->company,
            'status' => $status,
        ], $actor);

        DB::transaction(function () use ($lead, $order, $status): void {
            $lead->forceFill(['service_order_id' => $order->id])->save();
            $lead->requirements()->whereNull('service_order_id')->update(['service_order_id' => $order->id]);
            $lead->documents()->whereNull('service_order_id')->update(['service_order_id' => $order->id]);
            CrmActivity::query()->create([
                'contact_id' => $lead->contact_id,
                'lead_id' => $lead->id,
                'service_order_id' => $order->id,
                'type' => 'order_created',
                'title' => 'Order dibuat dari CRM',
                'description' => $order->order_number.' · '.(ServiceOrder::STATUSES[$status] ?? $status),
            ]);
        });

        $audit->record($request, 'crm.lead_converted_to_order', $lead, ['service_order_id' => $order->id]);
        return redirect()->route('admin.orders.show', $order)->with('success', 'Lead berhasil dikonversi menjadi order '.$order->order_number.'.');
    }

    public function applyRequirements(Request $request, CrmLead $lead, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate(['template_id' => ['required', 'exists:crm_requirement_templates,id']]);
        $template = CrmRequirementTemplate::query()->with('items')->findOrFail($data['template_id']);

        DB::transaction(function () use ($lead, $template): void {
            foreach ($template->items as $item) {
                CrmRequirement::query()->firstOrCreate(
                    ['lead_id' => $lead->id, 'template_item_id' => $item->id],
                    [
                        'contact_id' => $lead->contact_id,
                        'service_order_id' => $lead->service_order_id,
                        'name' => $item->name,
                        'status' => 'not_requested',
                    ],
                );
            }
            $lead->forceFill(['stage' => $lead->stage === 'deal' ? 'waiting_requirements' : $lead->stage])->save();
        });

        $audit->record($request, 'crm.requirements_applied', $lead, ['template_id' => $template->id]);
        return back()->with('success', 'Checklist persyaratan '.$template->name.' diterapkan.');
    }


    public function sendRequirements(
        Request $request,
        CrmLead $lead,
        WhatsAppManager $messages,
        CrmContactService $contacts,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'device_alias' => ['required', Rule::in(['default', 'transaction', 'support', 'partner', 'campaign'])],
            'intro' => ['nullable', 'string', 'max:3000'],
        ]);
        $lead->loadMissing(['contact', 'requirements']);
        if (! $lead->contact || $lead->requirements->isEmpty()) {
            return back()->withErrors(['requirements' => 'Terapkan checklist persyaratan terlebih dahulu.']);
        }

        $lines = $lead->requirements->values()->map(
            fn (CrmRequirement $requirement, int $index): string => ($index + 1).'. '.$requirement->name,
        )->implode("
");
        $body = trim((string) ($data['intro'] ?? ''));
        if ($body === '') {
            $body = 'Berikut persyaratan yang perlu disiapkan untuk '.($lead->service_interest ?: 'layanan yang dipilih').':';
        }
        $body .= "

".$lines."

Silakan kirim berkas melalui WhatsApp ini. Tim kami akan memeriksa dan mengarsipkannya pada order Anda.";

        $message = $messages->queueRaw([
            'contact_id' => $lead->contact_id,
            'lead_id' => $lead->id,
            'service_order_id' => $lead->service_order_id,
            'phone' => $lead->contact->phone,
            'recipient_name' => $lead->contact->name,
            'body' => $body,
            'device_alias' => $data['device_alias'],
            'created_by' => $request->attributes->get('currentUser')?->id,
            'idempotency_key' => 'crm-requirements:'.$lead->id.':'.now()->format('YmdHi'),
            'metadata' => ['source' => 'crm_requirements', 'lead_id' => $lead->id],
        ]);
        if (! $message) {
            return back()->withErrors(['requirements' => 'Pesan persyaratan tidak dapat dibuat.']);
        }

        $lead->requirements()->where('status', 'not_requested')->update(['status' => 'requested', 'requested_at' => now()]);
        $lead->forceFill(['stage' => 'waiting_requirements'])->save();
        $lead->contact->forceFill(['lifecycle_stage' => 'customer'])->save();
        $contacts->attachLabel($lead->contact, 'Menunggu Persyaratan', 'document', '#d97706', $request->attributes->get('currentUser')?->id);
        $audit->record($request, 'crm.requirements_queued', $lead, ['message_id' => $message->id]);

        return back()->with('success', 'Daftar persyaratan masuk antrean WhatsApp.');
    }

    public function updateRequirement(Request $request, CrmRequirement $requirement, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(CrmRequirement::STATUSES))],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $timestamps = match ($data['status']) {
            'requested' => ['requested_at' => now()],
            'received' => ['received_at' => now()],
            'verified' => ['verified_at' => now(), 'verified_by' => $request->attributes->get('currentUser')?->id],
            default => [],
        };
        $requirement->update([...$data, ...$timestamps]);
        $audit->record($request, 'crm.requirement_updated', $requirement, ['status' => $requirement->status]);
        return back()->with('success', 'Status persyaratan diperbarui.');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchWhatsAppCampaign;
use App\Models\ContentItem;
use App\Models\CrmContact;
use App\Models\CrmLabel;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppRecipientPreset;
use App\Rules\SafePublicUrl;
use App\Services\WhatsApp\WhatsAppManager;
use App\Services\WhatsApp\WhatsAppAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WhatsAppComposerController extends Controller
{
    public function index(): View
    {
        return view('admin.whatsapp.composer', [
            'contents' => ContentItem::where('status', 'ready')->latest()->get(),
            'contacts' => CrmContact::where('status', 'active')->orderBy('name')->limit(1000)->get(),
            'groups' => WhatsAppGroup::where('is_active', true)->orderBy('name')->get(),
            'labels' => CrmLabel::where('is_active', true)->orderBy('name')->get(),
            'presets' => WhatsAppRecipientPreset::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function personal(Request $request, WhatsAppManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:160'], 'recipient_name' => ['nullable', 'string', 'max:160'],
            'channel' => ['required', 'in:personal,group'], 'body' => ['required', 'string', 'max:10000'],
            'media_url' => ['nullable', 'url', 'max:2048', new SafePublicUrl], 'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
        ]);
        $message = $manager->queueRaw([
            ...$data, 'created_by' => $request->attributes->get('currentUser')?->id,
            'message_type' => filled($data['media_url'] ?? null) ? 'media' : 'text',
            'scheduled_at' => filled($data['scheduled_at'] ?? null) ? Carbon::parse($data['scheduled_at']) : null,
            'idempotency_key' => 'composer:'.hash('sha256', json_encode($data).'|'.microtime(true)),
            'metadata' => ['source' => 'content_hub_composer'],
        ]);
        if (! $message) throw ValidationException::withMessages(['phone' => 'Pesan tidak dibuat. Periksa izin kontak dan konfigurasi StarSender.']);
        return redirect()->route('admin.whatsapp.messages.index')->with('success', 'Pesan masuk antrean pengiriman.');
    }

    public function savePreset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'contact_ids' => ['nullable', 'array'],
            'contact_ids.*' => ['integer', 'exists:crm_contacts,id'], 'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', 'exists:whatsapp_groups,id'],
            'label_ids' => ['nullable', 'array'], 'label_ids.*' => ['integer', 'exists:crm_labels,id'],
        ]);
        WhatsAppRecipientPreset::create([
            'created_by' => $request->attributes->get('currentUser')?->id,
            'name' => $data['name'], 'definition' => ['contact_ids' => $data['contact_ids'] ?? [], 'label_ids' => $data['label_ids'] ?? [], 'group_ids' => $data['group_ids'] ?? []],
        ]);
        return back()->with('success', 'Preset penerima disimpan.');
    }

    public function deletePreset(WhatsAppRecipientPreset $preset): RedirectResponse
    {
        $preset->delete();
        return back()->with('success', 'Preset penerima dihapus.');
    }

    public function broadcast(Request $request, WhatsAppManager $manager, WhatsAppAuditService $audit): View
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:10000'],
            'contact_ids' => ['nullable', 'array'], 'contact_ids.*' => ['integer', 'exists:crm_contacts,id'],
            'group_ids' => ['nullable', 'array'], 'group_ids.*' => ['integer', 'exists:whatsapp_groups,id'],
            'label_ids' => ['nullable', 'array'], 'label_ids.*' => ['integer', 'exists:crm_labels,id'],
            'preset_id' => ['nullable', 'exists:whatsapp_recipient_presets,id'],
            'media_url' => ['nullable', 'url', 'max:2048', new SafePublicUrl], 'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
        ]);
        $contactIds = collect($data['contact_ids'] ?? []); $labelIds = collect($data['label_ids'] ?? []); $groupIds = collect($data['group_ids'] ?? []);
        if (! empty($data['preset_id'])) {
            $definition = (array) WhatsAppRecipientPreset::findOrFail($data['preset_id'])->definition;
            $contactIds = $contactIds->merge($definition['contact_ids'] ?? []);
            $labelIds = $labelIds->merge($definition['label_ids'] ?? []);
            $groupIds = $groupIds->merge($definition['group_ids'] ?? []);
        }
        $contacts = $contactIds->isEmpty() && $labelIds->isEmpty() ? collect() : CrmContact::query()->where('status', 'active')
            ->where(function ($query) use ($contactIds, $labelIds): void {
                if ($contactIds->isNotEmpty()) $query->whereIn('id', $contactIds->unique());
                if ($labelIds->isNotEmpty()) $query->orWhereHas('labels', fn ($labels) => $labels->whereIn('crm_labels.id', $labelIds->unique()));
            })->get();
        $groups = WhatsAppGroup::whereIn('id', $groupIds->unique())->where('is_active', true)->get();
        $total = $contacts->count() + $groups->count();
        if ($total < 1) throw ValidationException::withMessages(['contact_ids' => 'Pilih minimal satu penerima atau preset.']);
        if ($total > config('content-hub.max_broadcast_recipients', 500)) throw ValidationException::withMessages(['contact_ids' => 'Penerima melebihi batas aman per broadcast.']);

        $token = bin2hex(random_bytes(24));
        $campaign = DB::transaction(function () use ($request, $data, $contacts, $groups, $total, $token, $manager): WhatsAppCampaign {
            $scheduled = filled($data['scheduled_at'] ?? null) ? Carbon::parse($data['scheduled_at']) : null;
            $campaign = WhatsAppCampaign::create([
                'name' => $data['name'], 'created_by' => $request->attributes->get('currentUser')?->id,
                'audience_type' => 'preset', 'status' => 'draft',
                'delay_seconds' => 30, 'scheduled_at' => $scheduled, 'recipient_count' => $total,
                'body' => $data['body'], 'message_type' => filled($data['media_url'] ?? null) ? 'media' : 'text',
                'media_path' => $data['media_url'] ?? null,
                'audience_snapshot' => ['contacts' => $contacts->pluck('id'), 'labels' => $labelIds->unique()->values(), 'groups' => $groups->pluck('id')],
                'confirmation_token_hash' => hash('sha256', $token),
            ]);
            $campaign->recipients()->createMany([
                ...$contacts->map(function ($c) use ($manager): array {
                    $blocked = $manager->isBlocked($c->phone, true);
                    return ['phone' => $c->phone, 'name' => $c->name, 'contact_id' => $c->id, 'channel' => 'personal', 'status' => $blocked ? 'skipped' : 'pending', 'exclusion_reason' => $blocked ? 'consent_or_opt_out' : null, 'error' => $blocked ? 'Tanpa consent promosi aktif atau sudah opt-out.' : null, 'variables' => ['nama_pelanggan' => $c->name ?: 'Bapak/Ibu']];
                })->all(),
                ...$groups->map(fn ($g) => ['phone' => $g->group_jid, 'name' => $g->name, 'group_id' => $g->id, 'channel' => 'group', 'status' => 'pending', 'variables' => ['nama_pelanggan' => $g->name]])->all(),
            ]);
            return $campaign;
        });
        if (! $campaign->recipients()->where('status', 'pending')->exists()) {
            $campaign->delete();
            throw ValidationException::withMessages(['contact_ids' => 'Tidak ada penerima yang memenuhi syarat. Periksa consent dan opt-out.']);
        }
        $audit->record($request, 'whatsapp.broadcast_previewed', $campaign, ['eligible' => $campaign->recipients()->where('status', 'pending')->count(), 'excluded' => $campaign->recipients()->where('status', 'skipped')->count()]);

        return view('admin.whatsapp.broadcast-preview', ['campaign' => $campaign->load('recipients'), 'confirmationToken' => $token]);
    }

    public function confirm(Request $request, WhatsAppCampaign $campaign, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate(['confirmation_token' => ['required', 'string', 'size:48']]);
        abort_unless($campaign->status === 'draft' && $campaign->confirmation_token_hash, 409, 'Broadcast ini sudah diproses.');
        abort_unless(hash_equals($campaign->confirmation_token_hash, hash('sha256', $data['confirmation_token'])), 403);

        $campaign->update([
            'status' => $campaign->scheduled_at?->isFuture() ? 'scheduled' : 'running',
            'confirmation_token_hash' => null,
            'recipient_count' => $campaign->recipients()->count(),
        ]);
        DispatchWhatsAppCampaign::dispatch($campaign->id)->onQueue('whatsapp');
        $audit->record($request, 'whatsapp.broadcast_confirmed', $campaign, ['eligible' => $campaign->recipients()->where('status', 'pending')->count()]);

        return redirect()->route('admin.whatsapp.campaigns.show', $campaign)->with('success', 'Broadcast dikonfirmasi dan masuk antrean.');
    }
}

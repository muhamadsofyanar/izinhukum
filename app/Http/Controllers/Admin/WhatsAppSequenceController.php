<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmContact;
use App\Models\CrmDocument;
use App\Models\CrmLabel;
use App\Models\CrmSequence;
use App\Models\CrmSequenceEnrollment;
use App\Models\CrmSequenceStep;
use App\Models\WhatsAppGroupPreset;
use App\Models\WhatsAppTemplate;
use App\Services\Crm\CrmSequenceService;
use App\Services\WhatsApp\WhatsAppAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WhatsAppSequenceController extends Controller
{
    public function index(): View
    {
        return view('admin.whatsapp.sequences.index', [
            'sequences' => CrmSequence::query()->withCount(['steps', 'enrollments'])->latest()->paginate(30),
        ]);
    }

    public function show(CrmSequence $sequence): View
    {
        $sequence->load(['steps.template', 'steps.document', 'enrollments.contact.labels', 'enrollments.groupPreset']);
        return view('admin.whatsapp.sequences.show', [
            'sequence' => $sequence,
            'templates' => WhatsAppTemplate::query()->where('is_enabled', true)->orderBy('name')->get(),
            'documents' => CrmDocument::query()->where('archive_status', 'stored')->whereNotNull('path')->latest()->limit(1000)->get(),
            'contacts' => CrmContact::query()->orderBy('name')->limit(1000)->get(),
            'labels' => CrmLabel::query()->where('is_active', true)->orderBy('name')->get(),
            'groupPresets' => WhatsAppGroupPreset::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:3000'],
            'audience_type' => ['required', Rule::in(['contact', 'label', 'group_preset'])],
            'device_alias' => ['required', Rule::in(['default', 'transaction', 'support', 'partner', 'campaign'])],
            'group_interval_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
            'stop_on_reply' => ['nullable', 'boolean'],
            'stop_on_deal' => ['nullable', 'boolean'],
        ]);
        $sequence = CrmSequence::query()->create([
            ...$data,
            'stop_on_reply' => $request->boolean('stop_on_reply'),
            'stop_on_deal' => $request->boolean('stop_on_deal'),
            'is_active' => false,
            'created_by' => $request->attributes->get('currentUser')?->id,
        ]);
        $audit->record($request, 'crm.sequence_created', $sequence);
        return redirect()->route('admin.whatsapp.sequences.show', $sequence)->with('success', 'Sequence dibuat. Tambahkan langkah pesan lalu aktifkan.');
    }

    public function update(Request $request, CrmSequence $sequence, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:3000'],
            'device_alias' => ['required', Rule::in(['default', 'transaction', 'support', 'partner', 'campaign'])],
            'group_interval_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
            'stop_on_reply' => ['nullable', 'boolean'],
            'stop_on_deal' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $sequence->update([
            ...$data,
            'stop_on_reply' => $request->boolean('stop_on_reply'),
            'stop_on_deal' => $request->boolean('stop_on_deal'),
            'is_active' => $request->boolean('is_active'),
        ]);
        if ($sequence->is_active) {
            $paused = $sequence->enrollments()->where('status', 'paused')->whereNull('stopped_reason');
            (clone $paused)->whereNull('next_run_at')->update(['next_run_at' => now()]);
            $paused->update(['status' => 'active', 'paused_at' => null]);
        }
        $audit->record($request, 'crm.sequence_updated', $sequence);
        return back()->with('success', 'Sequence diperbarui.');
    }

    public function addStep(Request $request, CrmSequence $sequence, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'delay_value' => ['required', 'integer', 'min:0', 'max:3650'],
            'delay_unit' => ['required', Rule::in(['minute', 'hour', 'day'])],
            'send_time' => ['nullable', 'date_format:H:i'],
            'template_id' => ['nullable', 'exists:whatsapp_templates,id'],
            'crm_document_id' => ['nullable', 'exists:crm_documents,id'],
            'message_type' => ['required', Rule::in(['text', 'image', 'document', 'video', 'audio', 'media'])],
            'body' => ['nullable', 'string', 'max:10000'],
            'media_url' => ['nullable', 'url', 'max:2048'],
        ]);
        if (filled($data['template_id'] ?? null) && filled($data['crm_document_id'] ?? null)) {
            return back()->withErrors(['crm_document_id' => 'Pilih template atau dokumen vault, jangan keduanya pada langkah yang sama.']);
        }
        if (blank($data['body'] ?? null) && blank($data['template_id'] ?? null) && blank($data['media_url'] ?? null) && blank($data['crm_document_id'] ?? null)) {
            return back()->withErrors(['body' => 'Isi pesan, template, URL media, atau dokumen vault wajib diisi.']);
        }
        if (filled($data['crm_document_id'] ?? null)) {
            $document = CrmDocument::query()->findOrFail($data['crm_document_id']);
            if ($document->archive_status !== 'stored' || blank($document->path)) {
                return back()->withErrors(['crm_document_id' => 'Dokumen belum tersimpan di vault lokal.']);
            }
            $data['message_type'] = 'document';
            $data['media_url'] = null;
        }
        $position = (int) $sequence->steps()->max('position') + 1;
        $step = $sequence->steps()->create([...$data, 'position' => $position]);
        $audit->record($request, 'crm.sequence_step_created', $step, ['sequence_id' => $sequence->id]);
        return back()->with('success', 'Langkah '.$position.' ditambahkan.');
    }

    public function deleteStep(Request $request, CrmSequence $sequence, CrmSequenceStep $step, WhatsAppAuditService $audit): RedirectResponse
    {
        abort_unless((int) $step->sequence_id === (int) $sequence->id, 404);
        DB::transaction(function () use ($sequence, $step): void {
            $step->delete();
            $sequence->steps()->orderBy('position')->get()->each(fn (CrmSequenceStep $item, int $index) => $item->update(['position' => $index + 1]));
        });
        $audit->record($request, 'crm.sequence_step_deleted', $sequence, ['step_id' => $step->id]);
        return back()->with('success', 'Langkah dihapus dan urutan dirapikan.');
    }

    public function enroll(Request $request, CrmSequence $sequence, CrmSequenceService $service, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'contact_id' => ['nullable', 'exists:crm_contacts,id'],
            'label_id' => ['nullable', 'exists:crm_labels,id'],
            'group_preset_id' => ['nullable', 'exists:whatsapp_group_presets,id'],
        ]);
        $count = 0;
        if (! empty($data['contact_id'])) {
            $service->enrollContact($sequence, CrmContact::query()->findOrFail($data['contact_id']));
            $count++;
        }
        if (! empty($data['label_id'])) {
            CrmContact::query()->whereHas('labels', fn ($query) => $query->where('crm_labels.id', $data['label_id']))->chunkById(200, function ($contacts) use ($sequence, $service, &$count): void {
                foreach ($contacts as $contact) {
                    $service->enrollContact($sequence, $contact, ['source' => 'label']);
                    $count++;
                }
            });
        }
        if (! empty($data['group_preset_id'])) {
            $service->enrollGroupPreset($sequence, (int) $data['group_preset_id']);
            $count++;
        }
        if ($count === 0) {
            return back()->withErrors(['contact_id' => 'Pilih kontak, label, atau kategori grup.']);
        }
        $audit->record($request, 'crm.sequence_enrolled', $sequence, ['count' => $count]);
        return back()->with('success', $count.' target dimasukkan ke sequence.');
    }

    public function enrollmentAction(Request $request, CrmSequenceEnrollment $enrollment, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['pause', 'resume', 'stop'])]]);
        $updates = match ($data['action']) {
            'pause' => ['status' => 'paused', 'paused_at' => now(), 'next_run_at' => null, 'stopped_reason' => null],
            'resume' => ['status' => 'active', 'paused_at' => null, 'next_run_at' => now(), 'stopped_reason' => null],
            default => ['status' => 'stopped', 'completed_at' => now(), 'next_run_at' => null, 'stopped_reason' => 'Dihentikan manual oleh admin.'],
        };
        $enrollment->update($updates);
        $audit->record($request, 'crm.sequence_enrollment_'.$data['action'], $enrollment);
        return back()->with('success', 'Status enrollment diperbarui.');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmContact;
use App\Models\CrmLabel;
use App\Models\CrmSequence;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmSequenceService;
use App\Services\WhatsApp\PhoneNumberNormalizer;
use App\Services\WhatsApp\WhatsAppAuditService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class WhatsAppContactController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $labelId = (int) $request->query('label_id');
        $stage = trim((string) $request->query('stage'));
        $source = trim((string) $request->query('source'));

        $contacts = CrmContact::query()
            ->with(['labels', 'assignee'])
            ->withCount(['leads', 'documents'])
            ->search($search)
            ->when($labelId > 0, fn (Builder $query) => $query->whereHas('labels', fn (Builder $labels) => $labels->where('crm_labels.id', $labelId)))
            ->when($stage !== '', fn (Builder $query) => $query->where('lifecycle_stage', $stage))
            ->when($source !== '', fn (Builder $query) => $query->where('source', $source))
            ->orderByDesc('last_contact_at')
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        return view('admin.whatsapp.contacts.index', [
            'contacts' => $contacts,
            'labels' => CrmLabel::query()->where('is_active', true)->orderBy('category')->orderBy('name')->get(),
            'allLabels' => CrmLabel::query()->withCount('contacts')->orderBy('category')->orderBy('name')->get(),
            'admins' => User::query()->where('role', 'admin')->where('is_active', true)->orderBy('name')->get(),
            'search' => $search,
            'labelId' => $labelId,
            'stage' => $stage,
            'source' => $source,
            'stats' => [
                'total' => CrmContact::query()->count(),
                'leads' => CrmContact::query()->where('lifecycle_stage', 'lead')->count(),
                'customers' => CrmContact::query()->where('lifecycle_stage', 'customer')->count(),
                'followups' => CrmContact::query()->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now()->addDay())->count(),
            ],
        ]);
    }


    public function export(Request $request): StreamedResponse
    {
        $search = trim((string) $request->query('q'));
        $labelId = (int) $request->query('label_id');
        $stage = trim((string) $request->query('stage'));
        $source = trim((string) $request->query('source'));

        $query = CrmContact::query()
            ->with('labels')
            ->search($search)
            ->when($labelId > 0, fn (Builder $builder) => $builder->whereHas('labels', fn (Builder $labels) => $labels->where('crm_labels.id', $labelId)))
            ->when($stage !== '', fn (Builder $builder) => $builder->where('lifecycle_stage', $stage))
            ->when($source !== '', fn (Builder $builder) => $builder->where('source', $source))
            ->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['phone', 'name', 'email', 'company', 'source', 'lifecycle_stage', 'service_interest', 'labels', 'next_follow_up_at']);
            $query->chunkById(500, function ($contacts) use ($output): void {
                foreach ($contacts as $contact) {
                    fputcsv($output, [
                        $contact->phone,
                        $contact->name,
                        $contact->email,
                        $contact->company,
                        $contact->source,
                        $contact->lifecycle_stage,
                        $contact->service_interest,
                        $contact->labels->pluck('name')->implode('|'),
                        $contact->next_follow_up_at?->format('Y-m-d H:i:s'),
                    ]);
                }
            });
            fclose($output);
        }, 'kontak-crm-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(
        Request $request,
        CrmContactService $contacts,
        PhoneNumberNormalizer $phones,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $request->validate([
            'csv_file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
            'default_source' => ['required', 'string', 'max:60'],
        ]);

        $handle = fopen($request->file('csv_file')->getRealPath(), 'rb');
        if ($handle === false) {
            return back()->withErrors(['csv_file' => 'File CSV tidak dapat dibaca.']);
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);
            return back()->withErrors(['csv_file' => 'CSV tidak memiliki baris header.']);
        }
        $header = array_map(fn ($value) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value))), $header);
        $aliases = ['phone' => ['phone', 'nomor', 'nomor_whatsapp', 'whatsapp', 'wa']];
        $phoneColumn = collect($aliases['phone'])->map(fn ($name) => array_search($name, $header, true))->first(fn ($index) => $index !== false);
        if ($phoneColumn === null) {
            fclose($handle);
            return back()->withErrors(['csv_file' => 'Header wajib memiliki kolom phone, nomor, nomor_whatsapp, whatsapp, atau wa.']);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $rowNumber = 1;
        $column = fn (array $row, string $name) => (($index = array_search($name, $header, true)) !== false ? trim((string) ($row[$index] ?? '')) : null);

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if ($rowNumber > 5001) {
                $skipped++;
                continue;
            }
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            try {
                $normalized = $phones->normalize((string) ($row[$phoneColumn] ?? ''));
                if (! $normalized) {
                    $skipped++;
                    continue;
                }
                $existing = CrmContact::query()->where('phone', $normalized)->exists();
                $source = $column($row, 'source') ?: (string) $request->input('default_source');
                $contact = $contacts->upsertFromWhatsApp($normalized, $column($row, 'name'), $source, ['created_from' => 'csv_import']);
                if (! $contact) {
                    $skipped++;
                    continue;
                }
                $contact->update([
                    'email' => $column($row, 'email') ?: $contact->email,
                    'company' => $column($row, 'company') ?: $contact->company,
                    'service_interest' => $column($row, 'service_interest') ?: $contact->service_interest,
                ]);
                $labels = preg_split('/[|;,]+/', (string) ($column($row, 'labels') ?: ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                foreach (array_slice(array_unique(array_map('trim', $labels)), 0, 30) as $label) {
                    if ($label !== '') {
                        $contacts->attachLabel($contact, mb_substr($label, 0, 100), 'custom', '#0f766e', $request->attributes->get('currentUser')?->id);
                    }
                }
                $existing ? $updated++ : $created++;
            } catch (Throwable) {
                $skipped++;
            }
        }
        fclose($handle);

        $audit->record($request, 'crm.contacts_imported', null, compact('created', 'updated', 'skipped'));
        return back()->with('success', "Impor selesai: {$created} baru, {$updated} diperbarui, {$skipped} dilewati.");
    }

    public function show(CrmContact $contact): View
    {
        $contact->load([
            'labels', 'assignee', 'leads.assignee', 'activities.user', 'documents',
            'requirements.documents', 'sequenceEnrollments.sequence',
            'conversations' => fn ($query) => $query->latest('last_message_at'),
        ]);

        return view('admin.whatsapp.contacts.show', [
            'contact' => $contact,
            'labels' => CrmLabel::query()->where('is_active', true)->orderBy('category')->orderBy('name')->get(),
            'admins' => User::query()->where('role', 'admin')->where('is_active', true)->orderBy('name')->get(),
            'sequences' => CrmSequence::query()->where('is_active', true)->where('audience_type', 'contact')->orderBy('name')->get(),
        ]);
    }

    public function store(
        Request $request,
        CrmContactService $contacts,
        PhoneNumberNormalizer $phones,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'source' => ['required', 'string', 'max:60'],
            'service_interest' => ['nullable', 'string', 'max:160'],
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);

        $normalized = $phones->normalize($data['phone']);
        $contact = $contacts->upsertFromWhatsApp($normalized, $data['name'] ?? null, $data['source'], ['created_from' => 'admin']);
        abort_unless($contact, 422, 'Nomor kontak tidak valid.');
        $contact->update([
            'email' => $data['email'] ?? $contact->email,
            'company' => $data['company'] ?? $contact->company,
            'service_interest' => $data['service_interest'] ?? $contact->service_interest,
            'assigned_to' => $data['assigned_to'] ?? $contact->assigned_to,
        ]);

        $audit->record($request, 'crm.contact_saved', $contact);
        return redirect()->route('admin.whatsapp.contacts.show', $contact)->with('success', 'Kontak berhasil disimpan.');
    }

    public function update(Request $request, CrmContact $contact, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'source' => ['required', 'string', 'max:60'],
            'status' => ['required', Rule::in(['active', 'inactive', 'blocked'])],
            'lifecycle_stage' => ['required', Rule::in(['contact', 'lead', 'customer', 'former_customer'])],
            'service_interest' => ['nullable', 'string', 'max:160'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'next_follow_up_at' => ['nullable', 'date'],
            'is_opted_out' => ['nullable', 'boolean'],
        ]);

        $contact->update([...$data, 'is_opted_out' => $request->boolean('is_opted_out')]);
        $audit->record($request, 'crm.contact_updated', $contact);
        return back()->with('success', 'Kontak diperbarui.');
    }

    public function labels(Request $request, CrmContact $contact, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate(['label_ids' => ['nullable', 'array'], 'label_ids.*' => ['integer', 'exists:crm_labels,id']]);
        $userId = $request->attributes->get('currentUser')?->id;
        $sync = collect($data['label_ids'] ?? [])->mapWithKeys(fn ($id) => [(int) $id => ['assigned_by' => $userId]])->all();
        $contact->labels()->sync($sync);
        $audit->record($request, 'crm.contact_labels_updated', $contact, ['label_ids' => array_keys($sync)]);
        return back()->with('success', 'Label kontak diperbarui.');
    }

    public function send(Request $request, CrmContact $contact, WhatsAppManager $messages, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'device_alias' => ['required', Rule::in(['default', 'transaction', 'support', 'partner', 'campaign'])],
        ]);
        $message = $messages->queueRaw([
            'contact_id' => $contact->id,
            'phone' => $contact->phone,
            'recipient_name' => $contact->name,
            'body' => $data['body'],
            'device_alias' => $data['device_alias'],
            'created_by' => $request->attributes->get('currentUser')?->id,
            'idempotency_key' => 'crm-contact:'.$contact->id.':'.hash('sha256', $data['body'].'|'.microtime(true)),
            'metadata' => ['source' => 'crm_contact'],
        ]);
        if (! $message) {
            return back()->withErrors(['body' => 'Pesan tidak dibuat. Periksa integrasi, consent, atau opt-out.']);
        }
        $audit->record($request, 'crm.contact_message_queued', $contact, ['message_id' => $message->id]);
        return back()->with('success', 'Pesan masuk antrean.');
    }

    public function enroll(
        Request $request,
        CrmContact $contact,
        CrmSequenceService $sequences,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate(['sequence_id' => ['required', 'exists:crm_sequences,id']]);
        $sequence = CrmSequence::query()->findOrFail($data['sequence_id']);
        $enrollment = $sequences->enrollContact($sequence, $contact, ['enrolled_by' => $request->attributes->get('currentUser')?->id]);
        $audit->record($request, 'crm.sequence_contact_enrolled', $enrollment, ['contact_id' => $contact->id]);
        return back()->with('success', 'Kontak dimasukkan ke sequence '.$sequence->name.'.');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmContact;
use App\Models\CrmDocument;
use App\Models\CrmLead;
use App\Services\Crm\CrmDocumentService;
use App\Services\Crm\InboundMediaArchiveService;
use App\Services\WhatsApp\WhatsAppAuditService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class WhatsAppDocumentController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $status = trim((string) $request->query('archive_status'));
        $documents = CrmDocument::query()
            ->with(['contact', 'lead', 'serviceOrder', 'requirement'])
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('original_name', 'like', '%'.$search.'%')
                    ->orWhereHas('contact', fn (Builder $contact) => $contact->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%'));
            }))
            ->when($status !== '', fn (Builder $query) => $query->where('archive_status', $status))
            ->latest()
            ->paginate(40)
            ->withQueryString();

        return view('admin.whatsapp.documents.index', [
            'documents' => $documents,
            'contacts' => CrmContact::query()->orderBy('name')->limit(1000)->get(),
            'leads' => CrmLead::query()->with('contact')->whereNotIn('stage', ['completed', 'lost'])->latest()->limit(500)->get(),
            'search' => $search,
            'archiveStatus' => $status,
            'stats' => [
                'total' => CrmDocument::query()->count(),
                'pending' => CrmDocument::query()->where('archive_status', 'pending')->count(),
                'stored' => CrmDocument::query()->where('archive_status', 'stored')->count(),
                'unverified' => CrmDocument::query()->where('verification_status', 'unverified')->count(),
            ],
        ]);
    }

    public function store(Request $request, CrmDocumentService $documents, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,zip'],
            'contact_id' => ['nullable', 'exists:crm_contacts,id'],
            'lead_id' => ['nullable', 'exists:crm_leads,id'],
            'service_order_id' => ['nullable', 'exists:service_orders,id'],
            'category' => ['required', Rule::in(['requirement', 'revision', 'payment', 'process', 'final', 'whatsapp_attachment', 'other'])],
            'name' => ['required', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        if (empty($data['contact_id']) && empty($data['lead_id']) && empty($data['service_order_id'])) {
            return back()->withErrors(['contact_id' => 'Hubungkan dokumen ke kontak, lead, atau order.']);
        }
        if (! empty($data['lead_id'])) {
            $lead = CrmLead::query()->findOrFail($data['lead_id']);
            $data['contact_id'] = $data['contact_id'] ?? $lead->contact_id;
            $data['service_order_id'] = $data['service_order_id'] ?? $lead->service_order_id;
        }
        $document = $documents->storeUpload($request->file('file'), [
            'contact_id' => $data['contact_id'] ?? null,
            'lead_id' => $data['lead_id'] ?? null,
            'service_order_id' => $data['service_order_id'] ?? null,
            'category' => $data['category'],
            'name' => $data['name'],
            'notes' => $data['notes'] ?? null,
            'source' => 'admin',
        ], $request->attributes->get('currentUser')?->id);
        $audit->record($request, 'crm.document_uploaded', $document);
        return back()->with('success', 'Dokumen tersimpan di arsip privat.');
    }


    public function sendMany(
        Request $request,
        CrmDocumentService $documents,
        WhatsAppManager $messages,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'document_ids' => ['required', 'array', 'min:1', 'max:50'],
            'document_ids.*' => ['integer', 'distinct', 'exists:crm_documents,id'],
            'contact_id' => ['required', 'exists:crm_contacts,id'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'device_alias' => ['required', Rule::in(['default', 'transaction', 'support', 'partner', 'campaign'])],
        ]);
        $contact = CrmContact::query()->findOrFail($data['contact_id']);
        $items = CrmDocument::query()->whereIn('id', $data['document_ids'])->where('archive_status', 'stored')->get();
        $queued = 0;
        $failed = 0;
        foreach ($items as $index => $document) {
            if (! $documents->pathExists($document)) {
                $failed++;
                continue;
            }
            $url = $documents->issueProviderAccess($document, 180, $request->attributes->get('currentUser')?->id);
            $message = $messages->queueRaw([
                'contact_id' => $contact->id,
                'lead_id' => $document->lead_id,
                'service_order_id' => $document->service_order_id,
                'crm_document_id' => $document->id,
                'phone' => $contact->phone,
                'recipient_name' => $contact->name,
                'message_type' => 'document',
                'body' => $index === 0 ? ($data['caption'] ?? null) : null,
                'media_url' => $url,
                'device_alias' => $data['device_alias'],
                'created_by' => $request->attributes->get('currentUser')?->id,
                'idempotency_key' => 'crm-document-bulk:'.$document->id.':'.$contact->id.':'.now()->format('YmdHi'),
                'metadata' => ['source' => 'crm_document_bulk', 'document_id' => $document->id],
            ]);
            $message ? $queued++ : $failed++;
        }

        $audit->record($request, 'crm.documents_bulk_queued', null, [
            'contact_id' => $contact->id,
            'queued' => $queued,
            'failed' => $failed,
            'document_ids' => $items->pluck('id')->all(),
        ]);
        if ($queued === 0) {
            return back()->withErrors(['document_ids' => 'Tidak ada dokumen yang berhasil dimasukkan ke antrean.']);
        }

        return back()->with('success', $queued.' dokumen masuk antrean WhatsApp'.($failed > 0 ? ', '.$failed.' dilewati.' : '.'));
    }

    public function download(Request $request, CrmDocument $document, CrmDocumentService $documents): BinaryFileResponse
    {
        abort_unless($documents->pathExists($document), 404, 'File tidak ditemukan di penyimpanan.');
        $documents->logAccess($document, 'download_admin', $request->attributes->get('currentUser')?->id, $request->ip(), $request->userAgent());
        return response()->download(Storage::disk($document->disk)->path($document->path), $document->original_name ?: $document->name);
    }

    public function archive(Request $request, CrmDocument $document, InboundMediaArchiveService $archiver, WhatsAppAuditService $audit): RedirectResponse
    {
        try {
            $archiver->archive($document);
            $audit->record($request, 'crm.document_archived', $document);
            return back()->with('success', 'Lampiran WhatsApp berhasil disalin ke arsip privat.');
        } catch (Throwable $exception) {
            $document->forceFill(['archive_status' => 'failed', 'notes' => trim(($document->notes ? $document->notes."\n" : '').$exception->getMessage())])->save();
            return back()->withErrors(['archive' => $exception->getMessage()]);
        }
    }

    public function update(Request $request, CrmDocument $document, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(['requirement', 'revision', 'payment', 'process', 'final', 'whatsapp_attachment', 'other'])],
            'verification_status' => ['required', Rule::in(['unverified', 'valid', 'needs_revision', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:3000'],
            'requirement_id' => ['nullable', 'exists:crm_requirements,id'],
        ]);
        if (! empty($data['requirement_id'])) {
            $requirement = \App\Models\CrmRequirement::query()->findOrFail($data['requirement_id']);
            $sameOwner = ($document->lead_id && (int) $requirement->lead_id === (int) $document->lead_id)
                || ($document->contact_id && (int) $requirement->contact_id === (int) $document->contact_id)
                || ($document->service_order_id && (int) $requirement->service_order_id === (int) $document->service_order_id);
            if (! $sameOwner) {
                return back()->withErrors(['requirement_id' => 'Persyaratan tidak terkait dengan kontak, lead, atau order dokumen ini.']);
            }
        }
        $document->update([
            ...$data,
            'verified_at' => in_array($data['verification_status'], ['valid', 'rejected'], true) ? now() : null,
        ]);
        if ($document->requirement_id && $data['verification_status'] === 'valid') {
            $document->requirement()->update(['status' => 'verified', 'verified_at' => now(), 'verified_by' => $request->attributes->get('currentUser')?->id]);
        }
        $audit->record($request, 'crm.document_updated', $document);
        return back()->with('success', 'Dokumen diperbarui.');
    }

    public function send(
        Request $request,
        CrmDocument $document,
        CrmDocumentService $documents,
        WhatsAppManager $messages,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'contact_id' => ['required', 'exists:crm_contacts,id'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'device_alias' => ['required', Rule::in(['default', 'transaction', 'support', 'partner', 'campaign'])],
        ]);
        if (! $documents->pathExists($document)) {
            return back()->withErrors(['document' => 'Dokumen belum tersimpan secara lokal. Arsipkan dahulu.']);
        }
        $contact = CrmContact::query()->findOrFail($data['contact_id']);
        $url = $documents->issueProviderAccess($document, 180, $request->attributes->get('currentUser')?->id);
        $message = $messages->queueRaw([
            'contact_id' => $contact->id,
            'crm_document_id' => $document->id,
            'phone' => $contact->phone,
            'recipient_name' => $contact->name,
            'message_type' => 'document',
            'body' => $data['caption'] ?? null,
            'media_url' => $url,
            'device_alias' => $data['device_alias'],
            'created_by' => $request->attributes->get('currentUser')?->id,
            'idempotency_key' => 'crm-document:'.$document->id.':'.$contact->id.':'.now()->format('YmdHi'),
            'metadata' => ['source' => 'crm_document', 'document_id' => $document->id],
        ]);
        if (! $message) {
            return back()->withErrors(['document' => 'Pesan dokumen tidak dibuat.']);
        }
        $audit->record($request, 'crm.document_queued', $document, ['message_id' => $message->id, 'contact_id' => $contact->id]);
        return back()->with('success', 'Dokumen masuk antrean WhatsApp. Tautan provider berlaku 3 jam.');
    }
}

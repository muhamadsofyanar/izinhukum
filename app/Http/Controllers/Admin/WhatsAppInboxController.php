<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmDocument;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Rules\SafePublicUrl;
use App\Services\Crm\CrmDocumentService;
use App\Services\FeatureFlagService;
use App\Services\WhatsApp\StarSenderClient;
use App\Services\WhatsApp\WhatsAppAuditService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class WhatsAppInboxController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.whatsapp.inbox', $this->inboxData($request));
    }

    public function show(Request $request, WhatsAppConversation $conversation): View
    {
        $conversation->load(['messages.crmDocument', 'messages.attemptsLog', 'assignee', 'serviceOrder', 'inquiry', 'partner', 'contact.labels', 'lead']);
        $conversation->forceFill(['unread_count' => 0])->save();

        return view('admin.whatsapp.conversation', array_merge($this->inboxData($request), [
            'conversation' => $conversation,
            'admins' => User::query()->where('role', 'admin')->where('is_active', true)->orderBy('name')->get(),
            'vaultDocuments' => CrmDocument::query()->where('archive_status', 'stored')->whereNotNull('path')->when($conversation->contact_id, fn ($query) => $query->where('contact_id', $conversation->contact_id))->latest()->limit(500)->get(),
        ]));
    }

    private function inboxData(Request $request): array
    {
        $search = trim((string) $request->query('q'));
        $status = trim((string) $request->query('status'));
        $channel = trim((string) $request->query('channel'));
        $conversations = WhatsAppConversation::query()
            ->with(['assignee', 'serviceOrder', 'contact.labels', 'lead', 'latestMessage'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(fn (Builder $builder) => $builder
                    ->where('phone', 'like', '%'.$search.'%')
                    ->orWhere('display_name', 'like', '%'.$search.'%'));
            })
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($channel !== '', fn (Builder $query) => $query->where('channel', $channel))
            ->orderByDesc('last_message_at')
            ->paginate(30)
            ->withQueryString();

        return compact('conversations', 'search', 'status', 'channel');
    }

    public function reply(
        Request $request,
        WhatsAppConversation $conversation,
        WhatsAppManager $manager,
        CrmDocumentService $documents,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:10000'],
            'media_url' => ['nullable', 'url', 'max:2048', new SafePublicUrl],
            'message_type' => ['required', 'in:text,image,document,video,audio,media'],
            'crm_document_id' => ['nullable', 'exists:crm_documents,id'],
        ]);

        if (filled($data['media_url'] ?? null) && filled($data['crm_document_id'] ?? null)) {
            return back()->withErrors(['crm_document_id' => 'Pilih URL media atau dokumen vault, jangan keduanya.']);
        }
        if (blank($data['body'] ?? null) && blank($data['media_url'] ?? null) && blank($data['crm_document_id'] ?? null)) {
            return back()->withErrors(['body' => 'Isi balasan, URL media, atau dokumen vault wajib diisi.']);
        }

        $crmDocument = filled($data['crm_document_id'] ?? null) ? CrmDocument::query()->findOrFail($data['crm_document_id']) : null;
        if ($crmDocument && ! $documents->pathExists($crmDocument)) {
            return back()->withErrors(['crm_document_id' => 'Dokumen vault tidak ditemukan di penyimpanan.']);
        }
        $mediaUrl = $crmDocument
            ? $documents->issueProviderAccess($crmDocument, 180, $request->attributes->get('currentUser')?->id)
            : ($data['media_url'] ?? null);

        $isGroup = $conversation->channel === 'group' || $conversation->contact_type === 'group';
        $message = $manager->queueRaw([
            'phone' => $conversation->phone,
            'recipient_name' => $conversation->display_name,
            'channel' => $isGroup ? 'group' : 'personal',
            'body' => $data['body'] ?? null,
            'media_url' => $mediaUrl,
            'crm_document_id' => $crmDocument?->id,
            'message_type' => $crmDocument ? 'document' : $data['message_type'],
            'device_alias' => $conversation->device_alias ?: 'support',
            'conversation_id' => $conversation->id,
            'partner_id' => $conversation->partner_id,
            'inquiry_id' => $conversation->inquiry_id,
            'service_order_id' => $conversation->service_order_id,
            'created_by' => $request->attributes->get('currentUser')?->id,
            'idempotency_key' => 'reply:'.$conversation->id.':'.hash('sha256', json_encode($data).'|'.microtime(true)),
            'metadata' => ['source' => 'admin_inbox', 'conversation_channel' => $isGroup ? 'group' : 'personal'],
        ]);

        if (! $message) {
            return back()->withErrors(['body' => 'Balasan tidak dibuat. Periksa integrasi dan daftar opt-out.']);
        }

        $conversation->forceFill(['status' => $isGroup ? 'open' : 'waiting_customer'])->save();
        $audit->record($request, 'whatsapp.conversation_replied', $conversation, ['message_id' => $message->id]);
        return back()->with('success', 'Balasan masuk antrean.');
    }

    public function update(Request $request, WhatsAppConversation $conversation, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'assigned_to' => ['nullable', 'exists:users,id'],
            'status' => ['required', 'in:open,pending,waiting_customer,closed'],
            'labels' => ['nullable', 'string', 'max:500'],
            'is_ai_blocked' => ['nullable', 'boolean'],
        ]);

        $labels = collect(explode(',', (string) ($data['labels'] ?? '')))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();

        $conversation->update([
            'assigned_to' => $data['assigned_to'] ?? null,
            'status' => $data['status'],
            'labels' => $labels,
            'is_ai_blocked' => $request->boolean('is_ai_blocked'),
        ]);
        $audit->record($request, 'whatsapp.conversation_updated', $conversation, ['status' => $conversation->status]);

        return back()->with('success', 'Percakapan diperbarui.');
    }

    public function aiBlacklist(
        Request $request,
        WhatsAppConversation $conversation,
        StarSenderClient $client,
        WhatsAppAuditService $audit,
        FeatureFlagService $features,
    ): RedirectResponse {
        if ($conversation->channel === 'group' || $conversation->contact_type === 'group') {
            return back()->withErrors(['ai' => 'Blacklist AI provider hanya berlaku untuk percakapan personal.']);
        }

        if (! $features->enabled('whatsapp_ai_assistant')) {
            return back()->withErrors(['ai' => 'Feature flag AI Assistant WhatsApp belum diaktifkan.']);
        }

        $blocked = $request->boolean('blocked');
        try {
            if ($blocked) {
                $client->addAiBlacklist($conversation->phone, 'support');
            } else {
                $client->removeAiBlacklist($conversation->phone, 'support');
            }
            $conversation->update(['is_ai_blocked' => $blocked]);
            $audit->record($request, 'whatsapp.ai_blacklist_updated', $conversation, ['blocked' => $blocked]);
            return back()->with('success', $blocked ? 'Nomor diblokir dari AI provider.' : 'Nomor dikeluarkan dari blacklist AI provider.');
        } catch (Throwable $exception) {
            return back()->withErrors(['ai' => $exception->getMessage()]);
        }
    }
}

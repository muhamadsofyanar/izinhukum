<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppMessage;
use App\Models\WhatsAppMessage;
use App\Rules\SafePublicUrl;
use App\Services\WhatsApp\WhatsAppAuditService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class WhatsAppMessageController extends Controller
{
    public function index(Request $request): View
    {
        $status = trim((string) $request->query('status'));
        $direction = trim((string) $request->query('direction'));
        $search = trim((string) $request->query('q'));

        $messages = WhatsAppMessage::query()
            ->with(['conversation', 'template', 'attemptsLog'])
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($direction !== '', fn (Builder $query) => $query->where('direction', $direction))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $builder) use ($search): void {
                    $builder->where('phone', 'like', '%'.$search.'%')
                        ->orWhere('recipient_name', 'like', '%'.$search.'%')
                        ->orWhere('body', 'like', '%'.$search.'%')
                        ->orWhere('provider_message_id', 'like', '%'.$search.'%');
                });
            })
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.whatsapp.messages', compact('messages', 'status', 'direction', 'search'));
    }

    public function store(
        Request $request,
        WhatsAppManager $manager,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:160'],
            'recipient_name' => ['nullable', 'string', 'max:160'],
            'channel' => ['required', 'in:personal,group'],
            'message_type' => ['required', 'in:text,image,document,video,audio,media'],
            'body' => ['nullable', 'string', 'max:10000'],
            'media_url' => ['nullable', 'url', 'max:2048', new SafePublicUrl],
            'device_alias' => ['required', 'in:default,transaction,support,partner,campaign'],
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
        ]);

        if ($data['message_type'] === 'text' && blank($data['body'] ?? null)) {
            throw ValidationException::withMessages(['body' => 'Pesan teks wajib memiliki isi pesan.']);
        }
        if ($data['message_type'] !== 'text' && blank($data['media_url'] ?? null)) {
            throw ValidationException::withMessages(['media_url' => 'Pesan media wajib memiliki URL media HTTPS yang dapat diakses provider.']);
        }

        try {
            $message = $manager->queueRaw([
                ...$data,
                'created_by' => $request->attributes->get('currentUser')?->id,
                'scheduled_at' => filled($data['scheduled_at'] ?? null) ? Carbon::parse($data['scheduled_at']) : null,
                'idempotency_key' => 'manual:'.hash('sha256', json_encode($data).'|'.microtime(true)),
                'metadata' => ['source' => 'admin_manual'],
            ]);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }

        if (! $message) {
            throw ValidationException::withMessages(['phone' => 'Pesan tidak dibuat. Integrasi mungkin belum aktif atau nomor berada pada daftar blokir.']);
        }

        $audit->record($request, 'whatsapp.message_queued', $message, ['channel' => $message->channel]);
        return redirect()->route('admin.whatsapp.messages.index')->with('success', 'Pesan berhasil dimasukkan ke antrean.');
    }

    public function retry(Request $request, WhatsAppMessage $message, WhatsAppAuditService $audit): RedirectResponse
    {
        if (! in_array($message->status, ['failed', 'retrying'], true)) {
            return back()->withErrors(['message' => 'Hanya pesan gagal yang dapat dicoba ulang.']);
        }

        $message->forceFill(['status' => 'queued', 'attempts' => 0, 'scheduled_at' => null, 'failed_at' => null, 'last_error' => null])->save();
        SendWhatsAppMessage::dispatch($message->id)->onQueue('whatsapp');
        $audit->record($request, 'whatsapp.message_retried', $message);

        return back()->with('success', 'Pesan dimasukkan kembali ke antrean.');
    }

    public function cancel(Request $request, WhatsAppMessage $message, WhatsAppAuditService $audit): RedirectResponse
    {
        if (! in_array($message->status, ['queued', 'scheduled', 'retrying'], true)) {
            return back()->withErrors(['message' => 'Pesan yang sudah diterima provider tidak dapat dibatalkan dari aplikasi.']);
        }

        $message->forceFill(['status' => 'cancelled'])->save();
        $audit->record($request, 'whatsapp.message_cancelled', $message);

        return back()->with('success', 'Pesan dibatalkan.');
    }
}

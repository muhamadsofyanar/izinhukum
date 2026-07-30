<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WhatsAppWebhookMonitorController extends Controller
{
    public function index(Request $request): View
    {
        $status = trim((string) $request->query('status'));
        $events = WhatsAppWebhookEvent::query()
            ->when($status === 'processed', fn (Builder $query) => $query->where('processed', true))
            ->when($status === 'pending', fn (Builder $query) => $query->where('processed', false)->whereNull('processing_error'))
            ->when($status === 'failed', fn (Builder $query) => $query->whereNotNull('processing_error'))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('admin.whatsapp.webhooks.index', [
            'events' => $events,
            'status' => $status,
            'stats' => [
                'total' => WhatsAppWebhookEvent::query()->count(),
                'processed' => WhatsAppWebhookEvent::query()->where('processed', true)->count(),
                'pending' => WhatsAppWebhookEvent::query()->where('processed', false)->whereNull('processing_error')->count(),
                'failed' => WhatsAppWebhookEvent::query()->whereNotNull('processing_error')->count(),
                'latest' => WhatsAppWebhookEvent::query()->latest()->value('created_at'),
            ],
        ]);
    }

    public function retry(WhatsAppWebhookEvent $event): RedirectResponse
    {
        $event->forceFill(['processed' => false, 'processed_at' => null, 'processing_error' => null])->save();
        ProcessWhatsAppWebhook::dispatch($event->id)->onQueue('whatsapp');
        return back()->with('success', 'Webhook dimasukkan kembali ke antrean.');
    }
}

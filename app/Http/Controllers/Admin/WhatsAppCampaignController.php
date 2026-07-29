<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchWhatsAppCampaign;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConsent;
use App\Models\WhatsAppDevice;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\FeatureFlagService;
use App\Services\WhatsApp\PhoneNumberNormalizer;
use App\Services\WhatsApp\WhatsAppAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WhatsAppCampaignController extends Controller
{
    public function index(): View
    {
        return view('admin.whatsapp.campaigns', [
            'campaigns' => WhatsAppCampaign::query()->with('template')->withCount('recipients')->latest()->paginate(20),
            'templates' => WhatsAppTemplate::query()->where('is_enabled', true)->where('is_marketing', true)->orderBy('name')->get(),
        ]);
    }

    public function show(WhatsAppCampaign $campaign): View
    {
        $campaign->load(['template', 'recipients.message']);
        return view('admin.whatsapp.campaign-show', compact('campaign'));
    }

    public function store(
        Request $request,
        PhoneNumberNormalizer $phones,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'template_id' => ['required', 'exists:whatsapp_templates,id'],
            'recipients' => ['required', 'string', 'max:100000'],
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
            'delay_seconds' => ['required', 'integer', 'min:30', 'max:3600'],
            'use_rotator' => ['nullable', 'boolean'],
            'rotator_mode' => ['required', 'in:round_robin,batch'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $template = WhatsAppTemplate::query()->whereKey($data['template_id'])->where('is_marketing', true)->first();
        if (! $template) {
            throw ValidationException::withMessages(['template_id' => 'Campaign hanya dapat memakai template yang ditandai sebagai promosi.']);
        }

        $parsed = collect(preg_split('/\r\n|\r|\n/', $data['recipients']) ?: [])
            ->map(function (string $line) use ($phones): ?array {
                $columns = array_map('trim', str_getcsv($line));
                if (($columns[0] ?? '') === '') {
                    return null;
                }
                try {
                    $phone = $phones->normalize($columns[0]);
                } catch (\Throwable) {
                    return null;
                }
                return ['phone' => $phone, 'name' => $columns[1] ?? null];
            })
            ->filter()
            ->unique('phone')
            ->take(500)
            ->values();

        if ($parsed->isEmpty()) {
            throw ValidationException::withMessages(['recipients' => 'Tidak ditemukan nomor valid. Gunakan satu baris per penerima: nomor,nama.']);
        }

        $rawRecipientCount = collect(preg_split('/\r\n|\r|\n/', $data['recipients']) ?: [])->filter(fn (string $line): bool => trim($line) !== '')->count();
        if ($rawRecipientCount > 500) {
            throw ValidationException::withMessages([
                'recipients' => 'Satu campaign V11 dibatasi maksimal 500 baris agar antrean, audit, dan pemulihan tetap aman. Pecah daftar menjadi beberapa campaign.',
            ]);
        }

        $consented = WhatsAppConsent::query()
            ->whereIn('phone', $parsed->pluck('phone'))
            ->where('allow_marketing', true)
            ->whereNull('revoked_at')
            ->pluck('phone');
        $missingConsent = $parsed->pluck('phone')->diff($consented);
        if ($missingConsent->isNotEmpty()) {
            throw ValidationException::withMessages([
                'recipients' => 'Campaign ditolak karena ada nomor tanpa consent promosi aktif: '.
                    $missingConsent->take(10)->implode(', ').
                    ($missingConsent->count() > 10 ? ' dan '.($missingConsent->count() - 10).' nomor lainnya.' : '.'),
            ]);
        }

        $campaign = WhatsAppCampaign::query()->create([
            'name' => $data['name'],
            'template_id' => $template->id,
            'created_by' => $request->attributes->get('currentUser')?->id,
            'audience_type' => 'manual',
            'status' => filled($data['scheduled_at'] ?? null) ? 'scheduled' : 'draft',
            'use_rotator' => $request->boolean('use_rotator'),
            'rotator_mode' => $data['rotator_mode'],
            'delay_seconds' => $data['delay_seconds'],
            'scheduled_at' => filled($data['scheduled_at'] ?? null) ? Carbon::parse($data['scheduled_at']) : null,
            'recipient_count' => $parsed->count(),
            'notes' => $data['notes'] ?? null,
        ]);

        $campaign->recipients()->createMany($parsed->map(fn (array $row) => [
            ...$row,
            'status' => 'pending',
            'variables' => ['nama_pelanggan' => $row['name'] ?: 'Bapak/Ibu'],
        ])->all());

        $audit->record($request, 'whatsapp.campaign_created', $campaign, ['recipient_count' => $parsed->count()]);
        return redirect()->route('admin.whatsapp.campaigns.show', $campaign)->with('success', 'Campaign dibuat sebagai draf. Periksa penerima sebelum menjalankan.');
    }

    public function dispatch(
        Request $request,
        WhatsAppCampaign $campaign,
        FeatureFlagService $features,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        if (! $features->enabled('whatsapp_campaigns')) {
            return back()->withErrors(['campaign' => 'Feature flag Campaign WhatsApp belum diaktifkan.']);
        }
        if (! in_array($campaign->status, ['draft', 'scheduled', 'paused'], true)) {
            return back()->withErrors(['campaign' => 'Campaign tidak dapat dijalankan dari status saat ini.']);
        }
        if ($campaign->use_rotator && ! $features->enabled('whatsapp_rotator')) {
            return back()->withErrors(['campaign' => 'Campaign memakai rotator, tetapi feature flag rotator belum aktif.']);
        }
        $pending = $campaign->recipients()->where('status', 'pending')->count();
        if ($campaign->use_rotator) {
            $capacity = WhatsAppDevice::query()
                ->where('is_enabled', true)
                ->whereNotNull('provider_id')
                ->get()
                ->sum(function (WhatsAppDevice $device): int {
                    $used = WhatsAppMessage::query()
                        ->where('provider_device_id', $device->provider_id)
                        ->whereDate('accepted_at', today())
                        ->count();

                    return max(0, $device->daily_limit - $used);
                });
            if ($capacity < $pending) {
                return back()->withErrors([
                    'campaign' => "Sisa kapasitas rotator hari ini {$capacity} pesan, sedangkan penerima belum diproses {$pending}. Pecah atau jadwalkan campaign pada hari berikutnya.",
                ]);
            }
        } else {
            $dailyLimit = max(1, min(500, (int) config('starsender.campaign_daily_limit', 50)));
            $used = WhatsAppMessage::query()
                ->where('device_alias', 'campaign')
                ->where('direction', 'outbound')
                ->whereDate('created_at', today())
                ->where('status', '!=', 'cancelled')
                ->count();
            $remaining = max(0, $dailyLimit - $used);
            if ($remaining < $pending) {
                return back()->withErrors([
                    'campaign' => "Sisa batas device campaign hari ini {$remaining} pesan dari batas {$dailyLimit}. Pecah atau jadwalkan campaign pada hari berikutnya.",
                ]);
            }
        }

        $campaign->update(['status' => $campaign->scheduled_at?->isFuture() ? 'scheduled' : 'running']);
        DispatchWhatsAppCampaign::dispatch($campaign->id)->onQueue('whatsapp');
        $audit->record($request, 'whatsapp.campaign_dispatched', $campaign);

        return back()->with('success', 'Campaign masuk antrean.');
    }

    public function cancel(Request $request, WhatsAppCampaign $campaign, WhatsAppAuditService $audit): RedirectResponse
    {
        if (in_array($campaign->status, ['completed', 'cancelled'], true)) {
            return back()->withErrors(['campaign' => 'Campaign sudah selesai atau dibatalkan.']);
        }
        DB::transaction(function () use ($campaign): void {
            $campaign->update(['status' => 'cancelled', 'completed_at' => now()]);

            $recipients = WhatsAppCampaignRecipient::query()
                ->with('message')
                ->where('whatsapp_campaign_id', $campaign->id)
                ->whereIn('status', ['pending', 'queued'])
                ->lockForUpdate()
                ->get();

            foreach ($recipients as $recipient) {
                $message = $recipient->message;
                if ($message && in_array($message->status, ['queued', 'scheduled', 'retrying'], true)) {
                    $message->update(['status' => 'cancelled']);
                }

                if (! $message || in_array($message->status, ['cancelled', 'queued', 'scheduled', 'retrying'], true)) {
                    $recipient->update(['status' => 'cancelled']);
                }
            }
        });
        $audit->record($request, 'whatsapp.campaign_cancelled', $campaign);

        return back()->with('success', 'Penerima yang belum diterima provider telah dibatalkan. Pesan yang sudah diterima provider tidak dapat ditarik.');
    }
}

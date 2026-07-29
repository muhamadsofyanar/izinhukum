<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppConsent;
use App\Models\WhatsAppOptOut;
use App\Services\FeatureFlagService;
use App\Services\WhatsApp\PhoneNumberNormalizer;
use App\Services\WhatsApp\StarSenderClient;
use App\Services\WhatsApp\WhatsAppAuditService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class WhatsAppSettingsController extends Controller
{
    public function index(FeatureFlagService $features, StarSenderClient $client): View
    {
        return view('admin.whatsapp.settings', [
            'features' => collect($features->all())->whereIn('key', [
                'whatsapp', 'whatsapp_transactional', 'whatsapp_inbox', 'whatsapp_campaigns',
                'whatsapp_autoreply', 'whatsapp_ai_assistant', 'whatsapp_rotator', 'whatsapp_provider_tools',
            ])->values(),
            'integration' => [
                'enabled' => (bool) config('starsender.enabled'),
                'base_url' => config('starsender.base_url'),
                'account_key' => $client->hasAccountKey(),
                'transaction_key' => $client->hasDeviceKey('transaction'),
                'support_key' => $client->hasDeviceKey('support'),
                'partner_key' => $client->hasDeviceKey('partner'),
                'campaign_key' => $client->hasDeviceKey('campaign'),
                'webhook_secret' => trim((string) config('starsender.webhook_secret')) !== '',
                'rotator' => (bool) config('starsender.rotator_enabled'),
                'premium_webhook' => (bool) config('starsender.premium_webhook_enabled'),
                'media_webhook' => (bool) config('starsender.media_webhook_enabled'),
                'group_webhook' => (bool) config('starsender.group_webhook_enabled'),
            ],
            'consents' => WhatsAppConsent::query()->latest('consented_at')->paginate(20),
            'webhookUrl' => trim((string) config('starsender.webhook_secret')) !== ''
                ? route('webhooks.starsender', config('starsender.webhook_secret'))
                : null,
        ]);
    }

    public function testMessage(
        Request $request,
        WhatsAppManager $manager,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'body' => ['required', 'string', 'min:3', 'max:3000'],
            'device_alias' => ['required', 'in:transaction,support,partner,campaign,default'],
        ]);

        try {
            $message = $manager->queueRaw([
                'phone' => $data['phone'],
                'body' => $data['body'],
                'device_alias' => $data['device_alias'],
                'created_by' => $request->attributes->get('currentUser')?->id,
                'idempotency_key' => 'manual-test:'.hash('sha256', $data['phone'].'|'.$data['body'].'|'.now()->format('YmdHis')),
                'metadata' => ['source' => 'admin_test'],
            ]);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }

        if (! $message) {
            throw ValidationException::withMessages(['phone' => 'Pesan tidak dibuat. Periksa feature flag, konfigurasi environment, atau daftar opt-out.']);
        }

        $audit->record($request, 'whatsapp.test_queued', $message, ['device_alias' => $data['device_alias']]);
        return back()->with('success', 'Pesan uji masuk antrean. Periksa Riwayat pesan untuk hasilnya.');
    }

    public function storeConsent(
        Request $request,
        PhoneNumberNormalizer $phones,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'source' => ['required', 'string', 'max:80'],
            'evidence' => ['required', 'string', 'min:5', 'max:3000'],
            'allow_transactional' => ['nullable', 'boolean'],
            'allow_marketing' => ['nullable', 'boolean'],
        ]);
        try {
            $phone = $phones->normalize($data['phone']);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }

        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Nomor WhatsApp tidak valid.']);
        }

        $consent = WhatsAppConsent::query()->updateOrCreate(
            ['phone' => $phone],
            [
                'allow_transactional' => $request->boolean('allow_transactional'),
                'allow_marketing' => $request->boolean('allow_marketing'),
                'source' => $data['source'],
                'evidence' => $data['evidence'],
                'consented_at' => now(),
                'revoked_at' => null,
                'created_by' => $request->attributes->get('currentUser')?->id,
            ],
        );
        if ($consent->allow_marketing) {
            WhatsAppOptOut::query()->where('phone', $phone)->update([
                'block_marketing' => false,
                'updated_at' => now(),
            ]);
        }

        $audit->record($request, 'whatsapp.consent_recorded', $consent, ['marketing' => $consent->allow_marketing]);
        return back()->with('success', 'Persetujuan WhatsApp dicatat.');
    }

    public function revokeConsent(
        Request $request,
        WhatsAppConsent $consent,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $consent->update(['allow_marketing' => false, 'revoked_at' => now()]);
        WhatsAppOptOut::query()->updateOrCreate(
            ['phone' => $consent->phone],
            [
                'block_marketing' => true,
                'block_ai' => false,
                'block_all' => false,
                'source' => 'admin',
                'reason' => 'Persetujuan promosi dicabut dari panel admin.',
                'opted_out_at' => now(),
                'created_by' => $request->attributes->get('currentUser')?->id,
            ],
        );
        $audit->record($request, 'whatsapp.consent_revoked', $consent);
        return back()->with('success', 'Persetujuan promosi dicabut.');
    }

    public function checkNumber(
        Request $request,
        StarSenderClient $client,
        PhoneNumberNormalizer $phones,
    ): RedirectResponse {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        try {
            $number = $phones->normalize($data['phone']);
            $response = $client->checkNumber((string) $number, 'transaction');
            return back()->with('success', 'Hasil pemeriksaan nomor: '.json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $exception) {
            return back()->withErrors(['phone' => $exception->getMessage()]);
        }
    }
}

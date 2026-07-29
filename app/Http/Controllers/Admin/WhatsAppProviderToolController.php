<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FeatureFlagService;
use App\Services\WhatsApp\PhoneNumberNormalizer;
use App\Services\WhatsApp\StarSenderClient;
use App\Services\WhatsApp\WhatsAppAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class WhatsAppProviderToolController extends Controller
{
    public function index(StarSenderClient $client, FeatureFlagService $features): View
    {
        $groups = [];
        $providerError = null;

        if (config('starsender.enabled') && $client->hasAccountKey()) {
            try {
                $response = Cache::remember(
                    'starsender:contact-groups',
                    now()->addMinutes(5),
                    fn (): array => $client->listContactGroups(),
                );
                $groups = (array) (
                    data_get($response, 'data.groups')
                    ?? data_get($response, 'data.contact_groups')
                    ?? data_get($response, 'data')
                    ?? []
                );
            } catch (Throwable $exception) {
                $providerError = $exception->getMessage();
            }
        }

        return view('admin.whatsapp.provider-tools', [
            'groups' => collect($groups)->filter(fn (mixed $group): bool => is_array($group) || is_object($group))->values(),
            'providerReady' => config('starsender.enabled') && $client->hasAccountKey(),
            'campaignDeviceReady' => $client->hasDeviceKey('campaign'),
            'providerError' => $providerError,
            'writeEnabled' => $features->enabled('whatsapp_provider_tools'),
        ]);
    }

    public function createContact(
        Request $request,
        StarSenderClient $client,
        PhoneNumberNormalizer $phones,
        WhatsAppAuditService $audit,
        FeatureFlagService $features,
    ): RedirectResponse {
        $this->assertProviderToolsEnabled($features);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'number' => ['required', 'string', 'max:32'],
            'group_id' => ['nullable', 'integer', 'min:1'],
            'variables' => ['nullable', 'string', 'max:3000'],
        ]);

        try {
            $number = $phones->normalize($data['number']);
            $variables = collect(preg_split('/\r\n|\r|\n/', (string) ($data['variables'] ?? '')) ?: [])
                ->map(fn (string $value): string => trim($value))
                ->filter()
                ->take(30)
                ->values()
                ->all();

            $response = $client->createContact(array_filter([
                'name' => $data['name'],
                'number' => $number,
                'variabel' => $variables !== [] ? $variables : null,
                'group_id' => $data['group_id'] ?? null,
            ], fn (mixed $value): bool => $value !== null));

            Cache::forget('starsender:contact-groups');
            $audit->record($request, 'whatsapp.provider_contact_created', null, [
                'phone_hash' => hash('sha256', (string) $number),
                'group_id' => $data['group_id'] ?? null,
            ]);

            return back()->with('success', 'Kontak dikirim ke StarSender. Respons: '.$this->safeResponse($response));
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['number' => $exception->getMessage()]);
        }
    }

    public function removeContactFromGroup(
        Request $request,
        StarSenderClient $client,
        PhoneNumberNormalizer $phones,
        WhatsAppAuditService $audit,
        FeatureFlagService $features,
    ): RedirectResponse {
        $this->assertProviderToolsEnabled($features);
        $data = $request->validate([
            'number' => ['required', 'string', 'max:32'],
            'group_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $number = $phones->normalize($data['number']);
            $response = $client->removeContactFromGroup((string) $number, (int) $data['group_id']);
            $audit->record($request, 'whatsapp.provider_contact_removed_from_group', null, [
                'phone_hash' => hash('sha256', (string) $number),
                'group_id' => (int) $data['group_id'],
            ]);

            return back()->with('success', 'Kontak dikeluarkan dari grup. Respons: '.$this->safeResponse($response));
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['number' => $exception->getMessage()]);
        }
    }

    public function moveContactGroup(
        Request $request,
        StarSenderClient $client,
        PhoneNumberNormalizer $phones,
        WhatsAppAuditService $audit,
        FeatureFlagService $features,
    ): RedirectResponse {
        $this->assertProviderToolsEnabled($features);
        $data = $request->validate([
            'number' => ['required', 'string', 'max:32'],
            'from_group_id' => ['required', 'integer', 'min:1', 'different:to_group_id'],
            'to_group_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $number = $phones->normalize($data['number']);
            $response = $client->moveContactBetweenGroups(
                (string) $number,
                (int) $data['from_group_id'],
                (int) $data['to_group_id'],
            );
            $audit->record($request, 'whatsapp.provider_contact_moved_group', null, [
                'phone_hash' => hash('sha256', (string) $number),
                'from_group_id' => (int) $data['from_group_id'],
                'to_group_id' => (int) $data['to_group_id'],
            ]);

            return back()->with('success', 'Kontak dipindahkan antargrup. Respons: '.$this->safeResponse($response));
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['number' => $exception->getMessage()]);
        }
    }

    public function createProviderCampaign(
        Request $request,
        StarSenderClient $client,
        PhoneNumberNormalizer $phones,
        WhatsAppAuditService $audit,
        FeatureFlagService $features,
    ): RedirectResponse {
        $this->assertProviderToolsEnabled($features);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'syntax' => ['required', 'string', 'max:1000'],
            'welcome_message' => ['required', 'string', 'max:3000'],
            'number' => ['required', 'string', 'max:32'],
        ]);

        try {
            $number = $phones->normalize($data['number']);
            $response = $client->createProviderCampaignFromConfiguredDevice(
                $data['name'],
                $data['syntax'],
                $data['welcome_message'],
                (string) $number,
            );
            $audit->record($request, 'whatsapp.provider_campaign_created', null, [
                'name' => $data['name'],
                'device_phone_hash' => hash('sha256', (string) $number),
            ]);

            return back()->with('success', 'Campaign provider dibuat. Respons: '.$this->safeResponse($response));
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }
    }

    public function addProviderCampaignMember(
        Request $request,
        StarSenderClient $client,
        PhoneNumberNormalizer $phones,
        WhatsAppAuditService $audit,
        FeatureFlagService $features,
    ): RedirectResponse {
        $this->assertProviderToolsEnabled($features);
        $data = $request->validate([
            'campaign_id' => ['required', 'integer', 'min:1'],
            'number' => ['required', 'string', 'max:32'],
            'syntax' => ['required', 'string', 'max:3000'],
            'welcome_message' => ['nullable', 'boolean'],
        ]);

        try {
            $number = $phones->normalize($data['number']);
            $response = $client->addProviderCampaignMember([
                'campaign_id' => (int) $data['campaign_id'],
                'number' => $number,
                'syntax' => $data['syntax'],
                'welcome_message' => $request->boolean('welcome_message'),
            ]);
            $audit->record($request, 'whatsapp.provider_campaign_member_added', null, [
                'campaign_id' => (int) $data['campaign_id'],
                'phone_hash' => hash('sha256', (string) $number),
            ]);

            return back()->with('success', 'Anggota campaign provider ditambahkan. Respons: '.$this->safeResponse($response));
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['campaign_id' => $exception->getMessage()]);
        }
    }

    public function moveProviderCampaignMember(
        Request $request,
        StarSenderClient $client,
        PhoneNumberNormalizer $phones,
        WhatsAppAuditService $audit,
        FeatureFlagService $features,
    ): RedirectResponse {
        $this->assertProviderToolsEnabled($features);
        $data = $request->validate([
            'campaign_id_from' => ['required', 'integer', 'min:1', 'different:campaign_id_to'],
            'campaign_id_to' => ['required', 'integer', 'min:1'],
            'number' => ['required', 'string', 'max:32'],
        ]);

        try {
            $number = $phones->normalize($data['number']);
            $response = $client->moveProviderCampaignMember([
                'campaign_id_from' => (int) $data['campaign_id_from'],
                'campaign_id_to' => (int) $data['campaign_id_to'],
                'number' => $number,
            ]);
            $audit->record($request, 'whatsapp.provider_campaign_member_moved', null, [
                'campaign_id_from' => (int) $data['campaign_id_from'],
                'campaign_id_to' => (int) $data['campaign_id_to'],
                'phone_hash' => hash('sha256', (string) $number),
            ]);

            return back()->with('success', 'Anggota dipindahkan antar-campaign provider. Respons: '.$this->safeResponse($response));
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['campaign_id_from' => $exception->getMessage()]);
        }
    }

    private function assertProviderToolsEnabled(FeatureFlagService $features): void
    {
        if (! $features->enabled('whatsapp_provider_tools')) {
            throw ValidationException::withMessages([
                'provider' => 'Aktifkan feature flag Alat provider StarSender sebelum menjalankan operasi tulis.',
            ]);
        }
    }

    private function safeResponse(array $response): string
    {
        return (string) (data_get($response, 'message') ?: 'operasi diterima');
    }
}

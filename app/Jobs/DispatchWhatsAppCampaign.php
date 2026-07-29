<?php

namespace App\Jobs;

use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppDevice;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\PhoneNumberNormalizer;
use App\Services\WhatsApp\StarSenderClient;
use App\Services\WhatsApp\WhatsAppCampaignProgressService;
use App\Services\WhatsApp\WhatsAppManager;
use App\Services\WhatsApp\WhatsAppTemplateRenderer;
use App\Services\FeatureFlagService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DispatchWhatsAppCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $campaignId)
    {
    }

    public function handle(
        WhatsAppManager $manager,
        WhatsAppTemplateRenderer $renderer,
        PhoneNumberNormalizer $phoneNumbers,
        StarSenderClient $client,
        FeatureFlagService $features,
        WhatsAppCampaignProgressService $progress,
    ): void {
        if (! $features->enabled('whatsapp_campaigns')) {
            return;
        }

        $campaign = WhatsAppCampaign::query()->with(['template', 'recipients'])->find($this->campaignId);
        if (! $campaign || in_array($campaign->status, ['completed', 'cancelled'], true)) {
            return;
        }

        if ($campaign->scheduled_at?->isFuture()) {
            $this->release(max(1, now()->diffInSeconds($campaign->scheduled_at, false)));
            return;
        }

        $campaign->forceFill(['status' => 'running', 'started_at' => $campaign->started_at ?: now()])->save();

        if ($campaign->use_rotator && $features->enabled('whatsapp_rotator') && config('starsender.rotator_enabled') && $client->hasAccountKey()) {
            $this->dispatchRotator($campaign, $renderer, $phoneNumbers, $client, $manager);
        } else {
            foreach ($campaign->recipients()->where('status', 'pending')->get() as $recipient) {
                try {
                    $variables = [
                        ...(array) $recipient->variables,
                        'nama_pelanggan' => $recipient->name ?: 'Bapak/Ibu',
                    ];
                    $message = $manager->queueRaw([
                        'template_id' => $campaign->template_id,
                        'phone' => $recipient->phone,
                        'recipient_name' => $recipient->name,
                        'body' => $renderer->render($campaign->template?->body ?? '', $variables),
                        'message_type' => $campaign->template?->message_type ?? 'text',
                        'media_url' => $campaign->template?->media_url,
                        'device_alias' => 'campaign',
                        'idempotency_key' => 'campaign:'.$campaign->id.':'.$recipient->id,
                        'is_marketing' => true,
                        'metadata' => [
                            'campaign_id' => $campaign->id,
                            'delay' => max(30, $campaign->delay_seconds),
                        ],
                    ]);

                    $recipient->update([
                        'whatsapp_message_id' => $message?->id,
                        'status' => $message ? 'queued' : 'skipped',
                    ]);
                } catch (Throwable $exception) {
                    $recipient->update(['status' => 'failed', 'error' => $exception->getMessage()]);
                }
            }
        }

        $progress->refresh($campaign);
    }

    private function dispatchRotator(
        WhatsAppCampaign $campaign,
        WhatsAppTemplateRenderer $renderer,
        PhoneNumberNormalizer $phoneNumbers,
        StarSenderClient $client,
        WhatsAppManager $manager,
    ): void {
        $devices = WhatsAppDevice::query()
            ->where('is_enabled', true)
            ->whereNotNull('provider_id')
            ->get()
            ->map(function (WhatsAppDevice $device): ?array {
                $usedToday = WhatsAppMessage::query()
                    ->where('provider_device_id', $device->provider_id)
                    ->whereDate('accepted_at', today())
                    ->count();
                $remaining = max(0, $device->daily_limit - $usedToday);

                return $remaining > 0 ? [
                    'device_id' => $device->provider_id,
                    'limit' => $remaining,
                ] : null;
            })
            ->filter()
            ->values()
            ->all();

        if ($devices === []) {
            throw new \RuntimeException('Rotator aktif, tetapi belum ada device lokal yang memiliki provider ID.');
        }

        $localMessages = [];
        $payloadMessages = [];
        foreach ($campaign->recipients()->where('status', 'pending')->get() as $recipient) {
            $phone = $phoneNumbers->normalize($recipient->phone);
            if (! $phone || $manager->isBlocked($phone, true)) {
                $recipient->update(['status' => 'skipped', 'error' => 'Nomor diblokir atau tidak valid.']);
                continue;
            }

            $variables = [
                ...(array) $recipient->variables,
                'nama_pelanggan' => $recipient->name ?: 'Bapak/Ibu',
            ];
            $body = $renderer->render($campaign->template?->body ?? '', $variables);
            $message = WhatsAppMessage::query()->firstOrCreate(
                ['idempotency_key' => 'campaign:'.$campaign->id.':'.$recipient->id],
                [
                    'template_id' => $campaign->template_id,
                    'direction' => 'outbound',
                    'channel' => 'personal',
                    'phone' => $phone,
                    'recipient_name' => $recipient->name,
                    'message_type' => $campaign->template?->message_type ?? 'text',
                    'body' => $body,
                    'media_url' => $campaign->template?->media_url,
                    'device_alias' => 'campaign',
                    'status' => 'processing',
                    'metadata' => ['campaign_id' => $campaign->id, 'rotator' => true],
                ],
            );
            $recipient->update(['whatsapp_message_id' => $message->id, 'status' => 'processing']);
            $localMessages[] = [$recipient, $message];
            $payloadMessages[] = array_filter([
                'to' => $phone,
                'messageType' => $message->message_type === 'text' ? 'text' : 'media',
                'body' => $body,
                'file' => $message->media_url,
                'delay' => max(30, $campaign->delay_seconds),
            ]);
        }

        if ($payloadMessages === []) {
            return;
        }

        try {
            $response = $client->sendRotator([
                'mode' => $campaign->rotator_mode,
                'devices' => $devices,
                'messages' => $payloadMessages,
            ]);
            $providerMessages = (array) data_get($response, 'data.messages', []);

            foreach ($localMessages as $index => [$recipient, $message]) {
                $provider = $providerMessages[$index] ?? [];
                $message->update([
                    'status' => 'accepted',
                    'provider_message_id' => data_get($provider, 'message_id'),
                    'provider_device_id' => data_get($provider, 'device_id'),
                    'provider_response' => $provider,
                    'accepted_at' => now(),
                ]);
                $recipient->update(['status' => 'queued']);
            }
        } catch (Throwable $exception) {
            foreach ($localMessages as [$recipient, $message]) {
                $message->update(['status' => 'failed', 'failed_at' => now(), 'last_error' => $exception->getMessage()]);
                $recipient->update(['status' => 'failed', 'error' => $exception->getMessage()]);
            }
            throw $exception;
        }
    }

}

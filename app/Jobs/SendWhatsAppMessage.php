<?php

namespace App\Jobs;

use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppMessageAttempt;
use App\Services\FeatureFlagService;
use App\Services\WhatsApp\StarSenderClient;
use App\Services\WhatsApp\WhatsAppCampaignProgressService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendWhatsAppMessage implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $messageId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->messageId;
    }

    public function handle(
        StarSenderClient $client,
        FeatureFlagService $features,
        WhatsAppCampaignProgressService $campaignProgress,
    ): void {
        $message = WhatsAppMessage::query()->find($this->messageId);
        if (! $message || in_array($message->status, ['accepted', 'sent', 'cancelled', 'received'], true)) {
            return;
        }

        if (! config('starsender.enabled') || ! $features->enabled('whatsapp')) {
            return;
        }

        if ($message->scheduled_at?->isFuture()) {
            $this->release(max(1, now()->diffInSeconds($message->scheduled_at, false)));
            return;
        }

        $attemptNumber = max(1, ((int) $message->attempts) + 1);
        $claim = WhatsAppMessage::query()
            ->whereKey($this->messageId)
            ->where(function ($query) use ($attemptNumber): void {
                $query->whereIn('status', ['queued', 'scheduled', 'retrying']);
                if ($attemptNumber > 1) {
                    $query->orWhere(function ($stale): void {
                        $stale->where('status', 'processing')
                            ->where('updated_at', '<=', now()->subMinutes(3));
                    });
                }
            })
            ->update([
                'status' => 'processing',
                'attempts' => $attemptNumber,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($claim !== 1) {
            return;
        }

        $message = WhatsAppMessage::query()->findOrFail($this->messageId);
        $payload = array_filter([
            'messageType' => $message->message_type === 'text' ? 'text' : 'media',
            'to' => $message->phone,
            'body' => $message->body,
            'file' => $message->media_url,
            'delay' => (int) data_get($message->metadata, 'delay', config('starsender.default_delay', 2)),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            $response = $client->send($payload, $message->device_alias, $message->channel === 'group');
            $providerId = $client->extractProviderMessageId($response);

            WhatsAppMessageAttempt::query()->updateOrCreate(
                ['whatsapp_message_id' => $message->id, 'attempt_number' => $attemptNumber],
                [
                    'http_status' => data_get($response, '_http_status'),
                    'success' => true,
                    'request_payload' => $payload,
                    'response_payload' => $response,
                    'attempted_at' => now(),
                ],
            );

            $finalStatus = $providerId ? 'accepted' : 'sent';
            $message->forceFill([
                'status' => $finalStatus,
                'provider_message_id' => $providerId,
                'provider_response' => $response,
                'accepted_at' => now(),
                'sent_at' => $finalStatus === 'sent' ? now() : null,
                'scheduled_at' => null,
                'last_error' => null,
            ])->save();

            $this->updateCampaignRecipient($message, $finalStatus === 'sent' ? 'sent' : 'queued', null, $campaignProgress);

            if ($message->conversation) {
                $message->conversation->forceFill([
                    'last_message_at' => now(),
                    'last_outbound_at' => now(),
                ])->save();
            }
        } catch (Throwable $exception) {
            WhatsAppMessageAttempt::query()->updateOrCreate(
                ['whatsapp_message_id' => $message->id, 'attempt_number' => $attemptNumber],
                [
                    'success' => false,
                    'request_payload' => $payload,
                    'error' => $exception->getMessage(),
                    'attempted_at' => now(),
                ],
            );

            $maxAttempts = max(1, min(10, (int) config('starsender.max_attempts', 4)));
            $willRetry = $attemptNumber < $maxAttempts;
            $retryDelays = [120, 600, 1800];
            $retryDelay = $retryDelays[max(0, $attemptNumber - 1)] ?? 1800;
            $message->forceFill([
                'status' => $willRetry ? 'retrying' : 'failed',
                'scheduled_at' => $willRetry ? now()->addSeconds((int) $retryDelay) : null,
                'last_error' => $exception->getMessage(),
                'failed_at' => $willRetry ? null : now(),
            ])->save();

            $this->updateCampaignRecipient(
                $message,
                $willRetry ? 'queued' : 'failed',
                $willRetry ? null : $exception->getMessage(),
                $campaignProgress,
            );

            // Retry dikendalikan oleh scheduled_at dan scheduler internal agar tidak ada
            // dua mekanisme retry yang dapat mengirim pesan ganda.
            return;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = WhatsAppMessage::query()->find($this->messageId);
        if (! $message || in_array($message->status, ['accepted', 'sent', 'cancelled', 'received'], true)) {
            return;
        }

        $message->forceFill([
            'status' => 'failed',
            'failed_at' => now(),
            'last_error' => $exception?->getMessage() ?: 'Job pengiriman gagal setelah seluruh percobaan.',
        ])->save();

        try {
            $this->updateCampaignRecipient(
                $message,
                'failed',
                $message->last_error,
                app(WhatsAppCampaignProgressService::class),
            );
        } catch (Throwable) {
            // Status pesan utama tetap tersimpan walau pembaruan ringkasan campaign gagal.
        }
    }

    private function updateCampaignRecipient(
        WhatsAppMessage $message,
        string $status,
        ?string $error,
        WhatsAppCampaignProgressService $campaignProgress,
    ): void {
        $recipient = WhatsAppCampaignRecipient::query()
            ->where('whatsapp_message_id', $message->id)
            ->first();
        if (! $recipient) {
            return;
        }

        $recipient->forceFill(['status' => $status, 'error' => $error])->save();
        $campaign = WhatsAppCampaign::query()->find($recipient->whatsapp_campaign_id);
        if ($campaign) {
            $campaignProgress->refresh($campaign);
        }
    }
}

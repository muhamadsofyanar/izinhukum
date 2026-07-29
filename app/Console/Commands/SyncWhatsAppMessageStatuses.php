<?php

namespace App\Console\Commands;

use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\StarSenderClient;
use App\Services\WhatsApp\WhatsAppCampaignProgressService;
use Illuminate\Console\Command;
use Throwable;

class SyncWhatsAppMessageStatuses extends Command
{
    protected $signature = 'whatsapp:sync-status {--limit=100}';

    protected $description = 'Memperbarui status pesan yang sudah diterima StarSender.';

    public function handle(StarSenderClient $client, WhatsAppCampaignProgressService $progress): int
    {
        if (! config('starsender.enabled') || ! $client->hasAccountKey()) {
            $this->warn('Integrasi atau Account API Key belum aktif. Sinkronisasi dilewati.');
            return self::SUCCESS;
        }

        $updated = 0;
        $campaignIds = [];
        WhatsAppMessage::query()
            ->whereIn('status', ['accepted', 'processing'])
            ->whereNotNull('provider_message_id')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->each(function (WhatsAppMessage $message) use ($client, &$updated, &$campaignIds): void {
                try {
                    $response = $client->messageDetail($message->provider_message_id);
                    $providerStatus = strtolower((string) (
                        data_get($response, 'data.status')
                        ?? data_get($response, 'status')
                        ?? data_get($response, 'data.message.status')
                        ?? ''
                    ));

                    $status = match (true) {
                        in_array($providerStatus, ['sent', 'delivered', 'read', 'success', 'sukses'], true) => 'sent',
                        in_array($providerStatus, ['failed', 'error', 'rejected', 'cancelled', 'canceled'], true) => 'failed',
                        default => null,
                    };

                    if (! $status) {
                        return;
                    }

                    $message->forceFill([
                        'status' => $status,
                        'sent_at' => $status === 'sent' ? ($message->sent_at ?: now()) : $message->sent_at,
                        'failed_at' => $status === 'failed' ? now() : null,
                        'last_error' => $status === 'failed' ? (string) (data_get($response, 'message') ?: 'Pengiriman ditandai gagal oleh provider.') : null,
                        'provider_response' => $response,
                    ])->save();

                    $recipients = WhatsAppCampaignRecipient::query()
                        ->where('whatsapp_message_id', $message->id)
                        ->get();
                    foreach ($recipients as $recipient) {
                        $recipient->update([
                            'status' => $status,
                            'error' => $status === 'failed' ? $message->last_error : null,
                        ]);
                        $campaignIds[$recipient->whatsapp_campaign_id] = true;
                    }
                    $updated++;
                } catch (Throwable $exception) {
                    $message->forceFill(['last_error' => 'Sinkronisasi status: '.$exception->getMessage()])->save();
                }
            });

        foreach (array_keys($campaignIds) as $campaignId) {
            $campaign = WhatsAppCampaign::query()->find($campaignId);
            if ($campaign) {
                $progress->refresh($campaign);
            }
        }

        $this->info("{$updated} status pesan diperbarui.");
        return self::SUCCESS;
    }
}

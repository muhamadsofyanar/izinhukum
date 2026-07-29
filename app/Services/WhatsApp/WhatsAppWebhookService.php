<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConsent;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppOptOut;
use App\Models\WhatsAppWebhookEvent;
use App\Services\FeatureFlagService;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppWebhookService
{
    public function __construct(
        private readonly PhoneNumberNormalizer $phoneNumbers,
        private readonly WhatsAppManager $messages,
        private readonly StarSenderClient $client,
        private readonly FeatureFlagService $features,
        private readonly WhatsAppCampaignProgressService $campaignProgress,
    ) {
    }

    public function process(int $eventId): void
    {
        $event = WhatsAppWebhookEvent::query()->find($eventId);
        if (! $event || $event->processed) {
            return;
        }

        try {
            $payload = (array) $event->payload;
            $providerMessageId = (string) ($payload['message_id'] ?? $payload['id'] ?? '');
            $isMe = filter_var($payload['is_me'] ?? false, FILTER_VALIDATE_BOOL);

            if ($isMe) {
                if ($providerMessageId !== '') {
                    $message = WhatsAppMessage::query()
                        ->where('provider_message_id', $providerMessageId)
                        ->where('direction', 'outbound')
                        ->first();
                    if ($message) {
                        $message->forceFill(['status' => 'sent', 'sent_at' => $message->sent_at ?: now()])->save();
                        $recipient = WhatsAppCampaignRecipient::query()
                            ->where('whatsapp_message_id', $message->id)
                            ->first();
                        if ($recipient) {
                            $recipient->forceFill(['status' => 'sent', 'error' => null])->save();
                            $campaign = WhatsAppCampaign::query()->find($recipient->whatsapp_campaign_id);
                            if ($campaign) {
                                $this->campaignProgress->refresh($campaign);
                            }
                        }
                    }
                }
                $this->complete($event);
                return;
            }

            $chatType = (string) ($payload['chat_type'] ?? 'personal');
            $isGroup = filter_var($payload['is_group'] ?? false, FILTER_VALIDATE_BOOL) || $chatType === 'group';
            if ($isGroup) {
                if (! config('starsender.group_webhook_enabled')) {
                    $this->complete($event);
                    return;
                }
                $this->storeGroupInbound($event, $payload, $providerMessageId);
                $this->complete($event);
                return;
            }

            if (! $this->features->enabled('whatsapp_inbox')) {
                // Opt-out harus tetap diproses walau Inbox dimatikan. Ini mencegah campaign
                // berikutnya mengirim pesan promosi kepada nomor yang sudah meminta berhenti.
                $bodyWithoutInbox = trim((string) ($payload['message'] ?? $payload['body'] ?? ''));
                if ($this->isOptOutCommand($bodyWithoutInbox)) {
                    $phoneWithoutInbox = $this->phoneNumbers->normalize((string) ($payload['from'] ?? $payload['phone'] ?? ''));
                    if ($phoneWithoutInbox) {
                        $this->recordOptOut($phoneWithoutInbox, strtoupper(trim($bodyWithoutInbox)), null);
                    }
                }
                $this->complete($event);
                return;
            }

            $phone = $this->phoneNumbers->normalize((string) ($payload['from'] ?? $payload['phone'] ?? ''));
            if (! $phone) {
                throw new \RuntimeException('Webhook tidak memiliki nomor pengirim yang valid.');
            }

            $name = trim((string) ($payload['push_name'] ?? $payload['name'] ?? '')) ?: null;
            $body = trim((string) ($payload['message'] ?? $payload['body'] ?? ''));
            $media = trim((string) ($payload['file'] ?? '')) ?: null;
            $conversation = $this->messages->conversation($phone, $name);
            $this->messages->identifyConversation($conversation);

            WhatsAppMessage::query()->firstOrCreate(
                ['idempotency_key' => 'webhook:'.$event->fingerprint],
                [
                    'conversation_id' => $conversation->id,
                    'inquiry_id' => $conversation->inquiry_id,
                    'service_order_id' => $conversation->service_order_id,
                    'partner_id' => $conversation->partner_id,
                    'direction' => 'inbound',
                    'channel' => 'personal',
                    'phone' => $phone,
                    'recipient_name' => $name,
                    'message_type' => $media ? 'media' : 'text',
                    'body' => $body,
                    'media_url' => $media,
                    'status' => 'received',
                    'provider_message_id' => $providerMessageId ?: null,
                    'sent_at' => now(),
                    'metadata' => [
                        'device_id' => $payload['device_id'] ?? null,
                        'device_name' => $payload['device_name'] ?? null,
                        'quoted_message' => $payload['quoted_message'] ?? null,
                    ],
                ],
            );

            $conversation->forceFill([
                'display_name' => $name ?: $conversation->display_name,
                'unread_count' => $conversation->unread_count + 1,
                'last_message_at' => now(),
                'last_inbound_at' => now(),
                'status' => $conversation->status === 'closed' ? 'open' : $conversation->status,
            ])->save();

            $this->handleCommand($conversation->phone, $body, $conversation->id);
            $this->complete($event);
        } catch (Throwable $exception) {
            $event->forceFill(['processing_error' => $exception->getMessage()])->save();
            Log::warning('Gagal memproses webhook StarSender.', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    private function storeGroupInbound(WhatsAppWebhookEvent $event, array $payload, string $providerMessageId): void
    {
        WhatsAppMessage::query()->firstOrCreate(
            ['idempotency_key' => 'webhook:'.$event->fingerprint],
            [
                'direction' => 'inbound',
                'channel' => 'group',
                'phone' => (string) ($payload['from'] ?? 'group'),
                'recipient_name' => $payload['push_name'] ?? null,
                'message_type' => ! empty($payload['file']) ? 'media' : 'text',
                'body' => $payload['message'] ?? null,
                'media_url' => $payload['file'] ?? null,
                'status' => 'received',
                'provider_message_id' => $providerMessageId ?: null,
                'sent_at' => now(),
                'metadata' => $payload,
            ],
        );
    }

    private function handleCommand(string $phone, string $body, int $conversationId): void
    {
        $command = strtoupper(trim($body));
        if ($command === '') {
            return;
        }

        if ($this->isOptOutCommand($command)) {
            $this->recordOptOut($phone, $command, $conversationId);
            return;
        }

        if ($command === 'ADMIN') {
            $this->setAiBlocked($conversationId, $phone);
            $this->messages->queueTemplate(
                'support_handoff',
                $phone,
                null,
                [],
                [],
                'handoff:'.$phone.':'.now()->format('YmdH'),
                null,
                'support',
            );
            return;
        }

        if (! $this->features->enabled('whatsapp_autoreply')) {
            return;
        }

        if (in_array($command, ['HELP', 'BANTUAN', 'MENU'], true)) {
            $this->messages->queueTemplate('keyword_help', $phone, null, [], [], 'help:'.$phone.':'.now()->format('YmdH'), null, 'support');
            return;
        }

        if ($command === 'STATUS') {
            $order = $this->messages->latestOrderForPhone($phone);
            $body = $order
                ? "Status order {$order->order_number}: {$order->statusLabel()}\nProgres: {$order->progress}%\n".route('customer.orders.show', $order->public_token)
                : 'Belum ditemukan order aktif untuk nomor ini. Ketik ADMIN agar tim kami membantu.';
            $this->messages->queueRaw([
                'phone' => $phone,
                'body' => $body,
                'device_alias' => 'support',
                'service_order_id' => $order?->id,
                'idempotency_key' => 'status-reply:'.$phone.':'.now()->format('YmdHi'),
            ]);
            return;
        }

        if ($command === 'INVOICE') {
            $invoice = $this->messages->latestActiveInvoiceForPhone($phone);
            $body = $invoice
                ? "Invoice {$invoice->invoice_number}\nTotal: {$invoice->formattedTotal()}\nStatus: {$invoice->status}\n".route('invoices.public', $invoice->public_token)
                : 'Tidak ditemukan invoice aktif untuk nomor ini. Ketik ADMIN agar tim kami membantu.';
            $this->messages->queueRaw([
                'phone' => $phone,
                'body' => $body,
                'device_alias' => 'support',
                'invoice_id' => $invoice?->id,
                'idempotency_key' => 'invoice-reply:'.$phone.':'.now()->format('YmdHi'),
            ]);
            return;
        }

        $this->handleConfiguredKeyword($phone, $command);
    }

    private function handleConfiguredKeyword(string $phone, string $command): void
    {
        $automations = WhatsAppAutomation::query()
            ->with('template')
            ->where('trigger', 'keyword')
            ->where('is_enabled', true)
            ->get();

        foreach ($automations as $automation) {
            $keywords = array_map('strtoupper', (array) data_get($automation->conditions, 'keywords', []));
            if (! in_array($command, $keywords, true) || ! $automation->template?->is_enabled) {
                continue;
            }

            $this->messages->queueTemplate(
                $automation->template->key,
                $phone,
                null,
                [],
                [],
                'keyword:'.$automation->id.':'.$phone.':'.now()->format('YmdHi'),
                null,
                'support',
            );
            break;
        }
    }

    private function isOptOutCommand(string $body): bool
    {
        return in_array(strtoupper(trim($body)), ['STOP', 'BERHENTI', 'UNSUBSCRIBE', 'JANGAN KIRIM'], true);
    }

    private function recordOptOut(string $phone, string $command, ?int $conversationId): void
    {
        WhatsAppConsent::query()->where('phone', $phone)->update([
            'allow_marketing' => false,
            'revoked_at' => now(),
            'updated_at' => now(),
        ]);
        WhatsAppOptOut::query()->updateOrCreate(
            ['phone' => $phone],
            [
                'block_marketing' => true,
                'block_ai' => true,
                'block_all' => false,
                'source' => 'whatsapp_keyword',
                'reason' => 'Permintaan melalui pesan '.$command,
                'opted_out_at' => now(),
            ],
        );

        if ($conversationId !== null) {
            $this->setAiBlocked($conversationId, $phone);
        }

        $this->messages->queueTemplate(
            'opt_out_confirmed',
            $phone,
            null,
            [],
            ['metadata' => ['command' => $command]],
            'optout:'.$phone.':'.today()->toDateString(),
            null,
            'support',
        );
    }

    private function setAiBlocked(int $conversationId, string $phone): void
    {
        \App\Models\WhatsAppConversation::query()->whereKey($conversationId)->update([
            'is_ai_blocked' => true,
            'status' => 'pending',
        ]);

        if ($this->features->enabled('whatsapp_ai_assistant') && config('starsender.enabled') && $this->client->hasDeviceKey('support')) {
            try {
                $this->client->addAiBlacklist($phone, 'support');
            } catch (Throwable) {
                // Local handoff remains authoritative even if provider blacklist is unavailable.
            }
        }
    }

    private function complete(WhatsAppWebhookEvent $event): void
    {
        $event->forceFill([
            'processed' => true,
            'processed_at' => now(),
            'processing_error' => null,
        ])->save();
    }
}

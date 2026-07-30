<?php

namespace App\Services\WhatsApp;

use App\Jobs\ArchiveInboundWhatsAppMedia;
use App\Models\CrmContact;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConsent;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppOptOut;
use App\Models\WhatsAppWebhookEvent;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmDocumentService;
use App\Services\Crm\CrmFaqService;
use App\Services\Crm\CrmSequenceService;
use App\Services\FeatureFlagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class WhatsAppWebhookService
{
    public function __construct(
        private readonly PhoneNumberNormalizer $phoneNumbers,
        private readonly WhatsAppManager $messages,
        private readonly StarSenderClient $client,
        private readonly FeatureFlagService $features,
        private readonly WhatsAppCampaignProgressService $campaignProgress,
        private readonly CrmContactService $contacts,
        private readonly CrmSequenceService $sequences,
        private readonly CrmDocumentService $documents,
        private readonly CrmFaqService $faq,
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
                $this->processOutboundEcho($providerMessageId);
                $this->complete($event);
                return;
            }

            if ($this->payloadIsGroup($payload)) {
                if (! config('starsender.group_webhook_enabled') || ! $this->features->enabled('whatsapp_inbox')) {
                    $this->complete($event);
                    return;
                }
                $this->storeGroupInbound($event, $payload, $providerMessageId);
                $this->complete($event);
                return;
            }

            if (! $this->features->enabled('whatsapp_inbox')) {
                $this->processOptOutWithoutInbox($payload);
                $this->complete($event);
                return;
            }

            $this->storePersonalInbound($event, $payload, $providerMessageId);
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

    private function processOutboundEcho(string $providerMessageId): void
    {
        if ($providerMessageId === '') {
            return;
        }

        $message = WhatsAppMessage::query()
            ->where('provider_message_id', $providerMessageId)
            ->where('direction', 'outbound')
            ->first();
        if (! $message) {
            return;
        }

        $message->forceFill(['status' => 'sent', 'sent_at' => $message->sent_at ?: now()])->save();
        $recipient = WhatsAppCampaignRecipient::query()->where('whatsapp_message_id', $message->id)->first();
        if (! $recipient) {
            return;
        }

        $recipient->forceFill(['status' => 'sent', 'error' => null])->save();
        $campaign = WhatsAppCampaign::query()->find($recipient->whatsapp_campaign_id);
        if ($campaign) {
            $this->campaignProgress->refresh($campaign);
        }
    }

    private function storePersonalInbound(WhatsAppWebhookEvent $event, array $payload, string $providerMessageId): void
    {
        $phone = $this->phoneNumbers->normalize((string) ($payload['from'] ?? $payload['phone'] ?? ''));
        if (! $phone) {
            throw new \RuntimeException('Webhook tidak memiliki nomor pengirim yang valid.');
        }

        $name = trim((string) ($payload['push_name'] ?? $payload['name'] ?? '')) ?: null;
        $body = trim((string) ($payload['message'] ?? $payload['body'] ?? ''));
        $media = trim((string) ($payload['file'] ?? $payload['media_url'] ?? '')) ?: null;
        $conversation = $this->messages->conversation($phone, $name);
        $this->messages->identifyConversation($conversation);

        $contact = $this->features->enabled('crm_contacts')
            ? $this->contacts->upsertFromWhatsApp($phone, $name, 'whatsapp', [
                'last_device_id' => $payload['device_id'] ?? null,
                'last_device_name' => $payload['device_name'] ?? null,
            ])
            : null;
        if ($contact) {
            $this->contacts->linkConversation($conversation, $contact);
            if ($this->features->enabled('crm_sequences')) {
                $this->sequences->stopOnReply($contact);
            }
        }

        $inboundMessage = DB::transaction(function () use (
            $event,
            $conversation,
            $contact,
            $phone,
            $name,
            $body,
            $media,
            $providerMessageId,
            $payload,
        ): WhatsAppMessage {
            $message = WhatsAppMessage::query()->firstOrCreate(
                ['idempotency_key' => 'webhook:'.$event->fingerprint],
                [
                    'conversation_id' => $conversation->id,
                    'contact_id' => $contact?->id,
                    'lead_id' => $conversation->lead_id,
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
                        'payload_type' => $payload['type'] ?? null,
                    ],
                ],
            );

            $conversation->forceFill([
                'contact_id' => $contact?->id ?: $conversation->contact_id,
                'display_name' => $contact?->name ?: ($name ?: $conversation->display_name),
                'unread_count' => $conversation->unread_count + 1,
                'last_message_at' => now(),
                'last_inbound_at' => now(),
                'status' => $conversation->status === 'closed' ? 'open' : $conversation->status,
            ])->save();

            return $message;
        });

        if ($media && $this->features->enabled('crm_documents')) {
            $document = $this->documents->createInboundPlaceholder($inboundMessage, $media);
            $inboundMessage->forceFill(['crm_document_id' => $document->id])->save();
            if ($this->features->enabled('crm_media_archive')) {
                ArchiveInboundWhatsAppMedia::dispatch($document->id)->onQueue('whatsapp');
            }
        }

        $handled = $this->handleCommand($conversation->phone, $body, $conversation->id);
        if (! $handled) {
            $this->faq->reply($conversation->phone, $body, $conversation);
        }
    }

    private function storeGroupInbound(WhatsAppWebhookEvent $event, array $payload, string $providerMessageId): void
    {
        $groupJid = trim((string) ($payload['from'] ?? $payload['phone'] ?? ''));
        if ($groupJid === '') {
            throw new \RuntimeException('Webhook grup tidak memiliki JID grup.');
        }

        $groupName = trim((string) ($payload['group_name'] ?? $payload['name'] ?? '')) ?: 'Grup WhatsApp';
        $deviceAlias = trim((string) ($payload['device_alias'] ?? 'support')) ?: 'support';
        if (! in_array($deviceAlias, ['default', 'transaction', 'support', 'partner', 'campaign'], true)) {
            $deviceAlias = 'support';
        }

        if (Schema::hasTable('whatsapp_groups')) {
            WhatsAppGroup::query()->updateOrCreate(
                ['device_alias' => $deviceAlias, 'group_jid' => $groupJid],
                [
                    'name' => $groupName,
                    'is_active' => true,
                    'last_synced_at' => now(),
                    'metadata' => ['source' => 'webhook', 'device' => $payload['device'] ?? null],
                ],
            );
        }

        $conversation = $this->messages->groupConversation(
            $groupJid,
            $groupName,
            $deviceAlias,
            ['device' => $payload['device'] ?? null, 'device_id' => $payload['device_id'] ?? null],
        );

        $body = trim((string) ($payload['message'] ?? $payload['body'] ?? ''));
        $media = trim((string) ($payload['file'] ?? $payload['media_url'] ?? '')) ?: null;
        $senderName = trim((string) ($payload['push_name'] ?? $payload['sender_name'] ?? $payload['participant_name'] ?? '')) ?: null;
        $senderPhoneRaw = trim((string) ($payload['sender'] ?? $payload['author'] ?? $payload['participant'] ?? '')) ?: null;
        $senderPhone = null;
        try {
            $senderPhone = $senderPhoneRaw ? $this->phoneNumbers->normalize(str_replace('@s.whatsapp.net', '', $senderPhoneRaw)) : null;
        } catch (Throwable) {
            $senderPhone = null;
        }

        $contact = null;
        if ($senderPhone && $this->features->enabled('crm_contacts')) {
            $contact = $this->contacts->upsertFromWhatsApp($senderPhone, $senderName, 'whatsapp_group', [
                'last_group_jid' => $groupJid,
                'last_group_name' => $groupName,
            ]);
        }

        $message = WhatsAppMessage::query()->firstOrCreate(
            ['idempotency_key' => 'webhook:'.$event->fingerprint],
            [
                'conversation_id' => $conversation->id,
                'contact_id' => $contact?->id,
                'direction' => 'inbound',
                'channel' => 'group',
                'phone' => $groupJid,
                'recipient_name' => $groupName,
                'device_alias' => $deviceAlias,
                'message_type' => $media ? 'media' : 'text',
                'body' => $body,
                'media_url' => $media,
                'status' => 'received',
                'provider_message_id' => $providerMessageId ?: null,
                'sent_at' => now(),
                'metadata' => [
                    'sender_name' => $senderName,
                    'sender_phone' => $senderPhone ?: $senderPhoneRaw,
                    'device' => $payload['device'] ?? null,
                    'payload' => $payload,
                ],
            ],
        );

        $conversation->forceFill([
            'display_name' => $groupName,
            'unread_count' => $conversation->unread_count + 1,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'status' => $conversation->status === 'closed' ? 'open' : $conversation->status,
        ])->save();

        if ($media && $this->features->enabled('crm_documents')) {
            $document = $this->documents->createInboundPlaceholder($message, $media);
            $message->forceFill(['crm_document_id' => $document->id])->save();
            if ($this->features->enabled('crm_media_archive')) {
                ArchiveInboundWhatsAppMedia::dispatch($document->id)->onQueue('whatsapp');
            }
        }
    }

    private function handleCommand(string $phone, string $body, int $conversationId): bool
    {
        $command = strtoupper(trim($body));
        if ($command === '') {
            return false;
        }

        if ($this->isOptOutCommand($command)) {
            $this->recordOptOut($phone, $command, $conversationId);
            return true;
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
            return true;
        }

        if (! $this->features->enabled('whatsapp_autoreply')) {
            return false;
        }

        if (in_array($command, ['HELP', 'BANTUAN', 'MENU'], true)) {
            $this->messages->queueTemplate('keyword_help', $phone, null, [], [], 'help:'.$phone.':'.now()->format('YmdH'), null, 'support');
            return true;
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
            return true;
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
            return true;
        }

        return $this->handleConfiguredKeyword($phone, $command);
    }

    private function handleConfiguredKeyword(string $phone, string $command): bool
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
            return true;
        }

        return false;
    }

    private function payloadIsGroup(array $payload): bool
    {
        $chatType = strtolower((string) ($payload['chat_type'] ?? $payload['chatType'] ?? 'personal'));
        $from = trim((string) ($payload['from'] ?? $payload['phone'] ?? ''));

        return filter_var($payload['_detected_is_group'] ?? $payload['is_group'] ?? false, FILTER_VALIDATE_BOOL)
            || $chatType === 'group'
            || trim((string) ($payload['group_name'] ?? '')) !== ''
            || str_ends_with(strtolower($from), '@g.us');
    }

    private function processOptOutWithoutInbox(array $payload): void
    {
        $body = trim((string) ($payload['message'] ?? $payload['body'] ?? ''));
        if (! $this->isOptOutCommand($body)) {
            return;
        }
        $phone = $this->phoneNumbers->normalize((string) ($payload['from'] ?? $payload['phone'] ?? ''));
        if ($phone) {
            $this->recordOptOut($phone, strtoupper(trim($body)), null);
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
        CrmContact::query()->where('phone', $phone)->update(['is_opted_out' => true, 'updated_at' => now()]);

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

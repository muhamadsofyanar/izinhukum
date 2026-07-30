<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\WhatsAppConsent;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppOptOut;
use App\Models\WhatsAppTemplate;
use App\Services\FeatureFlagService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class WhatsAppManager
{
    public function __construct(
        private readonly PhoneNumberNormalizer $phoneNumbers,
        private readonly WhatsAppTemplateRenderer $renderer,
        private readonly FeatureFlagService $features,
    ) {
    }

    public function available(): bool
    {
        return Schema::hasTable('whatsapp_messages')
            && config('starsender.enabled')
            && $this->features->enabled('whatsapp');
    }

    public function queueTemplate(
        string $templateKey,
        string $phone,
        ?string $recipientName,
        array $variables,
        array $relations = [],
        ?string $idempotencyKey = null,
        ?CarbonInterface $scheduledAt = null,
        string $deviceAlias = 'transaction',
        string $channel = 'personal',
    ): ?WhatsAppMessage {
        if (! $this->available()) {
            return null;
        }

        $template = WhatsAppTemplate::query()
            ->where('key', $templateKey)
            ->where('is_enabled', true)
            ->first();

        if (! $template) {
            return null;
        }

        return $this->queueRaw([
            ...$relations,
            'template_id' => $template->id,
            'phone' => $phone,
            'recipient_name' => $recipientName,
            'body' => $this->renderer->render($template->body, $variables),
            'message_type' => $template->message_type,
            'media_url' => $this->renderer->render((string) $template->media_url, $variables) ?: null,
            'is_marketing' => $template->is_marketing,
            'idempotency_key' => $idempotencyKey,
            'scheduled_at' => $scheduledAt,
            'device_alias' => $deviceAlias,
            'channel' => $channel,
            'metadata' => ['template_key' => $templateKey, 'variables' => $variables],
        ]);
    }

    public function queueRaw(array $data): ?WhatsAppMessage
    {
        if (! $this->available()) {
            return null;
        }

        $channel = $data['channel'] ?? 'personal';
        $phone = $channel === 'group'
            ? trim((string) ($data['phone'] ?? ''))
            : $this->phoneNumbers->normalize($data['phone'] ?? null);

        if (! $phone) {
            throw new InvalidArgumentException('Nomor atau tujuan WhatsApp wajib diisi.');
        }

        if ($channel !== 'group' && $this->isBlocked($phone, (bool) ($data['is_marketing'] ?? false))) {
            return null;
        }

        if (! empty($data['idempotency_key'])) {
            $existing = WhatsAppMessage::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }
        }

        $conversation = $channel === 'personal'
            ? $this->conversation($phone, $data['recipient_name'] ?? null, $data)
            : $this->groupConversation(
                $phone,
                $data['recipient_name'] ?? null,
                $data['device_alias'] ?? 'support',
                (array) ($data['metadata'] ?? []),
            );
        $scheduledAt = $data['scheduled_at'] ?? null;
        $isFuture = $scheduledAt instanceof CarbonInterface && $scheduledAt->isFuture();

        $message = WhatsAppMessage::query()->create([
            'conversation_id' => $conversation?->id,
            'template_id' => $data['template_id'] ?? null,
            'contact_id' => $data['contact_id'] ?? $conversation?->contact_id,
            'lead_id' => $data['lead_id'] ?? $conversation?->lead_id,
            'crm_document_id' => $data['crm_document_id'] ?? null,
            'inquiry_id' => $data['inquiry_id'] ?? null,
            'service_order_id' => $data['service_order_id'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? null,
            'payment_id' => $data['payment_id'] ?? null,
            'partner_id' => $data['partner_id'] ?? null,
            'created_by' => $data['created_by'] ?? null,
            'direction' => 'outbound',
            'channel' => $channel,
            'phone' => $phone,
            'recipient_name' => $data['recipient_name'] ?? null,
            'message_type' => $data['message_type'] ?? 'text',
            'body' => $data['body'] ?? null,
            'media_url' => $data['media_url'] ?? null,
            'device_alias' => $data['device_alias'] ?? 'transaction',
            'status' => $isFuture ? 'scheduled' : 'queued',
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'scheduled_at' => $scheduledAt,
            'metadata' => $data['metadata'] ?? null,
        ]);

        if (! $isFuture) {
            DB::afterCommit(fn (): mixed => SendWhatsAppMessage::dispatch($message->id)->onQueue('whatsapp'));
        }

        return $message;
    }

    public function dispatchDue(int $limit = 200): int
    {
        if (! $this->available()) {
            return 0;
        }

        $count = 0;
        WhatsAppMessage::query()->due()->orderBy('id')->limit($limit)->get()->each(
            function (WhatsAppMessage $message) use (&$count): void {
                SendWhatsAppMessage::dispatch($message->id)->onQueue('whatsapp');
                $count++;
            },
        );

        return $count;
    }

    public function isBlocked(string $phone, bool $marketing): bool
    {
        $optOut = WhatsAppOptOut::query()->where('phone', $phone)->first();
        if ($optOut && ($optOut->block_all || ($marketing && $optOut->block_marketing))) {
            return true;
        }

        $consent = WhatsAppConsent::query()->where('phone', $phone)->first();

        if (! $marketing) {
            return $consent !== null && ! $consent->allow_transactional;
        }

        return ! $consent || ! $consent->marketingActive();
    }

    public function conversation(string $phone, ?string $name = null, array $relations = []): WhatsAppConversation
    {
        $conversation = WhatsAppConversation::query()->firstOrNew(['phone' => $phone]);
        $conversation->channel = 'personal';
        $conversation->device_alias = $relations['device_alias'] ?? ($conversation->device_alias ?: 'support');
        $conversation->display_name = $name ?: $conversation->display_name;
        $conversation->contact_id = $relations['contact_id'] ?? $conversation->contact_id;
        $conversation->lead_id = $relations['lead_id'] ?? $conversation->lead_id;
        $conversation->partner_id = $relations['partner_id'] ?? $conversation->partner_id;
        $conversation->inquiry_id = $relations['inquiry_id'] ?? $conversation->inquiry_id;
        $conversation->service_order_id = $relations['service_order_id'] ?? $conversation->service_order_id;
        $conversation->contact_type = $this->contactType($relations, $conversation->contact_type);
        $conversation->status = $conversation->status ?: 'open';
        $conversation->save();

        return $conversation;
    }

    public function groupConversation(
        string $groupJid,
        ?string $name = null,
        string $deviceAlias = 'support',
        array $metadata = [],
    ): WhatsAppConversation {
        $conversation = WhatsAppConversation::query()->firstOrNew(['phone' => trim($groupJid)]);
        $conversation->channel = 'group';
        $conversation->device_alias = $deviceAlias;
        $conversation->display_name = $name ?: $conversation->display_name ?: 'Grup WhatsApp';
        $conversation->contact_type = 'group';
        $conversation->status = $conversation->status ?: 'open';
        $conversation->metadata = array_replace((array) $conversation->metadata, $metadata);
        $conversation->save();

        return $conversation;
    }

    public function identifyConversation(WhatsAppConversation $conversation): WhatsAppConversation
    {
        $partner = User::query()
            ->where('role', 'partner')
            ->where('phone', 'like', '%'.substr($conversation->phone, -9))
            ->latest('id')
            ->limit(50)
            ->get()
            ->first(fn (User $candidate): bool => $this->samePhone($conversation->phone, $candidate->phone));
        if ($partner) {
            $conversation->forceFill([
                'display_name' => $partner->name,
                'contact_type' => 'partner',
                'partner_id' => $partner->id,
            ])->save();
            return $conversation;
        }

        $order = $this->latestOrderForPhone($conversation->phone);
        if ($order) {
            $conversation->forceFill([
                'display_name' => $order->customer_name,
                'contact_type' => 'customer',
                'service_order_id' => $order->id,
                'inquiry_id' => $order->inquiry_id,
            ])->save();
            return $conversation;
        }

        $inquiry = Inquiry::query()
            ->where('phone', 'like', '%'.substr($conversation->phone, -9))
            ->latest('id')
            ->limit(50)
            ->get()
            ->first(fn (Inquiry $candidate): bool => $this->samePhone($conversation->phone, $candidate->phone));
        if ($inquiry) {
            $conversation->forceFill([
                'display_name' => $inquiry->name,
                'contact_type' => 'lead',
                'inquiry_id' => $inquiry->id,
            ])->save();
        }

        return $conversation;
    }

    public function latestOrderForPhone(string $phone): ?ServiceOrder
    {
        $normalized = $this->phoneNumbers->normalize($phone);

        return ServiceOrder::query()
            ->where('customer_phone', 'like', '%'.substr($normalized, -9))
            ->latest('id')
            ->limit(50)
            ->get()
            ->first(fn (ServiceOrder $candidate): bool => $this->samePhone($normalized, $candidate->customer_phone));
    }

    public function latestActiveInvoiceForPhone(string $phone): ?Invoice
    {
        $normalized = $this->phoneNumbers->normalize($phone);

        return Invoice::query()
            ->where('recipient_phone', 'like', '%'.substr($normalized, -9))
            ->whereNotIn('status', ['paid', 'cancelled', 'draft'])
            ->latest('id')
            ->limit(50)
            ->get()
            ->first(fn (Invoice $candidate): bool => $this->samePhone($normalized, $candidate->recipient_phone));
    }

    private function samePhone(string $expected, ?string $candidate): bool
    {
        try {
            return $this->phoneNumbers->normalize($expected) === $this->phoneNumbers->normalize($candidate);
        } catch (\Throwable) {
            return false;
        }
    }

    private function contactType(array $relations, ?string $current): string
    {
        if (! empty($relations['partner_id'])) {
            return 'partner';
        }
        if (! empty($relations['service_order_id'])) {
            return 'customer';
        }
        if (! empty($relations['inquiry_id'])) {
            return 'lead';
        }

        return $current ?: 'unknown';
    }
}

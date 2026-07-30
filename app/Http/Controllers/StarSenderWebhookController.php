<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\WhatsAppWebhookEvent;
use App\Services\FeatureFlagService;
use App\Services\WhatsApp\PhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StarSenderWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $secret,
        FeatureFlagService $features,
        PhoneNumberNormalizer $phoneNumbers,
    ): JsonResponse {
        $configuredSecret = trim((string) config('starsender.webhook_secret'));
        if ($configuredSecret === '' || ! hash_equals($configuredSecret, $secret)) {
            return response()->json(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        $headerSecret = trim((string) config('starsender.webhook_header_secret'));
        if ($headerSecret !== '' && ! hash_equals($headerSecret, (string) $request->header('X-Webhook-Secret'))) {
            return response()->json(['message' => 'Unauthorized webhook.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! config('starsender.enabled') || ! $features->enabled('whatsapp')) {
            return response()->json(['status' => 'disabled'], Response::HTTP_ACCEPTED);
        }

        if (! Schema::hasTable('whatsapp_webhook_events')) {
            return response()->json(['message' => 'WhatsApp migration is not ready.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $raw = $request->getContent();
        if (strlen($raw) > 1_048_576) {
            return response()->json(['message' => 'Payload too large.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $payload = $request->json()->all();
        if (! is_array($payload) || $payload === []) {
            return response()->json(['message' => 'JSON payload is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $from = trim((string) ($payload['from'] ?? $payload['phone'] ?? ''));
        $chatType = strtolower(trim((string) ($payload['chat_type'] ?? $payload['chatType'] ?? '')));
        $isGroup = filter_var($payload['is_group'] ?? false, FILTER_VALIDATE_BOOL)
            || $chatType === 'group'
            || trim((string) ($payload['group_name'] ?? '')) !== ''
            || str_ends_with(strtolower($from), '@g.us');

        $providerId = trim((string) ($payload['message_id'] ?? $payload['id'] ?? ''));
        $fingerprintPayload = $providerId !== ''
            ? [
                'id' => $providerId,
                'event' => $payload['event'] ?? $payload['type'] ?? null,
                'status' => $payload['status'] ?? null,
                'is_me' => filter_var($payload['is_me'] ?? false, FILTER_VALIDATE_BOOL),
                'is_group' => $isGroup,
                'from' => $from,
                'timestamp' => $payload['timestamp'] ?? $payload['time'] ?? null,
                'body_hash' => hash('sha256', (string) ($payload['message'] ?? $payload['body'] ?? '')),
            ]
            : $raw;
        $fingerprint = hash(
            'sha256',
            is_array($fingerprintPayload)
                ? json_encode($fingerprintPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $fingerprintPayload,
        );

        $phone = $from ?: null;
        if (! $isGroup) {
            try {
                $phone = $phoneNumbers->normalize($from);
            } catch (Throwable) {
                $phone = $from ?: null;
            }
        }

        $event = WhatsAppWebhookEvent::query()->firstOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'event_type' => $isGroup ? 'group_message' : 'message',
                'provider_message_id' => $providerId ?: null,
                'phone' => $phone,
                'payload' => [
                    ...$payload,
                    '_detected_is_group' => $isGroup,
                ],
            ],
        );

        if ($event->wasRecentlyCreated || ! $event->processed) {
            ProcessWhatsAppWebhook::dispatch($event->id)->onQueue('whatsapp');
        }

        return response()->json([
            'status' => $event->wasRecentlyCreated ? 'accepted' : 'duplicate',
            'event_id' => $event->id,
        ], Response::HTTP_ACCEPTED);
    }
}

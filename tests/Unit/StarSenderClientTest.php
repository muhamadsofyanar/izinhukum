<?php

namespace Tests\Unit;

use App\Services\WhatsApp\StarSenderClient;
use App\Services\WhatsApp\StarSenderException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StarSenderClientTest extends TestCase
{
    public function test_personal_and_group_messages_use_one_device_key(): void
    {
        config()->set('starsender.enabled', true);
        config()->set('starsender.device_key', 'one-device-key');
        config()->set('starsender.base_url', 'https://api.starsender.online');
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['message_id' => 'm-1']])]);

        app(StarSenderClient::class)->send(['to' => '628123456789'], false);
        app(StarSenderClient::class)->send(['to' => '120363@g.us'], true);

        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'one-device-key');
    }

    public function test_rate_limit_is_retryable_and_bad_request_is_permanent(): void
    {
        config()->set('starsender.enabled', true);
        config()->set('starsender.device_key', 'one-device-key');
        Http::fakeSequence()
            ->push(['success' => false, 'message' => 'Terlalu cepat'], 429)
            ->push(['success' => false, 'message' => 'Nomor salah'], 400);

        try {
            app(StarSenderClient::class)->send(['to' => '628123456789']);
            self::fail('Expected retryable error');
        } catch (StarSenderException $exception) {
            self::assertTrue($exception->retryable);
        }

        try {
            app(StarSenderClient::class)->send(['to' => 'invalid']);
            self::fail('Expected permanent error');
        } catch (StarSenderException $exception) {
            self::assertFalse($exception->retryable);
        }
    }
}

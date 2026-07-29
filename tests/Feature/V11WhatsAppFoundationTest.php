<?php

namespace Tests\Feature;

use App\Services\FeatureFlagService;
use App\Services\WhatsApp\StarSenderClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class V11WhatsAppFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_v11_tables_are_available_after_migration(): void
    {
        foreach ([
            'jobs', 'failed_jobs', 'whatsapp_devices', 'whatsapp_templates',
            'whatsapp_conversations', 'whatsapp_messages', 'whatsapp_message_attempts',
            'whatsapp_automations', 'whatsapp_campaigns', 'whatsapp_campaign_recipients',
            'whatsapp_consents', 'whatsapp_opt_outs', 'whatsapp_webhook_events',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), $table.' should exist');
        }
    }

    public function test_whatsapp_features_are_disabled_by_default(): void
    {
        $features = app(FeatureFlagService::class);
        foreach (['whatsapp', 'whatsapp_transactional', 'whatsapp_inbox', 'whatsapp_campaigns', 'whatsapp_autoreply', 'whatsapp_ai_assistant', 'whatsapp_rotator', 'whatsapp_provider_tools'] as $feature) {
            self::assertFalse($features->enabled($feature));
        }
    }

    public function test_webhook_rejects_wrong_secret(): void
    {
        config()->set('starsender.webhook_secret', 'secret-yang-benar');
        $this->postJson('/webhooks/starsender/salah', ['from' => '6281234567890', 'message' => 'HELP'])
            ->assertNotFound();
    }

    public function test_starsender_client_uses_configured_device_key(): void
    {
        config()->set('starsender.enabled', true);
        config()->set('starsender.base_url', 'https://api.starsender.online');
        config()->set('starsender.device_keys.transaction', 'device-test-key');
        Http::fake(['https://api.starsender.online/api/check-number' => Http::response(['success' => true, 'data' => ['registered' => true]], 200)]);

        app(StarSenderClient::class)->checkNumber('6281234567890', 'transaction');

        Http::assertSent(fn ($request): bool =>
            $request->url() === 'https://api.starsender.online/api/check-number'
            && $request->header('Authorization')[0] === 'device-test-key'
            && $request['number'] === '6281234567890'
        );
    }
}

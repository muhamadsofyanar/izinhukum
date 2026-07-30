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
            'whatsapp_consents', 'whatsapp_opt_outs', 'whatsapp_webhook_events', 'whatsapp_groups',
            'whatsapp_group_selections',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), $table.' should exist');
        }
    }

    public function test_only_transactional_whatsapp_gateway_is_enabled_in_focus_mode(): void
    {
        $features = app(FeatureFlagService::class);
        self::assertTrue($features->enabled('whatsapp'));
        self::assertTrue($features->enabled('whatsapp_transactional'));

        foreach (['whatsapp_crm', 'whatsapp_inbox', 'whatsapp_campaigns', 'whatsapp_autoreply', 'whatsapp_ai_assistant', 'whatsapp_rotator', 'whatsapp_provider_tools'] as $feature) {
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

    public function test_group_list_uses_device_api_key(): void
    {
        config()->set('starsender.enabled', true);
        config()->set('starsender.base_url', 'https://api.starsender.online');
        config()->set('starsender.device_keys.support', 'support-device-key');
        Http::fake(['https://api.starsender.online/api/whatsapp/groups' => Http::response([
            'success' => true,
            'data' => [['id' => '120363000000000000@g.us', 'name' => 'Tim Legal']],
        ], 200)]);

        app(StarSenderClient::class)->listWhatsAppGroups('support');

        Http::assertSent(fn ($request): bool =>
            $request->url() === 'https://api.starsender.online/api/whatsapp/groups'
            && $request->header('Authorization')[0] === 'support-device-key'
        );
    }
}

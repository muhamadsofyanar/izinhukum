<?php

namespace Tests\Feature;

use App\Models\ServiceOrder;
use App\Services\FeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class V10FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_database_and_storage(): void
    {
        $this->getJson('/healthz')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('version', '22.0.0')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.schema', 'ok')
            ->assertJsonPath('checks.storage', 'ok');
    }

    public function test_application_health_check_validates_required_schema(): void
    {
        $this->artisan('app:health-check')
            ->expectsOutput('Database, struktur tabel, dan storage privat dalam kondisi siap.')
            ->assertSuccessful();
    }

    public function test_service_order_status_helpers_are_consistent(): void
    {
        $order = new ServiceOrder([
            'status' => 'processing',
            'payment_status' => 'partial',
            'priority' => 'high',
            'due_at' => Carbon::now()->subDay(),
        ]);

        $this->assertSame('Sedang diproses', $order->statusLabel());
        $this->assertSame('Dibayar sebagian', $order->paymentStatusLabel());
        $this->assertSame('Tinggi', $order->priorityLabel());
        $this->assertTrue($order->isOverdue());

        $order->status = 'completed';
        $this->assertFalse($order->isOverdue());
    }

    public function test_critical_features_default_to_enabled_before_settings_exist(): void
    {
        $features = app(FeatureFlagService::class);

        $this->assertTrue($features->enabled('customer_portal'));
        $this->assertTrue($features->enabled('public_proposal'));
        $this->assertTrue($features->enabled('referral_tracking'));
        $this->assertFalse($features->enabled('unknown_feature'));
    }
}

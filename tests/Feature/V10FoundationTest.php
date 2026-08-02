<?php

namespace Tests\Feature;

use App\Models\ServiceOrder;
use App\Services\FeatureFlagService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class V10FoundationTest extends TestCase
{
    public function test_health_endpoint_reports_database_and_storage(): void
    {
        $this->getJson('/healthz')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('version', '16.0.0')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.storage', 'ok');
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

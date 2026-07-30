<?php

namespace Tests\Feature;

use App\Jobs\SendNewInquiryEmailNotification;
use App\Jobs\SendNewInquiryWhatsAppNotification;
use App\Mail\NewInquiryAdminMail;
use App\Models\Inquiry;
use App\Models\User;
use App\Services\FeatureFlagService;
use App\Services\MailConfigurator;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FocusModeNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_inquiry_queues_email_and_whatsapp_notifications(): void
    {
        Queue::fake();

        $response = $this->post('/proposal', [
            'name' => 'Klien Baru',
            'phone' => '081234567890',
            'email' => 'klien@example.test',
            'journey_source' => 'name_generator',
            'privacy_consent' => '1',
        ]);

        $response->assertRedirect()->assertSessionHas('open_whatsapp', true);
        $this->assertDatabaseHas('inquiries', [
            'name' => 'Klien Baru',
            'source' => 'name_generator',
        ]);
        $this->assertDatabaseHas('service_orders', [
            'customer_name' => 'Klien Baru',
        ]);

        Queue::assertPushed(SendNewInquiryEmailNotification::class);
        Queue::assertPushed(SendNewInquiryWhatsAppNotification::class);
    }

    public function test_email_job_sends_new_order_to_configured_admin(): void
    {
        Queue::fake();
        Mail::fake();
        config()->set('business-notifications.new_order.email.enabled', true);
        config()->set('business-notifications.new_order.email.recipient', 'operasional@example.test');

        $inquiry = Inquiry::query()->create([
            'reference' => 'IH-260730-MAIL1',
            'name' => 'Klien Email',
            'phone' => '081234567890',
            'email' => 'klien@example.test',
            'source' => 'website',
            'status' => 'baru',
        ]);

        (new SendNewInquiryEmailNotification($inquiry->id))->handle(app(MailConfigurator::class));

        Mail::assertSent(
            NewInquiryAdminMail::class,
            fn (NewInquiryAdminMail $mail): bool => $mail->hasTo('operasional@example.test')
                && $mail->inquiry->is($inquiry),
        );
    }

    public function test_whatsapp_job_queues_only_transactional_admin_message(): void
    {
        Queue::fake();
        config()->set('starsender.enabled', true);
        config()->set('starsender.device_keys.transaction', 'device-test');
        config()->set('business-notifications.new_order.whatsapp.enabled', true);
        config()->set('business-notifications.new_order.whatsapp.recipient', '081111111111');

        $inquiry = Inquiry::query()->create([
            'reference' => 'IH-260730-WA001',
            'name' => 'Klien WhatsApp',
            'phone' => '081234567890',
            'source' => 'website',
            'status' => 'baru',
        ]);

        (new SendNewInquiryWhatsAppNotification($inquiry->id))->handle(
            app(WhatsAppManager::class),
            app(FeatureFlagService::class),
        );

        $this->assertDatabaseHas('whatsapp_messages', [
            'inquiry_id' => $inquiry->id,
            'phone' => '628111111111',
            'direction' => 'outbound',
            'channel' => 'personal',
            'conversation_id' => null,
            'status' => 'queued',
            'idempotency_key' => 'admin:new-inquiry:'.$inquiry->id,
        ]);
    }

    public function test_admin_navigation_is_focused_and_whatsapp_crm_is_unavailable(): void
    {
        $admin = User::query()->create([
            'role' => 'admin',
            'name' => 'Admin Fokus',
            'email' => 'admin-focus@example.test',
            'password' => 'password-aman',
            'is_active' => true,
        ]);

        $this->withSession(['portal_user_id' => $admin->id])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Pesanan')
            ->assertSee('Laporan keuangan')
            ->assertSee('Mitra & referral', false)
            ->assertSee('LMS mitra')
            ->assertSee('Bank konten')
            ->assertDontSee('WhatsApp &amp; CRM', false)
            ->assertDontSee('Community')
            ->assertDontSee('Inbox internal');

        $this->withSession(['portal_user_id' => $admin->id])
            ->get(route('admin.whatsapp.dashboard'))
            ->assertNotFound();
    }
}

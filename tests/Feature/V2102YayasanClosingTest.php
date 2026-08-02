<?php

namespace Tests\Feature;

use App\Jobs\SendNewInquiryEmailNotification;
use App\Jobs\SendNewInquiryWhatsAppNotification;
use App\Models\Inquiry;
use App\Models\MarketingCampaign;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class V2102YayasanClosingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-03 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_yayasan_service_landing_shows_visible_and_automatic_promo(): void
    {
        $service = Service::query()->where('slug', 'pendirian-yayasan')->firstOrFail();

        $this->get(route('services.show', $service))
            ->assertOk()
            ->assertSee('Dirikan Yayasan dengan Struktur yang Jelas Sejak Awal')
            ->assertSee('YAYASAN300')
            ->assertSee('Rp3.700.000')
            ->assertSee('Pembina, Pengurus, dan Pengawas')
            ->assertDontSee('Kartu nama direktur');

        $basic = ServicePackage::query()->where('service_id', $service->id)->where('name', 'Pendirian Yayasan')->firstOrFail();
        $plus = ServicePackage::query()->where('service_id', $service->id)->where('name', 'Pendirian Yayasan + Izin')->firstOrFail();
        $this->assertTrue($basic->is_popular);
        $this->assertFalse($plus->is_popular);
        $this->assertContains('Pengajuan pengesahan badan hukum melalui AHU', $basic->features);
        $this->assertNotContains('Kartu nama direktur', $plus->features);
    }

    public function test_yayasan_campaign_prefills_coupon_and_attributes_discounted_lead(): void
    {
        Queue::fake();
        $campaign = MarketingCampaign::query()->where('slug', 'yayasan-agustus-2026')->firstOrFail();
        $package = ServicePackage::query()->where('name', 'Pendirian Yayasan')->firstOrFail();

        $this->get(route('campaigns.landing', $campaign))
            ->assertOk()
            ->assertSee('Dirikan Yayasan Lebih Siap, Hemat Rp300.000')
            ->assertSee('YAYASAN300')
            ->assertSee('Rp3.700.000')
            ->assertSee('Promo otomatis diterapkan saat form dikirim.');

        $this->post(route('proposal.store'), [
            'service_package_id' => $package->id,
            'name' => 'Lead Yayasan Promo',
            'phone' => '081234562102',
            'email' => 'yayasan-v2102@example.test',
            'company_name' => 'Yayasan Harapan Bersama',
            'city' => 'Bandung',
            'message' => 'Kami ingin memulai proses pendirian Yayasan.',
            'foundation_purpose' => 'social_education',
            'foundation_readiness' => 'partial',
            'foundation_timeline' => 'immediately',
            'coupon_code' => 'YAYASAN300',
            'journey_source' => 'website',
            'privacy_consent' => '1',
        ])->assertRedirect();

        $inquiry = Inquiry::query()->where('email', 'yayasan-v2102@example.test')->firstOrFail();
        $this->assertSame($campaign->id, $inquiry->marketing_campaign_id);
        $this->assertSame('YAYASAN300', $inquiry->coupon_code);
        $this->assertSame(300000, $inquiry->coupon_discount_amount);
        $this->assertStringContainsString('Fokus kegiatan: Sosial/pendidikan', $inquiry->message);
        $this->assertStringContainsString('Target mulai: Secepatnya', $inquiry->message);
        Queue::assertPushed(SendNewInquiryEmailNotification::class);
        Queue::assertPushed(SendNewInquiryWhatsAppNotification::class);
    }

    public function test_admin_campaign_screen_exposes_campaign_coupon_connection(): void
    {
        $admin = User::query()->create([
            'role' => 'admin',
            'name' => 'Admin Yayasan',
            'email' => 'admin-yayasan@example.test',
            'password' => 'password-aman',
            'is_active' => true,
        ]);

        $this->withSession(['portal_user_id' => $admin->id])
            ->get(route('admin.marketing-campaigns.index'))
            ->assertOk()
            ->assertSee('Promo/kupon otomatis')
            ->assertSee('YAYASAN300')
            ->assertSee('yayasan-agustus-2026');
    }
}

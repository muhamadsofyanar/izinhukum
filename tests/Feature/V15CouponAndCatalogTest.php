<?php

namespace Tests\Feature;

use App\Jobs\SendNewInquiryEmailNotification;
use App\Jobs\SendNewInquiryWhatsAppNotification;
use App\Models\Coupon;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\User;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class V15CouponAndCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_v15_publishes_the_requested_services_without_invented_prices(): void
    {
        foreach ([
            'pengukuhan-pkp',
            'layanan-rups',
            'laporan-keuangan',
            'brand-identity',
            'perizinan-lainnya',
        ] as $slug) {
            $service = Service::query()->where('slug', $slug)->firstOrFail();
            $this->assertTrue($service->is_active);
            $this->assertSame(0, $service->packages()->firstOrFail()->price);
            $this->assertTrue($service->packages()->firstOrFail()->is_estimated);
        }

        $this->get('/layanan')
            ->assertOk()
            ->assertSee('Pengukuhan PKP')
            ->assertSee('Layanan RUPS')
            ->assertSee('Penyusunan Laporan Keuangan')
            ->assertSee('Brand Identity')
            ->assertSee('Perizinan Lainnya')
            ->assertSee('berdasarkan penawaran');
    }

    public function test_coupon_is_validated_and_copied_to_inquiry_order_and_invoice(): void
    {
        Queue::fake();
        $this->seed(ServiceSeeder::class);
        $package = ServicePackage::query()->where('name', 'Pendirian PT')->with('service')->firstOrFail();
        $coupon = Coupon::query()->create([
            'name' => 'Promo legalitas 10 persen',
            'code' => 'LEGAL10',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'maximum_discount' => 750000,
            'minimum_subtotal' => 1000000,
            'applies_to_all_services' => false,
            'is_active' => true,
        ]);
        $coupon->services()->attach($package->service_id);

        $this->postJson(route('proposal.coupon.check'), [
            'service_package_id' => $package->id,
            'coupon_code' => 'legal10',
        ])->assertOk()
            ->assertJsonPath('code', 'LEGAL10')
            ->assertJsonPath('discount_amount', 600000)
            ->assertJsonPath('total', 5400000);

        $this->post('/proposal', [
            'service_package_id' => $package->id,
            'coupon_code' => 'legal10',
            'name' => 'Klien Promo',
            'phone' => '081234567890',
            'email' => 'promo@example.test',
            'privacy_consent' => '1',
        ])->assertRedirect();

        $inquiry = Inquiry::query()->where('email', 'promo@example.test')->firstOrFail();
        $this->assertSame('LEGAL10', $inquiry->coupon_code);
        $this->assertSame(600000, $inquiry->coupon_discount_amount);
        $this->assertDatabaseHas('service_orders', [
            'inquiry_id' => $inquiry->id,
            'coupon_code' => 'LEGAL10',
            'coupon_discount_amount' => 600000,
        ]);
        $this->assertDatabaseHas('coupon_redemptions', [
            'inquiry_id' => $inquiry->id,
            'coupon_id' => $coupon->id,
            'discount_amount' => 600000,
        ]);
        Queue::assertPushed(SendNewInquiryEmailNotification::class);
        Queue::assertPushed(SendNewInquiryWhatsAppNotification::class);

        $admin = User::query()->create([
            'role' => 'admin',
            'name' => 'Admin Promo',
            'email' => 'admin-promo@example.test',
            'password' => 'password-aman',
            'is_active' => true,
        ]);
        $this->withSession(['portal_user_id' => $admin->id])->post(route('admin.invoices.store'), [
            'recipient_type' => 'end_user',
            'inquiry_id' => $inquiry->id,
            'recipient_name' => $inquiry->name,
            'recipient_email' => $inquiry->email,
            'recipient_phone' => $inquiry->phone,
            'issue_date' => now()->format('Y-m-d'),
            'items' => [[
                'service_package_id' => $package->id,
                'description' => $package->name,
                'quantity' => 1,
                'unit_price' => $package->price,
            ]],
        ])->assertRedirect();

        $invoice = Invoice::query()->where('inquiry_id', $inquiry->id)->firstOrFail();
        $this->assertSame('LEGAL10', $invoice->coupon_code);
        $this->assertSame(600000, $invoice->discount);
        $this->assertSame(5400000, $invoice->total);
    }

    public function test_coupon_cannot_be_used_for_an_unselected_service(): void
    {
        $this->seed(ServiceSeeder::class);
        $pt = ServicePackage::query()->where('name', 'Pendirian PT')->with('service')->firstOrFail();
        $cv = ServicePackage::query()->where('name', 'Pendirian CV')->with('service')->firstOrFail();
        $coupon = Coupon::query()->create([
            'name' => 'Promo PT',
            'code' => 'PTONLY',
            'discount_type' => 'fixed',
            'discount_value' => 500000,
            'minimum_subtotal' => 0,
            'applies_to_all_services' => false,
            'is_active' => true,
        ]);
        $coupon->services()->attach($pt->service_id);

        $this->postJson(route('proposal.coupon.check'), [
            'service_package_id' => $cv->id,
            'coupon_code' => 'PTONLY',
        ])->assertUnprocessable()->assertJsonValidationErrors('coupon_code');
    }

    public function test_coupon_is_not_consumed_before_an_offer_based_package_has_a_price(): void
    {
        $package = ServicePackage::query()
            ->whereHas('service', fn ($query) => $query->where('slug', 'pengukuhan-pkp'))
            ->with('service')
            ->firstOrFail();
        $coupon = Coupon::query()->create([
            'name' => 'Promo PKP',
            'code' => 'PKP10',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'minimum_subtotal' => 0,
            'applies_to_all_services' => false,
            'is_active' => true,
        ]);
        $coupon->services()->attach($package->service_id);

        $this->postJson(route('proposal.coupon.check'), [
            'service_package_id' => $package->id,
            'coupon_code' => 'PKP10',
        ])->assertUnprocessable()->assertJsonValidationErrors('coupon_code');

        $this->assertDatabaseCount('coupon_redemptions', 0);
    }

    public function test_generator_matches_the_extended_service_catalog_and_current_rules(): void
    {
        $this->get('/alat/generator-nama?jenis=koperasi&sektor=umum&kata=Mekar')
            ->assertOk()
            ->assertSee('Koperasi Mekar')
            ->assertSee('Permenkum 13 Tahun 2025');

        $this->get('/alat/generator-nama?jenis=perkumpulan&sektor=sosial&kata=Insan')
            ->assertOk()
            ->assertSee('Perkumpulan Insan')
            ->assertSee('Permenkum 18 Tahun 2025');

        $this->get('/alat/generator-nama?jenis=pt_pma&sektor=teknologi&kata=Global Data')
            ->assertOk()
            ->assertSee('Perseroan Terbatas PMA')
            ->assertSee('Permenkum 49 Tahun 2025');
    }
}

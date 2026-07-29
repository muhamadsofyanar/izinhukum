<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\PartnerReferral;
use App\Models\ServicePackage;
use App\Models\User;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerReferralTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_referral_is_saved_on_inquiry(): void
    {
        $partner = $this->partner();

        $this->get('/proposal?ref='.$partner->partner_code)
            ->assertOk()
            ->assertCookie('ih_partner_ref');

        $visit = PartnerReferral::query()->firstOrFail();
        $this->assertSame($partner->id, $visit->partner_id);
        $this->assertSame(1, $visit->click_count);

        $this->withSession([
            'partner_referral_id' => $visit->id,
            'partner_referral_code' => $partner->partner_code,
            'partner_referral_visitor' => $visit->visitor_token,
        ])->post('/proposal', [
            'name' => 'Prospek Referral',
            'phone' => '081234567890',
            'email' => 'prospek@example.test',
            'privacy_consent' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('inquiries', [
            'name' => 'Prospek Referral',
            'source' => 'partner_referral',
            'referred_by_partner_id' => $partner->id,
            'referral_code' => $partner->partner_code,
        ]);
    }

    public function test_invalid_referral_is_not_attributed(): void
    {
        $this->get('/proposal?ref=KODE-TIDAK-VALID')->assertOk();

        $this->post('/proposal', [
            'name' => 'Prospek Website',
            'phone' => '081234567890',
            'privacy_consent' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('inquiries', [
            'name' => 'Prospek Website',
            'source' => 'website',
            'referred_by_partner_id' => null,
        ]);
        $this->assertDatabaseCount('partner_referrals', 0);
    }

    public function test_admin_can_create_an_attributed_invoice_from_inquiry(): void
    {
        $this->seed(ServiceSeeder::class);
        $admin = $this->admin();
        $partner = $this->partner();
        $package = ServicePackage::query()->where('name', 'Pendirian PT')->firstOrFail();
        $inquiry = Inquiry::query()->create([
            'reference' => 'IH-260729-REF01',
            'service_package_id' => $package->id,
            'referred_by_partner_id' => $partner->id,
            'referral_code' => $partner->partner_code,
            'name' => 'Prospek Menjadi Klien',
            'phone' => '081234567890',
            'email' => 'klien-referral@example.test',
            'source' => 'partner_referral',
            'status' => 'baru',
        ]);

        $this->withSession(['portal_user_id' => $admin->id])
            ->get(route('admin.invoices.create', ['inquiry' => $inquiry->id]))
            ->assertOk()
            ->assertSee('Prospek Menjadi Klien')
            ->assertSee($partner->partner_code);

        $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.invoices.store'), [
                'inquiry_id' => $inquiry->id,
                'referred_by_partner_id' => $partner->id,
                'recipient_type' => 'end_user',
                'recipient_name' => 'Prospek Menjadi Klien',
                'recipient_email' => 'klien-referral@example.test',
                'issue_date' => '2026-07-29',
                'items' => [[
                    'service_package_id' => $package->id,
                    'quantity' => 1,
                    'unit_price' => $package->price,
                ]],
            ])->assertRedirect();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame($inquiry->id, $invoice->inquiry_id);
        $this->assertSame($partner->id, $invoice->referred_by_partner_id);
        $this->assertSame($partner->partner_code, $invoice->referral_code);
        $this->assertSame('proses', $inquiry->fresh()->status);
    }

    private function admin(): User
    {
        return User::query()->create([
            'role' => 'admin',
            'name' => 'Admin Referral',
            'email' => 'admin-referral@example.test',
            'password' => 'password-aman',
            'is_active' => true,
        ]);
    }

    private function partner(): User
    {
        return User::query()->create([
            'role' => 'partner',
            'partner_code' => 'LEG-2607-REF1',
            'partner_level' => 'professional',
            'name' => 'Mitra Referral',
            'email' => 'mitra-referral@example.test',
            'password' => 'password-aman',
            'is_active' => true,
            'account_status' => 'active',
        ]);
    }
}

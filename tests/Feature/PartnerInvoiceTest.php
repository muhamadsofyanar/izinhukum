<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\ServicePackage;
use App\Models\User;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_can_view_partner_prices_without_admin_access(): void
    {
        $this->seed(ServiceSeeder::class);
        $partner = $this->partner();

        $this->withSession(['portal_user_id' => $partner->id, 'portal_role' => 'partner'])
            ->get('/mitra/harga')
            ->assertOk()
            ->assertSee('Harga Mitra LegaOne')
            ->assertSee('Rp3.600.000');

        $this->withSession(['portal_user_id' => $partner->id, 'portal_role' => 'partner'])
            ->get('/admin')
            ->assertRedirect('/admin/masuk');
    }

    public function test_partner_cannot_create_an_invoice_below_minimum_end_user_price(): void
    {
        $this->seed(ServiceSeeder::class);
        $partner = $this->partner();
        $package = ServicePackage::where('name', 'Pendirian PT')->firstOrFail();

        $response = $this
            ->withSession(['portal_user_id' => $partner->id, 'portal_role' => 'partner'])
            ->post('/mitra/invoice', [
                'recipient_name' => 'Pelanggan Uji',
                'recipient_email' => 'pelanggan@example.com',
                'issue_date' => now()->format('Y-m-d'),
                'items' => [[
                    'service_package_id' => $package->id,
                    'quantity' => 1,
                    'unit_price' => $package->minimum_end_user_price - 1,
                ]],
            ]);

        $response->assertSessionHasErrors('items.0.unit_price');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_partner_can_create_a_public_invoice_at_the_minimum_price(): void
    {
        $this->seed(ServiceSeeder::class);
        $partner = $this->partner();
        $package = ServicePackage::where('name', 'Pendirian PT')->firstOrFail();

        $response = $this
            ->withSession(['portal_user_id' => $partner->id, 'portal_role' => 'partner'])
            ->post('/mitra/invoice', [
                'recipient_name' => 'Pelanggan Uji',
                'recipient_email' => 'pelanggan@example.com',
                'issue_date' => now()->format('Y-m-d'),
                'items' => [[
                    'service_package_id' => $package->id,
                    'quantity' => 1,
                    'unit_price' => $package->minimum_end_user_price,
                ]],
            ]);

        $invoice = Invoice::firstOrFail();
        $response->assertRedirect(route('partner.invoices.show', $invoice));
        $this->assertSame(4200000, $invoice->total);
        $this->get(route('invoices.public', $invoice->public_token))
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertSee('Rp4.200.000');
    }

    private function partner(): User
    {
        return User::create([
            'role' => 'partner',
            'partner_code' => 'LEG-TEST-0001',
            'name' => 'Mitra Uji',
            'email' => 'mitra@example.com',
            'password' => 'password-yang-kuat',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}

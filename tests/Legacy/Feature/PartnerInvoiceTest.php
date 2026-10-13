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

    public function test_partner_can_update_and_delete_an_own_draft_invoice(): void
    {
        $this->seed(ServiceSeeder::class);
        $partner = $this->partner();
        $package = ServicePackage::where('name', 'Pendirian PT')->firstOrFail();
        $invoice = Invoice::create([
            'invoice_number' => 'INV/IH/202607/00901',
            'public_token' => str_repeat('D', 56),
            'created_by' => $partner->id,
            'recipient_type' => 'end_user',
            'recipient_name' => 'Nama Lama',
            'issue_date' => '2026-07-29',
            'status' => 'draft',
            'subtotal' => 4200000,
            'total' => 4200000,
        ]);

        $this->withSession(['portal_user_id' => $partner->id])
            ->put(route('partner.invoices.update', $invoice), [
                'recipient_name' => 'Nama Baru',
                'recipient_email' => 'nama-baru@example.test',
                'issue_date' => '2026-07-29',
                'items' => [[
                    'service_package_id' => $package->id,
                    'quantity' => 1,
                    'unit_price' => $package->minimum_end_user_price,
                ]],
            ])
            ->assertRedirect(route('partner.invoices.show', $invoice))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'recipient_name' => 'Nama Baru',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invoice.updated',
            'subject_id' => $invoice->id,
        ]);

        $this->withSession(['portal_user_id' => $partner->id])
            ->delete(route('partner.invoices.destroy', $invoice))
            ->assertRedirect(route('partner.invoices.index'));

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invoice.deleted',
            'subject_id' => $invoice->id,
        ]);
    }

    public function test_sent_invoice_is_locked_and_requires_a_reason_to_cancel(): void
    {
        $partner = $this->partner();
        $invoice = Invoice::create([
            'invoice_number' => 'INV/IH/202607/00902',
            'public_token' => str_repeat('E', 56),
            'created_by' => $partner->id,
            'recipient_type' => 'end_user',
            'recipient_name' => 'Pelanggan Terkunci',
            'issue_date' => '2026-07-29',
            'status' => 'sent',
            'subtotal' => 1000000,
            'total' => 1000000,
            'sent_at' => now(),
        ]);

        $this->withSession(['portal_user_id' => $partner->id])
            ->from(route('partner.invoices.show', $invoice))
            ->get(route('partner.invoices.edit', $invoice))
            ->assertRedirect(route('partner.invoices.show', $invoice))
            ->assertSessionHasErrors('invoice');

        $this->withSession(['portal_user_id' => $partner->id])
            ->from(route('partner.invoices.show', $invoice))
            ->post(route('partner.invoices.cancel', $invoice))
            ->assertRedirect(route('partner.invoices.show', $invoice))
            ->assertSessionHasErrors('cancellation_reason');

        $this->withSession(['portal_user_id' => $partner->id])
            ->from(route('partner.invoices.show', $invoice))
            ->post(route('partner.invoices.cancel', $invoice), [
                'cancellation_reason' => 'Pelanggan membatalkan pesanan sebelum pembayaran.',
            ])
            ->assertRedirect(route('partner.invoices.show', $invoice))
            ->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame('cancelled', $invoice->status);
        $this->assertNotNull($invoice->cancelled_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invoice.cancelled',
            'subject_id' => $invoice->id,
        ]);
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

<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_document_branding_and_invoice_displays_it(): void
    {
        $admin = User::create([
            'role' => 'admin',
            'name' => 'Admin Branding',
            'email' => 'branding@example.test',
            'password' => 'password-aman',
            'is_active' => true,
        ]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV/IH/202607/00088',
            'public_token' => str_repeat('D', 56),
            'created_by' => $admin->id,
            'recipient_type' => 'end_user',
            'recipient_name' => 'Klien Branding',
            'issue_date' => '2026-07-29',
            'status' => 'draft',
            'subtotal' => 500000,
            'total' => 500000,
        ]);

        $this->withSession(['portal_user_id' => $admin->id])
            ->put(route('admin.branding.update'), [
                'brand_name' => 'IzinHukum Nusantara',
                'brand_tagline' => 'Legalitas terpercaya',
                'document_address' => 'Jalan Legalitas Nomor 1',
                'document_phone' => '081234567890',
                'document_email' => 'billing@example.test',
                'bank_name' => 'BCA',
                'bank_account_number' => '1234567890',
                'bank_account_holder' => 'PT Praktisi Izin Hukum',
                'signatory_name' => 'Direktur Utama',
                'signatory_title' => 'Direktur',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->get(route('invoices.public', $invoice->public_token))
            ->assertOk()
            ->assertSee('IzinHukum Nusantara')
            ->assertSee('Jalan Legalitas Nomor 1')
            ->assertSee('1234567890');
    }
}

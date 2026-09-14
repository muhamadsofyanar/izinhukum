<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvoiceSchemaCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_pages_do_not_crash_when_payment_proof_migration_is_temporarily_missing(): void
    {
        $admin = User::query()->create([
            'role' => 'admin', 'name' => 'Admin Invoice', 'email' => 'invoice-admin@example.test',
            'password' => 'password-aman', 'is_active' => true,
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV/IH/202609/00099',
            'public_token' => str_repeat('C', 56),
            'created_by' => $admin->id,
            'recipient_type' => 'end_user',
            'recipient_name' => 'Klien',
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 1000000,
            'discount' => 0,
            'tax' => 0,
            'total' => 1000000,
        ]);
        Schema::drop('payment_proofs');

        $this->withSession(['portal_user_id' => $admin->id])
            ->get(route('admin.invoices.show', $invoice))
            ->assertOk();
        $this->get(route('invoices.public', $invoice->public_token))->assertOk();
    }
}

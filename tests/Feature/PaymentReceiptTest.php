<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_creates_a_receipt_and_updates_invoice_status(): void
    {
        [$admin, $invoice] = $this->records();

        $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.invoices.payments.store', $invoice), [
                'payment_date' => '2026-07-29',
                'amount' => 1000000,
                'payment_method' => 'transfer',
                'reference_number' => 'TRX-001',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $payment = Payment::firstOrFail();
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertSame(1000000, $payment->amount);

        $this->get(route('receipts.public', $payment->public_token))
            ->assertOk()
            ->assertSee($payment->receipt_number)
            ->assertSee('Rp1.000.000')
            ->assertSee('Satu juta rupiah');
    }

    public function test_final_payment_marks_invoice_paid_and_overpayment_is_rejected(): void
    {
        [$admin, $invoice] = $this->records();

        $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.invoices.payments.store', $invoice), [
                'payment_date' => '2026-07-29',
                'amount' => 2000000,
                'payment_method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);

        $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.invoices.payments.store', $invoice), [
                'payment_date' => '2026-07-29',
                'amount' => 1,
                'payment_method' => 'cash',
            ])
            ->assertSessionHasErrors('amount');
    }

    private function records(): array
    {
        $admin = User::create([
            'role' => 'admin',
            'name' => 'Admin Keuangan',
            'email' => 'finance@example.test',
            'password' => 'password-aman',
            'is_active' => true,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV/IH/202607/00001',
            'public_token' => str_repeat('A', 56),
            'created_by' => $admin->id,
            'recipient_type' => 'end_user',
            'recipient_name' => 'Pelanggan Uji',
            'issue_date' => '2026-07-29',
            'status' => 'sent',
            'subtotal' => 2000000,
            'total' => 2000000,
        ]);

        return [$admin, $invoice];
    }
}

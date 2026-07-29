<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\FinancialReportService;
use Carbon\CarbonImmutable;
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

    public function test_admin_can_correct_and_cancel_a_receipt_with_an_audit_trail(): void
    {
        [$admin, $invoice] = $this->records();

        $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.invoices.payments.store', $invoice), [
                'payment_date' => '2026-07-29',
                'amount' => 1000000,
                'payment_method' => 'transfer',
                'reference_number' => 'TRX-AWAL',
            ])
            ->assertSessionHasNoErrors();

        $payment = Payment::firstOrFail();

        $this->withSession(['portal_user_id' => $admin->id])
            ->from(route('admin.payments.edit', $payment))
            ->put(route('admin.payments.update', $payment), [
                'payment_date' => '2026-07-29',
                'amount' => 1200000,
                'payment_method' => 'transfer',
                'reference_number' => 'TRX-KOREKSI',
            ])
            ->assertRedirect(route('admin.payments.edit', $payment))
            ->assertSessionHasErrors('edit_reason');

        $this->withSession(['portal_user_id' => $admin->id])
            ->put(route('admin.payments.update', $payment), [
                'payment_date' => '2026-07-29',
                'amount' => 1200000,
                'payer_name' => 'Pelanggan Uji',
                'description' => 'Pembayaran terkoreksi',
                'payment_method' => 'transfer',
                'reference_number' => 'TRX-KOREKSI',
                'edit_reason' => 'Nominal pada mutasi bank sebelumnya salah dibaca.',
            ])
            ->assertRedirect(route('admin.invoices.show', $invoice))
            ->assertSessionHasNoErrors();

        $payment->refresh();
        $this->assertSame(1200000, $payment->amount);
        $this->assertNotNull($payment->last_edited_at);
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.updated',
            'subject_id' => $payment->id,
        ]);

        $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.payments.cancel', $payment), [
                'cancellation_reason' => 'Transaksi dibatalkan karena dana dikembalikan.',
            ])
            ->assertRedirect(route('admin.invoices.show', $invoice))
            ->assertSessionHasNoErrors();

        $payment->refresh();
        $this->assertSame('cancelled', $payment->status);
        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.cancelled',
            'subject_id' => $payment->id,
        ]);
        $this->get(route('receipts.public', $payment->public_token))
            ->assertOk()
            ->assertSee('DIBATALKAN')
            ->assertSee('dana dikembalikan');

        $report = app(FinancialReportService::class)->forPeriod(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );
        $this->assertSame(0, $report['income']);
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'payment.deleted')
            ->count());
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

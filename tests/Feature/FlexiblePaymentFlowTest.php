<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\InvoicePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlexiblePaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_business_expense_categories_are_available(): void
    {
        $this->assertDatabaseHas('financial_categories', ['type' => 'expense', 'slug' => 'biaya-notaris-mitra']);
        $this->assertDatabaseHas('financial_categories', ['type' => 'expense', 'slug' => 'ongkir-dan-kurir']);
        $this->assertDatabaseHas('financial_categories', ['type' => 'expense', 'slug' => 'internet-dan-kuota']);
    }

    public function test_notary_direct_payment_reduces_invoice_balance_without_creating_izinhukum_income_or_receipt(): void
    {
        $admin = User::query()->create([
            'role' => 'admin', 'name' => 'Admin', 'email' => 'pay-admin@example.test',
            'password' => 'password-aman', 'is_active' => true,
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV/IH/202609/00001',
            'public_token' => str_repeat('B', 56),
            'created_by' => $admin->id,
            'recipient_type' => 'end_user',
            'recipient_name' => 'Klien Yayasan',
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'subtotal' => 3000000,
            'discount' => 0,
            'tax' => 0,
            'total' => 3000000,
            'payment_scheme' => Invoice::PAYMENT_SCHEME_INSTALLMENTS,
            'payment_recipient' => Invoice::PAYMENT_RECIPIENT_SPLIT,
            'payment_terms' => 'Rp1.500.000 langsung ke notaris; sisanya ke IzinHukum.',
            'sent_at' => now(),
        ]);

        $payment = app(InvoicePaymentService::class)->record($invoice, $admin, [
            'payment_date' => now()->toDateString(),
            'amount' => 1500000,
            'payment_method' => 'transfer',
            'received_by' => 'notary',
            'reference_number' => 'NOTARIS-001',
        ]);

        $this->assertFalse($payment->hasOfficialReceipt());
        $this->assertSame(1500000, $invoice->fresh()->remainingAmount());
        $this->get(route('receipts.public', $payment->public_token))->assertNotFound();

        $report = app(FinancialReportService::class)->forPeriod(now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame(0, $report['income']);
    }
}

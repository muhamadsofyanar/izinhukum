<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\LegacyPaidInvoiceReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyPaidInvoiceReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_paid_invoice_uses_paid_at_and_is_idempotent(): void
    {
        $admin = $this->admin();
        $invoice = $this->paidInvoice($admin, '2026-07-20 09:15:00');
        $reconciler = app(LegacyPaidInvoiceReconciler::class);

        $this->assertSame(1, $reconciler->run());
        $this->assertSame(0, $reconciler->run());

        $payment = Payment::query()->firstOrFail();
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame('2026-07-20', $payment->payment_date->toDateString());
        $this->assertSame(6000000, $payment->amount);
        $this->assertSame('legacy_invoice_migration', $payment->source);
        $this->assertSame('legacy-paid-invoice:'.$invoice->id, $payment->source_key);
        $this->assertDatabaseCount('payments', 1);

        $report = app(FinancialReportService::class)->forPeriod(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );
        $this->assertSame(6000000, $report['income']);
    }

    public function test_legacy_paid_invoice_falls_back_to_updated_at(): void
    {
        $admin = $this->admin();
        $invoice = $this->paidInvoice($admin, null);
        $invoice->forceFill(['updated_at' => CarbonImmutable::parse('2026-07-18 16:45:00')])->save();

        $this->assertSame(1, app(LegacyPaidInvoiceReconciler::class)->run());

        $this->assertSame(
            '2026-07-18',
            Payment::query()->firstOrFail()->payment_date->toDateString(),
        );
    }

    private function admin(): User
    {
        return User::query()->create([
            'role' => 'admin',
            'name' => 'Admin Rekonsiliasi',
            'email' => 'admin-rekonsiliasi@example.test',
            'password' => 'password-aman',
            'is_active' => true,
        ]);
    }

    private function paidInvoice(User $admin, ?string $paidAt): Invoice
    {
        return Invoice::query()->create([
            'invoice_number' => 'INV/IH/202607/00888',
            'public_token' => str_repeat('R', 56),
            'created_by' => $admin->id,
            'recipient_type' => 'end_user',
            'recipient_name' => 'Pelanggan Lama',
            'issue_date' => '2026-07-10',
            'status' => 'paid',
            'subtotal' => 6000000,
            'total' => 6000000,
            'paid_at' => $paidAt,
        ]);
    }
}


<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_uses_actual_payments_and_expenses(): void
    {
        $admin = $this->user('admin', 'admin-finance@example.test');
        $invoice = Invoice::create([
            'invoice_number' => 'INV/IH/202607/00009',
            'public_token' => str_repeat('B', 56),
            'created_by' => $admin->id,
            'recipient_type' => 'end_user',
            'recipient_name' => 'Klien Laporan',
            'issue_date' => '2026-07-01',
            'status' => 'partial',
            'subtotal' => 7000000,
            'total' => 7000000,
        ]);
        Payment::create([
            'invoice_id' => $invoice->id,
            'created_by' => $admin->id,
            'receipt_number' => 'KWT/IH/202607/00009',
            'public_token' => str_repeat('C', 64),
            'payment_date' => '2026-07-10',
            'amount' => 5000000,
            'payment_method' => 'transfer',
        ]);
        Expense::create([
            'created_by' => $admin->id,
            'transaction_date' => '2026-07-12',
            'description' => 'Operasional',
            'amount' => 1750000,
            'payment_method' => 'transfer',
        ]);

        $this->withSession(['portal_user_id' => $admin->id])
            ->get(route('admin.finance.index', ['from' => '2026-07-01', 'to' => '2026-07-31']))
            ->assertOk()
            ->assertSee('Rp5.000.000')
            ->assertSee('Rp1.750.000')
            ->assertSee('Rp3.250.000')
            ->assertSee('Rp2.000.000');
    }

    public function test_partner_cannot_access_financial_reports(): void
    {
        $partner = $this->user('partner', 'partner-finance@example.test');

        $this->withSession(['portal_user_id' => $partner->id])
            ->get('/admin/keuangan')
            ->assertRedirect('/admin/masuk');
    }

    public function test_financial_report_can_be_exported_as_csv(): void
    {
        $admin = $this->user('admin', 'admin-export@example.test');

        $this->withSession(['portal_user_id' => $admin->id])
            ->get(route('admin.finance.export', ['from' => '2026-07-01', 'to' => '2026-07-31']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('laporan-keuangan-20260701-20260731.csv');
    }

    public function test_admin_can_record_non_invoice_income(): void
    {
        $admin = $this->user('admin', 'admin-income@example.test');

        $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.finance.incomes.store'), [
                'payment_date' => '2026-07-15',
                'description' => 'Pendapatan konsultasi',
                'payer_name' => 'Klien Konsultasi',
                'amount' => 750000,
                'payment_method' => 'transfer',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('payments', [
            'invoice_id' => null,
            'description' => 'Pendapatan konsultasi',
            'amount' => 750000,
        ]);
    }

    private function user(string $role, string $email): User
    {
        return User::create([
            'role' => $role,
            'partner_code' => $role === 'partner' ? 'LEG-FINANCE' : null,
            'name' => 'Pengguna Keuangan',
            'email' => $email,
            'password' => 'password-aman',
            'is_active' => true,
        ]);
    }
}

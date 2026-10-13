<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_correction_and_cancellation_sync_referral_commission(): void
    {
        $admin = $this->user('admin', null, 'admin-komisi@example.test');
        $partner = $this->user('partner', 'priority', 'mitra-komisi@example.test');
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV/IH/202607/00777',
            'public_token' => str_repeat('Q', 56),
            'created_by' => $admin->id,
            'referred_by_partner_id' => $partner->id,
            'referral_code' => $partner->partner_code,
            'recipient_type' => 'end_user',
            'recipient_name' => 'Klien Referral',
            'issue_date' => '2026-07-29',
            'status' => 'sent',
            'subtotal' => 2000000,
            'total' => 2000000,
            'sent_at' => now(),
        ]);

        $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.invoices.payments.store', $invoice), [
                'payment_date' => '2026-07-29',
                'amount' => 1000000,
                'payment_method' => 'transfer',
            ])
            ->assertSessionHasNoErrors();

        $payment = Payment::query()->firstOrFail();
        $commission = Commission::query()->firstOrFail();
        $this->assertSame($payment->id, $commission->payment_id);
        $this->assertSame(1500, $commission->rate_bps);
        $this->assertSame(150000, $commission->amount);
        $this->assertSame('pending', $commission->status);

        $this->withSession(['portal_user_id' => $admin->id])
            ->put(route('admin.payments.update', $payment), [
                'payment_date' => '2026-07-29',
                'amount' => 800000,
                'payment_method' => 'transfer',
                'edit_reason' => 'Nominal mutasi bank dikoreksi.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(120000, $commission->fresh()->amount);
        $this->assertDatabaseCount('commissions', 1);

        $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.payments.cancel', $payment), [
                'cancellation_reason' => 'Dana dikembalikan kepada pelanggan.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $commission->fresh()->status);
    }

    public function test_cancelling_payment_after_commission_paid_requires_adjustment(): void
    {
        $admin = $this->user('admin', null, 'admin-adjustment@example.test');
        $partner = $this->user('partner', 'starter', 'mitra-adjustment@example.test');
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV/IH/202607/00778',
            'public_token' => str_repeat('S', 56),
            'created_by' => $admin->id,
            'referred_by_partner_id' => $partner->id,
            'recipient_type' => 'end_user',
            'recipient_name' => 'Klien Penyesuaian',
            'issue_date' => '2026-07-29',
            'status' => 'sent',
            'subtotal' => 1000000,
            'total' => 1000000,
            'sent_at' => now(),
        ]);

        $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.invoices.payments.store', $invoice), [
                'payment_date' => '2026-07-29',
                'amount' => 1000000,
                'payment_method' => 'transfer',
            ])
            ->assertSessionHasNoErrors();

        $payment = Payment::query()->firstOrFail();
        $commission = Commission::query()->firstOrFail();
        $commission->update(['status' => 'paid', 'paid_at' => now()]);

        $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.payments.cancel', $payment), [
                'cancellation_reason' => 'Pembayaran dibatalkan setelah komisi dibayarkan.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('adjustment_required', $commission->fresh()->status);
        $this->assertNotNull($commission->fresh()->paid_at);
    }

    private function user(string $role, ?string $level, string $email): User
    {
        return User::query()->create([
            'role' => $role,
            'partner_code' => $role === 'partner' ? 'LEG-2607-COMM' : null,
            'partner_level' => $level ?: 'starter',
            'name' => $role === 'admin' ? 'Admin Komisi' : 'Mitra Komisi',
            'email' => $email,
            'password' => 'password-aman',
            'is_active' => true,
            'account_status' => 'active',
        ]);
    }
}


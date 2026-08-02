<?php

namespace Tests\Feature;

use App\Jobs\SendNewInquiryEmailNotification;
use App\Jobs\SendNewInquiryWhatsAppNotification;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\PaymentProof;
use App\Models\SalesQuote;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class V16OneDeploySuiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_attribution_creates_a_sales_pipeline_lead(): void
    {
        Queue::fake();
        $package = ServicePackage::query()->where('price', '>', 0)->firstOrFail();

        $this->get('/layanan?utm_source=whatsapp&utm_medium=broadcast&utm_campaign=promo-agustus');
        $this->post('/proposal', [
            'service_package_id' => $package->id,
            'name' => 'Klien Campaign',
            'phone' => '081234567801',
            'email' => 'campaign@example.test',
            'privacy_consent' => '1',
        ])->assertRedirect();

        $inquiry = Inquiry::query()->where('email', 'campaign@example.test')->firstOrFail();
        $this->assertSame('whatsapp', $inquiry->utm_source);
        $this->assertSame('promo-agustus', $inquiry->utm_campaign);
        $this->assertDatabaseHas('crm_leads', [
            'inquiry_id' => $inquiry->id,
            'stage' => 'new',
        ]);
        Queue::assertPushed(SendNewInquiryEmailNotification::class);
        Queue::assertPushed(SendNewInquiryWhatsAppNotification::class);
    }

    public function test_public_quote_approval_creates_an_invoice_once(): void
    {
        $admin = $this->admin();
        $package = ServicePackage::query()->where('price', '>', 0)->firstOrFail();
        $inquiry = Inquiry::query()->create([
            'reference' => 'IH-V16-Q01',
            'service_package_id' => $package->id,
            'name' => 'Klien Penawaran',
            'phone' => '081234567802',
            'email' => 'quote@example.test',
            'source' => 'website',
            'status' => 'baru',
        ]);

        $this->withSession(['portal_user_id' => $admin->id])->post(route('admin.quotes.store'), [
            'inquiry_id' => $inquiry->id,
            'recipient_name' => $inquiry->name,
            'recipient_email' => $inquiry->email,
            'recipient_phone' => $inquiry->phone,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(14)->toDateString(),
            'invoice_due_days' => 7,
            'discount' => 0,
            'items' => [[
                'service_package_id' => $package->id,
                'description' => $package->name,
                'quantity' => 1,
                'unit_price' => $package->price,
            ]],
        ])->assertRedirect();
        $quote = SalesQuote::query()->firstOrFail();
        $this->withSession(['portal_user_id' => $admin->id])->post(route('admin.quotes.send', $quote))->assertRedirect();

        $this->post(route('quotes.approve', $quote->public_token), ['approval_confirmation' => '1'])->assertRedirect();
        $this->post(route('quotes.approve', $quote->public_token), ['approval_confirmation' => '1'])->assertRedirect();

        $quote->refresh();
        $this->assertSame('approved', $quote->status);
        $this->assertNotNull($quote->invoice_id);
        $this->assertSame(1, Invoice::query()->where('inquiry_id', $inquiry->id)->count());
        $this->assertSame((int) $package->price, (int) $quote->invoice->total);
    }

    public function test_admin_approval_of_payment_proof_creates_payment_and_receipt(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV/IH/V16/00001',
            'public_token' => str_repeat('A', 56),
            'created_by' => $admin->id,
            'recipient_type' => 'end_user',
            'recipient_name' => 'Klien Transfer',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'sent',
            'subtotal' => 1500000,
            'discount' => 0,
            'tax' => 0,
            'total' => 1500000,
            'sent_at' => now(),
        ]);

        $this->post(route('invoices.payment-proofs.store', $invoice->public_token), [
            'payer_name' => 'Klien Transfer',
            'transfer_date' => now()->toDateString(),
            'claimed_amount' => 1500000,
            'bank_reference' => 'TRX-V16-01',
            'proof_file' => UploadedFile::fake()->image('bukti.png'),
        ])->assertRedirect();
        $proof = PaymentProof::query()->firstOrFail();

        $this->withSession(['portal_user_id' => $admin->id])->post(route('admin.payment-proofs.review', $proof), [
            'action' => 'approve',
            'review_note' => 'Mutasi bank sudah sesuai.',
        ])->assertRedirect();

        $proof->refresh();
        $this->assertSame('approved', $proof->status);
        $this->assertNotNull($proof->payment_id);
        $this->assertDatabaseHas('payments', [
            'id' => $proof->payment_id,
            'source' => 'payment_proof',
            'source_key' => 'payment-proof:'.$proof->id,
            'amount' => 1500000,
        ]);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    private function admin(): User
    {
        return User::query()->firstOrCreate(['email' => 'admin-v16@example.test'], [
            'role' => 'admin',
            'name' => 'Admin V16',
            'password' => 'password-aman',
            'is_active' => true,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\SendNewInquiryEmailNotification;
use App\Jobs\SendNewInquiryWhatsAppNotification;
use App\Models\CrmContact;
use App\Models\CrmLead;
use App\Models\Inquiry;
use App\Models\MarketingCampaign;
use App\Models\SalesMessageTemplate;
use App\Models\SalesQuote;
use App\Models\SalesQuoteTemplate;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class V20ConversionSuiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_lead_is_attributed_and_scored(): void
    {
        Queue::fake();
        $campaign = MarketingCampaign::query()->create([
            'name' => 'Promo Agustus',
            'slug' => 'promo-agustus',
            'source' => 'whatsapp',
            'medium' => 'broadcast',
            'budget' => 2000000,
            'spend' => 1500000,
            'status' => 'active',
        ]);
        $package = ServicePackage::query()->where('price', '>', 0)->firstOrFail();

        $this->get('/layanan?utm_source=whatsapp&utm_medium=broadcast&utm_campaign=promo-agustus');
        $this->post('/proposal', [
            'service_package_id' => $package->id,
            'name' => 'Lead Berniat Tinggi',
            'phone' => '081234560020',
            'email' => 'lead-v20@example.test',
            'company_name' => 'PT Contoh V20',
            'message' => 'Kami ingin segera mengurus legalitas dan membutuhkan penawaran lengkap.',
            'privacy_consent' => '1',
        ])->assertRedirect();

        $inquiry = Inquiry::query()->where('email', 'lead-v20@example.test')->firstOrFail();
        $lead = CrmLead::query()->where('inquiry_id', $inquiry->id)->firstOrFail();
        $this->assertSame($campaign->id, $inquiry->marketing_campaign_id);
        $this->assertGreaterThanOrEqual(70, $lead->lead_score);
        $this->assertSame('hot', $lead->temperature);
        Queue::assertPushed(SendNewInquiryEmailNotification::class);
        Queue::assertPushed(SendNewInquiryWhatsAppNotification::class);
    }

    public function test_playbook_only_opens_a_prefilled_manual_whatsapp_message(): void
    {
        $admin = $this->admin();
        $contact = CrmContact::query()->create([
            'phone' => '6281234560021',
            'name' => 'Lead Manual',
            'source' => 'whatsapp',
            'status' => 'active',
            'lifecycle_stage' => 'lead',
        ]);
        $lead = CrmLead::query()->create([
            'contact_id' => $contact->id,
            'title' => 'Pendirian PT · Lead Manual',
            'source' => 'whatsapp',
            'stage' => 'new',
            'service_interest' => 'Pendirian PT',
            'lead_score' => 55,
            'temperature' => 'warm',
        ]);
        $template = SalesMessageTemplate::query()->create([
            'name' => 'Tes manual',
            'purpose' => 'first_response',
            'stage' => 'new',
            'body' => 'Halo {{name}}, kami siap membantu {{service}}.',
            'is_active' => true,
        ]);

        $response = $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.pipeline.whatsapp', [$lead, $template]));
        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://wa.me/6281234560021',
            (string) $response->headers->get('Location'),
        );
        $this->assertDatabaseHas('crm_activities', [
            'lead_id' => $lead->id,
            'type' => 'message_prepared',
        ]);
    }

    public function test_quote_template_is_linked_and_usage_is_counted(): void
    {
        $admin = $this->admin();
        $package = ServicePackage::query()->where('price', '>', 0)->with('service')->firstOrFail();
        $template = SalesQuoteTemplate::query()->create([
            'name' => 'Template '.$package->service->name,
            'service_id' => $package->service_id,
            'scope' => 'Pemeriksaan dan pengajuan sampai selesai.',
            'terms' => 'Pekerjaan dimulai setelah pembayaran.',
            'validity_days' => 14,
            'invoice_due_days' => 7,
            'is_active' => true,
        ]);

        $this->withSession(['portal_user_id' => $admin->id])->post(route('admin.quotes.store'), [
            'sales_quote_template_id' => $template->id,
            'recipient_name' => 'Klien Template',
            'recipient_phone' => '081234560022',
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(14)->toDateString(),
            'invoice_due_days' => 7,
            'scope' => $template->scope,
            'terms' => $template->terms,
            'items' => [[
                'service_package_id' => $package->id,
                'description' => $package->name,
                'quantity' => 1,
                'unit_price' => $package->price,
            ]],
        ])->assertRedirect();

        $quote = SalesQuote::query()->firstOrFail();
        $this->assertSame($template->id, $quote->sales_quote_template_id);
        $this->assertSame(1, $template->fresh()->use_count);
    }

    private function admin(): User
    {
        return User::query()->firstOrCreate(['email' => 'admin-v20@example.test'], [
            'role' => 'admin',
            'name' => 'Admin V20',
            'password' => 'password-aman',
            'is_active' => true,
        ]);
    }
}

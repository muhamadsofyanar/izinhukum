<?php

namespace Tests\Feature;

use App\Jobs\SendNewInquiryEmailNotification;
use App\Jobs\SendNewInquiryWhatsAppNotification;
use App\Models\Inquiry;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LeadScreeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_submission_creates_a_lead_without_creating_a_project(): void
    {
        Queue::fake();

        $this->post(route('proposal.store'), [
            'name' => 'Calon Klien',
            'phone' => '081234567890',
            'message' => 'Ingin membuat yayasan pendidikan.',
            'privacy_consent' => '1',
            'website' => '',
            'form_started_at' => now()->subSeconds(8)->timestamp,
        ])->assertRedirect();

        $lead = Inquiry::query()->where('name', 'Calon Klien')->firstOrFail();

        $this->assertSame('6281234567890', $lead->phone_normalized);
        $this->assertSame(Inquiry::DISPOSITION_NEW, $lead->disposition);
        $this->assertFalse($lead->canConvertToProject());
        $this->assertDatabaseCount('service_orders', 0);
        Queue::assertPushed(SendNewInquiryEmailNotification::class);
        Queue::assertPushed(SendNewInquiryWhatsAppNotification::class);
    }

    public function test_honeypot_submission_is_quarantined_without_notifications(): void
    {
        Queue::fake();

        $this->post(route('proposal.store'), [
            'name' => 'Robot Pengirim',
            'phone' => '081234567891',
            'message' => 'Promosi murah klik sekarang.',
            'privacy_consent' => '1',
            'website' => 'https://spam.example',
            'form_started_at' => now()->subSeconds(8)->timestamp,
        ])->assertRedirect();

        $this->assertDatabaseHas('inquiries', [
            'name' => 'Robot Pengirim',
            'disposition' => Inquiry::DISPOSITION_SPAM,
        ]);
        $this->assertDatabaseCount('service_orders', 0);
        Queue::assertNothingPushed();
    }

    public function test_repeated_phone_for_the_same_service_is_marked_duplicate(): void
    {
        Queue::fake();
        Inquiry::query()->create([
            'reference' => 'IH-260914-OLD01',
            'name' => 'Lead Pertama',
            'phone' => '081234567892',
            'phone_normalized' => '6281234567892',
            'source' => 'website',
            'status' => 'baru',
            'disposition' => Inquiry::DISPOSITION_NEW,
        ]);

        $this->post(route('proposal.store'), [
            'name' => 'Lead Berulang',
            'phone' => '+62 812-3456-7892',
            'privacy_consent' => '1',
            'website' => '',
            'form_started_at' => now()->subSeconds(8)->timestamp,
        ])->assertRedirect();

        $this->assertDatabaseHas('inquiries', [
            'name' => 'Lead Berulang',
            'phone_normalized' => '6281234567892',
            'disposition' => Inquiry::DISPOSITION_DUPLICATE,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_admin_must_qualify_a_lead_before_converting_it_to_a_project(): void
    {
        $admin = User::query()->create([
            'role' => 'admin',
            'name' => 'Admin Operasional',
            'email' => 'admin-lead@example.test',
            'password' => 'password-aman',
            'is_active' => true,
        ]);
        $lead = Inquiry::query()->create([
            'reference' => 'IH-260914-LEAD1',
            'name' => 'Lead Diperiksa',
            'phone' => '081234567893',
            'phone_normalized' => '6281234567893',
            'source' => 'website',
            'status' => 'baru',
            'disposition' => Inquiry::DISPOSITION_NEW,
        ]);

        $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.orders.from-inquiry', $lead))
            ->assertSessionHasErrors('disposition');
        $this->assertDatabaseCount('service_orders', 0);

        $this->withSession(['portal_user_id' => $admin->id])
            ->put(route('admin.inquiries.update', $lead), [
                'disposition' => Inquiry::DISPOSITION_QUALIFIED,
                'review_note' => 'Kebutuhan sudah diverifikasi melalui WhatsApp.',
            ])->assertRedirect();

        $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.orders.from-inquiry', $lead))
            ->assertRedirect();

        $this->assertDatabaseHas('service_orders', ['inquiry_id' => $lead->id]);
        $this->assertSame(1, ServiceOrder::query()->where('inquiry_id', $lead->id)->count());
        $this->assertDatabaseHas('inquiries', [
            'id' => $lead->id,
            'disposition' => Inquiry::DISPOSITION_WON,
        ]);
    }
}

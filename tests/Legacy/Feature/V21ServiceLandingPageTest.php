<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class V21ServiceLandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_active_service_uses_the_v21_conversion_standard(): void
    {
        $this->seed(ServiceSeeder::class);
        $services = Service::query()->where('is_active', true)->orderBy('id')->get();
        $this->assertGreaterThan(0, $services->count());

        foreach ($services as $service) {
            $this->get(route('services.show', $service))
                ->assertOk()
                ->assertSee($service->name)
                ->assertSee('Proses jelas sejak awal')
                ->assertSee('Kirim & lanjut ke WhatsApp')
                ->assertSee('FAQPage', false)
                ->assertSee('service_landing', false);
        }
    }

    public function test_admin_can_customize_one_service_without_redeploy(): void
    {
        $this->seed(ServiceSeeder::class);
        $admin = User::query()->create([
            'role' => 'admin',
            'name' => 'Admin V21',
            'email' => 'admin-v21@example.test',
            'password' => 'password-aman',
            'is_active' => true,
        ]);
        $service = Service::query()->where('is_active', true)->firstOrFail();

        $this->withSession(['portal_user_id' => $admin->id])
            ->put(route('admin.service-landings.update', $service), [
                'landing_eyebrow' => 'Solusi legalitas utama',
                'landing_headline' => 'Headline layanan yang diperbarui dari admin',
                'landing_subheadline' => 'Penjelasan khusus yang menjawab kebutuhan calon klien dengan jelas.',
                'benefits_text' => "Pemeriksaan kebutuhan\nBiaya jelas sebelum proses",
                'process_text' => "Konsultasi | Tim memahami kebutuhan\nPelaksanaan | Pekerjaan dimulai setelah disetujui",
                'faqs_text' => "Apakah bisa konsultasi? | Bisa, konsultasi awal tersedia.\nBagaimana biayanya? | Biaya dikonfirmasi sebelum proses.",
                'seo_title' => 'Judul SEO layanan V21',
                'seo_description' => 'Deskripsi SEO layanan yang diperbarui melalui panel admin V21.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $service->refresh();
        $this->assertSame('Headline layanan yang diperbarui dari admin', $service->landing_headline);
        $this->assertSame('Konsultasi', $service->landing_process[0]['title']);
        $this->get(route('services.show', $service))
            ->assertOk()
            ->assertSee('Headline layanan yang diperbarui dari admin')
            ->assertSee('Apakah bisa konsultasi?');
    }

    public function test_service_landing_form_creates_a_tracked_inquiry(): void
    {
        Queue::fake();
        $this->seed(ServiceSeeder::class);
        $service = Service::query()->where('is_active', true)->with('packages')->firstOrFail();
        $package = $service->packages->firstOrFail();

        $this->post(route('proposal.store'), [
            'service_package_id' => $package->id,
            'name' => 'Lead Landing V21',
            'phone' => '081234567821',
            'email' => 'lead-v21@example.test',
            'company_name' => 'PT Contoh V21',
            'message' => 'Saya ingin berkonsultasi dan meminta penawaran layanan ini secepatnya.',
            'journey_source' => 'service_landing',
            'privacy_consent' => '1',
        ])->assertRedirect();

        $inquiry = Inquiry::query()->where('email', 'lead-v21@example.test')->firstOrFail();
        $this->assertSame('service_landing', $inquiry->source);
        $this->assertSame($package->id, $inquiry->service_package_id);
    }
}

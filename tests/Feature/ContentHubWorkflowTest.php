<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Jobs\DispatchWhatsAppCampaign;
use App\Models\CrmContact;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\InteractsWithContentHub;
use Tests\TestCase;

class ContentHubWorkflowTest extends TestCase
{
    use InteractsWithContentHub;
    use RefreshDatabase;

    public function test_owner_can_create_ready_content_and_open_composer(): void
    {
        $this->actingAsHubOwner()
            ->post('/admin/whatsapp/bank-konten', [
                'title' => 'Pengingat konsultasi',
                'category' => 'follow-up',
                'body' => 'Halo {nama_pelanggan}, jadwal konsultasi Anda sudah dekat.',
                'status' => 'ready',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('content_items', ['title' => 'Pengingat konsultasi', 'status' => 'ready']);

        $this->actingAsHubOwner()
            ->get('/admin/whatsapp/kirim')
            ->assertOk()
            ->assertSee('Pengingat konsultasi');
    }

    public function test_content_role_can_manage_content_but_not_send_messages(): void
    {
        $user = User::factory()->create(['admin_role' => 'content']);

        $this->asHubUser($user)->get('/admin/whatsapp/bank-konten')->assertOk();
        $this->asHubUser($user)->get('/admin/whatsapp/kirim')->assertForbidden();
    }

    public function test_last_active_owner_cannot_be_disabled(): void
    {
        $owner = User::factory()->create(['admin_role' => 'owner']);

        $this->asHubUser($owner)
            ->put('/admin/whatsapp/pengguna/'.$owner->id, [
                'name' => $owner->name,
                'email' => $owner->email,
                'admin_role' => 'viewer',
            ])
            ->assertSessionHasErrors('admin_role');
    }

    public function test_broadcast_requires_preview_and_confirmation(): void
    {
        Bus::fake();
        $contact = CrmContact::create(['phone' => '628123456789', 'name' => 'Pelanggan', 'source' => 'manual', 'status' => 'active', 'lifecycle_stage' => 'contact']);
        WhatsAppConsent::create(['phone' => $contact->phone, 'allow_transactional' => true, 'allow_marketing' => true, 'source' => 'test', 'evidence' => 'Feature test', 'consented_at' => now()]);

        $preview = $this->actingAsHubOwner()->post('/admin/whatsapp/kirim/broadcast', [
            'name' => 'Pengumuman', 'body' => 'Halo {nama_pelanggan}', 'contact_ids' => [$contact->id],
        ])->assertOk()->assertSee('Konfirmasi 1 penerima');

        $campaign = WhatsAppCampaign::latest('id')->firstOrFail();
        self::assertSame('draft', $campaign->status);
        Bus::assertNothingDispatched();

        $this->actingAsHubOwner()->post('/admin/whatsapp/kirim/broadcast/'.$campaign->id.'/konfirmasi', [
            'confirmation_token' => $preview->viewData('confirmationToken'),
        ])->assertRedirect();
        Bus::assertDispatched(DispatchWhatsAppCampaign::class);
    }

    public function test_csv_is_previewed_before_contacts_are_imported(): void
    {
        $preview = $this->actingAsHubOwner()->post('/admin/whatsapp/kontak-impor', [
            'default_source' => 'csv',
            'csv_file' => UploadedFile::fake()->createWithContent('kontak.csv', "phone,name\n081234567890,Ani\nnomor-rusak,Budi\n"),
        ])->assertOk()->assertSee('1 baris siap diimpor');

        $import = $preview->viewData('import');
        $this->assertDatabaseMissing('crm_contacts', ['name' => 'Ani']);

        $this->actingAsHubOwner()->post('/admin/whatsapp/kontak-impor/'.$import->id.'/konfirmasi')->assertRedirect();
        $this->assertDatabaseHas('crm_contacts', ['name' => 'Ani', 'phone' => '6281234567890']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\KbliCode;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_is_available(): void
    {
        $this->seed(ServiceSeeder::class);

        $this->get('/')
            ->assertOk()
            ->assertSee('Kami bantu sampai tuntas')
            ->assertSee('PT PRAKTISI IZIN HUKUM');
    }

    public function test_estimated_prices_are_visibly_marked(): void
    {
        $this->seed(ServiceSeeder::class);

        $this->get('/layanan/pendaftaran-merek')
            ->assertOk()
            ->assertSee('Harga Perkiraan')
            ->assertSee('Rp1.500.000')
            ->assertSee('Minta Penawaran');
    }

    public function test_proposal_can_be_submitted(): void
    {
        $response = $this->post('/proposal', [
            'name' => 'Pengguna Uji',
            'phone' => '081234567890',
            'email' => 'uji@example.com',
            'message' => 'Ingin konsultasi pendirian PT.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('inquiries', [
            'name' => 'Pengguna Uji',
            'status' => 'baru',
        ]);
    }

    public function test_admin_requires_authentication(): void
    {
        $this->get('/admin')->assertRedirect('/admin/masuk');
    }

    public function test_kbli_2025_can_be_searched_and_opened(): void
    {
        $kbli = KbliCode::query()->create([
            'code' => '71203',
            'version' => '2025',
            'category_code' => 'N',
            'category_title' => 'Aktivitas Profesional, Ilmiah, dan Teknis',
            'title' => 'Jasa Verifikasi dan Validasi Teknis',
            'description' => 'Aktivitas penilaian objektif untuk memeriksa persyaratan teknis.',
            'risk_levels' => ['Menengah Tinggi'],
            'licenses' => ['Sertifikat Standar'],
            'source_url' => 'https://oss.go.id/id/kbli',
            'source_updated_at' => now(),
            'is_sample' => false,
        ]);

        $scope = $kbli->scopes()->create([
            'external_id' => '2325677f-2ad1-5608-9751-aeff00ee7da2',
            'name' => 'Seluruh',
            'sector' => 'Perindustrian',
            'regulations' => ['PP Nomor 28 Tahun 2025'],
        ]);

        $scope->profiles()->create([
            'external_code' => '71203-01-01',
            'business_scale' => 'Usaha Mikro',
            'risk_level' => 'Menengah Tinggi',
            'licenses' => ['Sertifikat Standar'],
            'issue_period' => '7 Hari',
            'requirements' => [['text' => 'Memiliki struktur organisasi.', 'period' => '7 Hari']],
            'obligations' => [['text' => 'Menyampaikan data industri.', 'period' => '7 Hari']],
            'authorities' => [['parameter' => 'PMA', 'authority' => 'Menteri/Kepala Badan']],
        ]);

        $this->get('/cek-risiko-kbli?q=verifikasi')
            ->assertOk()
            ->assertSee('Jasa Verifikasi dan Validasi Teknis')
            ->assertSee('Menengah Tinggi');

        $this->get('/cek-risiko-kbli/71203')
            ->assertOk()
            ->assertSee('Usaha Mikro')
            ->assertSee('Sertifikat Standar')
            ->assertSee('Memiliki struktur organisasi.')
            ->assertSee('Menyampaikan data industri.')
            ->assertSee('Menteri/Kepala Badan');
    }

    public function test_kbli_dataset_contains_the_complete_2025_catalog(): void
    {
        $path = database_path('data/kbli-2025.json');

        $this->assertFileExists($path);

        $dataset = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('2025', $dataset['metadata']['version']);
        $this->assertSame(1559, $dataset['metadata']['code_count']);
        $this->assertCount(1559, $dataset['records']);
        $this->assertSame('01111', $dataset['records'][0]['code']);
        $this->assertSame('99000', $dataset['records'][1558]['code']);
    }
}

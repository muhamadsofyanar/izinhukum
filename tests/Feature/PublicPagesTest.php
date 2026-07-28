<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_is_available(): void
    {
        $this->seed();

        $this->get('/')
            ->assertOk()
            ->assertSee('Kami bantu sampai tuntas')
            ->assertSee('PT PRAKTISI IZIN HUKUM');
    }

    public function test_estimated_prices_are_visibly_marked(): void
    {
        $this->seed();

        $this->get('/layanan/pendaftaran-merek')
            ->assertOk()
            ->assertSee('Harga Perkiraan')
            ->assertSee('Rp1.500.000')
            ->assertSee('Minta Penawaran');
    }

    public function test_proposal_can_be_submitted(): void
    {
        $this->seed();

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
}

<?php

namespace Tests\Feature;

use App\Models\PartnerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartnerPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_page_displays_exactly_three_partner_plans(): void
    {
        $this->get('/kemitraan')
            ->assertOk()
            ->assertSee('Gratis')
            ->assertSee('Berbayar')
            ->assertSee('Prioritas')
            ->assertSee('Rp499.000')
            ->assertSee('Rp1.499.000')
            ->assertSee('Komisi 5%')
            ->assertSee('Komisi 10%')
            ->assertSee('Komisi 15%');
    }

    public function test_selected_plan_is_saved_and_applied_when_admin_approves(): void
    {
        Mail::fake();

        $this->post('/kemitraan', [
            'desired_partner_level' => 'professional',
            'name' => 'Mitra Berbayar',
            'email' => 'mitra-berbayar@example.test',
            'password' => 'password-mitra-aman',
            'password_confirmation' => 'password-mitra-aman',
            'phone' => '081234567890',
            'city' => 'Jakarta',
            'privacy_consent' => '1',
        ])->assertRedirect(route('partnership.create'));

        $application = PartnerApplication::query()->firstOrFail();
        $this->assertSame('professional', $application->desired_partner_level);

        $admin = User::query()->create([
            'role' => 'admin',
            'name' => 'Admin Paket',
            'email' => 'admin-paket@example.test',
            'password' => 'password-aman',
            'is_active' => true,
        ]);

        $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.partners.approve', $application))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'mitra-berbayar@example.test',
            'partner_level' => 'professional',
            'role' => 'partner',
        ]);
    }
}


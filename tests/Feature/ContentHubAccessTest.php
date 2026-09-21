<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithContentHub;
use Tests\TestCase;

class ContentHubAccessTest extends TestCase
{
    use InteractsWithContentHub;
    use RefreshDatabase;

    public function test_public_host_cannot_open_content_hub(): void
    {
        config()->set('content-hub.domain', 'app.izinhukum.test');

        $this->withServerVariables(['HTTP_HOST' => 'izinhukum.test'])
            ->get('/admin/whatsapp')
            ->assertNotFound();
    }

    public function test_customer_service_cannot_dispatch_broadcast(): void
    {
        $user = User::factory()->create(['admin_role' => 'customer_service']);

        $this->asHubUser($user)
            ->post('/admin/whatsapp/kirim/broadcast', [])
            ->assertForbidden();
    }

    public function test_role_permissions_match_the_approved_matrix(): void
    {
        $matrix = [
            'owner' => ['users.manage' => true, 'message.broadcast' => true],
            'admin' => ['users.manage' => false, 'message.broadcast' => true],
            'content' => ['content.manage' => true, 'message.personal' => false],
            'customer_service' => ['contacts.manage' => true, 'message.broadcast' => false],
            'viewer' => ['dashboard.view' => true, 'contacts.manage' => false],
        ];

        foreach ($matrix as $role => $abilities) {
            $user = User::factory()->make(['role' => 'admin', 'admin_role' => $role, 'is_active' => true]);
            foreach ($abilities as $ability => $expected) {
                self::assertSame($expected, $user->hasAdminAbility($ability), $role.' '.$ability);
            }
        }
    }
}

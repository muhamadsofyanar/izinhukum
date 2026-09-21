<?php

namespace Tests\Concerns;

use App\Models\User;

trait InteractsWithContentHub
{
    protected function asHubUser(User $user): static
    {
        config()->set('content-hub.domain', 'app.izinhukum.test');

        return $this->withServerVariables(['HTTP_HOST' => 'app.izinhukum.test'])
            ->withSession(['portal_user_id' => $user->id, 'portal_role' => 'admin']);
    }

    protected function actingAsHubOwner(): static
    {
        return $this->asHubUser(User::factory()->create([
            'role' => 'admin',
            'admin_role' => 'owner',
            'is_active' => true,
        ]));
    }
}

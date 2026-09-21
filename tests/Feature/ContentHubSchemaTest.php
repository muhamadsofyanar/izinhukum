<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContentHubSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_hub_schema_is_available(): void
    {
        foreach (['content_items', 'crm_contact_imports', 'crm_contact_import_rows', 'whatsapp_recipient_presets'] as $table) {
            self::assertTrue(Schema::hasTable($table), $table.' should exist');
        }

        self::assertTrue(Schema::hasColumn('users', 'admin_role'));
        self::assertTrue(Schema::hasColumn('whatsapp_campaigns', 'audience_snapshot'));
        self::assertTrue(Schema::hasColumn('whatsapp_campaigns', 'confirmation_token_hash'));
        self::assertTrue(Schema::hasColumn('whatsapp_campaign_recipients', 'channel'));
    }

    public function test_existing_admin_can_use_an_owner_admin_role(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'admin_role' => 'owner']);

        self::assertTrue($user->isAdmin());
        self::assertSame('owner', $user->adminRole());
        self::assertTrue($user->hasAdminAbility('users.manage'));
    }
}

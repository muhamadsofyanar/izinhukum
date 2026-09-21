<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'admin_role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('admin_role', 32)->nullable()->after('role')->index();
            });
        }
        DB::table('users')->where('role', 'admin')->whereNull('admin_role')->update(['admin_role' => 'owner']);

        if (! Schema::hasTable('content_items')) {
            Schema::create('content_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
                $table->string('title', 180);
                $table->string('category', 80)->default('umum')->index();
                $table->longText('body');
                $table->string('media_path', 2048)->nullable();
                $table->string('status', 24)->default('draft')->index();
                $table->timestamp('scheduled_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('crm_contact_imports')) {
            Schema::create('crm_contact_imports', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->string('filename', 255);
                $table->string('default_source', 60)->default('csv');
                $table->string('status', 24)->default('preview')->index();
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('valid_rows')->default(0);
                $table->unsignedInteger('excluded_rows')->default(0);
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
            });

            Schema::create('crm_contact_import_rows', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('import_id')->constrained('crm_contact_imports')->cascadeOnDelete();
                $table->unsignedInteger('row_number');
                $table->string('normalized_phone', 32)->nullable()->index();
                $table->json('payload');
                $table->string('status', 24)->default('valid')->index();
                $table->json('errors')->nullable();
                $table->timestamps();
                $table->unique(['import_id', 'row_number']);
            });
        }

        if (! Schema::hasTable('whatsapp_recipient_presets')) {
            Schema::create('whatsapp_recipient_presets', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name', 160);
                $table->json('definition');
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        $campaignColumns = [
            'body' => fn (Blueprint $table) => $table->longText('body')->nullable(),
            'message_type' => fn (Blueprint $table) => $table->string('message_type', 24)->default('text'),
            'media_path' => fn (Blueprint $table) => $table->string('media_path', 2048)->nullable(),
            'audience_snapshot' => fn (Blueprint $table) => $table->json('audience_snapshot')->nullable(),
            'confirmation_token_hash' => fn (Blueprint $table) => $table->string('confirmation_token_hash', 64)->nullable()->unique(),
        ];
        foreach ($campaignColumns as $column => $definition) {
            if (! Schema::hasColumn('whatsapp_campaigns', $column)) {
                Schema::table('whatsapp_campaigns', $definition);
            }
        }

        $recipientColumns = [
            'channel' => fn (Blueprint $table) => $table->string('channel', 16)->default('personal')->index(),
            'contact_id' => fn (Blueprint $table) => $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete(),
            'group_id' => fn (Blueprint $table) => $table->foreignId('group_id')->nullable()->constrained('whatsapp_groups')->nullOnDelete(),
            'exclusion_reason' => fn (Blueprint $table) => $table->string('exclusion_reason', 80)->nullable()->index(),
            'retry_generation' => fn (Blueprint $table) => $table->unsignedInteger('retry_generation')->default(0),
        ];
        foreach ($recipientColumns as $column => $definition) {
            if (! Schema::hasColumn('whatsapp_campaign_recipients', $column)) {
                Schema::table('whatsapp_campaign_recipients', $definition);
            }
        }

        if (Schema::hasTable('system_settings')) {
            foreach (['whatsapp', 'whatsapp_transactional', 'whatsapp_crm', 'whatsapp_campaigns', 'crm_contacts'] as $feature) {
                DB::table('system_settings')->updateOrInsert(
                    ['key' => 'feature_'.$feature],
                    ['value' => '1', 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_recipient_presets');
        Schema::dropIfExists('crm_contact_import_rows');
        Schema::dropIfExists('crm_contact_imports');
        Schema::dropIfExists('content_items');

        if (Schema::hasTable('whatsapp_campaign_recipients')) {
            foreach (['contact_id', 'group_id'] as $column) {
                if (Schema::hasColumn('whatsapp_campaign_recipients', $column)) {
                    Schema::table('whatsapp_campaign_recipients', fn (Blueprint $table) => $table->dropConstrainedForeignId($column));
                }
            }
            foreach (['channel', 'exclusion_reason', 'retry_generation'] as $column) {
                if (Schema::hasColumn('whatsapp_campaign_recipients', $column)) {
                    Schema::table('whatsapp_campaign_recipients', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
        if (Schema::hasTable('whatsapp_campaigns')) {
            foreach (['body', 'message_type', 'media_path', 'audience_snapshot', 'confirmation_token_hash'] as $column) {
                if (Schema::hasColumn('whatsapp_campaigns', $column)) {
                    Schema::table('whatsapp_campaigns', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
        if (Schema::hasColumn('users', 'admin_role')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('admin_role'));
        }
    }
};

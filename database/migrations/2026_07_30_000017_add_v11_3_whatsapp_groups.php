<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_groups')) {
            Schema::create('whatsapp_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('device_alias', 32)->default('support')->index();
                $table->string('group_jid', 160);
                $table->string('name', 190)->nullable()->index();
                $table->unsignedInteger('participant_count')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('last_synced_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['device_alias', 'group_jid'], 'wa_groups_device_jid_unique');
            });
        }

        if (! Schema::hasTable('whatsapp_conversations')) {
            return;
        }

        $addChannel = ! Schema::hasColumn('whatsapp_conversations', 'channel');
        $addDeviceAlias = ! Schema::hasColumn('whatsapp_conversations', 'device_alias');
        $addMetadata = ! Schema::hasColumn('whatsapp_conversations', 'metadata');

        if ($addChannel || $addDeviceAlias || $addMetadata) {
            Schema::table('whatsapp_conversations', function (Blueprint $table) use ($addChannel, $addDeviceAlias, $addMetadata): void {
                if ($addChannel) {
                    $table->string('channel', 16)->default('personal')->index();
                }
                if ($addDeviceAlias) {
                    $table->string('device_alias', 32)->default('support');
                }
                if ($addMetadata) {
                    $table->json('metadata')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('whatsapp_conversations')) {
            foreach (['metadata', 'device_alias', 'channel'] as $column) {
                if (Schema::hasColumn('whatsapp_conversations', $column)) {
                    Schema::table('whatsapp_conversations', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }

        Schema::dropIfExists('whatsapp_groups');
    }
};

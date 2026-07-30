<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_group_presets')) {
            Schema::create('whatsapp_group_presets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('device_alias', 32)->default('support');
                $table->string('name', 100);
                $table->json('group_ids');
                $table->timestamps();
                $table->unique(
                    ['user_id', 'device_alias', 'name'],
                    'wa_group_presets_user_device_name_unique'
                );
                $table->index(['user_id', 'device_alias'], 'wa_group_presets_user_device_index');
            });
        }

        if (! Schema::hasTable('whatsapp_group_selections')) {
            return;
        }

        $now = now();
        DB::table('whatsapp_group_selections')
            ->orderBy('id')
            ->get()
            ->each(function (object $selection) use ($now): void {
                $rawGroupIds = $selection->group_ids ?? '[]';
                $groupIds = is_string($rawGroupIds)
                    ? json_decode($rawGroupIds, true)
                    : $rawGroupIds;

                if (! is_array($groupIds) || $groupIds === []) {
                    return;
                }

                $exists = DB::table('whatsapp_group_presets')
                    ->where('user_id', $selection->user_id)
                    ->where('device_alias', $selection->device_alias)
                    ->where('name', 'Pilihan sebelumnya')
                    ->exists();

                if ($exists) {
                    return;
                }

                DB::table('whatsapp_group_presets')->insert([
                    'user_id' => $selection->user_id,
                    'device_alias' => $selection->device_alias,
                    'name' => 'Pilihan sebelumnya',
                    'group_ids' => json_encode(array_values(array_map('intval', $groupIds))),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_group_presets');
    }
};

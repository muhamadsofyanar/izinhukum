<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_group_selections')) {
            Schema::create('whatsapp_group_selections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('device_alias', 32)->default('support');
                $table->json('group_ids');
                $table->timestamps();
                $table->unique(['user_id', 'device_alias'], 'wa_group_selections_user_device_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_group_selections');
    }
};

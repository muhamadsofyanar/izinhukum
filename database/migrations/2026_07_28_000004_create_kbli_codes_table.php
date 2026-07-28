<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kbli_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('risk_level')->nullable();
            $table->string('licensing')->nullable();
            $table->boolean('is_sample')->default(true);
            $table->timestamps();
            $table->index('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kbli_codes');
    }
};

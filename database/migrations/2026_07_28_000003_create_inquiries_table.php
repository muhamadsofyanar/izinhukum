<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('service_package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone', 32);
            $table->string('email')->nullable();
            $table->string('company_name')->nullable();
            $table->string('city')->nullable();
            $table->text('message')->nullable();
            $table->string('source')->default('website');
            $table->string('status')->default('baru');
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};

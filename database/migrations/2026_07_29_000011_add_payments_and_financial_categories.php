<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 16)->index();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['type', 'slug']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('financial_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_number', 64)->unique();
            $table->string('public_token', 64)->unique();
            $table->date('payment_date')->index();
            $table->unsignedBigInteger('amount');
            $table->string('payer_name', 160)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('payment_method', 32);
            $table->string('reference_number', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('financial_categories');
    }
};

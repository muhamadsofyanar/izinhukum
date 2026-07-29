<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('financial_category_id')->nullable()->constrained()->nullOnDelete();
            $table->date('transaction_date')->index();
            $table->string('description', 255);
            $table->string('payee', 160)->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('payment_method', 32);
            $table->string('reference_number', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['financial_category_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};

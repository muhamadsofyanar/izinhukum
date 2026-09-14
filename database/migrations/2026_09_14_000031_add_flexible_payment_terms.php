<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('payment_scheme', 24)->default('full')->after('due_date');
            $table->string('payment_recipient', 24)->default('izinhukum')->after('payment_scheme');
            $table->text('payment_terms')->nullable()->after('payment_recipient');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('received_by', 24)->default('izinhukum')->after('payment_method')->index();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['received_by']);
            $table->dropColumn('received_by');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['payment_scheme', 'payment_recipient', 'payment_terms']);
        });
    }
};

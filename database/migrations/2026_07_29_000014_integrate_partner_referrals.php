<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained('users')->cascadeOnDelete();
            $table->string('visitor_token', 64);
            $table->string('referral_code', 32);
            $table->string('first_landing_path', 2048)->nullable();
            $table->string('last_landing_path', 2048)->nullable();
            $table->unsignedInteger('click_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['partner_id', 'visitor_token']);
            $table->index(['partner_id', 'last_seen_at']);
        });

        Schema::table('partner_applications', function (Blueprint $table): void {
            $table->string('desired_partner_level', 32)
                ->default('starter')
                ->after('reference')
                ->index();
        });

        Schema::table('inquiries', function (Blueprint $table): void {
            $table->foreignId('referred_by_partner_id')
                ->nullable()
                ->after('service_package_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('partner_referral_id')
                ->nullable()
                ->after('referred_by_partner_id')
                ->constrained('partner_referrals')
                ->nullOnDelete();
            $table->string('referral_code', 32)->nullable()->after('source')->index();
            $table->timestamp('referred_at')->nullable()->after('referral_code');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('inquiry_id')
                ->nullable()
                ->after('created_by')
                ->constrained('inquiries')
                ->nullOnDelete();
            $table->foreignId('referred_by_partner_id')
                ->nullable()
                ->after('partner_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('referral_code', 32)->nullable()->after('referred_by_partner_id')->index();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('source', 48)->default('manual')->after('status')->index();
            $table->string('source_key', 160)->nullable()->after('source')->unique();
        });

        Schema::table('commissions', function (Blueprint $table): void {
            $table->foreignId('payment_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('payments')
                ->nullOnDelete()
                ->unique();
            $table->unsignedInteger('rate_bps')->default(0)->after('amount');
            $table->string('source', 48)->default('manual')->after('rate_bps')->index();
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_id');
            $table->dropColumn(['rate_bps', 'source']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['source_key']);
            $table->dropColumn(['source', 'source_key']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inquiry_id');
            $table->dropConstrainedForeignId('referred_by_partner_id');
            $table->dropColumn('referral_code');
        });

        Schema::table('inquiries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('partner_referral_id');
            $table->dropConstrainedForeignId('referred_by_partner_id');
            $table->dropColumn(['referral_code', 'referred_at']);
        });

        Schema::table('partner_applications', function (Blueprint $table): void {
            $table->dropColumn('desired_partner_level');
        });

        Schema::dropIfExists('partner_referrals');
    }
};


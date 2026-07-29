<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number', 40)->unique();
            $table->string('public_token', 64)->unique();
            $table->foreignId('inquiry_id')->nullable()->unique()->constrained('inquiries')->nullOnDelete();
            $table->foreignId('service_package_id')->nullable()->constrained('service_packages')->nullOnDelete();
            $table->foreignId('referred_by_partner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('partner_referral_id')->nullable()->constrained('partner_referrals')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('referral_code', 32)->nullable()->index();
            $table->string('title', 180);
            $table->string('customer_name', 160);
            $table->string('customer_email', 160)->nullable()->index();
            $table->string('customer_phone', 32)->nullable()->index();
            $table->string('customer_company', 180)->nullable();
            $table->string('customer_city', 120)->nullable();
            $table->text('customer_address')->nullable();
            $table->string('status', 40)->default('lead')->index();
            $table->string('payment_status', 24)->default('unpaid')->index();
            $table->string('priority', 16)->default('normal')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('description')->nullable();
            $table->text('internal_notes')->nullable();
            $table->json('checklist')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
            $table->index(['referred_by_partner_id', 'created_at']);
        });

        Schema::create('service_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 24)->default('system');
            $table->string('event_type', 48)->index();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('service_order_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploaded_by_type', 24)->default('customer');
            $table->string('category', 64)->default('supporting');
            $table->string('name', 180);
            $table->string('original_name', 255);
            $table->string('disk', 32)->default('local');
            $table->string('path', 1024);
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('status', 24)->default('received')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['service_order_id', 'category']);
        });

        Schema::create('referral_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_referral_id')->nullable()->constrained('partner_referrals')->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inquiry_id')->nullable()->constrained('inquiries')->nullOnDelete();
            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('event_type', 40)->index();
            $table->string('source_key', 160)->nullable()->unique();
            $table->unsignedBigInteger('event_value')->default(0);
            $table->string('path', 2048)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['partner_id', 'event_type', 'occurred_at'], 'referral_events_partner_type_date_idx');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('service_order_id')
                ->nullable()
                ->after('inquiry_id')
                ->constrained('service_orders')
                ->nullOnDelete()
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_order_id');
        });

        Schema::dropIfExists('referral_events');
        Schema::dropIfExists('service_order_documents');
        Schema::dropIfExists('service_order_events');
        Schema::dropIfExists('service_orders');
    }
};

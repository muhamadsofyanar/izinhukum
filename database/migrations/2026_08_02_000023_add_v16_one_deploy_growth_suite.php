<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->string('utm_source', 120)->nullable()->after('source')->index();
            $table->string('utm_medium', 120)->nullable()->after('utm_source');
            $table->string('utm_campaign', 160)->nullable()->after('utm_medium')->index();
            $table->string('utm_term', 160)->nullable()->after('utm_campaign');
            $table->string('utm_content', 160)->nullable()->after('utm_term');
            $table->string('landing_path', 1024)->nullable()->after('utm_content');
        });

        Schema::create('sales_quotes', function (Blueprint $table): void {
            $table->id();
            $table->string('quote_number', 64)->unique();
            $table->string('public_token', 64)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('inquiry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('crm_lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->foreignId('service_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('referred_by_partner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('referral_code', 32)->nullable()->index();
            $table->string('coupon_code', 32)->nullable()->index();
            $table->string('recipient_name', 160);
            $table->string('recipient_company', 180)->nullable();
            $table->string('recipient_email', 160)->nullable();
            $table->string('recipient_phone', 32)->nullable();
            $table->text('recipient_address')->nullable();
            $table->date('issue_date');
            $table->date('valid_until');
            $table->unsignedSmallInteger('invoice_due_days')->default(7);
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->text('scope')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'valid_until']);
            $table->index(['inquiry_id', 'created_at']);
        });

        Schema::create('sales_quote_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('line_total')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->string('payer_name', 160);
            $table->date('transfer_date');
            $table->unsignedBigInteger('claimed_amount');
            $table->string('bank_reference', 160)->nullable();
            $table->text('notes')->nullable();
            $table->string('disk', 32)->default('local');
            $table->string('path', 1024);
            $table->string('original_name', 255);
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum', 64)->nullable()->index();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'status']);
        });

        foreach ([
            'campaign_tracking',
            'sales_pipeline',
            'digital_quotes',
            'payment_proof_upload',
            'growth_analytics',
        ] as $feature) {
            $this->setting('feature_'.$feature, '1');
        }

        $this->backfillSalesPipeline();
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proofs');
        Schema::dropIfExists('sales_quote_items');
        Schema::dropIfExists('sales_quotes');

        Schema::table('inquiries', function (Blueprint $table): void {
            $table->dropColumn([
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_term',
                'utm_content',
                'landing_path',
            ]);
        });

        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->whereIn('key', [
                'feature_campaign_tracking',
                'feature_sales_pipeline',
                'feature_digital_quotes',
                'feature_payment_proof_upload',
                'feature_growth_analytics',
            ])->delete();
        }
    }

    private function backfillSalesPipeline(): void
    {
        if (! Schema::hasTable('crm_contacts') || ! Schema::hasTable('crm_leads')) {
            return;
        }

        DB::table('inquiries')->orderBy('id')->chunkById(100, function ($inquiries): void {
            foreach ($inquiries as $inquiry) {
                if (DB::table('crm_leads')->where('inquiry_id', $inquiry->id)->exists()) {
                    continue;
                }

                $phone = preg_replace('/\D/', '', (string) $inquiry->phone) ?: '';
                if (str_starts_with($phone, '0')) {
                    $phone = '62'.substr($phone, 1);
                }
                if ($phone === '') {
                    continue;
                }

                $contactId = DB::table('crm_contacts')->where('phone', $phone)->value('id');
                if (! $contactId) {
                    $contactId = DB::table('crm_contacts')->insertGetId([
                        'phone' => $phone,
                        'name' => $inquiry->name,
                        'email' => $inquiry->email,
                        'company' => $inquiry->company_name,
                        'source' => $inquiry->referred_by_partner_id ? 'referral' : 'website',
                        'status' => 'active',
                        'lifecycle_stage' => 'lead',
                        'last_contact_at' => null,
                        'is_opted_out' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $orderId = Schema::hasTable('service_orders')
                    ? DB::table('service_orders')->where('inquiry_id', $inquiry->id)->value('id')
                    : null;
                $packageName = $inquiry->service_package_id
                    ? DB::table('service_packages')->where('id', $inquiry->service_package_id)->value('name')
                    : null;

                DB::table('crm_leads')->insert([
                    'contact_id' => $contactId,
                    'inquiry_id' => $inquiry->id,
                    'service_order_id' => $orderId,
                    'title' => Str::limit(($packageName ?: 'Konsultasi legalitas').' · '.$inquiry->name, 190, ''),
                    'source' => $inquiry->source ?: 'website',
                    'stage' => $this->stageForInquiry((string) $inquiry->status),
                    'service_interest' => $packageName,
                    'estimated_value' => 0,
                    'probability' => $inquiry->status === 'selesai' ? 100 : 10,
                    'closed_at' => in_array($inquiry->status, ['selesai', 'batal'], true) ? now() : null,
                    'metadata' => json_encode(['backfilled_from' => 'inquiry'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $inquiry->created_at ?: now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    private function stageForInquiry(string $status): string
    {
        return match ($status) {
            'dihubungi' => 'qualified',
            'proses' => 'processing',
            'selesai' => 'completed',
            'batal' => 'lost',
            default => 'new',
        };
    }

    private function setting(string $key, string $value): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        DB::table('system_settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => $value,
                'is_encrypted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
};

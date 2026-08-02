<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_quote_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('scope')->nullable();
            $table->longText('terms')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('validity_days')->default(14);
            $table->unsignedSmallInteger('invoice_due_days')->default(7);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('use_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('sales_quotes', function (Blueprint $table): void {
            $table->foreignId('sales_quote_template_id')->nullable()->after('crm_lead_id')->constrained()->nullOnDelete();
        });

        DB::table('sales_quote_templates')->insert([
            'name' => 'Penawaran layanan legalitas umum',
            'scope' => "Konsultasi awal dan pemetaan kebutuhan.\nPemeriksaan kelengkapan dokumen.\nPersiapan serta pengajuan layanan sesuai ruang lingkup.\nPembaruan progres sampai hasil akhir diterima.",
            'terms' => "Pekerjaan dimulai setelah pembayaran dan dokumen persyaratan dinyatakan lengkap.\nEstimasi mengikuti respons instansi dan kelengkapan klien.\nBiaya tambahan di luar ruang lingkup dikonfirmasi dan disetujui terlebih dahulu.",
            'validity_days' => 14,
            'invoice_due_days' => 7,
            'is_active' => true,
            'use_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->setting('feature_quote_templates', '1');
        $this->setting('feature_lead_recovery', '1');
    }

    public function down(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_quote_template_id');
        });
        Schema::dropIfExists('sales_quote_templates');
        DB::table('system_settings')->whereIn('key', ['feature_quote_templates', 'feature_lead_recovery'])->delete();
    }

    private function setting(string $key, string $value): void
    {
        DB::table('system_settings')->updateOrInsert(['key' => $key], [
            'value' => $value, 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};

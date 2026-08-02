<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_message_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('purpose', 40)->default('follow_up')->index();
            $table->string('stage', 40)->nullable()->index();
            $table->longText('body');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        foreach ($this->defaults() as $index => $template) {
            DB::table('sales_message_templates')->insert([
                ...$template,
                'sort_order' => ($index + 1) * 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->setting('feature_manual_sales_playbooks', '1');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_message_templates');
        DB::table('system_settings')->where('key', 'feature_manual_sales_playbooks')->delete();
    }

    private function defaults(): array
    {
        return [
            ['name' => 'Respons awal lead baru', 'purpose' => 'first_response', 'stage' => 'new', 'body' => "Halo {{name}}, saya dari IzinHukum. Kami sudah menerima kebutuhan {{service}} dengan referensi {{reference}}. Agar saya dapat membantu dengan tepat, apakah ada target waktu penyelesaiannya?"],
            ['name' => 'Follow-up konsultasi', 'purpose' => 'follow_up', 'stage' => 'qualified', 'body' => "Halo {{name}}, saya menindaklanjuti kebutuhan {{service}}. Apakah ada bagian yang masih ingin ditanyakan sebelum kami siapkan penawaran resminya?"],
            ['name' => 'Kirim tautan penawaran', 'purpose' => 'quote', 'stage' => 'proposal', 'body' => "Halo {{name}}, penawaran resmi untuk {{service}} sudah tersedia di {{quote_url}}. Silakan diperiksa; jika sesuai dapat langsung disetujui melalui tautan tersebut."],
            ['name' => 'Pengingat penawaran', 'purpose' => 'quote_reminder', 'stage' => 'proposal', 'body' => "Halo {{name}}, izin mengingatkan penawaran {{quote_number}} untuk {{service}}. Apakah ada ruang lingkup atau ketentuan yang perlu kami jelaskan? {{quote_url}}"],
            ['name' => 'Pengingat pembayaran', 'purpose' => 'payment', 'stage' => 'deal', 'body' => "Halo {{name}}, invoice untuk {{service}} dapat dibuka di {{invoice_url}}. Setelah transfer, bukti pembayaran dapat diunggah langsung pada halaman tersebut."],
            ['name' => 'Aktivasi ulang lead', 'purpose' => 'reactivation', 'stage' => 'lost', 'body' => "Halo {{name}}, kami ingin menanyakan kembali rencana {{service}}. Jika kebutuhannya masih ada, kami siap membantu melanjutkan dari pembahasan sebelumnya tanpa mengulang dari awal."],
        ];
    }

    private function setting(string $key, string $value): void
    {
        DB::table('system_settings')->updateOrInsert(['key' => $key], [
            'value' => $value, 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        $this->createIfMissing('whatsapp_devices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('provider_id')->nullable()->unique();
            $table->string('name', 120);
            $table->string('phone', 32)->nullable()->index();
            $table->string('role', 32)->default('transaction')->index();
            $table->string('status', 32)->default('unknown')->index();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->unsignedInteger('daily_limit')->default(50);
            $table->timestamp('last_checked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('whatsapp_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 160);
            $table->string('category', 40)->default('transaction')->index();
            $table->text('description')->nullable();
            $table->longText('body');
            $table->string('message_type', 16)->default('text');
            $table->string('media_url', 2048)->nullable();
            $table->json('variables')->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('is_marketing')->default(false)->index();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->createIfMissing('whatsapp_conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 32)->unique();
            $table->string('display_name', 160)->nullable();
            $table->string('contact_type', 24)->default('unknown')->index();
            $table->foreignId('partner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inquiry_id')->nullable()->constrained('inquiries')->nullOnDelete();
            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('open')->index();
            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('is_ai_blocked')->default(false)->index();
            $table->json('labels')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('whatsapp_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained('whatsapp_conversations')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->foreignId('inquiry_id')->nullable()->constrained('inquiries')->nullOnDelete();
            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 12)->default('outbound')->index();
            $table->string('channel', 16)->default('personal')->index();
            $table->string('phone', 160)->index();
            $table->string('recipient_name', 160)->nullable();
            $table->string('message_type', 16)->default('text');
            $table->longText('body')->nullable();
            $table->string('media_url', 2048)->nullable();
            $table->string('device_alias', 32)->default('transaction');
            $table->string('status', 24)->default('queued')->index();
            $table->string('provider_message_id', 120)->nullable()->index();
            $table->unsignedBigInteger('provider_device_id')->nullable()->index();
            $table->string('idempotency_key', 190)->nullable()->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('provider_response')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'scheduled_at'], 'wa_messages_status_schedule_idx');
            $table->index(['direction', 'created_at'], 'wa_messages_direction_date_idx');
        });

        $this->createIfMissing('whatsapp_message_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('whatsapp_message_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('success')->default(false)->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('attempted_at')->index();
            $table->timestamps();
            $table->unique(['whatsapp_message_id', 'attempt_number'], 'wa_message_attempt_unique');
        });

        $this->createIfMissing('whatsapp_automations', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 160);
            $table->string('trigger', 100)->index();
            $table->foreignId('template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->string('recipient_type', 24)->default('customer');
            $table->boolean('is_enabled')->default(false)->index();
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->json('conditions')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('whatsapp_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->foreignId('template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audience_type', 40)->default('manual')->index();
            $table->string('status', 24)->default('draft')->index();
            $table->boolean('use_rotator')->default(false);
            $table->string('rotator_mode', 20)->default('round_robin');
            $table->unsignedInteger('delay_seconds')->default(30);
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('queued_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('filters')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('whatsapp_campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('whatsapp_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_message_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->string('phone', 32);
            $table->string('name', 160)->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->text('error')->nullable();
            $table->json('variables')->nullable();
            $table->timestamps();
            $table->unique(['whatsapp_campaign_id', 'phone'], 'wa_campaign_recipient_unique');
        });

        $this->createIfMissing('whatsapp_consents', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 32)->unique();
            $table->boolean('allow_transactional')->default(true)->index();
            $table->boolean('allow_marketing')->default(false)->index();
            $table->string('source', 80)->default('admin_record');
            $table->text('evidence')->nullable();
            $table->timestamp('consented_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->createIfMissing('whatsapp_opt_outs', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 32)->unique();
            $table->boolean('block_marketing')->default(true)->index();
            $table->boolean('block_ai')->default(true)->index();
            $table->boolean('block_all')->default(false)->index();
            $table->string('source', 40)->default('customer');
            $table->text('reason')->nullable();
            $table->timestamp('opted_out_at')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->createIfMissing('whatsapp_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('event_type', 40)->default('message')->index();
            $table->string('provider_message_id', 190)->nullable()->index();
            $table->string('phone', 160)->nullable()->index();
            $table->json('payload');
            $table->boolean('processed')->default(false)->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();
        });

        $templates = [
            ['key' => 'proposal_received', 'name' => 'Konfirmasi proposal masuk', 'category' => 'proposal', 'body' => "Halo {{nama_pelanggan}},\n\nPermintaan proposal {{nomor_proposal}} sudah kami terima. Tim IzinHukum akan meninjau kebutuhan Anda.\n\nStatus dapat diperiksa melalui:\n{{tautan_status}}", 'variables' => ['nama_pelanggan', 'nomor_proposal', 'tautan_status']],
            ['key' => 'order_created', 'name' => 'Order dibuat', 'category' => 'order', 'body' => "Halo {{nama_pelanggan}},\n\nOrder {{nomor_order}} untuk layanan {{nama_layanan}} telah dibuat.\n\nPantau progres melalui:\n{{tautan_portal}}", 'variables' => ['nama_pelanggan', 'nomor_order', 'nama_layanan', 'tautan_portal']],
            ['key' => 'order_status_changed', 'name' => 'Status order berubah', 'category' => 'order', 'body' => "Halo {{nama_pelanggan}},\n\nStatus order {{nomor_order}} berubah menjadi: {{status_order}}.\nProgres: {{progres}}%.\n\nDetail:\n{{tautan_portal}}", 'variables' => ['nama_pelanggan', 'nomor_order', 'status_order', 'progres', 'tautan_portal']],
            ['key' => 'order_completed', 'name' => 'Order selesai', 'category' => 'order', 'body' => "Halo {{nama_pelanggan}},\n\nOrder {{nomor_order}} telah selesai. Dokumen hasil dapat diperiksa melalui portal pelanggan:\n{{tautan_portal}}", 'variables' => ['nama_pelanggan', 'nomor_order', 'tautan_portal']],
            ['key' => 'invoice_sent', 'name' => 'Invoice diterbitkan', 'category' => 'finance', 'body' => "Halo {{nama_pelanggan}},\n\nInvoice {{nomor_invoice}} untuk {{nama_layanan}} telah diterbitkan.\nTotal: {{total_invoice}}\nJatuh tempo: {{jatuh_tempo}}\n\nDetail invoice:\n{{tautan_invoice}}", 'variables' => ['nama_pelanggan', 'nomor_invoice', 'nama_layanan', 'total_invoice', 'jatuh_tempo', 'tautan_invoice']],
            ['key' => 'invoice_due_3_days', 'name' => 'Pengingat invoice H-3', 'category' => 'finance', 'body' => "Halo {{nama_pelanggan}},\n\nInvoice {{nomor_invoice}} senilai {{sisa_tagihan}} akan jatuh tempo pada {{jatuh_tempo}}.\n\nDetail:\n{{tautan_invoice}}", 'variables' => ['nama_pelanggan', 'nomor_invoice', 'sisa_tagihan', 'jatuh_tempo', 'tautan_invoice']],
            ['key' => 'invoice_due_tomorrow', 'name' => 'Pengingat invoice H-1', 'category' => 'finance', 'body' => "Halo {{nama_pelanggan}},\n\nInvoice {{nomor_invoice}} akan jatuh tempo besok. Sisa tagihan: {{sisa_tagihan}}.\n\nDetail:\n{{tautan_invoice}}", 'variables' => ['nama_pelanggan', 'nomor_invoice', 'sisa_tagihan', 'tautan_invoice']],
            ['key' => 'invoice_overdue', 'name' => 'Invoice lewat jatuh tempo', 'category' => 'finance', 'body' => "Halo {{nama_pelanggan}},\n\nInvoice {{nomor_invoice}} telah melewati jatuh tempo. Sisa tagihan: {{sisa_tagihan}}.\nSilakan hubungi tim kami jika memerlukan konfirmasi.\n\nDetail:\n{{tautan_invoice}}", 'variables' => ['nama_pelanggan', 'nomor_invoice', 'sisa_tagihan', 'tautan_invoice']],
            ['key' => 'payment_received', 'name' => 'Pembayaran diterima', 'category' => 'finance', 'body' => "Halo {{nama_pelanggan}},\n\nPembayaran {{jumlah_pembayaran}} untuk invoice {{nomor_invoice}} telah kami terima.\nSisa tagihan: {{sisa_tagihan}}.\n\nKwitansi:\n{{tautan_kwitansi}}", 'variables' => ['nama_pelanggan', 'jumlah_pembayaran', 'nomor_invoice', 'sisa_tagihan', 'tautan_kwitansi']],
            ['key' => 'commission_available', 'name' => 'Komisi mitra tersedia', 'category' => 'partner', 'body' => "Halo {{nama_mitra}},\n\nKomisi sebesar {{nilai_komisi}} dari invoice {{nomor_invoice}} telah tersedia. Silakan periksa dashboard mitra.", 'variables' => ['nama_mitra', 'nilai_komisi', 'nomor_invoice']],
            ['key' => 'commission_paid', 'name' => 'Komisi mitra dibayar', 'category' => 'partner', 'body' => "Halo {{nama_mitra}},\n\nKomisi sebesar {{nilai_komisi}} telah ditandai sebagai dibayar. Silakan periksa riwayat komisi pada dashboard mitra.", 'variables' => ['nama_mitra', 'nilai_komisi']],
            ['key' => 'support_handoff', 'name' => 'Percakapan dialihkan ke admin', 'category' => 'support', 'body' => "Permintaan Anda sudah diteruskan kepada admin. Tim kami akan membalas pada jam operasional.", 'variables' => []],
            ['key' => 'opt_out_confirmed', 'name' => 'Konfirmasi berhenti promosi', 'category' => 'consent', 'body' => "Permintaan berhenti menerima pesan promosi sudah dicatat. Notifikasi transaksi penting tetap dapat dikirim selama diperlukan untuk layanan aktif.", 'variables' => []],
            ['key' => 'keyword_help', 'name' => 'Bantuan kata kunci', 'category' => 'support', 'body' => "Silakan ketik salah satu kata berikut:\nSTATUS untuk status order\nINVOICE untuk informasi tagihan\nADMIN untuk berbicara dengan tim\nSTOP untuk berhenti menerima promosi", 'variables' => []],
            ['key' => 'campaign_generic', 'name' => 'Campaign umum', 'category' => 'marketing', 'body' => "Halo {{nama_pelanggan}},\n\n{{pesan_campaign}}", 'variables' => ['nama_pelanggan', 'pesan_campaign'], 'is_marketing' => true],
        ];

        foreach ($templates as $template) {
            DB::table('whatsapp_templates')->updateOrInsert(
                ['key' => $template['key']],
                [
                    'name' => $template['name'],
                    'category' => $template['category'],
                    'body' => $template['body'],
                    'variables' => json_encode($template['variables'], JSON_UNESCAPED_UNICODE),
                    'is_enabled' => true,
                    'is_marketing' => $template['is_marketing'] ?? false,
                    'version' => 1,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $templateId = DB::table('whatsapp_templates')->where('key', $template['key'])->value('id');

            $automations = [
                'proposal_received' => 'Konfirmasi proposal masuk',
                'order_created' => 'Notifikasi order dibuat',
                'order_status_changed' => 'Notifikasi perubahan status order',
                'order_completed' => 'Notifikasi order selesai',
                'invoice_sent' => 'Notifikasi invoice diterbitkan',
                'invoice_due_3_days' => 'Pengingat invoice H-3',
                'invoice_due_tomorrow' => 'Pengingat invoice H-1',
                'invoice_overdue' => 'Pengingat invoice lewat jatuh tempo',
                'payment_received' => 'Konfirmasi pembayaran',
                'commission_available' => 'Notifikasi komisi tersedia',
                'commission_paid' => 'Notifikasi komisi dibayar',
            ];

            if (isset($automations[$template['key']])) {
                DB::table('whatsapp_automations')->updateOrInsert(
                    ['key' => $template['key']],
                    [
                        'name' => $automations[$template['key']],
                        'trigger' => $template['key'],
                        'template_id' => $templateId,
                        'recipient_type' => str_starts_with($template['key'], 'commission_') ? 'partner' : 'customer',
                        'is_enabled' => false,
                        'delay_minutes' => 0,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
    }

    private function createIfMissing(string $table, \Closure $callback): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $callback);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
        Schema::dropIfExists('whatsapp_opt_outs');
        Schema::dropIfExists('whatsapp_consents');
        Schema::dropIfExists('whatsapp_campaign_recipients');
        Schema::dropIfExists('whatsapp_campaigns');
        Schema::dropIfExists('whatsapp_automations');
        Schema::dropIfExists('whatsapp_message_attempts');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('whatsapp_devices');
    }
};

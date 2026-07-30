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
        $this->createIfMissing('crm_labels', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('category', 40)->default('custom')->index();
            $table->string('color', 20)->default('#0f766e');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->createIfMissing('crm_contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 32)->unique();
            $table->string('name', 160)->nullable()->index();
            $table->string('email', 190)->nullable()->index();
            $table->string('company', 190)->nullable();
            $table->string('source', 60)->default('manual')->index();
            $table->string('status', 40)->default('active')->index();
            $table->string('lifecycle_stage', 40)->default('contact')->index();
            $table->string('service_interest', 160)->nullable()->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_contact_at')->nullable()->index();
            $table->timestamp('next_follow_up_at')->nullable()->index();
            $table->boolean('is_opted_out')->default(false)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('crm_contact_label', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->foreignId('label_id')->constrained('crm_labels')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['contact_id', 'label_id'], 'crm_contact_label_unique');
        });

        $this->createIfMissing('crm_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->foreignId('inquiry_id')->nullable()->constrained('inquiries')->nullOnDelete();
            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->string('title', 190);
            $table->string('source', 60)->default('whatsapp')->index();
            $table->string('stage', 40)->default('new')->index();
            $table->string('service_interest', 160)->nullable()->index();
            $table->decimal('estimated_value', 15, 2)->default(0);
            $table->unsignedTinyInteger('probability')->default(10);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('next_follow_up_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable()->index();
            $table->text('lost_reason')->nullable();
            $table->longText('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('crm_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->foreignId('whatsapp_conversation_id')->nullable()->constrained('whatsapp_conversations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40)->default('note')->index();
            $table->string('title', 190);
            $table->longText('description')->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('crm_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 190);
            $table->text('description')->nullable();
            $table->string('audience_type', 40)->default('contact')->index();
            $table->string('device_alias', 32)->default('support');
            $table->unsignedInteger('group_interval_seconds')->default(10);
            $table->boolean('stop_on_reply')->default(true);
            $table->boolean('stop_on_deal')->default(true);
            $table->boolean('is_active')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->createIfMissing('crm_sequence_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sequence_id')->constrained('crm_sequences')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('name', 190);
            $table->unsignedInteger('delay_value')->default(0);
            $table->string('delay_unit', 16)->default('day');
            $table->time('send_time')->nullable();
            $table->foreignId('template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->unsignedBigInteger('crm_document_id')->nullable()->index();
            $table->string('message_type', 20)->default('text');
            $table->longText('body')->nullable();
            $table->string('media_url', 2048)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['sequence_id', 'position'], 'crm_sequence_step_position_unique');
        });

        $this->createIfMissing('crm_sequence_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sequence_id')->constrained('crm_sequences')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->cascadeOnDelete();
            $table->foreignId('group_preset_id')->nullable()->constrained('whatsapp_group_presets')->cascadeOnDelete();
            $table->string('status', 24)->default('active')->index();
            $table->unsignedInteger('current_step')->default(0);
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('stopped_reason', 190)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_run_at'], 'crm_sequence_due_idx');
        });

        $this->createIfMissing('crm_sequence_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('crm_sequence_enrollments')->cascadeOnDelete();
            $table->foreignId('step_id')->constrained('crm_sequence_steps')->cascadeOnDelete();
            $table->foreignId('whatsapp_message_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('dispatched_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'step_id'], 'crm_sequence_dispatch_unique');
        });

        $this->createIfMissing('crm_requirement_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 190);
            $table->string('service_key', 120)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->createIfMissing('crm_requirement_template_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('crm_requirement_templates')->cascadeOnDelete();
            $table->string('name', 190);
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $this->createIfMissing('crm_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->cascadeOnDelete();
            $table->foreignId('template_item_id')->nullable()->constrained('crm_requirement_template_items')->nullOnDelete();
            $table->string('name', 190);
            $table->string('status', 32)->default('not_requested')->index();
            $table->text('notes')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->createIfMissing('crm_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('whatsapp_conversations')->nullOnDelete();
            $table->foreignId('whatsapp_message_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->foreignId('requirement_id')->nullable()->constrained('crm_requirements')->nullOnDelete();
            $table->string('category', 60)->default('other')->index();
            $table->string('name', 190);
            $table->string('original_name', 255)->nullable();
            $table->string('disk', 40)->default('local');
            $table->string('path', 1024)->nullable();
            $table->string('mime_type', 160)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('checksum', 64)->nullable()->index();
            $table->string('source', 40)->default('admin')->index();
            $table->string('source_url', 2048)->nullable();
            $table->string('archive_status', 32)->default('stored')->index();
            $table->string('verification_status', 32)->default('unverified')->index();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('access_token', 80)->nullable()->unique();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('crm_document_share_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('crm_documents')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('purpose', 40)->default('provider_send')->index();
            $table->timestamp('expires_at')->index();
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('last_access_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->createIfMissing('crm_document_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('crm_documents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40)->index();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        $this->createIfMissing('crm_faq_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 190);
            $table->string('keyword', 190)->index();
            $table->string('match_type', 20)->default('contains');
            $table->longText('answer')->nullable();
            $table->foreignId('template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->unsignedInteger('priority')->default(100)->index();
            $table->boolean('is_active')->default(false)->index();
            $table->boolean('handoff_after_reply')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->addColumnIfMissing('whatsapp_conversations', 'contact_id', function (Blueprint $table): void {
            $table->unsignedBigInteger('contact_id')->nullable()->index();
        });
        $this->addColumnIfMissing('whatsapp_conversations', 'lead_id', function (Blueprint $table): void {
            $table->unsignedBigInteger('lead_id')->nullable()->index();
        });
        $this->addColumnIfMissing('whatsapp_messages', 'contact_id', function (Blueprint $table): void {
            $table->unsignedBigInteger('contact_id')->nullable()->index();
        });
        $this->addColumnIfMissing('whatsapp_messages', 'lead_id', function (Blueprint $table): void {
            $table->unsignedBigInteger('lead_id')->nullable()->index();
        });
        $this->addColumnIfMissing('whatsapp_messages', 'crm_document_id', function (Blueprint $table): void {
            $table->unsignedBigInteger('crm_document_id')->nullable()->index();
        });

        $this->seedDefaults();
    }

    private function seedDefaults(): void
    {
        $labels = [
            ['Lead Baru', 'status', '#2563eb'],
            ['Calon Klien', 'status', '#0f766e'],
            ['Sudah Deal', 'status', '#16a34a'],
            ['Menunggu Persyaratan', 'document', '#d97706'],
            ['Dokumen Kurang', 'document', '#dc2626'],
            ['Pendirian PT', 'service', '#7c3aed'],
            ['Pendirian CV', 'service', '#9333ea'],
            ['Website', 'source', '#0284c7'],
            ['WhatsApp', 'source', '#16a34a'],
            ['Referral', 'source', '#0891b2'],
            ['Prioritas', 'priority', '#be123c'],
        ];

        foreach ($labels as [$name, $category, $color]) {
            DB::table('crm_labels')->updateOrInsert(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'category' => $category,
                    'color' => $color,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $requirements = [
            'pendirian-pt' => [
                'name' => 'Pendirian PT',
                'items' => ['KTP para pendiri', 'NPWP para pendiri', 'Nomor telepon dan email', 'Pilihan nama perusahaan', 'Alamat perusahaan', 'Bidang usaha dan KBLI', 'Modal dasar dan modal disetor', 'Komposisi saham', 'Susunan direksi dan komisaris'],
            ],
            'pendirian-cv' => [
                'name' => 'Pendirian CV',
                'items' => ['KTP para sekutu', 'NPWP para sekutu', 'Nomor telepon dan email', 'Pilihan nama CV', 'Alamat usaha', 'Bidang usaha dan KBLI', 'Susunan sekutu aktif dan pasif'],
            ],
            'nib' => [
                'name' => 'NIB dan Perizinan Berusaha',
                'items' => ['KTP pemilik atau penanggung jawab', 'NPWP', 'Email dan nomor telepon', 'Alamat usaha', 'Bidang usaha dan KBLI', 'Data modal usaha'],
            ],
        ];

        foreach ($requirements as $key => $definition) {
            DB::table('crm_requirement_templates')->updateOrInsert(
                ['service_key' => $key],
                [
                    'name' => $definition['name'],
                    'description' => 'Template checklist persyaratan awal.',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $templateId = DB::table('crm_requirement_templates')->where('service_key', $key)->value('id');
            foreach ($definition['items'] as $index => $item) {
                DB::table('crm_requirement_template_items')->updateOrInsert(
                    ['template_id' => $templateId, 'name' => $item],
                    [
                        'is_required' => true,
                        'sort_order' => $index + 1,
                        'created_at' => now(),
                        'updated_at' => now(),
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

    private function addColumnIfMissing(string $table, string $column, \Closure $callback): void
    {
        if (Schema::hasTable($table) && ! Schema::hasColumn($table, $column)) {
            Schema::table($table, $callback);
        }
    }

    public function down(): void
    {
        foreach ([
            ['whatsapp_messages', ['contact_id', 'lead_id', 'crm_document_id']],
            ['whatsapp_conversations', ['contact_id', 'lead_id']],
        ] as [$table, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
                }
            }
        }

        Schema::dropIfExists('crm_faq_rules');
        Schema::dropIfExists('crm_document_access_logs');
        Schema::dropIfExists('crm_document_share_links');
        Schema::dropIfExists('crm_documents');
        Schema::dropIfExists('crm_requirements');
        Schema::dropIfExists('crm_requirement_template_items');
        Schema::dropIfExists('crm_requirement_templates');
        Schema::dropIfExists('crm_sequence_dispatches');
        Schema::dropIfExists('crm_sequence_enrollments');
        Schema::dropIfExists('crm_sequence_steps');
        Schema::dropIfExists('crm_sequences');
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_contact_label');
        Schema::dropIfExists('crm_contacts');
        Schema::dropIfExists('crm_labels');
    }
};

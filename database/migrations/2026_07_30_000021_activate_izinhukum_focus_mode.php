<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $enabled = [
            'customer_portal',
            'customer_document_upload',
            'public_proposal',
            'partner_registration',
            'referral_tracking',
            'partner_academy',
            'whatsapp',
            'whatsapp_transactional',
        ];
        $disabled = [
            'partner_community',
            'partner_inbox',
            'public_articles',
            'whatsapp_crm',
            'whatsapp_inbox',
            'whatsapp_campaigns',
            'whatsapp_autoreply',
            'whatsapp_ai_assistant',
            'whatsapp_rotator',
            'whatsapp_provider_tools',
            'crm_contacts',
            'crm_leads',
            'crm_sequences',
            'crm_documents',
            'crm_requirements',
            'crm_faq',
            'crm_media_archive',
            'whatsapp_webhook_monitor',
        ];

        $this->setting('app_mode', 'focus');
        foreach ($enabled as $feature) {
            $this->setting('feature_'.$feature, '1');
        }
        foreach ($disabled as $feature) {
            $this->setting('feature_'.$feature, '0');
        }

        // Otomasi lama mengirim pesan ke pelanggan. Mode fokus hanya memakai
        // notifikasi transaksi eksplisit, terutama pemberitahuan pesanan ke admin.
        if (Schema::hasTable('whatsapp_automations')) {
            DB::table('whatsapp_automations')->update([
                'is_enabled' => false,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->where('key', 'app_mode')->delete();
        }
    }

    private function setting(string $key, string $value): void
    {
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

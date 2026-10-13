<?php

namespace Tests\Feature;

use App\Services\FeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class V12CrmWhatsAppOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_v12_tables_are_available_after_migration(): void
    {
        foreach ([
            'crm_labels', 'crm_contacts', 'crm_contact_label', 'crm_leads', 'crm_activities',
            'crm_sequences', 'crm_sequence_steps', 'crm_sequence_enrollments', 'crm_sequence_dispatches',
            'crm_requirement_templates', 'crm_requirement_template_items', 'crm_requirements',
            'crm_documents', 'crm_document_share_links', 'crm_document_access_logs', 'crm_faq_rules',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), $table.' should exist');
        }
    }

    public function test_v12_features_are_disabled_by_default(): void
    {
        $features = app(FeatureFlagService::class);
        foreach ([
            'crm_contacts', 'crm_leads', 'crm_sequences', 'crm_documents',
            'crm_requirements', 'crm_faq', 'crm_media_archive', 'whatsapp_webhook_monitor',
        ] as $feature) {
            self::assertFalse($features->enabled($feature), $feature.' should be disabled by default');
        }
    }

    public function test_v12_admin_routes_are_registered(): void
    {
        foreach ([
            'admin.whatsapp.contacts.index', 'admin.whatsapp.contacts.import', 'admin.whatsapp.contacts.export',
            'admin.whatsapp.leads.index', 'admin.whatsapp.leads.orders.store',
            'admin.whatsapp.sequences.index', 'admin.whatsapp.documents.index',
            'admin.whatsapp.faq.index', 'admin.whatsapp.webhooks.index',
            'crm.documents.provider-download',
        ] as $name) {
            self::assertTrue(Route::has($name), $name.' should exist');
        }
    }
}

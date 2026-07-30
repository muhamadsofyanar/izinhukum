<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmContact;
use App\Models\CrmDocument;
use App\Models\CrmLead;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppDevice;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppOptOut;
use App\Models\WhatsAppWebhookEvent;
use App\Services\FeatureFlagService;
use App\Services\WhatsApp\StarSenderClient;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class WhatsAppDashboardController extends Controller
{
    public function __invoke(FeatureFlagService $features, StarSenderClient $client): View
    {
        $ready = Schema::hasTable('whatsapp_messages');
        $stats = [
            'queued' => $ready ? WhatsAppMessage::query()->whereIn('status', ['queued', 'scheduled', 'retrying', 'processing'])->count() : 0,
            'sent_today' => $ready ? WhatsAppMessage::query()->where('direction', 'outbound')->where('status', 'sent')->whereDate('sent_at', today())->count() : 0,
            'failed_today' => $ready ? WhatsAppMessage::query()->where('status', 'failed')->whereDate('updated_at', today())->count() : 0,
            'unread' => $ready ? WhatsAppConversation::query()->sum('unread_count') : 0,
            'campaigns' => $ready ? WhatsAppCampaign::query()->whereIn('status', ['scheduled', 'running'])->count() : 0,
            'opt_outs' => $ready ? WhatsAppOptOut::query()->count() : 0,
            'contacts' => Schema::hasTable('crm_contacts') ? CrmContact::query()->count() : 0,
            'active_leads' => Schema::hasTable('crm_leads') ? CrmLead::query()->whereNotIn('stage', ['completed', 'lost'])->count() : 0,
            'documents_pending' => Schema::hasTable('crm_documents') ? CrmDocument::query()->where('archive_status', 'pending')->count() : 0,
            'webhook_failed' => Schema::hasTable('whatsapp_webhook_events') ? WhatsAppWebhookEvent::query()->whereNotNull('processing_error')->count() : 0,
        ];

        return view('admin.whatsapp.dashboard', [
            'ready' => $ready,
            'stats' => $stats,
            'features' => collect($features->all())->whereIn('key', [
                'whatsapp', 'whatsapp_transactional', 'whatsapp_inbox', 'whatsapp_campaigns',
                'whatsapp_autoreply', 'whatsapp_ai_assistant', 'whatsapp_rotator', 'whatsapp_provider_tools',
                'crm_contacts', 'crm_leads', 'crm_sequences', 'crm_documents', 'crm_requirements',
                'crm_faq', 'crm_media_archive', 'whatsapp_webhook_monitor',
            ])->values(),
            'integration' => [
                'environment_enabled' => (bool) config('starsender.enabled'),
                'account_key' => $client->hasAccountKey(),
                'transaction_key' => $client->hasDeviceKey('transaction'),
                'support_key' => $client->hasDeviceKey('support'),
                'campaign_key' => $client->hasDeviceKey('campaign'),
                'webhook_secret' => trim((string) config('starsender.webhook_secret')) !== '',
                'queue' => config('queue.default'),
            ],
            'webhookUrl' => trim((string) config('starsender.webhook_secret')) !== ''
                ? route('webhooks.starsender', config('starsender.webhook_secret'))
                : null,
            'devices' => $ready ? WhatsAppDevice::query()->orderByDesc('is_default')->orderBy('name')->get() : collect(),
            'automations' => $ready ? WhatsAppAutomation::query()->with('template')->orderBy('name')->get() : collect(),
            'latestMessages' => $ready ? WhatsAppMessage::query()->latest()->limit(10)->get() : collect(),
        ]);
    }
}

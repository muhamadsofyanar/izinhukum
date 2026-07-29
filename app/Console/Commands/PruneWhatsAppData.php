<?php

namespace App\Console\Commands;

use App\Models\WhatsAppMessage;
use App\Models\WhatsAppMessageAttempt;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PruneWhatsAppData extends Command
{
    protected $signature = 'whatsapp:prune';

    protected $description = 'Menghapus payload webhook lama dan mereduksi payload teknis pesan sesuai retensi.';

    public function handle(): int
    {
        $webhookDays = max(7, (int) config('starsender.webhook_retention_days', 90));
        $technicalDays = max(30, (int) config('starsender.technical_log_retention_days', 180));

        $webhooks = 0;
        if (Schema::hasTable('whatsapp_webhook_events')) {
            $webhooks = WhatsAppWebhookEvent::query()
                ->where('processed', true)
                ->where('created_at', '<', now()->subDays($webhookDays))
                ->delete();
        }

        $attempts = 0;
        if (Schema::hasTable('whatsapp_message_attempts')) {
            $attempts = WhatsAppMessageAttempt::query()
                ->where('attempted_at', '<', now()->subDays($technicalDays))
                ->where(fn ($query) => $query->whereNotNull('request_payload')->orWhereNotNull('response_payload'))
                ->update([
                    'request_payload' => null,
                    'response_payload' => null,
                    'updated_at' => now(),
                ]);
        }

        $responses = 0;
        if (Schema::hasTable('whatsapp_messages')) {
            $responses = WhatsAppMessage::query()
                ->where('created_at', '<', now()->subDays($technicalDays))
                ->whereNotNull('provider_response')
                ->update([
                    'provider_response' => null,
                    'updated_at' => now(),
                ]);
        }

        $this->info("Webhook dihapus: {$webhooks}; payload percobaan direduksi: {$attempts}; respons provider direduksi: {$responses}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\FeatureFlagService;
use App\Services\WhatsApp\StarSenderClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class WhatsAppDiagnostics extends Command
{
    protected $signature = 'whatsapp:diagnose';

    protected $description = 'Memeriksa konfigurasi lokal WhatsApp tanpa mengirim pesan.';

    public function handle(FeatureFlagService $features, StarSenderClient $client): int
    {
        $rows = [
            ['STARSENDER_ENABLED', config('starsender.enabled') ? 'aktif' : 'nonaktif'],
            ['Feature whatsapp', $features->enabled('whatsapp') ? 'aktif' : 'nonaktif'],
            ['Account API Key', $client->hasAccountKey() ? 'tersedia' : 'belum diisi'],
            ['Device key transaksi', $client->hasDeviceKey('transaction') ? 'tersedia' : 'belum diisi'],
            ['Webhook secret', trim((string) config('starsender.webhook_secret')) !== '' ? 'tersedia' : 'belum diisi'],
            ['Feature Inbox', $features->enabled('whatsapp_inbox') ? 'aktif' : 'nonaktif'],
            ['Webhook grup', config('starsender.group_webhook_enabled') ? 'aktif' : 'nonaktif'],
            ['Tabel pesan', Schema::hasTable('whatsapp_messages') ? 'tersedia' : 'belum migrasi'],
            ['Tabel grup', Schema::hasTable('whatsapp_groups') ? 'tersedia' : 'belum migrasi'],
            ['Tabel jobs', Schema::hasTable('jobs') ? 'tersedia' : 'belum tersedia'],
        ];
        $this->table(['Pemeriksaan', 'Status'], $rows);

        return self::SUCCESS;
    }
}

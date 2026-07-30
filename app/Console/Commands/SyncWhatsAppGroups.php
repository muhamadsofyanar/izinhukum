<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WhatsAppGroupSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncWhatsAppGroups extends Command
{
    protected $signature = 'whatsapp:sync-groups {alias=support : Alias device: support, transaction, campaign, partner, atau default}';

    protected $description = 'Mengambil daftar grup WhatsApp dari device StarSender dan menyimpannya secara lokal.';

    public function handle(WhatsAppGroupSyncService $sync): int
    {
        $alias = (string) $this->argument('alias');
        if (! in_array($alias, ['default', 'transaction', 'support', 'partner', 'campaign'], true)) {
            $this->error('Alias device tidak valid.');
            return self::INVALID;
        }

        try {
            $result = $sync->sync($alias);
            $this->info($result['count'].' grup berhasil disinkronkan untuk device '.$alias.'.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}

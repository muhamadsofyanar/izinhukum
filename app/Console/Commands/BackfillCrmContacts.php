<?php

namespace App\Console\Commands;

use App\Models\WhatsAppConversation;
use App\Services\Crm\CrmContactService;
use Illuminate\Console\Command;

class BackfillCrmContacts extends Command
{
    protected $signature = 'crm:backfill-contacts {--limit=10000}';

    protected $description = 'Membentuk daftar kontak CRM dari percakapan WhatsApp personal yang sudah ada.';

    public function handle(CrmContactService $contacts): int
    {
        $count = 0;
        WhatsAppConversation::query()
            ->where(fn ($query) => $query->where('channel', 'personal')->orWhereNull('channel'))
            ->where('contact_type', '!=', 'group')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->each(function (WhatsAppConversation $conversation) use ($contacts, &$count): void {
                $contact = $contacts->upsertFromWhatsApp($conversation->phone, $conversation->display_name, 'whatsapp', [
                    'backfilled_from_conversation_id' => $conversation->id,
                ]);
                if ($contact) {
                    $contacts->linkConversation($conversation, $contact);
                    $count++;
                }
            });

        $this->info("{$count} percakapan dihubungkan ke kontak CRM.");
        return self::SUCCESS;
    }
}

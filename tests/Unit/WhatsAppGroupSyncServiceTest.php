<?php

namespace Tests\Unit;

use App\Services\WhatsApp\StarSenderClient;
use App\Services\WhatsApp\WhatsAppGroupSyncService;
use PHPUnit\Framework\TestCase;

class WhatsAppGroupSyncServiceTest extends TestCase
{
    public function test_it_normalizes_list_response(): void
    {
        $service = new WhatsAppGroupSyncService(new StarSenderClient());
        $groups = $service->normalize([
            'success' => true,
            'data' => [
                ['id' => '120363111111111111@g.us', 'name' => 'Tim Legal', 'participants' => [1, 2, 3]],
                ['jid' => '120363222222222222@g.us', 'subject' => 'Tim Operasional', 'participant_count' => 8],
            ],
        ]);

        self::assertCount(2, $groups);
        self::assertSame('120363111111111111@g.us', $groups[0]['group_jid']);
        self::assertSame('Tim Legal', $groups[0]['name']);
        self::assertSame(3, $groups[0]['participant_count']);
        self::assertSame(8, $groups[1]['participant_count']);
    }

    public function test_it_normalizes_map_response(): void
    {
        $service = new WhatsAppGroupSyncService(new StarSenderClient());
        $groups = $service->normalize([
            'data' => [
                '120363333333333333@g.us' => 'Grup Mitra',
            ],
        ]);

        self::assertSame('120363333333333333@g.us', $groups[0]['group_jid']);
        self::assertSame('Grup Mitra', $groups[0]['name']);
    }
}

<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppGroup;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WhatsAppGroupSyncService
{
    public function __construct(private readonly StarSenderClient $client)
    {
    }

    /**
     * @return array{count:int,groups:Collection<int,WhatsAppGroup>,response:array}
     */
    public function sync(string $deviceAlias = 'support'): array
    {
        $response = $this->client->listWhatsAppGroups($deviceAlias);
        $normalized = $this->normalize($response);

        if ($normalized === []) {
            throw new RuntimeException('StarSender tidak mengembalikan daftar grup yang dapat dikenali. Pastikan device terhubung dan Device API Key benar.');
        }

        $syncedIds = [];
        DB::transaction(function () use ($normalized, $deviceAlias, &$syncedIds): void {
            foreach ($normalized as $item) {
                $group = WhatsAppGroup::query()->updateOrCreate(
                    [
                        'device_alias' => $deviceAlias,
                        'group_jid' => $item['group_jid'],
                    ],
                    [
                        'name' => $item['name'],
                        'participant_count' => $item['participant_count'],
                        'is_active' => true,
                        'last_synced_at' => now(),
                        'metadata' => $item['metadata'],
                    ],
                );
                $syncedIds[] = $group->id;
            }

            WhatsAppGroup::query()
                ->where('device_alias', $deviceAlias)
                ->when($syncedIds !== [], fn ($query) => $query->whereNotIn('id', $syncedIds))
                ->update(['is_active' => false, 'updated_at' => now()]);
        });

        return [
            'count' => count($syncedIds),
            'groups' => WhatsAppGroup::query()->whereIn('id', $syncedIds)->orderBy('name')->get(),
            'response' => $response,
        ];
    }

    /**
     * @return array<int,array{group_jid:string,name:?string,participant_count:?int,metadata:array}>
     */
    public function normalize(array $response): array
    {
        $candidates = [
            data_get($response, 'data.groups'),
            data_get($response, 'data.items'),
            data_get($response, 'data'),
            data_get($response, 'groups'),
            data_get($response, 'items'),
        ];

        $source = collect($candidates)->first(fn (mixed $value): bool => is_array($value) && $value !== []);
        if (! is_array($source)) {
            return [];
        }

        $items = $this->flattenGroupPayload($source);

        return collect($items)
            ->map(function (array $item): ?array {
                $jid = $this->firstString($item, [
                    'group_jid', 'jid', 'id', '_id', 'group_id', 'chat_id', 'wid._serialized', '_serialized', 'value',
                ]);
                if ($jid === null) {
                    return null;
                }

                $jid = trim($jid);
                if ($jid === '') {
                    return null;
                }

                $name = $this->firstString($item, ['name', 'subject', 'group_name', 'title', 'label']);
                $count = $this->firstInt($item, ['participant_count', 'participants_count', 'member_count', 'members_count', 'count', 'size']);
                if ($count === null && is_array(data_get($item, 'participants'))) {
                    $count = count((array) data_get($item, 'participants'));
                }

                return [
                    'group_jid' => $jid,
                    'name' => $name,
                    'participant_count' => $count,
                    'metadata' => Arr::except($item, ['api_key', 'device_api_key', 'token', 'authorization']),
                ];
            })
            ->filter()
            ->unique('group_jid')
            ->values()
            ->all();
    }

    /** @return array<int,array> */
    private function flattenGroupPayload(array $source): array
    {
        if (array_is_list($source)) {
            return collect($source)
                ->filter(fn (mixed $item): bool => is_array($item) || is_object($item))
                ->map(fn (mixed $item): array => (array) $item)
                ->values()
                ->all();
        }

        $result = [];
        foreach ($source as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $item = (array) $value;
                if (! isset($item['id']) && ! isset($item['jid']) && is_string($key)) {
                    $item['group_jid'] = $key;
                }
                $result[] = $item;
            } elseif (is_string($key) && is_string($value)) {
                $result[] = ['group_jid' => $key, 'name' => $value];
            }
        }

        return $result;
    }

    private function firstString(array $item, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($item, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function firstInt(array $item, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($item, $path);
            if (is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        return null;
    }
}

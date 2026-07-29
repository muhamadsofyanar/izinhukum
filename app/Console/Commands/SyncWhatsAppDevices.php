<?php

namespace App\Console\Commands;

use App\Models\WhatsAppDevice;
use App\Services\WhatsApp\StarSenderClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Throwable;

class SyncWhatsAppDevices extends Command
{
    protected $signature = 'whatsapp:sync-devices';

    protected $description = 'Menyinkronkan daftar dan status perangkat StarSender.';

    public function handle(StarSenderClient $client): int
    {
        if (! config('starsender.enabled') || ! $client->hasAccountKey()) {
            $this->warn('Integrasi atau Account API Key belum aktif. Sinkronisasi dilewati.');
            return self::SUCCESS;
        }

        try {
            $response = $client->listDevices();
            $devices = data_get($response, 'data.devices')
                ?? data_get($response, 'data')
                ?? data_get($response, 'devices')
                ?? [];
            $devices = is_array($devices) ? $devices : [];

            $count = 0;
            foreach ($devices as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                $providerId = Arr::get($raw, 'id') ?? Arr::get($raw, 'device_id');
                if (! $providerId) {
                    continue;
                }

                WhatsAppDevice::query()->updateOrCreate(
                    ['provider_id' => (int) $providerId],
                    [
                        'name' => (string) (Arr::get($raw, 'name') ?: Arr::get($raw, 'device_name') ?: 'Perangkat '.$providerId),
                        'phone' => Arr::get($raw, 'phone') ?? Arr::get($raw, 'number') ?? Arr::get($raw, 'device'),
                        'status' => strtolower((string) (Arr::get($raw, 'status') ?: Arr::get($raw, 'connection_status') ?: 'unknown')),
                        'last_checked_at' => now(),
                        'metadata' => $raw,
                    ],
                );
                $count++;
            }

            $this->info("{$count} perangkat disinkronkan.");
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}

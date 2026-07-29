<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StarSenderClient
{
    public function isEnabled(): bool
    {
        return (bool) config('starsender.enabled');
    }

    public function hasAccountKey(): bool
    {
        return trim((string) config('starsender.account_api_key')) !== '';
    }

    public function hasDeviceKey(string $alias = 'transaction'): bool
    {
        return trim((string) $this->deviceKey($alias)) !== '';
    }

    public function send(array $payload, string $deviceAlias = 'transaction', bool $group = false): array
    {
        return $this->request(
            'post',
            $group ? '/api/send/grup' : '/api/send',
            $this->deviceKey($deviceAlias),
            $payload,
        );
    }

    public function sendRotator(array $payload): array
    {
        return $this->request('post', '/api/send/rotator', $this->accountKey(), $payload);
    }

    public function checkNumber(string $number, string $deviceAlias = 'transaction'): array
    {
        return $this->request('post', '/api/check-number', $this->deviceKey($deviceAlias), ['number' => $number]);
    }

    public function messageDetail(string|int $id): array
    {
        return $this->request('get', '/api/messages/'.rawurlencode((string) $id), $this->accountKey());
    }

    public function listDevices(): array
    {
        return $this->request('get', '/api/devices', $this->accountKey());
    }

    public function deviceDetail(int $id): array
    {
        return $this->request('get', '/api/devices/'.$id, $this->accountKey());
    }

    public function createDeviceAndScan(string $name): array
    {
        return $this->request('post', '/api/devices/create/scan', $this->accountKey(), ['name' => $name]);
    }

    public function relogDevice(int $id): array
    {
        return $this->request('post', '/api/devices/'.$id.'/relog', $this->accountKey(), []);
    }

    public function deleteDevice(int $id): array
    {
        return $this->request('post', '/api/devices/'.$id.'/delete', $this->accountKey(), []);
    }

    public function createContact(array $payload): array
    {
        return $this->request('post', '/api/contacts', $this->accountKey(), $payload);
    }

    public function listContactGroups(): array
    {
        return $this->request('get', '/api/groups', $this->accountKey());
    }

    public function removeContactFromGroup(string $number, int $groupId): array
    {
        return $this->request('post', '/api/groups/contacts/delete', $this->accountKey(), [
            'number' => $number,
            'group_id' => $groupId,
        ]);
    }

    public function moveContactBetweenGroups(string $number, int $fromGroupId, int $toGroupId): array
    {
        return $this->request('post', '/api/groups/contacts/change', $this->accountKey(), [
            'number' => $number,
            'group_id_from' => $fromGroupId,
            'group_id_to' => $toGroupId,
        ]);
    }

    public function createProviderCampaignFromConfiguredDevice(
        string $name,
        string $syntax,
        string $welcomeMessage,
        string $number,
    ): array {
        return $this->createProviderCampaign([
            'device_api_key' => $this->requiredDeviceKey('campaign'),
            'name' => $name,
            'syntax' => $syntax,
            'welcome_message' => $welcomeMessage,
            'number' => $number,
        ]);
    }

    public function createProviderCampaign(array $payload): array
    {
        return $this->request('post', '/api/campaigns', $this->accountKey(), $payload);
    }

    public function addProviderCampaignMember(array $payload): array
    {
        return $this->request('post', '/api/campaigns/users', $this->accountKey(), $payload);
    }

    public function moveProviderCampaignMember(array $payload): array
    {
        return $this->request('post', '/api/campaigns/users/change', $this->accountKey(), $payload);
    }

    public function listAiBlacklist(string $deviceAlias = 'support', int $page = 1, int $limit = 50): array
    {
        return $this->request('get', '/api/chat-gpt/blacklist', $this->deviceKey($deviceAlias), [], [
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    public function addAiBlacklist(string $number, string $deviceAlias = 'support'): array
    {
        return $this->request('post', '/api/chat-gpt/blacklist', $this->deviceKey($deviceAlias), ['number' => $number]);
    }

    public function removeAiBlacklist(string $number, string $deviceAlias = 'support'): array
    {
        return $this->request('delete', '/api/chat-gpt/blacklist/'.rawurlencode($number), $this->deviceKey($deviceAlias));
    }

    public function checkAiBlacklist(string $number, string $deviceAlias = 'support'): array
    {
        return $this->request('get', '/api/chat-gpt/blacklist/'.rawurlencode($number), $this->deviceKey($deviceAlias));
    }

    public function extractProviderMessageId(array $response): ?string
    {
        $candidates = [
            data_get($response, 'data.message_id'),
            data_get($response, 'data.id'),
            data_get($response, 'message_id'),
            data_get($response, 'id'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return (string) $candidate;
            }
        }

        return null;
    }

    private function request(
        string $method,
        string $path,
        ?string $apiKey,
        array $payload = [],
        array $query = [],
    ): array {
        if (! $this->isEnabled()) {
            throw new RuntimeException('Integrasi StarSender belum diaktifkan pada environment.');
        }

        if (trim((string) $apiKey) === '') {
            throw new RuntimeException('API key StarSender untuk operasi ini belum dikonfigurasi.');
        }

        $url = rtrim((string) config('starsender.base_url'), '/').'/'.ltrim($path, '/');
        $client = Http::acceptJson()
            ->asJson()
            ->withHeaders(['Authorization' => $apiKey])
            ->connectTimeout((int) config('starsender.connect_timeout', 5))
            ->timeout((int) config('starsender.timeout', 20));

        try {
            /** @var Response $response */
            $response = match (strtolower($method)) {
                'get' => $client->get($url, $query),
                'delete' => $client->delete($url, $payload),
                default => $client->{$method}($url, $payload),
            };
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Tidak dapat terhubung ke StarSender: '.$exception->getMessage(), 0, $exception);
        }

        $json = $response->json();
        $data = is_array($json) ? $json : ['raw' => $response->body()];
        $data['_http_status'] = $response->status();

        if (! $response->successful() || data_get($data, 'success') === false) {
            $message = (string) (data_get($data, 'message') ?: 'StarSender mengembalikan respons gagal.');
            throw new RuntimeException($message.' [HTTP '.$response->status().']');
        }

        return $data;
    }

    private function accountKey(): ?string
    {
        return config('starsender.account_api_key');
    }

    private function deviceKey(string $alias): ?string
    {
        return config('starsender.device_keys.'.$alias)
            ?: config('starsender.device_keys.default');
    }

    private function requiredDeviceKey(string $alias): string
    {
        $key = trim((string) $this->deviceKey($alias));
        if ($key === '') {
            throw new RuntimeException("Device API Key {$alias} belum dikonfigurasi.");
        }

        return $key;
    }
}

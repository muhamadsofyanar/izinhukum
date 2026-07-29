<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppDevice;
use App\Services\FeatureFlagService;
use App\Services\WhatsApp\StarSenderClient;
use App\Services\WhatsApp\WhatsAppAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class WhatsAppDeviceController extends Controller
{
    public function index(StarSenderClient $client): View
    {
        return view('admin.whatsapp.devices', [
            'devices' => WhatsAppDevice::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'providerReady' => config('starsender.enabled') && $client->hasAccountKey(),
        ]);
    }

    public function sync(Request $request, WhatsAppAuditService $audit): RedirectResponse
    {
        $exit = Artisan::call('whatsapp:sync-devices');
        $output = trim(Artisan::output());
        $audit->record($request, 'whatsapp.devices_synced', null, ['exit_code' => $exit]);

        return $exit === 0
            ? back()->with('success', $output ?: 'Perangkat disinkronkan.')
            : back()->withErrors(['device' => $output ?: 'Sinkronisasi perangkat gagal.']);
    }

    public function update(Request $request, WhatsAppDevice $device, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'in:default,transaction,support,partner,campaign'],
            'daily_limit' => ['required', 'integer', 'min:1', 'max:500'],
            'is_enabled' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('is_default')) {
            WhatsAppDevice::query()->where('id', '!=', $device->id)->update(['is_default' => false]);
        }
        $device->update([
            'role' => $data['role'],
            'daily_limit' => $data['daily_limit'],
            'is_enabled' => $request->boolean('is_enabled'),
            'is_default' => $request->boolean('is_default'),
        ]);
        $audit->record($request, 'whatsapp.device_updated', $device);

        return back()->with('success', 'Pengaturan perangkat lokal diperbarui. API key tetap dikelola melalui Coolify.');
    }

    public function create(Request $request, StarSenderClient $client, WhatsAppAuditService $audit, FeatureFlagService $features): Response|RedirectResponse
    {
        $this->assertProviderToolsEnabled($features);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        try {
            $providerResponse = $client->createDeviceAndScan($data['name']);
            $qrData = trim((string) data_get($providerResponse, 'data.kode_gambar'));
            $audit->record($request, 'whatsapp.provider_device_created', null, ['name' => $data['name']]);

            if (! str_starts_with($qrData, 'data:image/png;base64,') || strlen($qrData) > 750_000) {
                return back()->withErrors([
                    'device' => 'Perangkat dibuat, tetapi QR provider tidak memiliki format yang aman. Selesaikan scan melalui dashboard StarSender.',
                ]);
            }

            return response()
                ->view('admin.whatsapp.device-scan', [
                    'deviceName' => $data['name'],
                    'qrData' => $qrData,
                    'providerMessage' => (string) (data_get($providerResponse, 'message') ?: 'QR siap dipindai.'),
                ])
                ->header('Cache-Control', 'no-store, private, max-age=0')
                ->header('Pragma', 'no-cache')
                ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        } catch (Throwable $exception) {
            return back()->withErrors(['device' => $exception->getMessage()]);
        }
    }

    public function relog(Request $request, WhatsAppDevice $device, StarSenderClient $client, WhatsAppAuditService $audit, FeatureFlagService $features): RedirectResponse
    {
        $this->assertProviderToolsEnabled($features);
        if (! $device->provider_id) {
            return back()->withErrors(['device' => 'Provider ID perangkat belum tersedia.']);
        }
        try {
            $response = $client->relogDevice((int) $device->provider_id);
            $audit->record($request, 'whatsapp.provider_device_relog', $device);
            return back()->with('success', 'Relog perangkat diminta. '.(string) (data_get($response, 'message') ?: 'Operasi diterima.'));
        } catch (Throwable $exception) {
            return back()->withErrors(['device' => $exception->getMessage()]);
        }
    }

    public function delete(Request $request, WhatsAppDevice $device, StarSenderClient $client, WhatsAppAuditService $audit, FeatureFlagService $features): RedirectResponse
    {
        $this->assertProviderToolsEnabled($features);
        $data = $request->validate(['confirmation' => ['required', 'string', 'max:40']]);
        if (! hash_equals('HAPUS PERANGKAT', trim($data['confirmation']))) {
            throw ValidationException::withMessages([
                'confirmation' => 'Ketik HAPUS PERANGKAT secara tepat untuk melanjutkan.',
            ]);
        }
        if (! $device->provider_id) {
            return back()->withErrors(['device' => 'Provider ID perangkat belum tersedia.']);
        }
        try {
            $client->deleteDevice((int) $device->provider_id);
            $audit->record($request, 'whatsapp.provider_device_deleted', $device, ['provider_id' => $device->provider_id]);
            $device->delete();
            return back()->with('success', 'Perangkat dihapus dari provider dan daftar lokal.');
        } catch (Throwable $exception) {
            return back()->withErrors(['device' => $exception->getMessage()]);
        }
    }


    private function assertProviderToolsEnabled(FeatureFlagService $features): void
    {
        if (! $features->enabled('whatsapp_provider_tools')) {
            throw ValidationException::withMessages([
                'device' => 'Aktifkan feature flag Alat provider StarSender sebelum menjalankan operasi ini.',
            ]);
        }
    }
}

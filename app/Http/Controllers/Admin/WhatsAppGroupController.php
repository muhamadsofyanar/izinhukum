<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppGroupPreset;
use App\Models\WhatsAppMessage;
use App\Rules\SafePublicUrl;
use App\Services\WhatsApp\WhatsAppAuditService;
use App\Services\WhatsApp\WhatsAppGroupSyncService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class WhatsAppGroupController extends Controller
{
    public function index(Request $request): View
    {
        $deviceAlias = $this->normalizeDeviceAlias((string) $request->query('device_alias', 'support'));

        $groups = WhatsAppGroup::query()
            ->where('device_alias', $deviceAlias)
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('group_jid')
            ->get();

        $currentUserId = $request->attributes->get('currentUser')?->id;
        $presets = collect();
        $activePreset = null;
        $savedGroupIds = [];

        if ($currentUserId && Schema::hasTable('whatsapp_group_presets')) {
            $presets = WhatsAppGroupPreset::query()
                ->where('user_id', $currentUserId)
                ->where('device_alias', $deviceAlias)
                ->orderBy('name')
                ->get();

            $requestedPresetId = (int) $request->query('preset_id', 0);
            if ($requestedPresetId > 0) {
                $activePreset = $presets->firstWhere('id', $requestedPresetId);
            }

            if ($activePreset) {
                $activeIds = $groups->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
                $savedGroupIds = array_values(array_intersect(
                    array_map('intval', (array) $activePreset->group_ids),
                    $activeIds,
                ));
            }
        }

        $recentMessages = WhatsAppMessage::query()
            ->where('channel', 'group')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.whatsapp.groups', compact(
            'groups',
            'recentMessages',
            'deviceAlias',
            'presets',
            'activePreset',
            'savedGroupIds',
        ));
    }

    public function sync(
        Request $request,
        WhatsAppGroupSyncService $sync,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'device_alias' => ['required', 'in:default,transaction,support,partner,campaign'],
            'preset_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $result = $sync->sync($data['device_alias']);
            $audit->record($request, 'whatsapp.groups_synced', null, [
                'device_alias' => $data['device_alias'],
                'count' => $result['count'],
            ]);

            return redirect()
                ->route('admin.whatsapp.groups.index', array_filter([
                    'device_alias' => $data['device_alias'],
                    'preset_id' => $data['preset_id'] ?? null,
                ]))
                ->with('success', $result['count'].' grup WhatsApp berhasil disinkronkan.');
        } catch (Throwable $exception) {
            return back()->withErrors(['groups' => $exception->getMessage()]);
        }
    }

    public function savePreset(
        Request $request,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'selected_group_ids' => ['required', 'string', 'json'],
            'device_alias' => ['required', 'in:default,transaction,support,partner,campaign'],
            'preset_id' => ['nullable', 'integer', 'min:1'],
            'preset_name' => ['required', 'string', 'max:100'],
        ]);

        $currentUserId = $request->attributes->get('currentUser')?->id;
        abort_unless($currentUserId, 403);

        if (! Schema::hasTable('whatsapp_group_presets')) {
            throw ValidationException::withMessages([
                'preset_name' => 'Tabel kategori grup belum tersedia. Jalankan migrasi database terlebih dahulu.',
            ]);
        }

        $groupIds = $this->parseSelectedGroupIds($data['selected_group_ids']);
        $groups = $this->resolveSelectedGroups($groupIds, $data['device_alias']);
        $name = trim($data['preset_name']);
        $presetId = isset($data['preset_id']) ? (int) $data['preset_id'] : null;

        $duplicateQuery = WhatsAppGroupPreset::query()
            ->where('user_id', $currentUserId)
            ->where('device_alias', $data['device_alias'])
            ->whereRaw('LOWER(name) = LOWER(?)', [$name]);

        if ($presetId) {
            $duplicateQuery->where('id', '!=', $presetId);
        }

        if ($duplicateQuery->exists()) {
            throw ValidationException::withMessages([
                'preset_name' => 'Nama kategori tersebut sudah digunakan pada perangkat ini.',
            ]);
        }

        if ($presetId) {
            $preset = WhatsAppGroupPreset::query()
                ->whereKey($presetId)
                ->where('user_id', $currentUserId)
                ->where('device_alias', $data['device_alias'])
                ->firstOrFail();

            $preset->update([
                'name' => $name,
                'group_ids' => $groups->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            ]);
            $message = 'Kategori "'.$preset->name.'" berhasil diperbarui.';
            $event = 'whatsapp.group_preset_updated';
        } else {
            $preset = WhatsAppGroupPreset::query()->create([
                'user_id' => $currentUserId,
                'device_alias' => $data['device_alias'],
                'name' => $name,
                'group_ids' => $groups->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            ]);
            $message = 'Kategori "'.$preset->name.'" berhasil disimpan.';
            $event = 'whatsapp.group_preset_created';
        }

        $audit->record($request, $event, $preset, [
            'device_alias' => $data['device_alias'],
            'group_count' => $groups->count(),
        ]);

        return redirect()
            ->route('admin.whatsapp.groups.index', [
                'device_alias' => $data['device_alias'],
                'preset_id' => $preset->id,
            ])
            ->with('success', $message);
    }

    public function deletePreset(
        Request $request,
        WhatsAppGroupPreset $preset,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $currentUserId = $request->attributes->get('currentUser')?->id;
        abort_unless($currentUserId && (int) $preset->user_id === (int) $currentUserId, 404);

        $deviceAlias = $preset->device_alias;
        $name = $preset->name;
        $audit->record($request, 'whatsapp.group_preset_deleted', $preset, [
            'device_alias' => $deviceAlias,
            'group_count' => count((array) $preset->group_ids),
        ]);
        $preset->delete();

        return redirect()
            ->route('admin.whatsapp.groups.index', ['device_alias' => $deviceAlias])
            ->with('success', 'Kategori "'.$name.'" berhasil dihapus.');
    }

    public function sendMany(
        Request $request,
        WhatsAppManager $manager,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'selected_group_ids' => ['required', 'string', 'json'],
            'device_alias' => ['required', 'in:default,transaction,support,partner,campaign'],
            'preset_id' => ['nullable', 'integer', 'min:1'],
            'message_type' => ['required', 'in:text,image,document,video,audio,media'],
            'body' => ['nullable', 'string', 'max:10000'],
            'media_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'media_url' => ['nullable', 'url', 'max:2048', new SafePublicUrl],
            'delay_seconds' => ['required', 'integer', 'min:5', 'max:3600'],
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
            'confirm_group_policy' => ['accepted'],
        ]);

        $groupIds = $this->parseSelectedGroupIds($data['selected_group_ids']);
        $messageType = $request->hasFile('media_file') ? 'image' : $data['message_type'];
        $mediaUrl = filled($data['media_url'] ?? null) ? trim((string) $data['media_url']) : null;

        if ($request->hasFile('media_file')) {
            $mediaUrl = $this->storeUploadedGroupImage($request);
        }

        if ($messageType === 'text' && blank($data['body'] ?? null)) {
            throw ValidationException::withMessages(['body' => 'Pesan teks wajib memiliki isi pesan.']);
        }
        if ($messageType !== 'text' && blank($mediaUrl)) {
            throw ValidationException::withMessages(['media_file' => 'Unggah gambar atau isi URL media yang dapat diakses StarSender.']);
        }

        $groups = $this->resolveSelectedGroups($groupIds, $data['device_alias']);
        $batchId = (string) Str::uuid();
        $baseSchedule = filled($data['scheduled_at'] ?? null) ? Carbon::parse($data['scheduled_at']) : null;
        $interGroupDelay = (int) $data['delay_seconds'];

        $queued = DB::transaction(function () use (
            $groups,
            $data,
            $request,
            $manager,
            $batchId,
            $baseSchedule,
            $interGroupDelay,
            $messageType,
            $mediaUrl,
        ): int {
            $count = 0;
            foreach ($groups->values() as $index => $group) {
                $providerDelay = max(2, $index * $interGroupDelay);
                $message = $manager->queueRaw([
                    'phone' => $group->group_jid,
                    'recipient_name' => $group->name,
                    'channel' => 'group',
                    'message_type' => $messageType,
                    'body' => $data['body'] ?? null,
                    'media_url' => $mediaUrl,
                    'device_alias' => $data['device_alias'],
                    'created_by' => $request->attributes->get('currentUser')?->id,
                    'scheduled_at' => $baseSchedule,
                    'idempotency_key' => 'group-batch:'.$batchId.':'.$group->id,
                    'metadata' => [
                        'source' => 'admin_group_multi_send',
                        'group_id' => $group->id,
                        'group_preset_id' => $data['preset_id'] ?? null,
                        'batch_id' => $batchId,
                        'batch_position' => $index + 1,
                        'batch_total' => $groups->count(),
                        'inter_group_delay_seconds' => $interGroupDelay,
                        'delay' => $providerDelay,
                    ],
                ]);

                if ($message) {
                    $count++;
                }
            }

            return $count;
        });

        if ($queued === 0) {
            throw ValidationException::withMessages(['group_ids' => 'Tidak ada pesan grup yang berhasil dibuat. Periksa integrasi WhatsApp.']);
        }

        $audit->record($request, 'whatsapp.group_batch_queued', null, [
            'batch_id' => $batchId,
            'group_count' => $queued,
            'group_preset_id' => $data['preset_id'] ?? null,
            'device_alias' => $data['device_alias'],
            'message_type' => $messageType,
            'has_media' => filled($mediaUrl),
            'delay_seconds' => $interGroupDelay,
        ]);

        return redirect()
            ->route('admin.whatsapp.groups.index', array_filter([
                'device_alias' => $data['device_alias'],
                'preset_id' => $data['preset_id'] ?? null,
            ]))
            ->with('success', $queued.' pesan grup berhasil dimasukkan ke antrean.');
    }

    private function normalizeDeviceAlias(string $deviceAlias): string
    {
        $deviceAlias = trim($deviceAlias);

        return in_array($deviceAlias, ['default', 'transaction', 'support', 'partner', 'campaign'], true)
            ? $deviceAlias
            : 'support';
    }

    private function parseSelectedGroupIds(string $json): array
    {
        $groupIds = collect(json_decode($json, true, 512, JSON_THROW_ON_ERROR))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($groupIds === []) {
            throw ValidationException::withMessages(['group_ids' => 'Pilih minimal satu grup.']);
        }

        return $groupIds;
    }

    private function resolveSelectedGroups(array $groupIds, string $deviceAlias)
    {
        $groups = WhatsAppGroup::query()
            ->whereIn('id', $groupIds)
            ->where('device_alias', $deviceAlias)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($groups->count() !== count($groupIds)) {
            throw ValidationException::withMessages([
                'group_ids' => 'Sebagian grup tidak aktif atau berasal dari perangkat yang berbeda. Sinkronkan ulang dan pilih kembali.',
            ]);
        }

        return $groups;
    }

    private function storeUploadedGroupImage(Request $request): string
    {
        $file = $request->file('media_file');
        if (! $file) {
            throw ValidationException::withMessages(['media_file' => 'File gambar tidak ditemukan.']);
        }

        $folder = 'whatsapp/groups/'.now()->format('Y/m');
        $directory = database_path('uploads/'.$folder);
        File::ensureDirectoryExists($directory, 0755, true);

        $extension = strtolower((string) ($file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg'));
        $filename = (string) Str::uuid().'.'.$extension;
        $file->move($directory, $filename);

        return url('/storage/'.$folder.'/'.$filename);
    }
}

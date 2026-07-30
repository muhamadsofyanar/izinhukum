<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppGroupSelection;
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
        $deviceAlias = trim((string) $request->query('device_alias', 'support'));
        if (! in_array($deviceAlias, ['default', 'transaction', 'support', 'partner', 'campaign'], true)) {
            $deviceAlias = 'support';
        }

        $groups = WhatsAppGroup::query()
            ->where('device_alias', $deviceAlias)
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('group_jid')
            ->get();

        $savedGroupIds = [];
        $currentUserId = $request->attributes->get('currentUser')?->id;
        if ($currentUserId && Schema::hasTable('whatsapp_group_selections')) {
            $selection = WhatsAppGroupSelection::query()
                ->where('user_id', $currentUserId)
                ->where('device_alias', $deviceAlias)
                ->first();

            $activeIds = $groups->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $savedGroupIds = array_values(array_intersect(
                array_map('intval', (array) ($selection?->group_ids ?? [])),
                $activeIds,
            ));
        }

        $recentMessages = WhatsAppMessage::query()
            ->where('channel', 'group')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.whatsapp.groups', compact('groups', 'recentMessages', 'deviceAlias', 'savedGroupIds'));
    }

    public function sync(
        Request $request,
        WhatsAppGroupSyncService $sync,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'device_alias' => ['required', 'in:default,transaction,support,partner,campaign'],
        ]);

        try {
            $result = $sync->sync($data['device_alias']);
            $audit->record($request, 'whatsapp.groups_synced', null, [
                'device_alias' => $data['device_alias'],
                'count' => $result['count'],
            ]);

            return redirect()
                ->route('admin.whatsapp.groups.index', ['device_alias' => $data['device_alias']])
                ->with('success', $result['count'].' grup WhatsApp berhasil disinkronkan.');
        } catch (Throwable $exception) {
            return back()->withErrors(['groups' => $exception->getMessage()]);
        }
    }

    public function clearSelection(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'device_alias' => ['required', 'in:default,transaction,support,partner,campaign'],
        ]);

        $currentUserId = $request->attributes->get('currentUser')?->id;
        if ($currentUserId && Schema::hasTable('whatsapp_group_selections')) {
            WhatsAppGroupSelection::query()
                ->where('user_id', $currentUserId)
                ->where('device_alias', $data['device_alias'])
                ->delete();
        }

        return redirect()
            ->route('admin.whatsapp.groups.index', ['device_alias' => $data['device_alias']])
            ->with('success', 'Pilihan grup tersimpan telah dihapus.');
    }

    public function sendMany(
        Request $request,
        WhatsAppManager $manager,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'selected_group_ids' => ['required', 'string', 'json'],
            'device_alias' => ['required', 'in:default,transaction,support,partner,campaign'],
            'message_type' => ['required', 'in:text,image,document,video,audio,media'],
            'body' => ['nullable', 'string', 'max:10000'],
            'media_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'media_url' => ['nullable', 'url', 'max:2048', new SafePublicUrl],
            'delay_seconds' => ['required', 'integer', 'min:5', 'max:3600'],
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
            'confirm_group_policy' => ['accepted'],
        ]);

        $groupIds = collect(json_decode($data['selected_group_ids'], true, 512, JSON_THROW_ON_ERROR))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        if ($groupIds === []) {
            throw ValidationException::withMessages(['group_ids' => 'Pilih minimal satu grup.']);
        }

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

        $groups = WhatsAppGroup::query()
            ->whereIn('id', $groupIds)
            ->where('device_alias', $data['device_alias'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($groups->count() !== count($groupIds)) {
            throw ValidationException::withMessages([
                'group_ids' => 'Sebagian grup tidak aktif atau berasal dari perangkat yang berbeda. Sinkronkan ulang dan pilih kembali.',
            ]);
        }

        $this->saveSelection($request, $data['device_alias'], $groups->pluck('id')->all());

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
            'device_alias' => $data['device_alias'],
            'message_type' => $messageType,
            'has_media' => filled($mediaUrl),
            'delay_seconds' => $interGroupDelay,
        ]);

        return redirect()
            ->route('admin.whatsapp.groups.index', ['device_alias' => $data['device_alias']])
            ->with('success', $queued.' pesan grup masuk antrean. Pilihan grup telah disimpan untuk pengiriman berikutnya.');
    }

    private function saveSelection(Request $request, string $deviceAlias, array $groupIds): void
    {
        $currentUserId = $request->attributes->get('currentUser')?->id;
        if (! $currentUserId || ! Schema::hasTable('whatsapp_group_selections')) {
            return;
        }

        WhatsAppGroupSelection::query()->updateOrCreate(
            ['user_id' => $currentUserId, 'device_alias' => $deviceAlias],
            ['group_ids' => array_values(array_map('intval', $groupIds))],
        );
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

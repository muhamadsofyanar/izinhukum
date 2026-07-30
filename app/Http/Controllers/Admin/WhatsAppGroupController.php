<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppMessage;
use App\Rules\SafePublicUrl;
use App\Services\WhatsApp\WhatsAppAuditService;
use App\Services\WhatsApp\WhatsAppGroupSyncService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        $recentMessages = WhatsAppMessage::query()
            ->where('channel', 'group')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.whatsapp.groups', compact('groups', 'recentMessages', 'deviceAlias'));
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

    public function sendMany(
        Request $request,
        WhatsAppManager $manager,
        WhatsAppAuditService $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'group_ids' => ['required', 'array', 'min:1', 'max:50'],
            'group_ids.*' => ['required', 'integer', 'distinct', 'exists:whatsapp_groups,id'],
            'device_alias' => ['required', 'in:default,transaction,support,partner,campaign'],
            'message_type' => ['required', 'in:text,image,document,video,audio,media'],
            'body' => ['nullable', 'string', 'max:10000'],
            'media_url' => ['nullable', 'url', 'max:2048', new SafePublicUrl],
            'delay_seconds' => ['required', 'integer', 'min:2', 'max:300'],
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
            'confirm_group_policy' => ['accepted'],
        ]);

        if ($data['message_type'] === 'text' && blank($data['body'] ?? null)) {
            throw ValidationException::withMessages(['body' => 'Pesan teks wajib memiliki isi pesan.']);
        }
        if ($data['message_type'] !== 'text' && blank($data['media_url'] ?? null)) {
            throw ValidationException::withMessages(['media_url' => 'Pesan media wajib memiliki URL HTTPS yang dapat diakses StarSender.']);
        }

        $groups = WhatsAppGroup::query()
            ->whereIn('id', $data['group_ids'])
            ->where('device_alias', $data['device_alias'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($groups->count() !== count($data['group_ids'])) {
            throw ValidationException::withMessages([
                'group_ids' => 'Sebagian grup tidak aktif atau berasal dari perangkat yang berbeda. Sinkronkan ulang dan pilih kembali.',
            ]);
        }

        $batchId = (string) Str::uuid();
        $baseSchedule = filled($data['scheduled_at'] ?? null) ? Carbon::parse($data['scheduled_at']) : null;
        $queued = DB::transaction(function () use ($groups, $data, $request, $manager, $batchId, $baseSchedule): int {
            $count = 0;
            foreach ($groups->values() as $index => $group) {
                $scheduledAt = $baseSchedule?->copy()->addSeconds($index * (int) $data['delay_seconds']);
                $message = $manager->queueRaw([
                    'phone' => $group->group_jid,
                    'recipient_name' => $group->name,
                    'channel' => 'group',
                    'message_type' => $data['message_type'],
                    'body' => $data['body'] ?? null,
                    'media_url' => $data['media_url'] ?? null,
                    'device_alias' => $data['device_alias'],
                    'created_by' => $request->attributes->get('currentUser')?->id,
                    'scheduled_at' => $scheduledAt,
                    'idempotency_key' => 'group-batch:'.$batchId.':'.$group->id,
                    'metadata' => [
                        'source' => 'admin_group_multi_send',
                        'group_id' => $group->id,
                        'batch_id' => $batchId,
                        'delay' => min(3600, max(2, (int) $data['delay_seconds']) * $index),
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
        ]);

        return redirect()
            ->route('admin.whatsapp.groups.index', ['device_alias' => $data['device_alias']])
            ->with('success', $queued.' pesan grup masuk antrean dalam satu batch.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\MarketingMaterial;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OperationsController extends Controller
{
    public function index(Request $request, string $module): View
    {
        $user = $request->attributes->get('currentUser');
        $isAdmin = $user->isAdmin();
        abort_unless(in_array($module, ['announcements', 'materials', 'tickets', 'commissions', 'audit'], true), 404);
        abort_if(!$isAdmin && $module === 'audit', 403);

        $data = match ($module) {
            'announcements' => Announcement::whereNotNull('published_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderByDesc('is_pinned')->latest('published_at')->paginate(20),
            'materials' => MarketingMaterial::when(!$isAdmin, fn ($q) => $q->where('is_active', true))->latest()->paginate(20),
            'tickets' => SupportTicket::with('user')->when(!$isAdmin, fn ($q) => $q->where('user_id', $user->id))->latest()->paginate(20),
            'commissions' => Commission::with('partner')->when(!$isAdmin, fn ($q) => $q->where('partner_id', $user->id))->latest()->paginate(20),
            'audit' => AuditLog::latest()->paginate(30),
        };

        return view('portal.operations', compact('module', 'data', 'isAdmin'));
    }

    public function store(Request $request, string $module): RedirectResponse
    {
        $user = $request->attributes->get('currentUser');

        if ($module === 'tickets') {
            $data = $request->validate([
                'subject' => ['required', 'string', 'max:180'],
                'category' => ['required', 'string', 'max:64'],
                'priority' => ['required', 'in:low,normal,high,urgent'],
                'message' => ['required', 'string', 'max:5000'],
            ]);
            SupportTicket::create([...$data, 'reference' => 'TKT-'.now()->format('ymd').'-'.Str::upper(Str::random(5)), 'user_id' => $user->id]);
            return back()->with('success', 'Tiket bantuan berhasil dibuat.');
        }

        abort_unless($user->isAdmin(), 403);

        if ($module === 'announcements') {
            $data = $request->validate(['title' => ['required', 'string', 'max:180'], 'body' => ['required', 'string', 'max:5000'], 'is_pinned' => ['nullable', 'boolean']]);
            Announcement::create([...$data, 'created_by' => $user->id, 'published_at' => now(), 'audience' => 'all_partners']);
        } elseif ($module === 'materials') {
            $data = $request->validate(['title' => ['required', 'string', 'max:180'], 'category' => ['required', 'string', 'max:64'], 'description' => ['nullable', 'string'], 'file_url' => ['required', 'url', 'max:2048']]);
            MarketingMaterial::create([...$data, 'created_by' => $user->id]);
        } elseif ($module === 'commissions') {
            $data = $request->validate(['partner_id' => ['required', 'exists:users,id'], 'amount' => ['required', 'integer', 'min:0'], 'notes' => ['nullable', 'string']]);
            abort_unless(User::whereKey($data['partner_id'])->where('role', 'partner')->exists(), 422);
            Commission::create($data);
        } else {
            abort(404);
        }

        return back()->with('success', 'Data berhasil ditambahkan.');
    }

    public function updateTicket(Request $request, SupportTicket $ticket): RedirectResponse
    {
        abort_unless($request->attributes->get('currentUser')->isAdmin(), 403);
        $data = $request->validate(['status' => ['required', 'in:open,in_progress,resolved,closed'], 'admin_response' => ['nullable', 'string', 'max:5000']]);
        $ticket->update([...$data, 'assigned_to' => $request->attributes->get('currentUser')->id, 'resolved_at' => $data['status'] === 'resolved' ? now() : null]);
        return back()->with('success', 'Tiket diperbarui.');
    }

    public function updateCommission(Request $request, Commission $commission): RedirectResponse
    {
        abort_unless($request->attributes->get('currentUser')->isAdmin(), 403);
        $data = $request->validate(['status' => ['required', 'in:pending,approved,paid,cancelled']]);
        $commission->update([...$data, 'paid_at' => $data['status'] === 'paid' ? now() : null]);
        return back()->with('success', 'Status komisi diperbarui.');
    }
}

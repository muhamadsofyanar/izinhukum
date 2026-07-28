<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\DirectMessage;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->attributes->get('currentUser');
        DirectMessage::where('recipient_id', $user->id)->whereNull('read_at')->update(['read_at' => now()]);
        return view('portal.inbox', [
            'messages' => DirectMessage::with(['sender', 'recipient'])
                ->where(fn ($q) => $q->where('sender_id', $user->id)->orWhere('recipient_id', $user->id))
                ->latest()->paginate(30),
            'recipients' => $user->isAdmin()
                ? User::where('role', 'partner')->where('is_active', true)->orderBy('name')->get()
                : User::where('role', 'admin')->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $sender = $request->attributes->get('currentUser');
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:10000'],
        ]);
        $recipient = User::findOrFail($data['recipient_id']);
        abort_unless($sender->isAdmin() ? $recipient->isPartner() : $recipient->isAdmin(), 403);
        DirectMessage::create([...$data, 'sender_id' => $sender->id]);
        return back()->with('success', 'Pesan berhasil dikirim.');
    }
}

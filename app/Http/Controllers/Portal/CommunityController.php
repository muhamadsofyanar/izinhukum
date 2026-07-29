<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CommunityPost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CommunityController extends Controller
{
    public function index(): View
    {
        return view('portal.community', [
            'posts' => CommunityPost::with(['user', 'comments.user'])
                ->orderByDesc('is_pinned')->latest()->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:10000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);
        $data['user_id'] = $request->attributes->get('currentUser')->id;
        $data['attachment_path'] = $request->file('attachment')?->store('community', 'local');
        unset($data['attachment']);
        CommunityPost::create($data);
        return back()->with('success', 'Postingan diterbitkan ke komunitas.');
    }

    public function comment(Request $request, CommunityPost $post): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:3000']]);
        $post->comments()->create([...$data, 'user_id' => $request->attributes->get('currentUser')->id]);
        return back()->with('success', 'Komentar dikirim.');
    }
}

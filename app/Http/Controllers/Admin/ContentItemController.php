<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ContentItemController extends Controller
{
    public function index(Request $request): View
    {
        $items = ContentItem::query()
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($s) => $s->where('title', 'like', '%'.$request->string('q').'%')->orWhere('body', 'like', '%'.$request->string('q').'%')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->latest()->paginate(20)->withQueryString();

        return view('admin.whatsapp.content-items', [
            'items' => $items,
            'editing' => $request->filled('edit') ? ContentItem::find($request->integer('edit')) : null,
            'categories' => ContentItem::query()->distinct()->orderBy('category')->pluck('category'),
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->attributes->get('currentUser')?->id;
        $data['media_path'] = $this->storeMedia($request);
        ContentItem::create($data);

        return back()->with('success', 'Konten berhasil ditambahkan ke bank konten.');
    }

    public function update(Request $request, ContentItem $contentItem): RedirectResponse
    {
        $data = $this->validated($request);
        if ($request->hasFile('media')) {
            if ($contentItem->media_path) Storage::disk('public')->delete($contentItem->media_path);
            $data['media_path'] = $this->storeMedia($request);
        }
        $contentItem->update($data);

        return redirect()->route('admin.whatsapp.content.index')->with('success', 'Konten diperbarui.');
    }

    public function destroy(ContentItem $contentItem): RedirectResponse
    {
        if ($contentItem->media_path) Storage::disk('public')->delete($contentItem->media_path);
        $contentItem->delete();
        return back()->with('success', 'Konten dihapus.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', 'max:80'],
            'service_id' => ['nullable', 'exists:services,id'],
            'body' => ['required', 'string', 'max:20000'],
            'status' => ['required', Rule::in(['draft', 'ready', 'archived'])],
            'scheduled_at' => ['nullable', 'date'],
            'media' => ['nullable', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp,pdf,mp4,mp3'],
        ]);
    }

    private function storeMedia(Request $request): ?string
    {
        return $request->file('media')?->store('content-items/'.now()->format('Y/m'), 'public');
    }
}

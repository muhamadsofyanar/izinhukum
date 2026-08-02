<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesQuoteTemplate;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesQuoteTemplateController extends Controller
{
    public function index(): View
    {
        return view('admin.quote-templates.index', [
            'templates' => SalesQuoteTemplate::query()->with(['service', 'creator'])->orderByDesc('is_active')->orderBy('name')->get(),
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->attributes->get('currentUser');
        abort_unless($actor instanceof User, 403);
        SalesQuoteTemplate::query()->create([
            ...$this->validateData($request),
            'created_by' => $actor->id,
        ]);

        return back()->with('success', 'Template penawaran berhasil dibuat.');
    }

    public function update(Request $request, SalesQuoteTemplate $template): RedirectResponse
    {
        $template->update($this->validateData($request));

        return back()->with('success', 'Template penawaran berhasil diperbarui.');
    }

    public function destroy(SalesQuoteTemplate $template): RedirectResponse
    {
        if ($template->quotes()->exists()) {
            $template->update(['is_active' => false]);

            return back()->with('success', 'Template sudah pernah dipakai sehingga dinonaktifkan, bukan dihapus.');
        }
        $template->delete();

        return back()->with('success', 'Template penawaran dihapus.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'service_id' => ['nullable', 'exists:services,id'],
            'scope' => ['nullable', 'string', 'max:20000'],
            'terms' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'validity_days' => ['required', 'integer', 'min:1', 'max:90'],
            'invoice_due_days' => ['required', 'integer', 'min:1', 'max:90'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}

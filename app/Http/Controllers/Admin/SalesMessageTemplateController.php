<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Models\SalesMessageTemplate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SalesMessageTemplateController extends Controller
{
    public function index(): View
    {
        return view('admin.sales-messages.index', [
            'templates' => SalesMessageTemplate::query()->with('creator')->orderBy('sort_order')->orderBy('name')->get(),
            'purposes' => SalesMessageTemplate::PURPOSES,
            'stages' => CrmLead::STAGES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->attributes->get('currentUser');
        abort_unless($actor instanceof User, 403);
        SalesMessageTemplate::query()->create([
            ...$this->validateData($request),
            'created_by' => $actor->id,
        ]);

        return back()->with('success', 'Template pesan berhasil ditambahkan.');
    }

    public function update(Request $request, SalesMessageTemplate $template): RedirectResponse
    {
        $template->update($this->validateData($request));

        return back()->with('success', 'Template pesan berhasil diperbarui.');
    }

    public function destroy(SalesMessageTemplate $template): RedirectResponse
    {
        $template->delete();

        return back()->with('success', 'Template pesan dihapus. Histori aktivitas lead tetap tersimpan.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'purpose' => ['required', Rule::in(array_keys(SalesMessageTemplate::PURPOSES))],
            'stage' => ['nullable', Rule::in(array_keys(CrmLead::STAGES))],
            'body' => ['required', 'string', 'max:10000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmLabel;
use App\Services\WhatsApp\WhatsAppAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WhatsAppLabelController extends Controller
{
    public function store(Request $request, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::in(['source', 'status', 'service', 'document', 'priority', 'custom'])],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $slug = Str::slug($data['name']);
        $label = CrmLabel::query()->updateOrCreate(
            ['slug' => $slug],
            [...$data, 'is_active' => true, 'created_by' => $request->attributes->get('currentUser')?->id],
        );
        $audit->record($request, 'crm.label_saved', $label);
        return back()->with('success', 'Label berhasil disimpan.');
    }

    public function update(Request $request, CrmLabel $label, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::in(['source', 'status', 'service', 'document', 'priority', 'custom'])],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['slug'] = Str::slug($data['name']);
        if (CrmLabel::query()->where('slug', $data['slug'])->whereKeyNot($label->id)->exists()) {
            return back()->withErrors(['name' => 'Nama label sudah digunakan.']);
        }
        $data['is_active'] = $request->boolean('is_active');
        $label->update($data);
        $audit->record($request, 'crm.label_updated', $label);
        return back()->with('success', 'Label diperbarui.');
    }

    public function destroy(Request $request, CrmLabel $label, WhatsAppAuditService $audit): RedirectResponse
    {
        $audit->record($request, 'crm.label_deleted', $label, ['name' => $label->name]);
        $label->delete();
        return back()->with('success', 'Label dihapus dari CRM.');
    }
}

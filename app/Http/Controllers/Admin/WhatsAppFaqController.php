<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmFaqRule;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WhatsAppFaqController extends Controller
{
    public function index(): View
    {
        return view('admin.whatsapp.faq.index', [
            'rules' => CrmFaqRule::query()->with('template')->orderBy('priority')->orderBy('name')->paginate(40),
            'templates' => WhatsAppTemplate::query()->where('is_enabled', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'keyword' => ['required', 'string', 'max:190'],
            'match_type' => ['required', Rule::in(['exact', 'contains', 'regex'])],
            'answer' => ['nullable', 'string', 'max:10000'],
            'template_id' => ['nullable', 'exists:whatsapp_templates,id'],
            'priority' => ['required', 'integer', 'min:1', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
            'handoff_after_reply' => ['nullable', 'boolean'],
        ]);
        if (($data['match_type'] ?? null) === 'regex' && @preg_match($data['keyword'], '') === false) {
            return back()->withErrors(['keyword' => 'Pola regex tidak valid. Gunakan delimiter, misalnya /biaya.*pt/i.']);
        }
        if (blank($data['answer'] ?? null) && blank($data['template_id'] ?? null)) {
            return back()->withErrors(['answer' => 'Isi jawaban atau pilih template.']);
        }
        $rule = CrmFaqRule::query()->create([
            ...$data,
            'is_active' => $request->boolean('is_active'),
            'handoff_after_reply' => $request->boolean('handoff_after_reply'),
            'created_by' => $request->attributes->get('currentUser')?->id,
        ]);
        $audit->record($request, 'crm.faq_created', $rule);
        return back()->with('success', 'Aturan FAQ disimpan.');
    }

    public function update(Request $request, CrmFaqRule $faq, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'keyword' => ['required', 'string', 'max:190'],
            'match_type' => ['required', Rule::in(['exact', 'contains', 'regex'])],
            'answer' => ['nullable', 'string', 'max:10000'],
            'template_id' => ['nullable', 'exists:whatsapp_templates,id'],
            'priority' => ['required', 'integer', 'min:1', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
            'handoff_after_reply' => ['nullable', 'boolean'],
        ]);
        if (($data['match_type'] ?? null) === 'regex' && @preg_match($data['keyword'], '') === false) {
            return back()->withErrors(['keyword' => 'Pola regex tidak valid. Gunakan delimiter, misalnya /biaya.*pt/i.']);
        }
        if (blank($data['answer'] ?? null) && blank($data['template_id'] ?? null)) {
            return back()->withErrors(['answer' => 'Isi jawaban atau pilih template.']);
        }
        $faq->update([
            ...$data,
            'is_active' => $request->boolean('is_active'),
            'handoff_after_reply' => $request->boolean('handoff_after_reply'),
        ]);
        $audit->record($request, 'crm.faq_updated', $faq);
        return back()->with('success', 'Aturan FAQ diperbarui.');
    }

    public function destroy(Request $request, CrmFaqRule $faq, WhatsAppAuditService $audit): RedirectResponse
    {
        $audit->record($request, 'crm.faq_deleted', $faq);
        $faq->delete();
        return back()->with('success', 'Aturan FAQ dihapus.');
    }
}

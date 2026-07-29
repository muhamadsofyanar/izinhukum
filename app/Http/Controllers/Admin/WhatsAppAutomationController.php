<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WhatsAppAutomationController extends Controller
{
    public function index(): View
    {
        return view('admin.whatsapp.automations', [
            'automations' => WhatsAppAutomation::query()->with('template')->orderBy('name')->get(),
            'templates' => WhatsAppTemplate::query()->where('is_enabled', true)->orderBy('category')->orderBy('name')->get(),
        ]);
    }

    public function storeKeyword(Request $request, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'alpha_dash', 'max:100', Rule::unique('whatsapp_automations', 'key')],
            'name' => ['required', 'string', 'max:160'],
            'template_id' => ['required', 'exists:whatsapp_templates,id'],
            'keywords' => ['required', 'string', 'max:1000'],
        ]);

        $keywords = collect(preg_split('/[,\n]+/', $data['keywords']) ?: [])
            ->map(fn (string $value) => strtoupper(trim($value)))
            ->filter()
            ->unique()
            ->take(50)
            ->values()
            ->all();

        $automation = WhatsAppAutomation::query()->create([
            'key' => $data['key'],
            'name' => $data['name'],
            'trigger' => 'keyword',
            'template_id' => $data['template_id'],
            'recipient_type' => 'customer',
            'is_enabled' => false,
            'delay_minutes' => 0,
            'conditions' => ['keywords' => $keywords],
        ]);
        $audit->record($request, 'whatsapp.automation_created', $automation, ['keywords' => $keywords]);

        return back()->with('success', 'Autoreply kata kunci dibuat dalam keadaan nonaktif.');
    }

    public function update(Request $request, WhatsAppAutomation $automation, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'template_id' => ['required', 'exists:whatsapp_templates,id'],
            'delay_minutes' => ['required', 'integer', 'min:0', 'max:43200'],
            'keywords' => ['nullable', 'string', 'max:1000'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $conditions = $automation->conditions ?? [];
        if ($automation->trigger === 'keyword') {
            $conditions['keywords'] = collect(preg_split('/[,\n]+/', (string) ($data['keywords'] ?? '')) ?: [])
                ->map(fn (string $value) => strtoupper(trim($value)))
                ->filter()
                ->unique()
                ->take(50)
                ->values()
                ->all();
        }

        $automation->update([
            'name' => $data['name'],
            'template_id' => $data['template_id'],
            'delay_minutes' => $data['delay_minutes'],
            'is_enabled' => $request->boolean('is_enabled'),
            'conditions' => $conditions,
        ]);
        $audit->record($request, 'whatsapp.automation_updated', $automation, ['enabled' => $automation->is_enabled]);

        return back()->with('success', 'Otomasi diperbarui.');
    }
}

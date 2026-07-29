<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppTemplate;
use App\Rules\SafePublicUrl;
use App\Services\WhatsApp\WhatsAppAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WhatsAppTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $category = trim((string) $request->query('category'));
        $templates = WhatsAppTemplate::query()
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return view('admin.whatsapp.templates', [
            'templates' => $templates,
            'category' => $category,
            'categories' => WhatsAppTemplate::query()->select('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function store(Request $request, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $this->validateTemplate($request);
        $template = WhatsAppTemplate::query()->create([
            ...$data,
            'key' => $data['key'],
            'variables' => $this->variables($data['body']),
            'updated_by' => $request->attributes->get('currentUser')?->id,
        ]);
        $audit->record($request, 'whatsapp.template_created', $template);

        return back()->with('success', 'Template WhatsApp berhasil dibuat.');
    }

    public function update(Request $request, WhatsAppTemplate $template, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $this->validateTemplate($request, $template);
        $beforeVersion = $template->version;
        $template->update([
            ...$data,
            'variables' => $this->variables($data['body']),
            'version' => $beforeVersion + 1,
            'updated_by' => $request->attributes->get('currentUser')?->id,
        ]);
        $audit->record($request, 'whatsapp.template_updated', $template, ['version_from' => $beforeVersion, 'version_to' => $template->version]);

        return back()->with('success', 'Template diperbarui ke versi '.$template->version.'.');
    }

    private function validateTemplate(Request $request, ?WhatsAppTemplate $template = null): array
    {
        $data = $request->validate([
            'key' => ['required', 'alpha_dash', 'max:100', Rule::unique('whatsapp_templates', 'key')->ignore($template?->id)],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
            'body' => ['required', 'string', 'max:10000'],
            'message_type' => ['required', 'in:text,image,document,video,audio,media'],
            'media_url' => ['nullable', 'url', 'max:2048', new SafePublicUrl],
            'is_enabled' => ['nullable', 'boolean'],
            'is_marketing' => ['nullable', 'boolean'],
        ]);

        if ($data['message_type'] !== 'text' && blank($data['media_url'] ?? null)) {
            throw ValidationException::withMessages([
                'media_url' => 'Template media wajib memiliki URL media HTTPS yang dapat diakses provider.',
            ]);
        }

        return $data + [
            'is_enabled' => $request->boolean('is_enabled'),
            'is_marketing' => $request->boolean('is_marketing'),
        ];
    }

    private function variables(string $body): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $body, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }
}

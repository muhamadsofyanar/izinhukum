<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\BrandingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class BrandingController extends Controller
{
    public function edit(BrandingService $brandingService): View
    {
        return view('admin.branding', ['branding' => $brandingService->document()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand_name' => ['required', 'string', 'max:80'],
            'brand_tagline' => ['nullable', 'string', 'max:120'],
            'document_address' => ['required', 'string', 'max:1000'],
            'document_phone' => ['required', 'string', 'max:32'],
            'document_email' => ['required', 'email', 'max:160'],
            'bank_name' => ['required', 'string', 'max:80'],
            'bank_account_number' => ['required', 'string', 'max:64'],
            'bank_account_holder' => ['required', 'string', 'max:160'],
            'signatory_name' => ['required', 'string', 'max:160'],
            'signatory_title' => ['required', 'string', 'max:120'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'signature' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'stamp' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        foreach ([
            'brand_name',
            'brand_tagline',
            'document_address',
            'document_phone',
            'document_email',
            'bank_name',
            'bank_account_number',
            'bank_account_holder',
            'signatory_name',
            'signatory_title',
        ] as $key) {
            SystemSetting::storeValue($key, $data[$key] ?? '');
        }

        $this->replaceMedia($request, 'logo', 'brand_logo');
        $this->replaceMedia($request, 'signature', 'document_signature');
        $this->replaceMedia($request, 'stamp', 'document_stamp');

        return back()->with('success', 'Identitas merek dan dokumen bisnis diperbarui.');
    }

    private function replaceMedia(Request $request, string $field, string $settingKey): void
    {
        if (! $request->hasFile($field)) {
            return;
        }

        $path = $request->file($field)->store('branding', 'public');

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('Berkas branding gagal disimpan.');
        }

        $oldPath = SystemSetting::valueFor($settingKey);
        SystemSetting::storeValue($settingKey, $path);

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }
    }
}

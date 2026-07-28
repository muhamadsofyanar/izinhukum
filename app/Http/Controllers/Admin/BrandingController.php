<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class BrandingController extends Controller
{
    public function edit(): View
    {
        return view('admin.branding', [
            'brandName' => SystemSetting::valueFor('brand_name', 'IzinHukum'),
            'brandTagline' => SystemSetting::valueFor('brand_tagline', 'Legalitas sampai tuntas'),
            'brandLogo' => SystemSetting::valueFor('brand_logo'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand_name' => ['required', 'string', 'max:80'],
            'brand_tagline' => ['nullable', 'string', 'max:120'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
        ]);
        SystemSetting::storeValue('brand_name', $data['brand_name']);
        SystemSetting::storeValue('brand_tagline', $data['brand_tagline'] ?? '');
        if ($request->hasFile('logo')) {
            $old = SystemSetting::valueFor('brand_logo');
            if ($old) Storage::disk('public')->delete($old);
            SystemSetting::storeValue('brand_logo', $request->file('logo')->store('branding', 'public'));
        }
        return back()->with('success', 'Logo dan identitas platform diperbarui.');
    }
}

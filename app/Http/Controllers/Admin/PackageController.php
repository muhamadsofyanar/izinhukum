<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PackageController extends Controller
{
    public function index(): View
    {
        $packages = ServicePackage::with('service')->orderBy('service_id')->orderBy('sort_order')->get();

        return view('admin.packages', compact('packages'));
    }

    public function update(Request $request, ServicePackage $package): RedirectResponse
    {
        $validated = $request->validate([
            'price' => ['required', 'integer', 'min:0'],
            'minimum_end_user_price' => ['required', 'integer', 'min:0', 'lte:price'],
            'partner_price' => ['required', 'integer', 'min:0', 'lte:minimum_end_user_price'],
            'original_price' => ['nullable', 'integer', 'min:0'],
            'is_estimated' => ['nullable', 'boolean'],
            'is_popular' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $package->update([
            'price' => $validated['price'],
            'minimum_end_user_price' => $validated['minimum_end_user_price'],
            'partner_price' => $validated['partner_price'],
            'original_price' => $validated['original_price'] ?? null,
            'is_estimated' => $request->boolean('is_estimated'),
            'is_popular' => $request->boolean('is_popular'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', 'Paket berhasil diperbarui.');
    }
}

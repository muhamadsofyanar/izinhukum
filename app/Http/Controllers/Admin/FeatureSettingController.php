<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FeatureFlagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeatureSettingController extends Controller
{
    public function edit(FeatureFlagService $features): View
    {
        return view('admin.features.edit', ['features' => $features->all()]);
    }

    public function update(Request $request, FeatureFlagService $features): RedirectResponse
    {
        $features->store((array) $request->input('features', []));

        return back()->with('success', 'Pengaturan fitur berhasil disimpan. Perubahan berlaku tanpa redeploy.');
    }
}

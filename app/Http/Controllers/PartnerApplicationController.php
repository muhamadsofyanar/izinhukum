<?php

namespace App\Http\Controllers;

use App\Models\PartnerApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PartnerApplicationController extends Controller
{
    public function create(): View
    {
        return view('partnership', [
            'plans' => collect(config('partner.plans', [])),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'desired_partner_level' => ['required', 'in:starter,professional,priority'],
            'email' => ['required', 'email', 'max:160'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
            'phone' => ['required', 'string', 'max:32'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'tax_id' => ['nullable', 'string', 'max:40'],
            'city' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:1000'],
            'message' => ['nullable', 'string', 'max:2000'],
            'privacy_consent' => ['accepted'],
        ]);

        unset($validated['privacy_consent']);
        unset($validated['password_confirmation']);
        $validated['email'] = mb_strtolower($validated['email']);
        $validated['reference'] = 'MIT-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        $application = PartnerApplication::create($validated);

        return redirect()->route('partnership.create')
            ->with('success', 'Pendaftaran mitra diterima. Nomor referensi: '.$application->reference);
    }
}

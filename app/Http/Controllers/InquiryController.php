<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use App\Models\Service;
use App\Models\ServicePackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InquiryController extends Controller
{
    public function create(Request $request): View
    {
        $packages = ServicePackage::query()
            ->with('service')
            ->where('is_active', true)
            ->whereHas('service', fn ($query) => $query->where('is_active', true))
            ->orderBy('service_id')
            ->orderBy('sort_order')
            ->get();

        $selectedPackage = $request->integer('paket');

        return view('proposal', compact('packages', 'selectedPackage'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'service_package_id' => ['nullable', 'exists:service_packages,id'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:160'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:3000'],
            'privacy_consent' => ['accepted'],
        ]);

        unset($validated['privacy_consent']);
        $inquiry = Inquiry::create([
            ...$validated,
            'reference' => 'IH-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
            'source' => 'website',
            'status' => 'baru',
        ]);

        return redirect()
            ->route('proposal.success', $inquiry)
            ->with('success', 'Permintaan Anda sudah kami terima.');
    }

    public function success(Inquiry $inquiry): View
    {
        $inquiry->load('package.service');

        return view('proposal-success', compact('inquiry'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Jobs\SendNewInquiryEmailNotification;
use App\Jobs\SendNewInquiryWhatsAppNotification;
use App\Models\Inquiry;
use App\Models\ServicePackage;
use App\Services\FeatureFlagService;
use App\Services\PartnerReferralService;
use App\Services\ReferralEventService;
use App\Services\ServiceOrderService;
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

    public function store(
        Request $request,
        PartnerReferralService $referrals,
        ServiceOrderService $orders,
        ReferralEventService $events,
        FeatureFlagService $features,
    ): RedirectResponse {
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

        $attribution = $features->enabled('referral_tracking')
            ? $referrals->attribution($request)
            : null;
        $inquiry = Inquiry::query()->create([
            ...$validated,
            ...($attribution ?? []),
            'reference' => 'IH-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
            'source' => $attribution ? 'partner_referral' : 'website',
            'status' => 'baru',
        ]);

        $events->recordInquiry($inquiry);
        $orders->createFromInquiry($inquiry);
        SendNewInquiryEmailNotification::dispatch($inquiry->id)->onQueue('default');
        SendNewInquiryWhatsAppNotification::dispatch($inquiry->id)->onQueue('whatsapp');

        return redirect()
            ->route('proposal.success', $inquiry)
            ->with('success', 'Permintaan Anda sudah kami terima.');
    }

    public function success(Inquiry $inquiry): View
    {
        $inquiry->load(['package.service', 'serviceOrder']);

        return view('proposal-success', compact('inquiry'));
    }
}

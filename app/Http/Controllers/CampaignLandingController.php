<?php

namespace App\Http\Controllers;

use App\Models\MarketingCampaign;
use App\Models\ServicePackage;
use App\Services\FeatureFlagService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CampaignLandingController extends Controller
{
    public function __invoke(
        Request $request,
        MarketingCampaign $campaign,
        FeatureFlagService $features,
    ): View {
        abort_unless($campaign->isLandingLive(), 404);

        if ($features->enabled('campaign_tracking')) {
            $request->session()->put('marketing_attribution', [
                ...(array) $request->session()->get('marketing_attribution', []),
                'utm_source' => $campaign->source,
                'utm_medium' => $campaign->medium,
                'utm_campaign' => $campaign->slug,
                'landing_path' => '/promo/'.$campaign->slug,
            ]);
        }

        $campaign->increment('landing_views');
        $packages = ServicePackage::query()
            ->with('service')
            ->where('is_active', true)
            ->whereHas('service', fn ($query) => $query->where('is_active', true))
            ->when($campaign->service_id, fn ($query) => $query->where('service_id', $campaign->service_id))
            ->orderByDesc('is_popular')
            ->orderBy('sort_order')
            ->get();
        $campaign->loadMissing('service');

        return view('campaign-landing', [
            'campaign' => $campaign,
            'packages' => $packages,
            'headline' => $campaign->landing_headline
                ?: 'Urus legalitas lebih jelas, tanpa mulai dari nol',
            'subheadline' => $campaign->landing_subheadline
                ?: 'Ceritakan kebutuhan Anda. Tim IzinHukum memeriksa kebutuhan, menjelaskan tahap dan biaya, lalu melanjutkan konsultasi langsung melalui WhatsApp.',
        ]);
    }
}

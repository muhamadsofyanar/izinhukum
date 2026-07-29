<?php

namespace App\Http\Middleware;

use App\Services\FeatureFlagService;
use App\Services\PartnerReferralService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CapturePartnerReferral
{
    public function __construct(
        private readonly PartnerReferralService $referrals,
        private readonly FeatureFlagService $features,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (
            ! $request->is(
                'up',
                'healthz',
                'admin',
                'admin/*',
                'mitra',
                'mitra/*',
                'tagihan/*',
                'kwitansi/*',
                'pelanggan/*',
            )
            && $this->features->enabled('referral_tracking')
        ) {
            $this->referrals->capture($request);
        }

        return $next($request);
    }
}

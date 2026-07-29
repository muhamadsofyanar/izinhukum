<?php

namespace App\Http\Middleware;

use App\Services\PartnerReferralService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CapturePartnerReferral
{
    public function __construct(private readonly PartnerReferralService $referrals)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->referrals->capture($request);

        return $next($request);
    }
}


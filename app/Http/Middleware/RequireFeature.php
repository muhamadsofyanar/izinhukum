<?php

namespace App\Http\Middleware;

use App\Services\FeatureFlagService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireFeature
{
    public function __construct(private readonly FeatureFlagService $features)
    {
    }

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $currentUser = $request->attributes->get('currentUser');
        if ($currentUser?->isAdmin() || $this->features->enabled($feature)) {
            return $next($request);
        }

        abort(404);
    }
}

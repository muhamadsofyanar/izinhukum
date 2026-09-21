<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireContentHubHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = strtolower(trim((string) config('content-hub.domain')));
        if ($expected !== '' && ! hash_equals($expected, strtolower($request->getHost()))) {
            abort(404);
        }

        return $next($request);
    }
}

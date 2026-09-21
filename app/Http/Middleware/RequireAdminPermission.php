<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminPermission
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->attributes->get('currentUser');
        abort_unless($user instanceof User && $user->hasAdminAbility($ability), 403);

        return $next($request);
    }
}

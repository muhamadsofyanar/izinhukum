<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class PartnerAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = User::query()
            ->whereKey($request->session()->get('portal_user_id'))
            ->where('role', 'partner')
            ->where('is_active', true)
            ->first();

        if (! $user) {
            $request->session()->forget(['portal_user_id', 'portal_role']);

            return redirect()->route('partner.login');
        }

        $request->attributes->set('currentUser', $user);
        View::share('currentUser', $user);

        return $next($request);
    }
}

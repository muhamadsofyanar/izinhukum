<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = User::query()
            ->whereKey($request->session()->get('portal_user_id'))
            ->where('role', 'admin')
            ->where('is_active', true)
            ->first();

        if (! $user) {
            $request->session()->forget(['portal_user_id', 'portal_role', 'admin_authenticated']);

            return redirect()->route('admin.login');
        }

        if (! array_key_exists($user->adminRole(), (array) config('content-hub.abilities', []))) {
            abort(403, 'Peran pengguna internal tidak dikenali.');
        }

        $request->attributes->set('currentUser', $user);
        View::share('currentUser', $user);

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictContentHubRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('currentUser');
        abort_unless($user instanceof User, 403);

        if (in_array($user->adminRole(), ['owner', 'admin'], true)) {
            return $next($request);
        }

        $route = (string) $request->route()?->getName();
        if ($route === 'admin.logout') {
            return $next($request);
        }
        $allowed = match ($user->adminRole()) {
            'content' => ['admin.whatsapp.dashboard', 'admin.whatsapp.content.', 'admin.whatsapp.messages.'],
            'customer_service' => ['admin.whatsapp.dashboard', 'admin.whatsapp.composer.', 'admin.whatsapp.contacts.', 'admin.whatsapp.labels.', 'admin.whatsapp.inbox.', 'admin.whatsapp.messages.'],
            'viewer' => ['admin.whatsapp.dashboard', 'admin.whatsapp.messages.'],
            default => [],
        };

        $permitted = collect($allowed)->contains(fn (string $prefix): bool => $route === $prefix || str_starts_with($route, $prefix));
        abort_unless($permitted, 403);

        return $next($request);
    }
}

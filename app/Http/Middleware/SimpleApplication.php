<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class SimpleApplication
{
    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();
        $allowed = ['home', 'legacy.proposal', 'healthz', 'services.index', 'services.show', 'proposal.create', 'proposal.store', 'proposal.success', 'contact', 'legal.privacy', 'legal.terms', 'sitemap', 'invoices.public', 'receipts.public'];
        $admin = ['admin.dashboard', 'admin.login', 'admin.login.store', 'admin.logout', 'admin.orders.*', 'admin.packages.*', 'admin.service-landings.*', 'admin.invoices.*', 'admin.payments.*', 'admin.finance.*', 'admin.branding.*', 'admin.profile.*'];
        $isAdmin = $request->routeIs(...$admin);
        abort_unless(in_array($name, $allowed, true) || $isAdmin, 404);
        // Các chức năng này không thuộc phiên bản thủ công.
        abort_if($request->routeIs('admin.invoices.send', 'admin.orders.portal-token', 'admin.orders.sync', 'admin.orders.from-inquiry'), 404);
        $host = $isAdmin ? config('simple.admin_host') : config('simple.public_host');
        if ($host && $name !== 'healthz') {
            abort_unless(strcasecmp($request->getHost(), $host) === 0, 404);
        }
        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\PartnerApplication;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'newInquiries' => Inquiry::query()->where('status', 'baru')->count(),
            'activePartners' => User::query()->where('role', 'partner')->where('is_active', true)->count(),
            'pendingPartners' => PartnerApplication::query()->where('status', 'pending')->count(),
            'unpaidInvoices' => Invoice::query()->whereIn('status', ['draft', 'sent'])->count(),
            'openOrders' => ServiceOrder::query()->open()->count(),
            'overdueOrders' => ServiceOrder::query()
                ->open()
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->count(),
            'completedOrdersThisMonth' => ServiceOrder::query()
                ->where('status', 'completed')
                ->whereBetween('completed_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'latestOrders' => ServiceOrder::query()
                ->with(['package.service', 'assignee'])
                ->latest()
                ->limit(8)
                ->get(),
        ]);
    }
}

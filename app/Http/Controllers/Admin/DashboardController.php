<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\PartnerApplication;
use App\Models\Service;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'newInquiries' => Inquiry::where('status', 'baru')->count(),
            'totalInquiries' => Inquiry::count(),
            'activeServices' => Service::where('is_active', true)->count(),
            'activePartners' => User::where('role', 'partner')->where('is_active', true)->count(),
            'pendingPartners' => PartnerApplication::where('status', 'pending')->count(),
            'unpaidInvoices' => Invoice::whereIn('status', ['draft', 'sent'])->count(),
            'latestInquiries' => Inquiry::with('package.service')->latest()->limit(8)->get(),
        ]);
    }
}

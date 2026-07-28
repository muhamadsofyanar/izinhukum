<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Service;
use App\Models\ServicePackage;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'newInquiries' => Inquiry::where('status', 'baru')->count(),
            'totalInquiries' => Inquiry::count(),
            'activeServices' => Service::where('is_active', true)->count(),
            'estimatedPackages' => ServicePackage::where('is_estimated', true)->count(),
            'latestInquiries' => Inquiry::with('package.service')->latest()->limit(8)->get(),
        ]);
    }
}

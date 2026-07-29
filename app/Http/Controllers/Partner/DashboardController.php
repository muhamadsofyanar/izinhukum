<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Commission;
use App\Models\CourseEnrollment;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\PartnerReferral;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServicePackage;
use App\Services\FeatureFlagService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, FeatureFlagService $features): View
    {
        $user = $request->attributes->get('currentUser');
        $invoices = Invoice::query()
            ->where(function ($query) use ($user): void {
                $query->where('created_by', $user->id)->orWhere('partner_id', $user->id);
            });

        $referralServices = Service::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Service $service): array => [
                'name' => $service->short_name ?: $service->name,
                'category' => $service->category,
                'url' => route('services.show', [
                    'service' => $service,
                    'ref' => $user->partner_code,
                ]),
            ]);

        return view('partner.dashboard', [
            'partnerPlan' => $user->partnerPlan(),
            'referralTrackingEnabled' => $features->enabled('referral_tracking'),
            'referralUrl' => route('home', ['ref' => $user->partner_code]),
            'proposalReferralUrl' => route('proposal.create', ['ref' => $user->partner_code]),
            'servicesReferralUrl' => route('services.index', ['ref' => $user->partner_code]),
            'referralServices' => $referralServices,
            'referralClicks' => PartnerReferral::where('partner_id', $user->id)->sum('click_count'),
            'referralLeads' => Inquiry::where('referred_by_partner_id', $user->id)->count(),
            'referralOrders' => ServiceOrder::where('referred_by_partner_id', $user->id)->count(),
            'referralInvoices' => Invoice::where('referred_by_partner_id', $user->id)->count(),
            'referralRevenue' => Payment::query()
                ->active()
                ->whereHas('invoice', fn ($query) => $query->where('referred_by_partner_id', $user->id))
                ->sum('amount'),
            'activePackages' => ServicePackage::where('is_active', true)->count(),
            'createdInvoices' => (clone $invoices)->where('created_by', $user->id)->count(),
            'incomingInvoices' => (clone $invoices)->where('partner_id', $user->id)->count(),
            'paidInvoices' => (clone $invoices)->where('status', 'paid')->count(),
            'latestInvoices' => $invoices->with('creator')->latest()->limit(8)->get(),
            'activeCourses' => CourseEnrollment::where('user_id', $user->id)->where('status', '!=', 'completed')->count(),
            'completedCourses' => CourseEnrollment::where('user_id', $user->id)->where('status', 'completed')->count(),
            'commissionTotal' => Commission::where('partner_id', $user->id)->where('status', 'paid')->sum('amount'),
            'commissionPending' => Commission::where('partner_id', $user->id)
                ->whereIn('status', ['pending', 'approved', 'adjustment_required'])
                ->sum('amount'),
            'announcements' => Announcement::whereNotNull('published_at')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest('published_at')
                ->limit(3)
                ->get(),
        ]);
    }
}

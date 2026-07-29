<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\ServicePackage;
use App\Models\Announcement;
use App\Models\CourseEnrollment;
use App\Models\Commission;
use App\Models\Inquiry;
use App\Models\Payment;
use App\Models\PartnerReferral;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->attributes->get('currentUser');
        $invoices = Invoice::query()
            ->where(function ($query) use ($user): void {
                $query->where('created_by', $user->id)->orWhere('partner_id', $user->id);
            });

        return view('partner.dashboard', [
            'partnerPlan' => $user->partnerPlan(),
            'referralUrl' => route('proposal.create', ['ref' => $user->partner_code]),
            'referralClicks' => PartnerReferral::where('partner_id', $user->id)->sum('click_count'),
            'referralLeads' => Inquiry::where('referred_by_partner_id', $user->id)->count(),
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
            'announcements' => Announcement::whereNotNull('published_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('published_at')->limit(3)->get(),
        ]);
    }
}
